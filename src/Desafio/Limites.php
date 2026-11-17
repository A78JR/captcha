<?php

declare(strict_types=1);

namespace Captcha\Desafio;

/** Os limites de um desafio (ver Regras): tempos mínimos e o mínimo que o arraste tem de ter para parecer de gente. */
final class Limites
{
    public function __construct(
        public readonly int $minimoDesdeCriadoMs,
        public readonly int $minimoDesdePegouMs,
        public readonly int $pontosMinimos,
        public readonly int $duracaoMinimaMs,
        public readonly float $desvioMinimo,
    ) {
    }

    /** Os menores valores possíveis: para testes e para quem não tem banco. */
    public static function padrao(): self
    {
        return new self(900, 180, 4, 120, 1.2);
    }
}
