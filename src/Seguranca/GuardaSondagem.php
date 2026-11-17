<?php

declare(strict_types=1);

namespace Captcha\Seguranca;

use Captcha\Nucleo\Requisicao;
use Captcha\Nucleo\Resposta;
use Throwable;

/**
 * Bloqueio de quem vasculha o site atrás de arquivos sensíveis (.env, .git, cópias do banco, painéis de
 * WordPress e phpMyAdmin...). Esses endereços não existem na Captcha; só robôs de ataque os pedem. Cada pedido
 * assim conta para o IP; no 3º em 24 horas o IP fica bloqueado no site todo até completar as 24 horas.
 * Quem está logado não é barrado (vários celulares dividem o mesmo IP), e se o banco falhar ninguém é bloqueado.
 */
final class GuardaSondagem
{
    public const MAXIMO = 3;
    public const JANELA = 24 * 3600;

    /** Partes de endereço que só um robô de ataque pede. */
    private const PADRAO = '#(^|/)(
        \.(env|git|svn|hg|ht|aws|ssh|docker|vscode|idea|DS_Store|bash_history)
        | (wp-(admin|login|content|includes|config)[^/]* | phpmyadmin | pma | adminer[^/]* | phpinfo[^/]* | server-status)(/|$)
        | (src|config|db|vendor|temp|views|tests)/.*\.(php|sql|env|log|json|lock|sh)$
        | composer\.(json|lock)$ | cgi-bin/
        | [^/]*\.(sql|sql\.gz|bak|old|orig|swp|ini|log|sh|asp|aspx|jsp|cgi)$
        | (?!index\.php$)[^/]*\.php\d?$
    )#xi';

    public function __construct(
        private readonly LimiteTaxa $limite,
        private readonly RegistroSeguranca $registro,
    ) {
    }

    public static function ehSondagem(string $caminho): bool
    {
        return preg_match(self::PADRAO, $caminho) === 1;
    }

    /** Resposta de bloqueio (ou de "não encontrado" para uma sondagem), ou null para seguir. */
    public function conferir(Requisicao $requisicao, bool $logado): ?Resposta
    {
        $sondagem = self::ehSondagem($requisicao->caminho);
        if (!$sondagem && $logado) {
            return null;
        }
        $balde = 'sonda:' . LimiteTaxa::chaveIp($requisicao->ip);
        try {
            if ($sondagem) {
                $this->limite->contar($balde, self::MAXIMO, self::JANELA);
                if ($this->limite->contagem($balde, self::JANELA) === self::MAXIMO) {
                    $this->registro->evento('ip_bloqueado_sondagem', $requisicao);
                }
                return self::naoEncontrado();
            }
            if ($this->limite->estourado($balde, self::MAXIMO, self::JANELA)) {
                return new Resposta("Acesso bloqueado por 24 horas.\n", 403, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
            }
        } catch (Throwable) {
            return $sondagem ? self::naoEncontrado() : null; // banco fora: não bloqueia ninguém
        }
        return null;
    }

    private static function naoEncontrado(): Resposta
    {
        return new Resposta("Não encontrado.\n", 404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
