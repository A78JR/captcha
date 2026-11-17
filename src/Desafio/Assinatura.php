<?php

declare(strict_types=1);

namespace Captcha\Desafio;

use PDO;
use RuntimeException;

/**
 * Assina os passes com Ed25519 (sodium, que já vem no PHP). A chave secreta fica só neste serviço, em
 * captcha_config, criada na primeira vez. Os sites conferem o passe com a chave pública (/api/chave), sem
 * segredo nenhum para guardar e sem perguntar nada ao serviço a cada visitante.
 */
final class Assinatura
{
    private const NOME = 'ed25519_secreta';

    private ?string $secreta = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Chave pública em base64, para /api/chave. */
    public function publica(): string
    {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->secreta()));
    }

    /** @param array<string, mixed> $dados */
    public function assinar(array $dados): string
    {
        $corpo = self::b64((string) json_encode($dados, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $corpo . '.' . self::b64(sodium_crypto_sign_detached($corpo, $this->secreta()));
    }

    private function secreta(): string
    {
        if ($this->secreta !== null) {
            return $this->secreta;
        }
        $ler = fn (): string|false => $this->pdo->query("SELECT valor FROM captcha_config WHERE nome = '" . self::NOME . "'")->fetchColumn();
        $valor = $ler();
        if ($valor === false) {
            $par = sodium_crypto_sign_keypair();
            // INSERT IGNORE: se dois pedidos criarem ao mesmo tempo, vale o primeiro e os dois leem o mesmo.
            $this->pdo->prepare('INSERT IGNORE INTO captcha_config (nome, valor) VALUES (?, ?)')
                ->execute([self::NOME, base64_encode(sodium_crypto_sign_secretkey($par))]);
            $valor = $ler();
        }
        $secreta = base64_decode((string) $valor, true);
        if ($secreta === false || strlen($secreta) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Chave de assinatura inválida em captcha_config');
        }
        return $this->secreta = $secreta;
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
