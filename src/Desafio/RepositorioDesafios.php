<?php

declare(strict_types=1);

namespace Captcha\Desafio;

use PDO;

/** Os desafios (captcha_desafio): criar, abrir, marcar quando a pessoa pegou o objeto e fechar com o resultado. */
final class RepositorioDesafios
{
    /** Quanto tempo um desafio vale, em segundos. */
    public const VALIDADE = 300;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function criar(int $siteId, string $nonce, string $chaveIp): Desafio
    {
        $id = bin2hex(random_bytes(16));
        $semente = random_int(1, 0xFFFFFFFF);
        $plano = Plano::daSemente($semente);
        $certa = $plano->certa();
        $this->pdo->prepare(
            'INSERT INTO captcha_desafio (id, site_id, semente, cena, objeto, alvo_x, alvo_y, alvo_giro, nonce_site, chave_ip, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3))'
        )->execute([$id, $siteId, $semente, $plano->cena->value, $certa->objeto, $certa->x, $certa->y, $certa->giro, $nonce, $chaveIp]);
        return $this->abrir($id) ?? throw new \RuntimeException('Desafio não gravado');
    }

    public function abrir(string $id): ?Desafio
    {
        if (preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
            return null;
        }
        $consulta = $this->pdo->prepare(
            'SELECT d.*, s.slug AS site_slug, TIMESTAMPDIFF(MICROSECOND, d.criado_em, UTC_TIMESTAMP(3)) DIV 1000 AS idade_ms,
                    TIMESTAMPDIFF(MICROSECOND, d.pegou_em, UTC_TIMESTAMP(3)) DIV 1000 AS desde_pegou_ms
             FROM captcha_desafio d JOIN captcha_site s ON s.id = d.site_id WHERE d.id = ?'
        );
        $consulta->execute([$id]);
        $linha = $consulta->fetch(PDO::FETCH_ASSOC);
        return $linha === false ? null : Desafio::daLinha($linha);
    }

    /** Marca a primeira vez que a pessoa pegou o objeto (pediu a imagem inclinada). */
    public function pegou(string $id): void
    {
        $this->pdo->prepare("UPDATE captcha_desafio SET pegou_em = UTC_TIMESTAMP(3) WHERE id = ? AND pegou_em IS NULL AND situacao = 'aberto'")
            ->execute([$id]);
    }

    /** Fecha o desafio, uma vez só. Devolve false se ele já estava fechado (resposta repetida). */
    public function fechar(string $id, bool $resolvido, ?string $motivo): bool
    {
        $consulta = $this->pdo->prepare(
            "UPDATE captcha_desafio SET situacao = ?, motivo = ?, respondido_em = UTC_TIMESTAMP(3) WHERE id = ? AND situacao = 'aberto'"
        );
        $consulta->execute([$resolvido ? 'resolvido' : 'falhou', $motivo, $id]);
        return $consulta->rowCount() === 1;
    }

    /** Falhas deste IP nos últimos $segundos (para barrar quem chuta). */
    public function falhasRecentes(string $chaveIp, int $segundos): int
    {
        $consulta = $this->pdo->prepare(
            "SELECT COUNT(*) FROM captcha_desafio WHERE chave_ip = ? AND situacao = 'falhou' AND criado_em >= UTC_TIMESTAMP() - INTERVAL ? SECOND"
        );
        $consulta->execute([$chaveIp, $segundos]);
        return (int) $consulta->fetchColumn();
    }

    /** Apaga desafios com mais de 90 dias (chamado na migração). */
    public function limpar(): int
    {
        return (int) $this->pdo->exec('DELETE FROM captcha_desafio WHERE criado_em < UTC_TIMESTAMP() - INTERVAL 90 DAY');
    }

    /**
     * Números por site nos últimos $dias.
     * @return list<array{site: string, nome: string, emitidos: int, resolvidos: int, falhos: int, abandonados: int, tempo_medio_ms: ?int, motivos: array<string, int>}>
     */
    public function estatisticas(int $dias): array
    {
        $consulta = $this->pdo->prepare(
            "SELECT s.slug, s.nome,
                    COUNT(d.id) AS emitidos,
                    SUM(d.situacao = 'resolvido') AS resolvidos,
                    SUM(d.situacao = 'falhou') AS falhos,
                    SUM(d.situacao = 'aberto') AS abandonados,
                    AVG(CASE WHEN d.situacao = 'resolvido' THEN TIMESTAMPDIFF(MICROSECOND, d.criado_em, d.respondido_em) DIV 1000 END) AS tempo_medio_ms
             FROM captcha_site s
             LEFT JOIN captcha_desafio d ON d.site_id = s.id AND d.criado_em >= UTC_TIMESTAMP() - INTERVAL ? DAY
             GROUP BY s.id, s.slug, s.nome ORDER BY s.nome"
        );
        $consulta->execute([$dias]);
        $motivos = $this->pdo->prepare(
            "SELECT s.slug, d.motivo, COUNT(*) AS n FROM captcha_desafio d JOIN captcha_site s ON s.id = d.site_id
             WHERE d.situacao = 'falhou' AND d.criado_em >= UTC_TIMESTAMP() - INTERVAL ? DAY GROUP BY s.slug, d.motivo"
        );
        $motivos->execute([$dias]);
        $porSite = [];
        foreach ($motivos->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $porSite[$m['slug']][(string) $m['motivo']] = (int) $m['n'];
        }
        $linhas = [];
        foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $linhas[] = [
                'site' => (string) $l['slug'],
                'nome' => (string) $l['nome'],
                'emitidos' => (int) $l['emitidos'],
                'resolvidos' => (int) $l['resolvidos'],
                'falhos' => (int) $l['falhos'],
                'abandonados' => (int) $l['abandonados'],
                'tempo_medio_ms' => $l['tempo_medio_ms'] !== null ? (int) $l['tempo_medio_ms'] : null,
                'motivos' => $porSite[$l['slug']] ?? [],
            ];
        }
        return $linhas;
    }
}
