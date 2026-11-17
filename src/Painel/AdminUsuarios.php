<?php

declare(strict_types=1);

namespace Captcha\Painel;

use Captcha\Acesso\AprovacaoCadastro;
use Captcha\Acesso\Permissoes;
use Captcha\Acesso\ServicoAutenticacao;
use Captcha\Acesso\ServicoSenha;
use Captcha\Acesso\Usuario;
use PDO;

/**
 * Painel webmaster > Usuários (/painel/usuarios), como o Controle de Usuários do burgu e do Bazar de Tudo:
 * contadores por situação, busca, a lista com grupo, duas etapas, situação e último acesso, e as ações da
 * equipe sobre cada conta.
 *
 * Travas (valem também para quem chama o endereço por fora da tela):
 *  - a conta 1 é do webmaster: ninguém a suspende, bane ou troca de grupo;
 *  - conta da equipe (grupos 1 e 2) só recebe ação de quem tem painel.grupos (o webmaster), e a equipe é
 *    montada em Painel > Equipe;
 *  - ninguém age sobre a própria conta, e conta excluída não recebe ação;
 *  - conta pendente (cadastro novo) só recebe Aprovar ou Recusar, e só do webmaster (painel.grupos).
 * Toda ação fica no RegistroPainel, com quem fez e o motivo.
 */
final class AdminUsuarios
{
    public const PERMISSAO = 'painel.usuarios';
    /** Trocar o grupo de uma conta: só o webmaster. */
    public const PERMISSAO_GRUPOS = 'painel.grupos';
    public const POR_PAGINA = 50;
    public const CONTA_RESERVADA = 1;
    /** Grupos que a aba Usuários pode dar (1 e 2, a equipe, são da aba Equipe). */
    public const GRUPOS_LIVRES = [Permissoes::GRUPO_USUARIO];
    public const SITUACOES = ['pendente' => 'aguardando aprovação', 'ativa' => 'ativa', 'suspensa' => 'suspensa', 'banida' => 'banida', 'excluida' => 'excluída'];
    /** Ações da tela (a palavra vai no endereço). */
    public const ACOES = ['aprovar', 'recusar', 'suspender', 'banir', 'reativar', 'grupo', 'senha', 'desconectar', 'confirmacao'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Permissoes $permissoes,
        private readonly ServicoAutenticacao $autenticacao,
        private readonly ServicoSenha $senhas,
        private readonly RegistroPainel $registro,
        private readonly AprovacaoCadastro $aprovacao,
    ) {
    }

