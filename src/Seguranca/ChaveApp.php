<?php

declare(strict_types=1);

namespace Captcha\Seguranca;

use Captcha\Nucleo\Config;
use RuntimeException;

/**
 * A chave secreta do Captcha ('chave_app' no config.local.php): assina o tempo dos formulários e mistura os
 * códigos da entrada em duas etapas. Sem ela (ou curta demais) o sistema para em vez de seguir inseguro.
 */
final class ChaveApp
{
    public const MINIMO = 32;

    public function __construct(private readonly Config $config)
    {
    }

    public function valor(): string
    {
        $chave = (string) $this->config->obter('chave_app', '');
        if (strlen($chave) < self::MINIMO) {
            throw new RuntimeException("Falta 'chave_app' (32 caracteres ou mais) em config/config.local.php");
        }
        return $chave;
    }

    /** Assinatura de $texto, separada por $uso (o que vale para uma coisa não vale para outra). */
    public function assinar(string $uso, string $texto): string
    {
        return hash_hmac('sha256', $uso . '|' . $texto, $this->valor());
    }
}
