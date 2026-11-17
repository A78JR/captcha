<?php

declare(strict_types=1);

namespace Captcha\Acesso;

use Captcha\Nucleo\Config;

/**
 * Quem é webmaster do Captcha: sempre o login do Alfredo (DONO), mais os e-mails extras da lista
 * 'webmasters' do config.local.php. A conta com um destes e-mails vira webmaster (grupo 1) assim que
 * confirma o e-mail, e o cadastro deixa esses e-mails entrarem mesmo fechado.
 */
final class Webmasters
{
    public const DONO = 'alfredolingoist@gmail.com';

    /** @return list<string> */
    public static function emails(Config $config): array
    {
        $emails = array_map(
            static fn ($e): string => mb_strtolower(trim((string) $e)),
            array_merge([self::DONO], (array) $config->obter('webmasters', [])),
        );
        return array_values(array_unique(array_filter($emails)));
    }
}
