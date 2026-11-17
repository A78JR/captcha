<?php

declare(strict_types=1);

namespace Captcha\Seguranca;

use Captcha\Nucleo\Requisicao;
use Captcha\Nucleo\Servicos;
use Throwable;

/**
 * Freio para quem tenta adivinhar os tokens das rotas de máquina (/sistema/migrar e, no idioma, /nuvem): depois de
 * MAXIMO tokens errados na JANELA, o IP recebe 429 até a janela acabar. Só os erros contam (o deploy com o token
 * certo nunca é barrado), e se o banco falhar ninguém é barrado.
 */
final class TokenErrado
{
    public const MAXIMO = 10;
    public const JANELA = 3600;

    public function __construct(private readonly ?Servicos $servicos)
    {
    }

    public function esgotado(Requisicao $requisicao): bool
    {
        try {
            return $this->servicos !== null
                && $this->servicos->limite()->estourado(self::balde($requisicao), self::MAXIMO, self::JANELA);
        } catch (Throwable) {
            return false;
        }
    }

    public function contar(Requisicao $requisicao): void
    {
        try {
            $this->servicos?->limite()->contar(self::balde($requisicao), self::MAXIMO, self::JANELA);
        } catch (Throwable) {
        }
    }

    private static function balde(Requisicao $requisicao): string
    {
        return 'token-errado:' . LimiteTaxa::chaveIp($requisicao->ip);
    }
}
