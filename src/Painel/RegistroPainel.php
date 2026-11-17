<?php

declare(strict_types=1);

namespace Captcha\Painel;

use PDO;

/**
 * O que a equipe fez nas contas pelo Painel (suspender, banir, reativar, trocar o grupo, mandar link de
 * senha, desconectar, reenviar a confirmação, entrar e sair da equipe): quem fez, em quem, quando e por quê.
 */
final class RegistroPainel
{
    public const ACOES = ['aprovar', 'recusar', 'suspender', 'banir', 'reativar', 'grupo', 'senha', 'desconectar', 'confirmacao', 'equipe_entrou', 'equipe_saiu'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function registrar(int $alvoId, string $acao, ?int $autorId, ?string $nota = null): void
    {
        $this->pdo->prepare('INSERT INTO captcha_registro_painel (alvo_id, acao, autor_id, nota) VALUES (?, ?, ?, ?)')
            ->execute([$alvoId, $acao, $autorId, $nota !== null && $nota !== '' ? mb_substr($nota, 0, 1000) : null]);
    }

    /** @return list<array<string, mixed>> últimas ações, com o nome de quem fez e de quem recebeu */
    public function ultimas(int $limite = 20, ?int $autorId = null): array
    {
        $onde = $autorId !== null ? 'WHERE r.autor_id = ?' : '';
        $consulta = $this->pdo->prepare(
            "SELECT r.*, a.nome AS autor_nome, u.nome AS alvo_nome FROM captcha_registro_painel r
             LEFT JOIN captcha_usuario a ON a.id = r.autor_id LEFT JOIN captcha_usuario u ON u.id = r.alvo_id
             {$onde} ORDER BY r.id DESC LIMIT " . max(1, min(200, $limite))
        );
        $consulta->execute($autorId !== null ? [$autorId] : []);
        return $consulta->fetchAll();
    }

    /** Quantas ações a pessoa fez desde $horas horas atrás. */
    public function contagem(int $autorId, int $horas): int
    {
        $consulta = $this->pdo->prepare('SELECT COUNT(*) FROM captcha_registro_painel WHERE autor_id = ? AND criado_em >= UTC_TIMESTAMP() - INTERVAL ? HOUR');
        $consulta->execute([$autorId, $horas]);
        return (int) $consulta->fetchColumn();
    }
}
