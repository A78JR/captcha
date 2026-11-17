<?php

declare(strict_types=1);

namespace Captcha\Testes;

use Captcha\Cliente\VerificadorPasse;
use Captcha\Desafio\Plano;
use Captcha\Testes\Apoio\TesteComBanco;

final class ApiTest extends TesteComBanco
{
    private const ORIGEM = 'https://espanhol.lingoist.com.br';
    private const NONCE = 'nonce-da-sessao-0123456789';

    /** @return array<string, mixed> */
    private function api(string $metodo, string $caminho, array $corpo = [], string $ip = '203.0.113.7'): array
    {
        $r = $this->app->atender(new \Captcha\Nucleo\Requisicao($metodo, $caminho, [], $corpo, ['origin' => self::ORIGEM], $ip, true));
        return ['status' => $r->status, 'json' => json_decode($r->corpo, true), 'resposta' => $r];
    }

    /** @return array<string, mixed> */
    private function novoDesafio(string $ip = '203.0.113.7'): array
    {
        $r = $this->api('POST', '/api/desafio', ['site' => 'idioma', 'nonce' => self::NONCE], $ip);
        self::assertSame(200, $r['status']);
        return $r['json'];
    }

    /** Volta o relógio do desafio, como se a pessoa tivesse levado alguns segundos. */
    private function envelhecer(string $id, int $criadoHaMs = 5000, int $pegouHaMs = 2000): void
    {
        $this->pdo->prepare('UPDATE captcha_desafio SET criado_em = UTC_TIMESTAMP(3) - INTERVAL ? MICROSECOND, pegou_em = UTC_TIMESTAMP(3) - INTERVAL ? MICROSECOND WHERE id = ?')
            ->execute([$criadoHaMs * 1000, $pegouHaMs * 1000, $id]);
    }

    private function plano(string $id): Plano
    {
        $s = $this->pdo->prepare('SELECT semente FROM captcha_desafio WHERE id = ?');
        $s->execute([$id]);
        return Plano::daSemente((int) $s->fetchColumn());
    }

    /** Trajeto curvo de 600 ms até (x, y). */
    private static function trajeto(float $x, float $y): string
    {
        $pontos = [];
        for ($i = 0; $i <= 12; $i++) {
            $t = $i / 12;
            $pontos[] = [$i * 50, 200 + ($x - 200) * $t, 320 + ($y - 320) * $t - 30 * sin(M_PI * $t)];
        }
        return (string) json_encode($pontos);
    }

    public function testFluxoCompletoDaOPasseQueOSiteAceitaUmaVez(): void
    {
        $d = $this->novoDesafio();
        self::assertSame(Plano::LARGURA, $d['largura']);
        $id = $d['id'];

        // Imagens: as duas versões da cena e o objeto reto; o inclinado só depois de pegar.
        foreach (['a', 'b', 'o'] as $parte) {
            $img = $this->api('GET', "/api/imagem/{$id}/{$parte}");
            self::assertSame(200, $img['status'], $parte);
            self::assertSame('image/png', $img['resposta']->cabecalho('Content-Type'));
        }
        self::assertSame(404, $this->api('GET', "/api/imagem/{$id}/g")['status']);
        $pegar = $this->api('POST', '/api/pegar', ['id' => $id]);
        self::assertTrue($pegar['json']['ok']);
        self::assertSame(self::ORIGEM, $pegar['resposta']->cabecalho('Access-Control-Allow-Origin'));
        self::assertSame(200, $this->api('GET', "/api/imagem/{$id}/g")['status']);

        $this->envelhecer($id);
        $certa = $this->plano($id)->certa();
        // Solto a 15% do tamanho do centro: passa (o limite é 20%).
        $x = $certa->x + 0.15 * $certa->tamanho;
        $r = $this->api('POST', '/api/conferir', ['id' => $id, 'x' => (string) $x, 'y' => (string) $certa->y, 'modo' => 'arrastar', 'trajeto' => self::trajeto($x, $certa->y)]);
        self::assertTrue($r['json']['ok'], json_encode($r['json']));
        $passe = $r['json']['passe'];

        $publica = $this->api('GET', '/api/chave')['json']['chave'];
        $v = new VerificadorPasse('idioma', sys_get_temp_dir(), 'https://x', fn (): string => $publica);
        self::assertTrue($v->conferir($passe, self::NONCE));
        self::assertFalse($v->conferir($passe, 'outra-sessao-0000000000'));
        self::assertFalse((new VerificadorPasse('school', sys_get_temp_dir(), 'https://x', fn (): string => $publica))->conferir($passe, self::NONCE));

        // Segunda resposta no mesmo desafio: recusada.
        $de_novo = $this->api('POST', '/api/conferir', ['id' => $id, 'x' => (string) $certa->x, 'y' => (string) $certa->y, 'modo' => 'tocar']);
        self::assertSame(409, $de_novo['status']);
        // Desafio fechado: as imagens somem.
        self::assertSame(404, $this->api('GET', "/api/imagem/{$id}/a")['status']);
    }

