<?php

declare(strict_types=1);

namespace Captcha\Nucleo;

use PDO;
use RuntimeException;

/** Abre a conexão PDO com o MySQL só quando alguém precisa dela. */
final class Conexao
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    /** O banco está configurado (sem tentar conectar)? */
    public function configurada(): bool
    {
        $banco = $this->config->obter('banco');
        return is_array($banco) && !empty($banco['nome']);
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        $banco = $this->config->obter('banco');
        if (!is_array($banco) || empty($banco['nome'])) {
            throw new RuntimeException('Banco não configurado em config/config.local.php');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $banco['host'] ?? 'localhost',
            (int) ($banco['porta'] ?? 3306),
            $banco['nome'],
        );
        $pdo = new PDO($dsn, (string) ($banco['usuario'] ?? ''), (string) ($banco['senha'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // O banco guarda e compara horários em UTC (UTC_TIMESTAMP, CURRENT_TIMESTAMP); a tela converte para Brasília.
        $pdo->exec("SET time_zone = '+00:00'");
        return $this->pdo = $pdo;
    }
}