    /** @return array<string, int> total, pendente, ativa, suspensa, banida, excluida, sem_email */
    public function contagens(): array
    {
        $linha = $this->pdo->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(situacao = 'pendente'), 0) AS pendente,
                    COALESCE(SUM(situacao = 'ativa'), 0) AS ativa,
                    COALESCE(SUM(situacao = 'suspensa'), 0) AS suspensa,
                    COALESCE(SUM(situacao = 'banida'), 0) AS banida,
                    COALESCE(SUM(situacao = 'excluida'), 0) AS excluida,
                    COALESCE(SUM(situacao = 'ativa' AND email_confirmado_em IS NULL), 0) AS sem_email
             FROM captcha_usuario"
        )->fetch();
        return array_map('intval', $linha);
    }

    /**
     * Uma página da lista, mais nova primeiro.
     *
     * @param array{q?: string, grupo?: string, situacao?: string} $filtros
     * @return array{linhas: list<array<string, mixed>>, total: int, pagina: int, paginas: int}
     */
    public function buscar(array $filtros, int $pagina): array
    {
        $onde = ['1 = 1'];
        $args = [];
        $q = trim((string) ($filtros['q'] ?? ''));
        if ($q !== '') {
            if (ctype_digit($q)) {
                $onde[] = 'u.id = ?';
                $args[] = (int) $q;
            } else {
                $parecido = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
                $onde[] = '(u.nome LIKE ? OR u.email LIKE ?)';
                array_push($args, $parecido, $parecido);
            }
        }
        $grupo = (string) ($filtros['grupo'] ?? '');
        if (ctype_digit($grupo)) {
            $onde[] = 'u.grupo_id = ?';
            $args[] = (int) $grupo;
        }
        $situacao = (string) ($filtros['situacao'] ?? '');
        if (isset(self::SITUACOES[$situacao])) {
            $onde[] = 'u.situacao = ?';
            $args[] = $situacao;
        } elseif ($situacao === 'sem_email') {
            $onde[] = "u.situacao = 'ativa' AND u.email_confirmado_em IS NULL";
        }
        $sqlOnde = implode(' AND ', $onde);

        $contar = $this->pdo->prepare("SELECT COUNT(*) FROM captcha_usuario u WHERE {$sqlOnde}");
        $contar->execute($args);
        $total = (int) $contar->fetchColumn();
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = min(max(1, $pagina), $paginas);

        $consulta = $this->pdo->prepare(
            "SELECT u.id, u.nome, u.email, u.email_confirmado_em, u.duas_etapas, u.situacao, u.grupo_id, u.ultimo_acesso_em, u.criado_em,
                    g.nome AS grupo_nome,
                    (SELECT COUNT(*) FROM captcha_registro_acesso a WHERE a.usuario_id = u.id AND a.criado_em >= UTC_TIMESTAMP() - INTERVAL 90 DAY) AS acessos_90
             FROM captcha_usuario u LEFT JOIN captcha_grupo_acesso g ON g.id = u.grupo_id
             WHERE {$sqlOnde} ORDER BY u.id DESC LIMIT " . self::POR_PAGINA . ' OFFSET ' . (($pagina - 1) * self::POR_PAGINA)
        );
        $consulta->execute($args);
        return ['linhas' => $consulta->fetchAll(), 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    /** Pode quem está na tela fazer esta ação nesta conta? Devolve o motivo da recusa, ou null. */
    public function recusa(Usuario $equipe, Usuario $alvo, string $acao): ?string
    {
        if ($alvo->situacao === Usuario::EXCLUIDA) {
            return 'Conta excluída não recebe ação.';
        }
        if ($alvo->id === $equipe->id && in_array($acao, ['suspender', 'banir', 'grupo', 'desconectar'], true)) {
            return 'Ninguém age sobre a própria conta por aqui.';
        }
        if ($alvo->id === self::CONTA_RESERVADA && in_array($acao, ['suspender', 'banir', 'grupo'], true)) {
            return 'A conta 1 é reservada ao webmaster: não pode ser suspensa, banida nem mudar de grupo.';
        }
        $podeGrupos = $this->permissoes->pode($equipe, self::PERMISSAO_GRUPOS);
        if (in_array($acao, ['aprovar', 'recusar'], true)) {
            if (!$podeGrupos) {
                return 'Só o webmaster aprova ou recusa cadastros.';
            }
            return $alvo->pendente() ? null : 'Esta conta não está aguardando aprovação.';
        }
        if ($alvo->pendente() && !in_array($acao, ['senha', 'confirmacao'], true)) {
            return 'Conta aguardando aprovação: aprove ou recuse primeiro.';
        }
        if ($alvo->daEquipe() && !$podeGrupos) {
            return 'Conta da equipe só recebe ação do webmaster.';
        }
        if ($acao === 'grupo') {
            if (!$podeGrupos) {
                return 'Só o webmaster muda o grupo de uma conta.';
            }
            if ($alvo->daEquipe()) {
                return 'A equipe (webmaster e assistentes) se monta em Painel > Equipe.';
            }
        }
        return null;
    }

    /**
     * Faz a ação. Devolve [true, mensagem] quando deu certo, [false, motivo] quando foi recusada.
     *
     * @return array{0: bool, 1: string}
     */
    public function agir(Usuario $equipe, int $alvoId, string $acao, string $motivo = '', string $grupo = ''): array
    {
        $alvo = $this->autenticacao->porId($alvoId);
        if ($alvo === null) {
            return [false, 'Conta não encontrada.'];
        }
        if (!in_array($acao, self::ACOES, true)) {
            return [false, 'Ação desconhecida.'];
        }
        $recusa = $this->recusa($equipe, $alvo, $acao);
        if ($recusa !== null) {
            return [false, $recusa];
        }
        $motivo = trim(mb_substr($motivo, 0, 500));
        switch ($acao) {
            case 'aprovar':
                // Aprovar já pode pôr a conta num dos grupos da lista (ex.: o grupo que vê o conteúdo do site).
                $grupoId = ctype_digit($grupo) ? (int) $grupo : 0;
                $this->aprovacao->aprovar($alvoId);
                $nota = $motivo;
                if ($grupoId !== $alvo->grupoId && in_array($grupoId, self::GRUPOS_LIVRES, true)) {
                    $slug = $this->pdo->prepare('SELECT slug FROM captcha_grupo_acesso WHERE id = ?');
                    $slug->execute([$grupoId]);
                    $this->permissoes->mudarGrupo($alvoId, (string) $slug->fetchColumn());
                    $this->permissoes->esquecerCache();
                    $nota = 'grupo ' . ($this->permissoes->nomeDoGrupo($grupoId) ?? (string) $grupoId) . ($motivo !== '' ? ' · ' . $motivo : '');
                }
                $this->registro->registrar($alvoId, 'aprovar', $equipe->id, $nota);
                return [true, 'Conta aprovada. A pessoa recebeu um e-mail avisando.'];
            case 'recusar':
                $this->aprovacao->recusar($alvoId);
                $this->registro->registrar($alvoId, 'recusar', $equipe->id, $motivo);
                return [true, 'Cadastro recusado: a conta foi excluída.'];
            case 'suspender':
            case 'banir':
                if (mb_strlen($motivo) < 5) {
                    return [false, 'Escreva o motivo (pelo menos 5 letras).'];
                }
                $nova = $acao === 'banir' ? Usuario::BANIDA : Usuario::SUSPENSA;
                if ($alvo->situacao === $nova) {
                    return [false, 'A conta já está ' . self::SITUACOES[$nova] . '.'];
                }
                $this->pdo->prepare('UPDATE captcha_usuario SET situacao = ?, versao_sessao = versao_sessao + 1 WHERE id = ?')->execute([$nova, $alvoId]);
                $this->registro->registrar($alvoId, $acao, $equipe->id, $motivo);
                return [true, $acao === 'banir' ? 'Conta banida e desconectada de todos os aparelhos.' : 'Conta suspensa e desconectada de todos os aparelhos.'];
            case 'reativar':
                if ($alvo->ativa()) {
                    return [false, 'A conta já está ativa.'];
                }
                if (mb_strlen($motivo) < 5) {
                    return [false, 'Escreva o motivo (pelo menos 5 letras).'];
                }
                $this->pdo->prepare("UPDATE captcha_usuario SET situacao = 'ativa' WHERE id = ?")->execute([$alvoId]);
                $this->registro->registrar($alvoId, 'reativar', $equipe->id, $motivo);
                return [true, 'Conta reativada.'];
            case 'grupo':
                $grupoId = ctype_digit($grupo) ? (int) $grupo : 0;
                if (!in_array($grupoId, self::GRUPOS_LIVRES, true)) {
                    return [false, 'Escolha um dos grupos da lista.'];
                }
                if ($grupoId === $alvo->grupoId) {
                    return [false, 'A conta já está neste grupo.'];
                }
                $slug = $this->pdo->prepare('SELECT slug FROM captcha_grupo_acesso WHERE id = ?');
                $slug->execute([$grupoId]);
                $antes = $this->permissoes->nomeDoGrupo($alvo->grupoId) ?? (string) $alvo->grupoId;
                $this->permissoes->mudarGrupo($alvoId, (string) $slug->fetchColumn());
                $this->permissoes->esquecerCache();
                $this->registro->registrar($alvoId, 'grupo', $equipe->id, $antes . ' -> ' . ($this->permissoes->nomeDoGrupo($grupoId) ?? (string) $grupoId) . ($motivo !== '' ? ' · ' . $motivo : ''));
                return [true, 'Grupo trocado.'];
            case 'senha':
                if ($alvo->situacao === Usuario::BANIDA) {
                    return [false, 'Conta banida não recebe link de senha.'];
                }
                $this->senhas->enviarLink($alvoId, $alvo->email);
                $this->registro->registrar($alvoId, 'senha', $equipe->id, $motivo);
                return [true, 'Link para criar uma senha nova enviado ao e-mail da conta.'];
            case 'desconectar':
                $this->senhas->desconectarTudo($alvoId);
                $this->registro->registrar($alvoId, 'desconectar', $equipe->id, $motivo);
                return [true, 'A conta foi desconectada de todos os aparelhos.'];
            default: // confirmacao
                if ($alvo->emailConfirmado()) {
                    return [false, 'O e-mail desta conta já está confirmado.'];
                }
                $this->autenticacao->enviarConfirmacao($alvoId, $alvo->email);
                $this->registro->registrar($alvoId, 'confirmacao', $equipe->id);
                return [true, 'Link de confirmação reenviado.'];
        }
    }
}
