<?php

declare(strict_types=1);

namespace Captcha\Api;

use Captcha\Desafio\Conferencia;
use Captcha\Desafio\Desafio;
use Captcha\Desafio\Pintor;
use Captcha\Desafio\Plano;
use Captcha\Nucleo\Requisicao;
use Captcha\Nucleo\Resposta;
use Captcha\Nucleo\Roteador;
use Captcha\Nucleo\Servicos;
use Captcha\Seguranca\LimiteTaxa;

/**
 * A API que o captcha.js usa, chamada de dentro dos outros sites (sem sessão nem login):
 *
 * | Pedido                         | O que faz                                                            |
 * |--------------------------------|----------------------------------------------------------------------|
 * | POST /api/desafio              | site + nonce → desafio novo (endereços das imagens e tamanho)         |
 * | GET  /api/imagem/{id}/{parte}  | a, b = cena nas duas versões; o = objeto cinza reto; g = inclinado    |
 * | POST /api/pegar                | a pessoa começou a carregar: libera a imagem inclinada (g)           |
 * | POST /api/conferir             | x, y (centro solto), modo e trajeto → passe assinado ou "não passou"  |
 * | GET  /api/chave                | chave pública Ed25519 para os sites conferirem o passe               |
 *
 * Os POST vêm como formulário comum (sem JSON), para o navegador não precisar de pré-consulta CORS.
 */
final class ApiControlador
{
    /** Quanto tempo o passe vale para o site trocá-lo pela liberação (segundos). */
    public const VALIDADE_PASSE = 120;

    public function __construct(private readonly Servicos $s)
    {
    }

    public function registrar(Roteador $r): void
    {
        $r->post('/api/desafio', fn (Requisicao $q): Resposta => $this->desafio($q));
        $r->get('/api/imagem/{id}/{parte}', fn (Requisicao $q, array $p): Resposta => $this->imagem((string) $p['id'], (string) $p['parte']));
        $r->post('/api/pegar', fn (Requisicao $q): Resposta => $this->pegar($q));
        $r->post('/api/conferir', fn (Requisicao $q): Resposta => $this->conferir($q));
        $r->get('/api/chave', fn (Requisicao $q): Resposta => $this->chave($q));
    }

