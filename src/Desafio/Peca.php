<?php

declare(strict_types=1);

namespace Captcha\Desafio;

/**
 * Um objeto colorido na prateleira: o centro (x, y), o tamanho em pixels, o giro em graus (horário),
 * a cor (giro de matiz, 0 a 359) e a camada (quem tem camada maior fica por cima).
 */
final class Peca
{
    public function __construct(
        public readonly string $objeto,
        public readonly int $x,
        public readonly int $y,
        public readonly int $tamanho,
        public readonly int $giro,
        public readonly int $matiz,
        public readonly int $camada,
        public readonly bool $certa = false,
    ) {
    }

    /** O ponto (x, y) cai dentro do objeto? (círculo um pouco menor que o quadrado do desenho) */
    public function contem(float $x, float $y): bool
    {
        return hypot($x - $this->x, $y - $this->y) <= $this->tamanho * 0.42;
    }
}
