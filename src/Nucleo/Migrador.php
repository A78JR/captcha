<?php

declare(strict_types=1);

namespace Captcha\Nucleo;

use PDO;

/** Roda, em ordem, os arquivos de db/migracoes/ que ainda não rodaram. */
final class Migrador
{
    public function __construct(private readonly PDO $pdo, private readonly string $pasta)
    {
    }

    /** @return list<string> os arquivos aplicados nesta chamada */
    public function migrar(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS captcha_migracao (
                arquivo VARCHAR(190) PRIMARY KEY,
                aplicada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $feitas = $this->pdo->query('SELECT arquivo FROM captcha_migracao')->fetchAll(PDO::FETCH_COLUMN);
        $aplicadas = [];
        foreach ($this->pendentes($feitas) as $arquivo) {
            foreach ($this->comandos((string) file_get_contents($this->pasta . '/' . $arquivo)) as $sql) {
                $this->pdo->exec($sql);
            }
            $this->pdo->prepare('INSERT INTO captcha_migracao (arquivo) VALUES (?)')->execute([$arquivo]);
            $aplicadas[] = $arquivo;
        }
        return $aplicadas;
    }

    /**
     * @param list<string> $feitas
     * @return list<string>
     */
    public function pendentes(array $feitas): array
    {
        $arquivos = array_map('basename', glob($this->pasta . '/*.sql') ?: []);
        sort($arquivos, SORT_STRING);
        return array_values(array_diff($arquivos, $feitas));
    }

    /** @return list<string> */
    public function comandos(string $sql): array
    {
        $sem_comentarios = preg_replace('/^\s*--.*$/m', '', $sql);
        return array_values(array_filter(array_map('trim', explode(';', (string) $sem_comentarios)), 'strlen'));
    }
}