    public function desafio(Requisicao $q): Resposta
    {
        $site = $this->s->sites()->porSlug($q->entrada('site'));
        if ($site === null) {
            return $this->json($q, null, ['ok' => false, 'erro' => 'site desconhecido'], 404);
        }
        $origem = $q->cabecalho('origin');
        if ($origem !== '' && !in_array($origem, $site['origens'], true)) {
            return $this->json($q, null, ['ok' => false, 'erro' => 'origem não autorizada'], 403);
        }
        $nonce = $q->entrada('nonce');
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce) !== 1) {
            return $this->json($q, $site['origens'], ['ok' => false, 'erro' => 'nonce inválido'], 400);
        }
        $ip = $this->chaveIp($q);
        // Quem pede desafio sem parar ou erra muito seguido espera um pouco (chute às cegas não compensa).
        if (!$this->s->limite()->contar('captcha-desafio:' . $ip, 40, 900)
            || $this->s->desafios()->falhasRecentes($ip, 900) >= 6) {
            return $this->json($q, $site['origens'], ['ok' => false, 'erro' => 'aguarde', 'aguarde' => 900], 429);
        }
        $d = $this->s->desafios()->criar($site['id'], $nonce, $ip);
        $base = $this->s->urlBase() . '/api/imagem/' . $d->id . '/';
        return $this->json($q, $site['origens'], [
            'ok' => true,
            'id' => $d->id,
            'largura' => Plano::LARGURA,
            'altura' => Plano::ALTURA,
            'a' => $base . 'a',
            'b' => $base . 'b',
            'objeto' => $base . 'o',
            'tamanho' => $d->plano()->certa()->tamanho,
            'quadro' => Pintor::quadro($d->plano()->certa()->tamanho),
            'validade' => \Captcha\Desafio\RepositorioDesafios::VALIDADE,
        ]);
    }

    public function imagem(string $id, string $parte): Resposta
    {
        $d = $this->s->desafios()->abrir($id);
        if ($d === null || !$d->aberto() || !in_array($parte, ['a', 'b', 'o', 'g'], true) || ($parte === 'g' && !$d->pegou())) {
            return new Resposta('', 404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
        $pintor = new Pintor($this->s->raiz() . '/assets/objetos');
        $png = match ($parte) {
            'a' => $pintor->fundo($d->plano(), 0),
            'b' => $pintor->fundo($d->plano(), 1),
            'o' => $pintor->objeto($d->plano(), false),
            'g' => $pintor->objeto($d->plano(), true),
        };
        return new Resposta($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }

    public function pegar(Requisicao $q): Resposta
    {
        $d = $this->s->desafios()->abrir($q->entrada('id'));
        $origens = $d !== null ? ($this->s->sites()->porId($d->siteId)['origens'] ?? []) : [];
        if ($d === null || !$d->aberto()) {
            return $this->json($q, $origens, ['ok' => false, 'novo' => true], 410);
        }
        $this->s->desafios()->pegou($d->id);
        return $this->json($q, $origens, ['ok' => true, 'inclinado' => $this->s->urlBase() . '/api/imagem/' . $d->id . '/g']);
    }

    public function conferir(Requisicao $q): Resposta
    {
        $d = $this->s->desafios()->abrir($q->entrada('id'));
        if ($d === null) {
            return $this->json($q, null, ['ok' => false, 'novo' => true], 404);
        }
        $origens = $this->s->sites()->porId($d->siteId)['origens'] ?? [];
        $x = $q->entrada('x');
        $y = $q->entrada('y');
        if (!is_numeric($x) || !is_numeric($y)) {
            return $this->json($q, $origens, ['ok' => false, 'erro' => 'faltou o ponto'], 400);
        }
        $modo = in_array($q->entrada('modo'), ['arrastar', 'tocar', 'teclado'], true) ? $q->entrada('modo') : 'arrastar';
        $motivo = (new Conferencia($this->s->regras()->paraDesafio($d->id)))->motivo($d, (float) $x, (float) $y, $modo, Conferencia::lerTrajeto($q->entrada('trajeto')));
        // Uma resposta só por desafio: errou, é outro desafio (sem segunda chance no mesmo).
        if (!$this->s->desafios()->fechar($d->id, $motivo === null, $motivo)) {
            return $this->json($q, $origens, ['ok' => false, 'novo' => true], 409);
        }
        if ($motivo !== null) {
            return $this->json($q, $origens, ['ok' => false, 'novo' => true]);
        }
        $passe = $this->s->assinatura()->assinar([
            'v' => 1,
            's' => $d->siteSlug,
            'n' => $d->nonce,
            'd' => $d->id,
            'e' => time() + self::VALIDADE_PASSE,
        ]);
        return $this->json($q, $origens, ['ok' => true, 'passe' => $passe]);
    }

    public function chave(Requisicao $q): Resposta
    {
        return new Resposta(
            (string) json_encode(['algoritmo' => 'ed25519', 'chave' => $this->s->assinatura()->publica()], JSON_UNESCAPED_SLASHES),
            200,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'public, max-age=3600', 'Access-Control-Allow-Origin' => '*'],
        );
    }

    /** IP misturado com a chave_app: dá para contar por IP sem guardar o IP. */
    private function chaveIp(Requisicao $q): string
    {
        return hash_hmac('sha256', LimiteTaxa::chaveIp($q->ip), (string) $this->s->config()->obter('chave_app', 'captcha'));
    }

    /**
     * Resposta JSON com CORS só para as origens do site (o navegador não deixa outros sites lerem).
     * @param list<string>|null $origens
     * @param array<string, mixed> $dados
     */
    private function json(Requisicao $q, ?array $origens, array $dados, int $status = 200): Resposta
    {
        $resposta = Resposta::json($dados, $status)->comCabecalho('Cache-Control', 'no-store')->comCabecalho('Vary', 'Origin');
        $origem = $q->cabecalho('origin');
        if ($origem !== '' && $origens !== null && in_array($origem, $origens, true)) {
            $resposta = $resposta->comCabecalho('Access-Control-Allow-Origin', $origem);
        }
        return $resposta;
    }
}
