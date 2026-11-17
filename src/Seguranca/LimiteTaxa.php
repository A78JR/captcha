<?php

declare(strict_types=1);

namespace Captcha\Seguranca;

use PDO;
use Throwable;

/**
 * Limite de tentativas por janela fixa, guardado no MySQL (tabela limite_taxa).
 * Ex.: no máximo 10 senhas erradas por conta a cada hora.
 */
final class LimiteTaxa
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Chave do IP para os limites. Um IPv6 vale pela faixa /64 inteira: quem recebe uma faixa (comum em
     * servidores e operadoras) poderia trocar de endereço a cada pedido.
     */
    public static function chaveIp(string $ip): string
    {
        if (str_contains($ip, ':')) {
            $binario = @inet_pton($ip);
            // IPv4 escrito como IPv6 (::ffff:1.2.3.4) continua valendo pelo endereço IPv4.
            if ($binario !== false && strlen($binario) === 16 && str_starts_with($binario, str_repeat("\0", 10) . "\xff\xff")) {
                return (string) inet_ntop(substr($binario, 12));
            }
            if ($binario !== false && strlen($binario) === 16) {
                return (string) inet_ntop(substr($binario, 0, 8) . str_repeat("\0", 8)) . '/64';
            }
        }
        return $ip;
    }

    /** O limite já foi atingido? (sem contar uma tentativa nova) */
    public function estourado(string $balde, int $maximo, int $janela): bool
    {
        return $this->contagem($balde, $janela) >= $maximo;
    }

    /** Tentativas já contadas na janela atual (0 se a janela acabou ou nunca houve). */
    public function contagem(string $balde, int $janela): int
    {
        $consulta = $this->pdo->prepare('SELECT tentativas, inicio FROM captcha_limite_taxa WHERE balde = ?');
        $consulta->execute([substr($balde, 0, 190)]);
        $linha = $consulta->fetch();
        return $linha !== false && strtotime($linha['inicio'] . ' UTC') + $janela > time() ? (int) $linha['tentativas'] : 0;
    }

    /** Conta uma tentativa e diz se ela ainda está dentro do limite. */
    public function contar(string $balde, int $maximo, int $janela): bool
    {
        $balde = substr($balde, 0, 190);
        $this->pdo->beginTransaction();
        try {
            $consulta = $this->pdo->prepare('SELECT tentativas, inicio FROM captcha_limite_taxa WHERE balde = ? FOR UPDATE');
            $consulta->execute([$balde]);
            $linha = $consulta->fetch();
            if ($linha === false || strtotime($linha['inicio'] . ' UTC') + $janela <= time()) {
                $this->pdo->prepare('REPLACE INTO captcha_limite_taxa (balde, tentativas, inicio) VALUES (?, 1, UTC_TIMESTAMP())')->execute([$balde]);
                $this->pdo->commit();
                return 1 <= $maximo;
            }
            $tentativas = (int) $linha['tentativas'] + 1;
            $this->pdo->prepare('UPDATE captcha_limite_taxa SET tentativas = ? WHERE balde = ?')->execute([$tentativas, $balde]);
            $this->pdo->commit();
            return $tentativas <= $maximo;
        } catch (Throwable $erro) {
            $this->pdo->rollBack();
            throw $erro;
        }
    }
}
