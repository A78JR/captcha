<?php

declare(strict_types=1);

namespace Captcha\Testes;

use Captcha\Nucleo\Aplicacao;
use Captcha\Nucleo\Config;
use Captcha\Nucleo\Requisicao;
use Captcha\Nucleo\SessaoMemoria;
use Captcha\Testes\Apoio\CarteiroMemoria;
use PHPUnit\Framework\TestCase;

final class AplicacaoTest extends TestCase
{
    private function aplicacao(array $config = []): Aplicacao
    {
        return Aplicacao::criar(dirname(__DIR__), new Config($config), new SessaoMemoria(), new CarteiroMemoria());
    }

    public function testPaginaInicialSoApresentaEPedeEntrada(): void
    {
        $resposta = $this->aplicacao()->atender(new Requisicao('GET', '/'));

        self::assertSame(200, $resposta->status);
        self::assertStringContainsString('<title>Captcha</title>', $resposta->corpo);
        self::assertStringContainsString('href="/entrar"', $resposta->corpo);
        self::assertStringContainsString('href="/demo"', $resposta->corpo);
    }

    public function testVisitanteVeOMenuLateralComEntrarECriarConta(): void
    {
        $corpo = $this->aplicacao()->atender(new Requisicao('GET', '/'))->corpo;

        self::assertStringContainsString('id="menu-lateral"', $corpo);
        self::assertStringContainsString('href="/entrar"', $corpo);
        self::assertStringContainsString('href="/cadastro"', $corpo);
        self::assertStringContainsString('Recolher menu', $corpo);
        self::assertStringNotContainsString('Painel webmaster', $corpo);
        self::assertStringNotContainsString('action="/sair"', $corpo);
        self::assertMatchesRegularExpression('#href="/"[^>]*aria-current="page"#', $corpo);
    }

    public function testTodaRespostaLevaOsCabecalhosDeSeguranca(): void
    {
        $app = $this->aplicacao();
        foreach (['/', '/sistema/saude', '/nao-existe'] as $caminho) {
            $resposta = $app->atender(new Requisicao('GET', $caminho, https: true));
            self::assertSame('DENY', $resposta->cabecalho('X-Frame-Options'), $caminho);
            self::assertSame('nosniff', $resposta->cabecalho('X-Content-Type-Options'), $caminho);
            self::assertStringContainsString("frame-ancestors 'none'", (string) $resposta->cabecalho('Content-Security-Policy'), $caminho);
            self::assertNotNull($resposta->cabecalho('Strict-Transport-Security'), $caminho);
        }
    }

    public function testSaudeRespondeJson(): void
    {
        $resposta = $this->aplicacao()->atender(new Requisicao('GET', '/sistema/saude'));

        self::assertSame(200, $resposta->status);
        self::assertTrue(json_decode($resposta->corpo, true)['ok']);
    }

    public function testMigrarSemTokenEhRecusado(): void
    {
        $app = $this->aplicacao(['migrar_token' => 'segredo']);

        self::assertSame(401, $app->atender(new Requisicao('POST', '/sistema/migrar'))->status);
        self::assertSame(401, $app->atender(new Requisicao('POST', '/sistema/migrar', cabecalhos: ['x-captcha-token' => 'errado']))->status);
    }

    public function testMigrarSemTokenConfiguradoEhRecusado(): void
    {
        $resposta = $this->aplicacao()->atender(new Requisicao('POST', '/sistema/migrar', cabecalhos: ['x-captcha-token' => '']));

        self::assertSame(401, $resposta->status);
    }
}
