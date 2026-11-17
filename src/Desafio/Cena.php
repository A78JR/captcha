<?php

declare(strict_types=1);

namespace Captcha\Desafio;

/** As cenas e os objetos de cada uma. Os desenhos ficam em assets/objetos/<nome>.png (arte própria). */
enum Cena: string
{
    case Cozinha = 'cozinha';
    case Brinquedos = 'brinquedos';

    /** @return list<string> */
    public function objetos(): array
    {
        return match ($this) {
            self::Cozinha => ['panela', 'xicara', 'chaleira', 'colher', 'frigideira'],
            self::Brinquedos => ['bola', 'pato', 'carrinho', 'cubo', 'piao'],
        };
    }

    public function nome(): string
    {
        return match ($this) {
            self::Cozinha => 'cozinha',
            self::Brinquedos => 'quarto de brinquedos',
        };
    }

    public static function sortear(Aleatorio $a): self
    {
        return $a->fracao() < 0.5 ? self::Cozinha : self::Brinquedos;
    }
}
