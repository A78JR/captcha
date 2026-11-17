<?php

declare(strict_types=1);

namespace Captcha\Acesso;

use Closure;
use Captcha\Nucleo\Requisicao;
use Captcha\Nucleo\Resposta;
use Captcha\Nucleo\Servicos;
use Captcha\Seguranca\Csrf;
use Captcha\Seguranca\LimiteTaxa;

/**
 * Guardas das rotas (o "middleware" do Bazar de Tudo). Cada método devolve uma função que o Roteador chama
 * antes do manipulador: ela devolve uma resposta para barrar o pedido, ou null para seguir.
 */
final class Guardas
{
    public function __construct(private readonly Servicos $servicos)
    {
    }

    /** Todo formulário (POST) leva o token CSRF da sessão. */
    public function csrf(): Closure
    {
        return function (Requisicao $requisicao): ?Resposta {
            if ($this->servicos->csrf()->valido($requisicao->entrada(Csrf::CAMPO))) {
                return null;
            }
            $this->servicos->registroSeguranca()->evento('formulario_sem_csrf', $requisicao);
            $this->servicos->sessao()->avisar('erro', 'Sua sessão expirou. Tente novamente.');
            $volta = (string) parse_url($requisicao->cabecalho('referer'), PHP_URL_PATH);
            return Resposta::redirecionar($volta !== '' ? $volta : '/');
        };
    }

    /** Página que exige conta: o visitante vai para Entrar e, depois de entrar, volta para cá. */
    public function logado(): Closure
    {
        return function (Requisicao $requisicao): ?Resposta {
            return $this->servicos->autenticacao()->usuario() === null ? $this->pedirEntrada($requisicao) : null;
        };
    }

    /**
     * Exige uma permissão (Permissoes). $oculta: para quem não tem a permissão (logado ou não), a página "não
     * existe": responde igual a um endereço que não existe (404), sem pedir login nem dizer que é restrita.
     */
    public function pode(string $permissao, bool $oculta = false): Closure
    {
        return function (Requisicao $requisicao) use ($permissao, $oculta): ?Resposta {
            $usuario = $this->servicos->autenticacao()->usuario();
            $pode = $this->servicos->permissoes()->pode($usuario, $permissao);
            if ($pode) {
                return null;
            }
            if ($oculta) {
                return $this->servicos->pagina()->erro($requisicao, 404, 'Página não encontrada.');
            }
            if ($usuario === null) {
                return $this->pedirEntrada($requisicao);
            }
            return $this->servicos->pagina()->erro($requisicao, 403, 'Sua conta não tem permissão para isto.');
        };
    }

    /** No máximo $maximo pedidos por IP a cada $janela segundos nesta ação. */
    public function limitar(string $acao, int $maximo, int $janela): Closure
    {
        return function (Requisicao $requisicao) use ($acao, $maximo, $janela): ?Resposta {
            if ($this->servicos->limite()->contar("{$acao}:ip:" . LimiteTaxa::chaveIp($requisicao->ip), $maximo, $janela)) {
                return null;
            }
            $this->servicos->registroSeguranca()->evento('limite_estourado', $requisicao, ['acao' => $acao]);
            return $this->servicos->pagina()->erro($requisicao, 429, 'Muitas tentativas. Aguarde alguns minutos e tente novamente.');
        };
    }

    private function pedirEntrada(Requisicao $requisicao): Resposta
    {
        // Só páginas: pedidos de fundo (conferência da segunda etapa) não viram destino.
        if ($requisicao->metodo === 'GET' && $requisicao->pedePagina()) {
            $consulta = http_build_query($requisicao->consulta);
            $this->servicos->destino()->lembrar($requisicao->caminho . ($consulta !== '' ? '?' . $consulta : ''));
        }
        $this->servicos->sessao()->avisar('erro', 'Entre na sua conta para continuar.');
        return Resposta::redirecionar('/entrar');
    }
}
