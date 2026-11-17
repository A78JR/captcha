<?php

declare(strict_types=1);

namespace Captcha\Painel;

use Captcha\Acesso\Permissoes;
use Captcha\Nucleo\Carteiro;
use PDO;

/**
 * Painel webmaster > Equipe: o webmaster chama assistentes (grupo 2) pelo e-mail, sem mexer no banco nem no
 * config. Conta que já existe e já confirmou o e-mail entra na hora; quem ainda não tem conta recebe um
 * convite, pode se cadastrar mesmo com os cadastros fechados (PortaCadastro) e vira assistente ao confirmar
 * o e-mail. A tabela convite_equipe guarda quem chamou quem, quando entrou e quando saiu.
 */
final class Equipe
{
    public const PERMISSAO = 'painel.equipe';
    public const VALIDADE_DIAS = 14;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Permissoes $permissoes,
        private readonly RegistroPainel $registro,
        private readonly Carteiro $carteiro,
        private readonly string $urlBase,
    ) {
    }

    /** @return list<array<string, mixed>> webmasters e assistentes, com o que cada um fez no painel em 1 e 7 dias */
    public function membros(): array
    {
        return $this->pdo->query(
            "SELECT u.id, u.nome, u.email, u.grupo_id, u.situacao, u.ultimo_acesso_em,
                    (SELECT COUNT(*) FROM captcha_registro_painel r WHERE r.autor_id = u.id AND r.criado_em >= UTC_TIMESTAMP() - INTERVAL 1 DAY) AS acoes_dia,
                    (SELECT COUNT(*) FROM captcha_registro_painel r WHERE r.autor_id = u.id AND r.criado_em >= UTC_TIMESTAMP() - INTERVAL 7 DAY) AS acoes_semana
             FROM captcha_usuario u WHERE u.grupo_id IN (1, 2) AND u.situacao <> 'excluida'
             ORDER BY u.grupo_id, u.nome"
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> convites ainda abertos, mais novo primeiro */
    public function convitesAbertos(): array
    {
        return $this->pdo->query(
            'SELECT c.*, u.nome AS convidado_por_nome FROM captcha_convite_equipe c LEFT JOIN captcha_usuario u ON u.id = c.convidado_por
             WHERE c.aceito_em IS NULL AND c.revogado_em IS NULL AND c.criado_em > UTC_TIMESTAMP() - INTERVAL ' . self::VALIDADE_DIAS . ' DAY
             ORDER BY c.id DESC'
        )->fetchAll();
    }

    /** @return string 'entrou' (já tinha conta: virou assistente), 'convidado' (convite enviado), ou a mensagem de erro */
    public function convidar(string $email, int $por): string
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            return 'Digite um e-mail válido.';
        }
        $consulta = $this->pdo->prepare('SELECT id, grupo_id, situacao, email_confirmado_em FROM captcha_usuario WHERE email = ?');
        $consulta->execute([$email]);
        $conta = $consulta->fetch();
        if ($conta !== false && (int) $conta['grupo_id'] === Permissoes::GRUPO_WEBMASTER) {
            return 'Esta conta já é webmaster.';
        }
        if ($conta !== false && (int) $conta['grupo_id'] === Permissoes::GRUPO_ASSISTENTE) {
            return 'Esta conta já está na equipe.';
        }
        if ($conta !== false && $conta['situacao'] !== 'ativa') {
            return 'Esta conta está suspensa, banida ou foi excluída: não pode entrar na equipe.';
        }
        $this->pdo->prepare('UPDATE captcha_convite_equipe SET revogado_em = UTC_TIMESTAMP() WHERE email = ? AND aceito_em IS NULL AND revogado_em IS NULL')->execute([$email]);
        $this->pdo->prepare('INSERT INTO captcha_convite_equipe (email, convidado_por) VALUES (?, ?)')->execute([$email, $por]);
        if ($conta !== false && $conta['email_confirmado_em'] !== null) {
            $this->aceitar((int) $conta['id'], $email, $por);
            return 'entrou';
        }
        $this->enviarConvite($email, $conta === false);
        return 'convidado';
    }

    /** Tira a pessoa da equipe: volta a ser conta comum. O webmaster não sai por aqui. */
    public function remover(int $usuarioId, int $por): bool
    {
        $consulta = $this->pdo->prepare('SELECT id FROM captcha_usuario WHERE id = ? AND grupo_id = ?');
        $consulta->execute([$usuarioId, Permissoes::GRUPO_ASSISTENTE]);
        if ($consulta->fetchColumn() === false) {
            return false;
        }
        $this->permissoes->mudarGrupo($usuarioId, Permissoes::USUARIO);
        $this->permissoes->esquecerCache();
        $this->pdo->prepare('UPDATE captcha_convite_equipe SET removido_em = UTC_TIMESTAMP(), removido_por = ? WHERE usuario_aceito_id = ? AND removido_em IS NULL')
            ->execute([$por, $usuarioId]);
        $this->registro->registrar($usuarioId, 'equipe_saiu', $por);
        return true;
    }

    public function revogar(int $conviteId): bool
    {
        $consulta = $this->pdo->prepare('UPDATE captcha_convite_equipe SET revogado_em = UTC_TIMESTAMP() WHERE id = ? AND aceito_em IS NULL AND revogado_em IS NULL');
        $consulta->execute([$conviteId]);
        return $consulta->rowCount() > 0;
    }

    /** Há convite aberto para este e-mail? (deixa a pessoa se cadastrar com os cadastros fechados) */
    public function convidado(string $email): bool
    {
        $consulta = $this->pdo->prepare(
            'SELECT 1 FROM captcha_convite_equipe WHERE email = ? AND aceito_em IS NULL AND revogado_em IS NULL
               AND criado_em > UTC_TIMESTAMP() - INTERVAL ' . self::VALIDADE_DIAS . ' DAY LIMIT 1'
        );
        $consulta->execute([mb_strtolower(trim($email))]);
        return $consulta->fetchColumn() !== false;
    }

    /** A conta acabou de confirmar o e-mail: se havia convite aberto, vira assistente agora. */
    public function aceitarPendente(int $usuarioId): bool
    {
        $consulta = $this->pdo->prepare("SELECT email FROM captcha_usuario WHERE id = ? AND situacao = 'ativa' AND grupo_id NOT IN (1, 2) AND email_confirmado_em IS NOT NULL");
        $consulta->execute([$usuarioId]);
        $email = $consulta->fetchColumn();
        if ($email === false || !$this->convidado((string) $email)) {
            return false;
        }
        $this->aceitar($usuarioId, (string) $email, null);
        return true;
    }

    private function aceitar(int $usuarioId, string $email, ?int $por): void
    {
        $this->permissoes->mudarGrupo($usuarioId, Permissoes::ASSISTENTE);
        $this->permissoes->esquecerCache();
        $this->pdo->prepare('UPDATE captcha_convite_equipe SET aceito_em = UTC_TIMESTAMP(), usuario_aceito_id = ? WHERE email = ? AND aceito_em IS NULL AND revogado_em IS NULL')
            ->execute([$usuarioId, $email]);
        $this->registro->registrar($usuarioId, 'equipe_entrou', $por);
    }

    private function enviarConvite(string $email, bool $semConta): void
    {
        $base = rtrim($this->urlBase, '/');
        $link = $semConta ? $base . '/cadastro?email=' . rawurlencode($email) : $base . '/entrar';
        $this->carteiro->enviar($email, 'Convite para a equipe do Captcha',
            "Olá!\n\nVocê foi chamado para ajudar a cuidar do Captcha.\n\n"
            . ($semConta
                ? "Crie sua conta com este mesmo e-mail pelo link abaixo e confirme o e-mail: você entra na equipe na hora.\n\n"
                : "Confirme o e-mail da sua conta (o link chegou quando você se cadastrou; ele vai de novo quando você tenta entrar) e você entra na equipe.\n\n")
            . "{$link}\n\nO convite vale " . self::VALIDADE_DIAS . ' dias. Se você não esperava este e-mail, ignore esta mensagem.');
    }
}
