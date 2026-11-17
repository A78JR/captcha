<?php

declare(strict_types=1);

namespace Captcha\Testes;

use Captcha\Cliente\VerificadorPasse;
use Captcha\Desafio\Conferencia;
use Captcha\Desafio\Pintor;
use Captcha\Desafio\Plano;
use PHPUnit\Framework\TestCase;

final class DesafioTest extends TestCase
{
    public function testMesmaSementeDaOMesmoPlano(): void
    {
        self::assertEquals(Plano::daSemente(123456), Plano::daSemente(123456));
        self::assertNotEquals(Plano::daSemente(123456)->pecas, Plano::daSemente(654321)->pecas);
    }

    public function testDozePecasComOObjetoCertoTresVezesEmInclinacoesDiferentes(): void
    {
        foreach ([1, 77, 4242, 99999, 0xFFFFFFF0] as $semente) {
            $plano = Plano::daSemente($semente);
            self::assertGreaterThanOrEqual(Plano::PECAS_MIN, count($plano->pecas));
            self::assertLessThanOrEqual(Plano::PECAS_MAX, count($plano->pecas));
            $certas = array_filter($plano->pecas, static fn ($p) => $p->certa);
            self::assertCount(1, $certas);
            $certa = $plano->certa();
            $copias = array_values(array_filter($plano->pecas, static fn ($p) => $p->objeto === $certa->objeto));
            self::assertGreaterThanOrEqual(2, count($copias), "semente {$semente}");
            foreach ($copias as $copia) {
                if ($copia !== $certa) {
                    // As outras cópias têm inclinação claramente diferente (mais que a folga de 3 graus para cada lado).
                    self::assertGreaterThanOrEqual(8, abs($copia->giro - $certa->giro), "semente {$semente}");
                }
            }
            self::assertContains($plano->cena->value, ['cozinha', 'brinquedos']);
            self::assertGreaterThanOrEqual((int) ceil(count($plano->pecas) * 0.55), $certa->camada);
        }
    }

    public function testObjetosSobrepostos(): void
    {
        $plano = Plano::daSemente(31337);
        $sobrepostos = 0;
        foreach ($plano->pecas as $i => $p) {
            foreach ($plano->pecas as $j => $q) {
                if ($i < $j && hypot($p->x - $q->x, $p->y - $q->y) < ($p->tamanho + $q->tamanho) / 2) {
                    $sobrepostos++;
                }
            }
        }
        self::assertGreaterThan(6, $sobrepostos);
    }

    public function testPintorFazPngsDoTamanhoCerto(): void
    {
        $plano = Plano::daSemente(2024);
        $pintor = new Pintor(dirname(__DIR__) . '/assets/objetos');
        $a = $pintor->fundo($plano, 0);
        $b = $pintor->fundo($plano, 1);
        self::assertSame([Plano::LARGURA, Plano::ALTURA], array_slice(getimagesizefromstring($a), 0, 2));
        self::assertNotSame($a, $b);
        $lado = Pintor::quadro($plano->certa()->tamanho);
        self::assertSame([$lado, $lado], array_slice(getimagesizefromstring($pintor->objeto($plano, false)), 0, 2));
        self::assertSame([$lado, $lado], array_slice(getimagesizefromstring($pintor->objeto($plano, true)), 0, 2));
    }

    public function testTrajetoReto(): void
    {
        $reto = [];
        for ($i = 0; $i <= 10; $i++) {
            $reto[] = [$i * 30.0, 10.0 * $i, 5.0 * $i];
        }
        self::assertFalse(Conferencia::trajetoHumano($reto));
        $curvo = $reto;
        $curvo[5][2] += 6;
        self::assertTrue(Conferencia::trajetoHumano($curvo));
        self::assertFalse(Conferencia::trajetoHumano(array_slice($curvo, 0, 3)), 'poucos pontos');
        $rapido = array_map(static fn ($p) => [$p[0] / 10, $p[1], $p[2]], $curvo);
        self::assertFalse(Conferencia::trajetoHumano($rapido), 'rápido demais');
        self::assertSame([], Conferencia::lerTrajeto('lixo'));
        self::assertCount(2, Conferencia::lerTrajeto('[[1,2,3],[4,5,6],["x",1,2]]'));
    }

    public function testVerificadorPasse(): void
    {
        $par = sodium_crypto_sign_keypair();
        $publica = base64_encode(sodium_crypto_sign_publickey($par));
        $assinar = static function (array $dados) use ($par): string {
            $corpo = rtrim(strtr(base64_encode((string) json_encode($dados)), '+/', '-_'), '=');
            return $corpo . '.' . rtrim(strtr(base64_encode(sodium_crypto_sign_detached($corpo, sodium_crypto_sign_secretkey($par))), '+/', '-_'), '=');
        };
        $agora = 1_800_000_000;
        $bom = $assinar(['v' => 1, 's' => 'idioma', 'n' => 'abc123abc123abc1', 'd' => 'x', 'e' => $agora + 60]);
        self::assertTrue(VerificadorPasse::conferirCom($bom, 'abc123abc123abc1', 'idioma', $publica, $agora));
        self::assertFalse(VerificadorPasse::conferirCom($bom, 'outro-nonce-0000', 'idioma', $publica, $agora), 'outra sessão');
        self::assertFalse(VerificadorPasse::conferirCom($bom, 'abc123abc123abc1', 'school', $publica, $agora), 'outro site');
        self::assertFalse(VerificadorPasse::conferirCom($bom, 'abc123abc123abc1', 'idioma', $publica, $agora + 61), 'vencido');
        [$corpo, $assinatura] = explode('.', $bom);
        $falso = rtrim(strtr(base64_encode((string) json_encode(['v' => 1, 's' => 'idioma', 'n' => 'abc123abc123abc1', 'd' => 'x', 'e' => $agora + 9999])), '+/', '-_'), '=');
        self::assertFalse(VerificadorPasse::conferirCom($falso . '.' . $assinatura, 'abc123abc123abc1', 'idioma', $publica, $agora), 'dados trocados');
        self::assertFalse(VerificadorPasse::conferirCom('', 'abc123abc123abc1', 'idioma', $publica, $agora));
        $outraChave = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        self::assertFalse(VerificadorPasse::conferirCom($bom, 'abc123abc123abc1', 'idioma', $outraChave, $agora), 'outra chave');
    }
}
