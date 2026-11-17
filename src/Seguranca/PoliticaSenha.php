<?php

declare(strict_types=1);

namespace Captcha\Seguranca;

/** Regras da senha (10 caracteres ou mais, com letras e números) e o hash Argon2id. */
final class PoliticaSenha
{
    public const MINIMO = 10;
    public const MAXIMO_BYTES = 4096;

    /** Devolve o problema da senha, ou null se ela serve. */
    public static function problema(string $senha): ?string
    {
        if (mb_strlen($senha) < self::MINIMO) {
            return 'A senha precisa ter pelo menos ' . self::MINIMO . ' caracteres.';
        }
        if (strlen($senha) > self::MAXIMO_BYTES) {
            return 'Senha longa demais.';
        }
        if (!preg_match('/\p{L}/u', $senha) || !preg_match('/\d/', $senha)) {
            return 'A senha precisa ter letras e números.';
        }
        return null;
    }

    public static function hash(string $senha): string
    {
        return password_hash($senha, self::algoritmo());
    }

    public static function precisaRefazer(string $hash): bool
    {
        return password_needs_rehash($hash, self::algoritmo());
    }

    private static function algoritmo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }
}