    public function testErrosFechamODesafioComOMotivo(): void
    {
        $casos = [
            'fora' => fn (Plano $p): array => [$p->certa()->x + 0.3 * $p->certa()->tamanho, $p->certa()->y, 'tocar', true],
            'inclinacao' => function (Plano $p): array {
                foreach ($p->pecas as $q) {
                    if ($q->objeto === $p->certa()->objeto && !$q->certa && hypot($q->x - $p->certa()->x, $q->y - $p->certa()->y) > 60) {
                        return [$q->x, $q->y, 'tocar', true];
                    }
                }
                return [-1, -1, 'tocar', true];
            },
            'movimento' => fn (Plano $p): array => [$p->certa()->x, $p->certa()->y, 'arrastar', true],
            'sem_pegar' => fn (Plano $p): array => [$p->certa()->x, $p->certa()->y, 'tocar', false],
        ];
        foreach ($casos as $esperado => $caso) {
            $d = $this->novoDesafio('198.51.100.' . random_int(1, 200));
            $plano = $this->plano($d['id']);
            [$x, $y, $modo, $pegar] = $caso($plano);
            if ($x < 0) {
                continue;
            }
            if ($pegar) {
                $this->api('POST', '/api/pegar', ['id' => $d['id']]);
                $this->envelhecer($d['id']);
            }
            $reto = (string) json_encode([[0, 0, 0], [100, 10, 10], [200, 20, 20], [300, 30, 30]]);
            $r = $this->api('POST', '/api/conferir', ['id' => $d['id'], 'x' => (string) $x, 'y' => (string) $y, 'modo' => $modo, 'trajeto' => $reto]);
            self::assertFalse($r['json']['ok'], $esperado);
            self::assertTrue($r['json']['novo'], $esperado);
            $m = $this->pdo->prepare('SELECT situacao, motivo FROM captcha_desafio WHERE id = ?');
            $m->execute([$d['id']]);
            self::assertSame(['situacao' => 'falhou', 'motivo' => $esperado], $m->fetch());
        }
    }

    public function testRespostaRapidaDemaisNaoPassa(): void
    {
        $d = $this->novoDesafio();
        $this->api('POST', '/api/pegar', ['id' => $d['id']]);
        $certa = $this->plano($d['id'])->certa();
        $r = $this->api('POST', '/api/conferir', ['id' => $d['id'], 'x' => (string) $certa->x, 'y' => (string) $certa->y, 'modo' => 'tocar']);
        self::assertFalse($r['json']['ok']);
    }

    public function testSiteEOrigemDesconhecidos(): void
    {
        self::assertSame(404, $this->api('POST', '/api/desafio', ['site' => 'nao-existe', 'nonce' => self::NONCE])['status']);
        $r = $this->app->atender(new \Captcha\Nucleo\Requisicao('POST', '/api/desafio', [], ['site' => 'idioma', 'nonce' => self::NONCE], ['origin' => 'https://outro.site'], '203.0.113.9', true));
        self::assertSame(403, $r->status);
        self::assertNull($r->cabecalho('Access-Control-Allow-Origin'));
        self::assertSame(400, $this->api('POST', '/api/desafio', ['site' => 'idioma', 'nonce' => 'curto'])['status']);
    }

    public function testQuemErraMuitoEsperaUmPouco(): void
    {
        $ip = '192.0.2.50';
        for ($i = 0; $i < 6; $i++) {
            $d = $this->novoDesafio($ip);
            $this->api('POST', '/api/conferir', ['id' => $d['id'], 'x' => '0', 'y' => '0', 'modo' => 'tocar'], $ip);
        }
        $r = $this->api('POST', '/api/desafio', ['site' => 'idioma', 'nonce' => self::NONCE], $ip);
        self::assertSame(429, $r['status']);
        self::assertSame('aguarde', $r['json']['erro']);
    }

    /** Os limites saem do banco (sorteados uma vez por instalação) e variam de desafio para desafio, dentro das faixas. */
    public function testRegrasSorteadasNoServidor(): void
    {
        $regras = $this->app->servicos()->regras();
        $vistos = [];
        for ($i = 0; $i < 30; $i++) {
            $l = $regras->paraDesafio(bin2hex(random_bytes(16)));
            self::assertGreaterThanOrEqual(900, $l->minimoDesdeCriadoMs);
            self::assertLessThanOrEqual(1950, $l->minimoDesdeCriadoMs);
            self::assertGreaterThanOrEqual(180, $l->minimoDesdePegouMs);
            self::assertLessThanOrEqual(460, $l->minimoDesdePegouMs);
            self::assertGreaterThanOrEqual(4, $l->pontosMinimos);
            self::assertLessThanOrEqual(8, $l->pontosMinimos);
            self::assertGreaterThanOrEqual(1.2, $l->desvioMinimo);
            self::assertLessThanOrEqual(3.0, $l->desvioMinimo);
            $vistos[$l->minimoDesdeCriadoMs] = true;
        }
        self::assertGreaterThan(5, count($vistos), 'cada desafio tem os seus limites');
        self::assertEquals($regras->paraDesafio('abc'), $regras->paraDesafio('abc'));
        $guardado = json_decode((string) $this->pdo->query("SELECT valor FROM captcha_config WHERE nome = 'regras'")->fetchColumn(), true);
        self::assertSame(64, strlen($guardado['sal']));
    }
}
