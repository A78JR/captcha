<?php

declare(strict_types=1);

namespace Captcha\Testes;

use Captcha\Nucleo\Requisicao;
use Captcha\Nucleo\Resposta;
use Captcha\Nucleo\Roteador;
use PHPUnit\Framework\TestCase;

final class RoteadorTest extends TestCase
{
    public function testDespachaComParametro(): void
    {
        $roteador = new Roteador();
        $roteador->get('/series/{codigo}', fn (Requisicao $r, array $p): Resposta => new Resposta('serie ' . $p['codigo']));

        $resposta = $roteador->despachar(new Requisicao('GET', '/series/ipca'));

        self::assertSame(200, $resposta->status);
        self::assertSame('serie ipca', $resposta->corpo);
    }

    public function testCaminhoInexistenteDa404(): void
    {
        self::assertSame(404, (new Roteador())->despachar(new Requisicao('GET', '/nada'))->status);
    }

    public function testMetodoErradoDa405(): void
    {
        $roteador = new Roteador();
        $roteador->post('/sistema/migrar', fn (): Resposta => new Resposta('ok'));

        self::assertSame(405, $roteador->despachar(new Requisicao('GET', '/sistema/migrar'))->status);
    }
}
