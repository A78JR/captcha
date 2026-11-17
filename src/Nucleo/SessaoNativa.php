<?php

declare(strict_types=1);

namespace Captcha\Nucleo;

use Captcha\Acesso\Inatividade;

/**
 * Sessão do PHP, com cookie HttpOnly, SameSite=Lax e Secure no https. Só abre quando alguém lê ou grava,
 * então /sistema/saude e as páginas de visitante sem formulário não criam cookie.
 *
 * Os arquivos ficam em temp/sessoes, uma pasta só do Captcha: a limpeza padrão da hospedagem apaga sessões
 * paradas há 24 minutos, o que derrubaria o login muito antes do prazo de Inatividade.
 */
final class SessaoNativa implements Sessao
{
    public const NOME = 'captcha_sid';

    public function __construct(
        private readonly string $pasta,
        private readonly bool $seguro,
    ) {
    }

    public function obter(string $chave, mixed $padrao = null): mixed
    {
        $this->abrir();
        return $_SESSION[$chave] ?? $padrao;
    }

    public function definir(string $chave, mixed $valor): void
    {
        $this->abrir();
        $_SESSION[$chave] = $valor;
    }

    public function esquecer(string $chave): void
    {
        $this->abrir();
        unset($_SESSION[$chave]);
    }

    public function avisar(string $tipo, string $mensagem): void
    {
        $this->abrir();
        $_SESSION['_aviso'][$tipo] = $mensagem;
    }

    public function aviso(string $tipo): ?string
    {
        if (!$this->temCookie() && session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $this->abrir();
        $valor = $_SESSION['_aviso'][$tipo] ?? null;
        unset($_SESSION['_aviso'][$tipo]);
        return is_string($valor) ? $valor : null;
    }

    public function regenerar(): void
    {
        $this->abrir();
        session_regenerate_id(true);
    }

    public function destruir(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !$this->temCookie()) {
            return;
        }
        $this->abrir();
        $_SESSION = [];
        session_destroy();
        if (!headers_sent()) {
            setcookie(self::NOME, '', ['expires' => time() - 3600] + $this->opcoesCookie());
        }
    }

    public function manterPor(int $segundos): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            setcookie(self::NOME, (string) session_id(), ['expires' => time() + $segundos] + $this->opcoesCookie());
        }
    }

    private function temCookie(): bool
    {
        return isset($_COOKIE[self::NOME]);
    }

    private function abrir(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(self::NOME);
        session_set_cookie_params(['lifetime' => 0] + $this->opcoesCookie());
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        if ((is_dir($this->pasta) || @mkdir($this->pasta, 0700, true)) && is_writable($this->pasta)) {
            session_save_path($this->pasta);
            ini_set('session.gc_maxlifetime', (string) (Inatividade::MAXIMO + 3600));
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        }
        session_start();
    }

    /** @return array{path: string, secure: bool, httponly: bool, samesite: string} */
    private function opcoesCookie(): array
    {
        return ['path' => '/', 'secure' => $this->seguro, 'httponly' => true, 'samesite' => 'Lax'];
    }
}
