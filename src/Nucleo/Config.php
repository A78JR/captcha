<?php

declare(strict_types=1);

namespace Captcha\Nucleo;

/** Configuração lida de config/config.local.php (fora do git), com leitura por chave "a.b.c". */
final class Config
{
    /** @param array<string, mixed> $valores */
    public function __construct(private readonly array $valores)
    {
    }

    public static function carregar(string $raiz): self
    {
        $arquivo = $raiz . '/config/config.local.php';
        $valores = is_file($arquivo) ? require $arquivo : [];
        return new self(is_array($valores) ? $valores : []);
    }

    public function obter(string $chave, mixed $padrao = null): mixed
    {
        $atual = $this->valores;
        foreach (explode('.', $chave) as $parte) {
            if (!is_array($atual) || !array_key_exists($parte, $atual)) {
                return $padrao;
            }
            $atual = $atual[$parte];
        }
        return $atual;
    }
}
