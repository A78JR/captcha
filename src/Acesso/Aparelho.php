<?php

declare(strict_types=1);

namespace Captcha\Acesso;

/** "Chrome no Windows", "Safari no iPhone"... Só para a pessoa reconhecer o aparelho nos e-mails e no painel. */
final class Aparelho
{
    public static function descrever(?string $agente): string
    {
        $ua = (string) $agente;
        $navegador = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => '',
        };
        $sistema = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };
        if ($navegador === '' && $sistema === '') {
            return 'desconhecido';
        }
        return $navegador !== '' && $sistema !== '' ? "{$navegador} no {$sistema}" : $navegador . $sistema;
    }
}
