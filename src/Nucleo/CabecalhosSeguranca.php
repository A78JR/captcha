<?php

declare(strict_types=1);

namespace Captcha\Nucleo;

/**
 * Cabeçalhos de segurança de toda resposta: CSP restritiva (só o próprio site), sem moldura de outros
 * sites, sem adivinhar tipo de arquivo e HSTS no https.
 */
final class CabecalhosSeguranca
{
    /** @return array<string, string> */
    public static function padrao(bool $https): array
    {
        $cabecalhos = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                "object-src 'none'",
                "script-src 'self'",
                "style-src 'self'",
                "img-src 'self' data:",
                "connect-src 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
            ]),
        ];
        if ($https) {
            $cabecalhos['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        return $cabecalhos;
    }
}
