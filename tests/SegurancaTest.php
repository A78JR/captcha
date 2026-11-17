<?php

declare(strict_types=1);

namespace Captcha\Testes;

use Captcha\Testes\Apoio\TesteComBanco;

final class SegurancaTest extends TesteComBanco
{
    public function testQuemVasculhaArquivosEhBloqueadoNoTerceiroPedido(): void
    {
        self::assertSame(404, $this->pedir('GET', '/.env', [], '192.0.2.66')->status);
        self::assertSame(404, $this->pedir('GET', '/wp-login.php', [], '192.0.2.66')->status);
        self::assertSame(200, $this->pedir('GET', '/', [], '192.0.2.66')->status);
        self::assertSame(404, $this->pedir('GET', '/config/config.local.php', [], '192.0.2.66')->status);

        self::assertSame(403, $this->pedir('GET', '/', [], '192.0.2.66')->status);
        self::assertSame(200, $this->pedir('GET', '/', [], '192.0.2.67')->status);
        self::assertStringContainsString('ip_bloqueado_sondagem', (string) file_get_contents($this->registro));
    }

    public function testLimitePorIpNaRotaDeEsqueciASenha(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->pedir('POST', '/esqueci-senha', ['email' => "x{$i}@captcha.teste"], '192.0.2.90');
        }
        self::assertSame(429, $this->pedir('POST', '/esqueci-senha', ['email' => 'y@captcha.teste'], '192.0.2.90')->status);
    }

    public function testCodigoDaSegundaEtapaVenceDepoisDeTresErros(): void
    {
        $this->criarConta('dono@captcha.teste', 'Dono');
        $this->pedir('POST', '/entrar', ['email' => 'dono@captcha.teste', 'senha' => 'senha-forte-123']);
        $codigo = (int) $this->carteiro->achar('dono@captcha.teste', '/código para entrar na Captcha: (\d{3})/');
        for ($i = 1; $i <= 2; $i++) {
            self::assertSame(422, $this->pedir('POST', '/entrar/codigo', ['codigo' => sprintf('%03d', ($codigo + $i) % 1000)])->status);
        }
        $terceiro = $this->pedir('POST', '/entrar/codigo', ['codigo' => sprintf('%03d', ($codigo + 3) % 1000)]);
        self::assertSame('/entrar', $terceiro->cabecalho('Location'));
        self::assertSame('/entrar', $this->pedir('POST', '/entrar/codigo', ['codigo' => sprintf('%03d', $codigo)])->cabecalho('Location'));
        self::assertNull($this->sessao->obter('usuario_id'));
    }
}
