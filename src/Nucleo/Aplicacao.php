<?php

declare(strict_types=1);

namespace Captcha\Nucleo;

use Captcha\Acesso\ContaControlador;
use Captcha\Acesso\EntradaControlador;
use Captcha\Api\ApiControlador;
use Captcha\Demo\DemoControlador;
use Captcha\Painel\EstatisticasControlador;
use Captcha\Inicio\InicioControlador;
use Captcha\Painel\PainelControlador;
use Captcha\Sistema\SistemaControlador;
use Throwable;

/** Monta as dependências, registra as rotas e atende a requisição. */
final class Aplicacao
{
    private function __construct(
        private readonly Servicos $servicos,
        private readonly Roteador $roteador,
    ) {
    }

    public static function criar(string $raiz, ?Config $config = null, ?Sessao $sessao = null, ?Carteiro $carteiro = null): self
    {
        $config ??= Config::carregar($raiz);
        date_default_timezone_set((string) $config->obter('fuso', 'America/Sao_Paulo'));

        $https = str_starts_with((string) $config->obter('url', ''), 'https://');
        $sessao ??= new SessaoNativa($raiz . '/temp/sessoes', $https);
        $remetente = (string) $config->obter('email_remetente', '');
        $carteiro ??= $config->obter('email_de_verdade', false) === true && $remetente !== ''
            ? new CarteiroPhp($remetente)
            : new CarteiroArquivo($raiz . '/temp/logs/emails.log');
        $conexao = new Conexao($config);
        $servicos = new Servicos($raiz, $config, $sessao, $carteiro, $conexao);
        $roteador = new Roteador();

        (new InicioControlador($servicos))->registrar($roteador);
        (new EntradaControlador($servicos))->registrar($roteador);
        (new ContaControlador($servicos))->registrar($roteador);
        (new PainelControlador($servicos))->registrar($roteador);
        (new ApiControlador($servicos))->registrar($roteador);
        (new DemoControlador($servicos))->registrar($roteador);
        (new EstatisticasControlador($servicos))->registrar($roteador);
        (new SistemaControlador($config, $conexao, $raiz . '/db/migracoes', $servicos))->registrar($roteador);

        return new self($servicos, $roteador);
    }

    public function servicos(): Servicos
    {
        return $this->servicos;
    }

    public function atender(Requisicao $requisicao): Resposta
    {
        try {
            $resposta = $this->protegida($requisicao);
        } catch (Throwable $erro) {
            error_log('[captcha] ' . $erro);
            $detalhe = $this->servicos->config()->obter('depurar', false) ? ': ' . $erro->getMessage() : '';
            $resposta = new Resposta('Erro interno' . $detalhe, 500);
        }
        return $resposta->comCabecalhosPadrao(CabecalhosSeguranca::padrao($requisicao->https));
    }

    private function protegida(Requisicao $requisicao): Resposta
    {
        $banco = $this->servicos->conexao()->configurada();
        $usuario = null;
        // A API do captcha é chamada de dentro dos outros sites: não abre sessão nem confere conta.
        if ($banco && $requisicao->caminho !== '/sistema/saude' && !str_starts_with($requisicao->caminho, '/api/')) {
            // Cada requisição relê a conta (no servidor cada uma já é um processo novo; aqui vale para os testes).
            $this->servicos->autenticacao()->esquecerCache();
            $this->servicos->permissoes()->esquecerCache();
            $usuario = $this->servicos->autenticacao()->usuario();
            // Quem vasculha arquivos sensíveis recebe "não encontrado" e, no 3º pedido em 24 horas, fica bloqueado.
            $barrada = $this->servicos->guardaSondagem()->conferir($requisicao, $usuario !== null);
            if ($barrada !== null) {
                return $barrada;
            }
            if ($usuario !== null) {
                $this->servicos->autenticacao()->marcarAcesso($usuario, $requisicao);
            }
        }
        return $this->roteador->despachar($requisicao);
    }

    public function executar(): void
    {
        $this->atender(Requisicao::daGlobal())->enviar();
    }
}
