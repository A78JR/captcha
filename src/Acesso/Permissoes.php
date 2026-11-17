<?php

declare(strict_types=1);

namespace Captcha\Acesso;

use InvalidArgumentException;
use PDO;

/**
 * Grupos e permissões, como no burgu e no Bazar de Tudo: cada conta tem um grupo só (usuario.grupo_id,
 * com o nome em usuario.credencial) e cada grupo dá uma lista de permissões. O resto do código pergunta só
 * "pode fazer X?" com pode(), para que mudar o que um grupo pode fazer seja só mudar o banco.
 *
 * | Nº | Grupo        | Para quem                     | O que pode                                   |
 * |----|--------------|-------------------------------|----------------------------------------------|
 * | 1  | webmaster    | Dono do site                  | Tudo (permissão "*")                          |
 * | 2  | assistente   | Quem ajuda a cuidar do site   | Painel, Usuários e estatísticas do captcha    |
 * | 3  | usuario      | Conta comum (padrão)          | Só entrar                                     |
 *
 * Contas suspensas, banidas ou excluídas não têm nenhuma permissão.
 */
final class Permissoes
{
    public const GRUPO_WEBMASTER = 1;
    public const GRUPO_ASSISTENTE = 2;
    public const GRUPO_USUARIO = 3;

    public const WEBMASTER = 'webmaster';
    public const ASSISTENTE = 'assistente';
    public const USUARIO = 'usuario';

    /** Dá todas as permissões. */
    private const TODAS = '*';

    /** @var array<int, array<string, true>> permissões por grupo, lidas uma vez por requisição */
    private array $cache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function pode(?Usuario $usuario, string $permissao): bool
    {
        if ($usuario === null || !$usuario->ativa()) {
            return false;
        }
        return $this->grupoPode($usuario->grupoId, $permissao);
    }

    /** O grupo tem a permissão (sem olhar a situação da conta)? */
    public function grupoPode(int $grupoId, string $permissao): bool
    {
        $permissoes = $this->permissoes($grupoId);
        return isset($permissoes[self::TODAS]) || isset($permissoes[$permissao]);
    }

    /** @return list<array{id: int|string, slug: string, nome: string}> */
    public function grupos(): array
    {
        return $this->pdo->query('SELECT id, slug, nome FROM captcha_grupo_acesso ORDER BY id')->fetchAll();
    }

    public function nomeDoGrupo(int $grupoId): ?string
    {
        $consulta = $this->pdo->prepare('SELECT nome FROM captcha_grupo_acesso WHERE id = ?');
        $consulta->execute([$grupoId]);
        $nome = $consulta->fetchColumn();
        return $nome === false ? null : (string) $nome;
    }

    /**
     * As contas com estes e-mails (ver Webmasters) vão para o grupo 1 (webmaster). Só contas com e-mail
     * confirmado, ativas ou pendentes (o webmaster não espera aprovação: a conta pendente fica ativa). Devolve quantas contas mudaram.
     *
     * @param list<string> $emails
     */
    public function sincronizarWebmasters(array $emails): int
    {
        $emails = array_values(array_filter(array_map(static fn ($e): string => mb_strtolower(trim((string) $e)), $emails)));
        if ($emails === []) {
            return 0;
        }
        $quem = 'email IN (' . implode(',', array_fill(0, count($emails), '?')) . ')';
        $consulta = $this->pdo->prepare(
            "UPDATE captcha_usuario SET grupo_id = 1, credencial = 'webmaster', situacao = 'ativa'
             WHERE {$quem} AND (grupo_id <> 1 OR situacao = 'pendente') AND email_confirmado_em IS NOT NULL AND situacao IN ('ativa', 'pendente')"
        );
        $consulta->execute($emails);
        return $consulta->rowCount();
    }

    /** Muda o grupo da conta (grupo_id e credencial juntos). */
    public function mudarGrupo(int $usuarioId, string $grupo): void
    {
        $consulta = $this->pdo->prepare('SELECT id FROM captcha_grupo_acesso WHERE slug = ?');
        $consulta->execute([$grupo]);
        $grupoId = $consulta->fetchColumn();
        if ($grupoId === false) {
            throw new InvalidArgumentException("Grupo desconhecido: {$grupo}");
        }
        $this->pdo->prepare('UPDATE captcha_usuario SET grupo_id = ?, credencial = ? WHERE id = ?')->execute([$grupoId, $grupo, $usuarioId]);
    }

    public function esquecerCache(): void
    {
        $this->cache = [];
    }

    /** @return array<string, true> */
    private function permissoes(int $grupoId): array
    {
        if (!isset($this->cache[$grupoId])) {
            $consulta = $this->pdo->prepare(
                'SELECT p.slug FROM captcha_grupo_permissao gp JOIN captcha_permissao p ON p.id = gp.permissao_id WHERE gp.grupo_id = ?'
            );
            $consulta->execute([$grupoId]);
            $this->cache[$grupoId] = array_fill_keys($consulta->fetchAll(PDO::FETCH_COLUMN), true);
        }
        return $this->cache[$grupoId];
    }
}
