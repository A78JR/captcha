<?php

declare(strict_types=1);

namespace Captcha\Testes;

use Captcha\Seguranca\TokenErrado;
use Captcha\Testes\Apoio\TesteComBanco;

/** Quem tenta adivinhar o token de /sistema/migrar é freado; o deploy com o token certo segue. */
final class TokenErradoTest extends TesteComBanco
{
    public function testTokenErradoDemaisDa429SoParaAqueleIp(): void
    {
        for ($i = 0; $i < TokenErrado::MAXIMO; $i++) {
            self::assertSame(401, $this->pedir('POST', '/sistema/migrar', [], '198.51.100.9', ['x-captcha-token' => 'chute' . $i])->status);
        }
        self::assertSame(429, $this->pedir('POST', '/sistema/migrar', [], '198.51.100.9', ['x-captcha-token' => 'segredo'])->status);
        // Outro IP (o deploy) com o token certo não é afetado.
        self::assertSame(200, $this->pedir('POST', '/sistema/migrar', [], '203.0.113.50', ['x-captcha-token' => 'segredo'])->status);
    }

    public function testSaudeNaoMostraVersaoDoPhp(): void
    {
        $resposta = $this->pedir('GET', '/sistema/saude');
        self::assertSame(200, $resposta->status);
        self::assertStringNotContainsString('php', $resposta->corpo);
    }
}
