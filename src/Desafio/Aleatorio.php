<?php

declare(strict_types=1);

namespace Captcha\Desafio;

/**
 * Sorteio repetível (mulberry32): a mesma semente dá sempre o mesmo desafio. Assim o banco guarda só a
 * semente e as imagens são desenhadas de novo a cada pedido, sem arquivo nenhum no servidor.
 */
final class Aleatorio
{
    private int $estado;

    public function __construct(int $semente)
    {
        $this->estado = $semente & 0xFFFFFFFF;
    }

    /** Número de 0 a 1 (sem o 1). */
    public function fracao(): float
    {
        $this->estado = ($this->estado + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->estado;
        $t = $this->mult($t ^ ($t >> 15), $t | 1);
        $t ^= ($t + $this->mult($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF;
        return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
    }

    /** Inteiro de $min a $max, os dois inclusive. */
    public function inteiro(int $min, int $max): int
    {
        return $min + (int) floor($this->fracao() * ($max - $min + 1));
    }

    /**
     * @template T
     * @param list<T> $lista
     * @return list<T>
     */
    public function embaralhar(array $lista): array
    {
        for ($i = count($lista) - 1; $i > 0; $i--) {
            $j = $this->inteiro(0, $i);
            [$lista[$i], $lista[$j]] = [$lista[$j], $lista[$i]];
        }
        return $lista;
    }

    /** Multiplicação de 32 bits sem estourar o inteiro do PHP. */
    private function mult(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;
        $baixo = (($a & 0xFFFF) * $b) & 0xFFFFFFFF;
        $alto = ((($a >> 16) * $b) & 0xFFFF) << 16;
        return ($baixo + $alto) & 0xFFFFFFFF;
    }
}
