<?php

declare(strict_types=1);

namespace Captcha\Testes;

use Captcha\Nucleo\Migrador;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigradorTest extends TestCase
{
    private function migrador(): Migrador
    {
        return new Migrador(new PDO('sqlite::memory:'), dirname(__DIR__) . '/db/migracoes');
    }

    public function testSeparaComandosIgnorandoComentarios(): void
    {
        $sql = "-- comentário\nCREATE TABLE a (x INT);\n\nCREATE TABLE b (y INT);\n";

        self::assertSame(['CREATE TABLE a (x INT)', 'CREATE TABLE b (y INT)'], $this->migrador()->comandos($sql));
    }

    public function testPendentesEmOrdemSemAsJaFeitas(): void
    {
        self::assertContains('001_usuarios.sql', $this->migrador()->pendentes([]));
        self::assertNotContains('001_usuarios.sql', $this->migrador()->pendentes(['001_usuarios.sql']));
    }
}
