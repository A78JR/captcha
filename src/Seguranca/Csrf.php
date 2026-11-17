<?php

declare(strict_types=1);

namespace Captcha\Seguranca;

use Captcha\Nucleo\Sessao;

/** Token CSRF por sessão, que vai em todo formulário (campo _csrf) e é comparado em tempo constante. */
final class Csrf
{
    public const CAMPO = '_csrf';
    private const CHAVE = '_csrf';

    public function __construct(private readonly Sessao $sessao)
    {
    }

    public function token(): string
    {
        $token = $this->sessao->obter(self::CHAVE);
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $this->sessao->definir(self::CHAVE, $token);
        }
        return $token;
    }

    public function valido(?string $enviado): bool
    {
        $token = $this->sessao->obter(self::CHAVE);
        return is_string($token) && is_string($enviado) && $enviado !== '' && hash_equals($token, $enviado);
    }
}
