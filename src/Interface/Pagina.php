<?php

declare(strict_types=1);

namespace Captcha\Interface;

use Captcha\Acesso\Usuario;
use Captcha\Nucleo\Requisicao;
use Captcha\Nucleo\Resposta;
use Captcha\Nucleo\Marca;
use Captcha\Nucleo\Servicos;
use Throwable;

/**
 * Monta a página inteira: o template do controlador dentro do layout, com o menu lateral de quem está vendo,
 * os recados da sessão (sucesso e erro) e os campos ocultos dos formulários.
 */
final class Pagina
{
    public function __construct(private readonly Servicos $servicos)
    {
    }

    /** @param array<string, mixed> $dados */
    public function html(Requisicao $requisicao, string $template, array $dados = [], string $titulo = Marca::NOME, int $status = 200): Resposta
    {
        $usuario = $this->usuario();
        $csrf = $this->servicos->csrf()->token();
        $campos = new CamposFormulario($csrf, $this->carimbo());
        $contexto = new ContextoMenu($usuario, $usuario !== null ? $this->servicos->permissoes() : null);
        $sessao = $this->servicos->sessao();
        $visao = $this->servicos->visao();
        $menu = Menu::montar($contexto, $requisicao->caminho, RegistroModulos::padrao());
        $corpo = $visao->renderizar($template, $dados + ['campos' => $campos, 'usuario' => $usuario, 'contexto' => $contexto], $titulo, [
            'menu' => $menu,
            'menuHtml' => $visao->parcial('parciais/menu_lateral', ['menu' => $menu, 'campos' => $campos]),
            'avisos' => array_filter(['sucesso' => $sessao->aviso('sucesso'), 'erro' => $sessao->aviso('erro')]),
        ]);
        return new Resposta($corpo, $status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public function erro(Requisicao $requisicao, int $status, string $mensagem): Resposta
    {
        return $this->html($requisicao, 'erro', ['codigo' => $status, 'mensagem' => $mensagem], $mensagem, $status);
    }

    /** A conta da sessão; sem banco configurado (ou fora do ar), a página sai como a de visitante. */
    private function usuario(): ?Usuario
    {
        if (!$this->servicos->conexao()->configurada()) {
            return null;
        }
        try {
            return $this->servicos->autenticacao()->usuario();
        } catch (Throwable $erro) {
            error_log('[captcha] Sem conta na página: ' . $erro->getMessage());
            return null;
        }
    }

    /** Tempo assinado dos formulários públicos; sem a chave_app configurada, os formulários públicos ficam sem ele. */
    private function carimbo(): ?string
    {
        try {
            return $this->servicos->guardaFormulario()->carimbo();
        } catch (Throwable) {
            return null;
        }
    }
}
