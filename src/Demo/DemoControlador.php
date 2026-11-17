<?php

declare(strict_types=1);

namespace Captcha\Demo;

use Captcha\Cliente\VerificadorPasse;
use Captcha\Nucleo\Requisicao;
use Captcha\Nucleo\Resposta;
use Captcha\Nucleo\Roteador;
use Captcha\Nucleo\Servicos;

/**
 * /demo: o captcha funcionando como em qualquer site (site "demo"), do começo ao fim: o nonce vai na
 * sessão, o captcha.js preenche captcha_passe e o envio confere o passe com o VerificadorPasse.
 */
final class DemoControlador
{
    private const SESSAO = 'captcha_demo_nonce';

    public function __construct(private readonly Servicos $s)
    {
    }

    public function registrar(Roteador $r): void
    {
        $r->get('/demo', fn (Requisicao $q): Resposta => $this->tela($q, null));
        $r->post('/demo', fn (Requisicao $q): Resposta => $this->conferir($q), [$this->s->guardas()->csrf()]);
    }

    private function tela(Requisicao $q, ?bool $passou): Resposta
    {
        $nonce = VerificadorPasse::novoNonce();
        $this->s->sessao()->definir(self::SESSAO, $nonce);
        return $this->s->pagina()->html($q, 'demo/demo', ['nonce' => $nonce, 'passou' => $passou], 'Experimentar');
    }

    private function conferir(Requisicao $q): Resposta
    {
        $nonce = (string) $this->s->sessao()->obter(self::SESSAO, '');
        $verificador = new VerificadorPasse('demo', $this->s->raiz() . '/temp', $this->s->urlBase(), fn (): string => $this->s->assinatura()->publica());
        $passou = $verificador->conferir($q->entrada('captcha_passe'), $nonce);
        $this->s->sessao()->esquecer(self::SESSAO); // um passe vale uma vez: a próxima tela gera outro nonce
        return $this->tela($q, $passou);
    }
}
