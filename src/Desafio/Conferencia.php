<?php

declare(strict_types=1);

namespace Captcha\Desafio;

/**
 * Decide se a resposta passa. Regra do Alfredo: o centro do objeto solto tem de ficar a até 20% do tamanho
 * do objeto, contando do centro da peça certa (mesmo objeto e mesma inclinação). Além disso, o jeito de
 * responder tem de parecer de gente: ter pegado o objeto antes (pedido da imagem inclinada), não responder
 * rápido demais e, no arraste, um trajeto com pontos, tempo e algum desvio da linha reta.
 */
final class Conferencia
{
    public const FOLGA = 0.20;

    /** Os tempos e o arraste mínimos vêm de Regras (sorteados no servidor, diferentes a cada desafio). */
    public function __construct(private readonly Limites $limites)
    {
    }

    /**
     * @param list<array{0: float, 1: float, 2: float}> $trajeto pontos [ms, x, y] em pixels da cena (400 x 300)
     * @return string|null null quando passa; senão o motivo (objeto, inclinacao, fora, rapido, movimento, sem_pegar, expirado)
     */
    public function motivo(Desafio $d, float $x, float $y, string $modo, array $trajeto): ?string
    {
        if (!$d->aberto()) {
            return 'expirado';
        }
        if (!$d->pegou()) {
            return 'sem_pegar';
        }
        if ($d->idadeMs < $this->limites->minimoDesdeCriadoMs || (int) $d->desdePegouMs < $this->limites->minimoDesdePegouMs) {
            return 'rapido';
        }
        if ($modo === 'arrastar' && !self::trajetoHumano($trajeto, $this->limites)) {
            return 'movimento';
        }
        $certa = $d->plano()->certa();
        if (hypot($x - $certa->x, $y - $certa->y) <= self::FOLGA * $certa->tamanho) {
            return null;
        }
        // Errou: o motivo só vai para as estatísticas (o navegador recebe só "não passou").
        $perto = null;
        foreach ($d->plano()->pecas as $peca) {
            if (hypot($x - $peca->x, $y - $peca->y) <= self::FOLGA * $peca->tamanho) {
                $perto = $peca;
            }
        }
        if ($perto === null) {
            return 'fora';
        }
        return $perto->objeto === $certa->objeto ? 'inclinacao' : 'objeto';
    }

    /** @param list<array{0: float, 1: float, 2: float}> $t */
    public static function trajetoHumano(array $t, ?Limites $limites = null): bool
    {
        $limites ??= Limites::padrao();
        if (count($t) < $limites->pontosMinimos) {
            return false;
        }
        $duracao = $t[count($t) - 1][0] - $t[0][0];
        if ($duracao < $limites->duracaoMinimaMs) {
            return false;
        }
        for ($i = 1; $i < count($t); $i++) {
            if ($t[$i][0] < $t[$i - 1][0]) {
                return false;
            }
        }
        // Distância máxima dos pontos à reta entre o primeiro e o último: robô simples arrasta em linha reta.
        [$x1, $y1] = [$t[0][1], $t[0][2]];
        [$x2, $y2] = [$t[count($t) - 1][1], $t[count($t) - 1][2]];
        $comprimento = hypot($x2 - $x1, $y2 - $y1);
        if ($comprimento < 1) {
            return false;
        }
        $desvio = 0.0;
        foreach ($t as [, $x, $y]) {
            $desvio = max($desvio, abs(($y2 - $y1) * $x - ($x2 - $x1) * $y + $x2 * $y1 - $y2 * $x1) / $comprimento);
        }
        return $desvio >= $limites->desvioMinimo;
    }

    /**
     * Lê o trajeto mandado pelo navegador (texto JSON), com no máximo 400 pontos.
     * @return list<array{0: float, 1: float, 2: float}>
     */
    public static function lerTrajeto(string $json): array
    {
        $dados = json_decode($json, true, 4);
        if (!is_array($dados)) {
            return [];
        }
        $pontos = [];
        foreach (array_slice($dados, 0, 400) as $p) {
            if (is_array($p) && count($p) === 3 && is_numeric($p[0]) && is_numeric($p[1]) && is_numeric($p[2])) {
                $pontos[] = [(float) $p[0], (float) $p[1], (float) $p[2]];
            }
        }
        return $pontos;
    }
}
