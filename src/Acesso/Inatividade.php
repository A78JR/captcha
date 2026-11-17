<?php

declare(strict_types=1);

namespace Captcha\Acesso;

/**
 * Quanto tempo sem clicar até a conta sair sozinha. Conta do último clique e recomeça a cada clique.
 * Equipe (webmaster e assistente): 8 horas. Todos os outros: 30 horas.
 */
final class Inatividade
{
    public const USUARIO = 30 * 3600;
    public const EQUIPE = 8 * 3600;
    /** O maior dos prazos: é quanto a sessão precisa durar no servidor e no navegador. */
    public const MAXIMO = self::USUARIO;

    public static function segundosPara(Usuario $usuario): int
    {
        return $usuario->daEquipe() ? self::EQUIPE : self::USUARIO;
    }

    public static function venceu(Usuario $usuario, ?int $ultimoClique, int $agora): bool
    {
        return $ultimoClique !== null && $agora - $ultimoClique > self::segundosPara($usuario);
    }
}
