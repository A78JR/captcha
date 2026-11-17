<?php

declare(strict_types=1);

namespace Captcha\Cliente;

use Closure;

/**
 * Confere, no servidor de cada site, o passe que o captcha.js deixou no campo captcha_passe.
 * Este arquivo é copiado para os outros sistemas (só muda o namespace): não depende de nada do Captcha.
 *
 * Uso:
 *   $v = new VerificadorPasse('idioma', $raiz . '/temp');
 *   $nonce = VerificadorPasse::novoNonce();          // guarde na sessão e ponha em data-captcha-nonce
 *   if ($v->conferir($_POST['captcha_passe'] ?? '', $nonceDaSessao)) { ... liberado ... }
 *
 * O passe é "dados.assinatura" (base64url), assinado com Ed25519 pelo captcha.lingoist.com.br. Vale se a
 * assinatura bate com a chave pública do serviço, se é para este site, se leva o nonce desta sessão e se
 * ainda não venceu (2 minutos). A chave pública é lida de /api/chave e guardada por um dia em $pastaCache.
 */
final class VerificadorPasse
{
    public const URL = 'https://captcha.lingoist.com.br';

    /** @var Closure(): ?string|null */
    private ?Closure $buscarChave;

    /** @param (Closure(): ?string)|null $buscarChave devolve a chave pública em base64 (testes e o próprio Captcha) */
    public function __construct(
        private readonly string $site,
        private readonly string $pastaCache,
        private readonly string $url = self::URL,
        ?Closure $buscarChave = null,
    ) {
        $this->buscarChave = $buscarChave;
    }

    /** Código novo para a sessão (vai em data-captcha-nonce e volta dentro do passe). */
    public static function novoNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function conferir(string $passe, string $nonce): bool
    {
        $chave = $this->chave();
        return $chave !== null && self::conferirCom($passe, $nonce, $this->site, $chave, time());
    }

    public static function conferirCom(string $passe, string $nonce, string $site, string $chaveB64, int $agora): bool
    {
        if ($nonce === '' || strlen($passe) > 1000 || substr_count($passe, '.') !== 1) {
            return false;
        }
        [$corpo, $assinatura] = explode('.', $passe);
        $chave = base64_decode($chaveB64, true);
        $assinatura = self::de64($assinatura);
        if ($chave === false || strlen($chave) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $assinatura === null || strlen($assinatura) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($assinatura, $corpo, $chave)) {
            return false;
        }
        $dados = json_decode((string) self::de64($corpo), true);
        return is_array($dados)
            && ($dados['s'] ?? null) === $site
            && is_string($dados['n'] ?? null) && hash_equals($nonce, $dados['n'])
            && is_int($dados['e'] ?? null) && $dados['e'] >= $agora;
    }

    /** Chave pública em base64: do cache (um dia) ou de /api/chave. */
    private function chave(): ?string
    {
        if ($this->buscarChave !== null) {
            return ($this->buscarChave)();
        }
        $arquivo = rtrim($this->pastaCache, '/') . '/captcha-chave.txt';
        if (is_file($arquivo) && filemtime($arquivo) > time() - 86400) {
            $guardada = trim((string) file_get_contents($arquivo));
            if ($guardada !== '') {
                return $guardada;
            }
        }
        $resposta = self::baixar($this->url . '/api/chave');
        $dados = $resposta !== null ? json_decode($resposta, true) : null;
        $chave = is_array($dados) && is_string($dados['chave'] ?? null) ? $dados['chave'] : null;
        if ($chave !== null) {
            @mkdir(dirname($arquivo), 0700, true);
            @file_put_contents($arquivo, $chave, LOCK_EX);
            return $chave;
        }
        // Serviço fora do ar: usa a chave antiga, mesmo vencida, se houver.
        return is_file($arquivo) ? (trim((string) file_get_contents($arquivo)) ?: null) : null;
    }

    private static function baixar(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $c = curl_init($url);
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
            $corpo = curl_exec($c);
            $ok = curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 200;
            curl_close($c);
            return $ok && is_string($corpo) ? $corpo : null;
        }
        $corpo = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5]]));
        return is_string($corpo) ? $corpo : null;
    }

    private static function de64(string $texto): ?string
    {
        $bytes = base64_decode(strtr($texto, '-_', '+/') . str_repeat('=', (4 - strlen($texto) % 4) % 4), true);
        return $bytes === false ? null : $bytes;
    }
}
