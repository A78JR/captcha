<?php

declare(strict_types=1);

namespace Captcha\Acesso;

use Closure;
use Captcha\Nucleo\Carteiro;
use Captcha\Nucleo\Requisicao;
use Captcha\Nucleo\Sessao;
use Captcha\Seguranca\LimiteTaxa;
use Captcha\Seguranca\PoliticaSenha;
use PDO;

/**
 * Cadastro, conferência de senha, sessão da conta e confirmação de e-mail.
 *
 * Regras que vieram do Bazar de Tudo:
 *  - mesmo tempo de resposta para e-mail que existe ou não (hash falso quando a conta não existe);
 *  - cadastro com e-mail que já tem conta responde igual a um cadastro novo, e o dono recebe um aviso: ninguém
 *    descobre pelo cadastro quem tem conta;
 *  - a senha não basta enquanto o e-mail não for confirmado (o link vai de novo, no máximo 3 por hora);
 *  - trocar a senha sobe usuario.versao_sessao e derruba as sessões abertas em outros aparelhos;
 *  - muito tempo sem clicar (Inatividade) sai da conta.
 */
final class ServicoAutenticacao
{
    public const VALIDADE_CONFIRMACAO = 86400;
    /** Hash de uma senha aleatória descartada, usado quando o e-mail não existe. */
    private const HASH_FALSO = '$argon2id$v=19$m=65536,t=4,p=1$V2Mwd1NqZnRIblE5eUl3OA$/2f/MxnW/B41f/LZBoM6Q/Rk/DOejIGuk135iW63Qto';

    private ?Usuario $cache = null;

    /**
     * @param Closure(int): void $aoConfirmarEmail o que mais acontece quando uma conta confirma o e-mail
     *                                            (entrar na equipe por convite, virar webmaster)
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Sessao $sessao,
        private readonly Carteiro $carteiro,
        private readonly LimiteTaxa $limite,
        private readonly RegistroAcessos $acessos,
        private readonly PortaCadastro $porta,
        private readonly string $urlBase,
        private readonly Closure $aoConfirmarEmail,
    ) {
    }

    /**
     * Valida e cria a conta (sem abrir sessão: a conta só entra pelo link do e-mail e, se nasceu pendente,
     * depois que o webmaster aprovar).
     *
     * @param array{nome?: string, email?: string, senha?: string, senha_confirmacao?: string} $dados
     * @return array<string, string>|int erros por campo, ou o número da conta criada (0: o e-mail já tinha conta)
     */
    public function cadastrar(array $dados): array|int
    {
        $nome = trim((string) ($dados['nome'] ?? ''));
        $email = mb_strtolower(trim((string) ($dados['email'] ?? '')));
        $senha = (string) ($dados['senha'] ?? '');
        $erros = [];
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 120 || !preg_match("/^[\\p{L} '.-]+$/u", $nome)) {
            $erros['nome'] = 'Informe seu nome.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $erros['email'] = 'E-mail inválido.';
        }
        if (($problema = PoliticaSenha::problema($senha)) !== null) {
            $erros['senha'] = $problema;
        } elseif (isset($dados['senha_confirmacao']) && $dados['senha_confirmacao'] !== $senha) {
            $erros['senha_confirmacao'] = 'As senhas não conferem.';
        }
        if ($erros !== []) {
            return $erros;
        }
        $existe = $this->pdo->prepare('SELECT 1 FROM captcha_usuario WHERE email = ?');
        $existe->execute([$email]);
        if ($existe->fetchColumn() !== false) {
            $this->avisarContaExistente($email);
            return 0;
        }
        $this->pdo->prepare(
            "INSERT INTO captcha_usuario (nome, email, senha_hash, senha_definida_em, situacao, grupo_id, credencial)
             VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?)"
        )->execute([$nome, $email, PoliticaSenha::hash($senha), $this->porta->situacaoInicial($email), Permissoes::GRUPO_USUARIO, Permissoes::USUARIO]);
        $id = (int) $this->pdo->lastInsertId();
        $this->enviarConfirmacao($id, $email);
        return $id;
    }

    /** Alguém tentou criar conta com um e-mail que já tem: avisa o dono (no máximo 2 vezes por hora). */
    private function avisarContaExistente(string $email): void
    {
        if (!$this->limite->contar('cadastro-repetido:' . hash('sha256', $email), 2, 3600)) {
            return;
        }
        $base = rtrim($this->urlBase, '/');
        $this->carteiro->enviar($email, 'Tentaram criar uma conta com o seu e-mail na Captcha',
            "Olá!\n\nAlguém tentou criar uma conta nova com este e-mail, mas você já tem uma. Nada foi mudado na sua conta.\n\n"
            . "Se foi você, entre por aqui:\n{$base}/entrar\n\nEsqueceu a senha? Crie uma nova por aqui:\n{$base}/esqueci-senha\n\n"
            . 'Se não foi você, pode ignorar esta mensagem.');
    }

    /**
     * Confere e-mail e senha sem abrir sessão. Devolve o número da conta, ou null.
     * Quem chama decide se ainda falta a segunda etapa (EntradaDuasEtapas) antes de abrir a sessão.
     *
     * @throws ContaPendente senha certa e e-mail confirmado, mas o webmaster ainda não aprovou a conta
     */
    public function verificarCredenciais(string $email, string $senha, string $ip): ?int
    {
        $email = mb_strtolower(trim($email));
        $consulta = $this->pdo->prepare('SELECT id, senha_hash, situacao, email_confirmado_em FROM captcha_usuario WHERE email = ?');
        $consulta->execute([$email]);
        $linha = $consulta->fetch();

        // Mesmo custo de hash com ou sem conta, para não revelar quais e-mails existem.
        $hash = is_array($linha) && $linha['senha_hash'] !== null ? (string) $linha['senha_hash'] : self::HASH_FALSO;
        $certa = password_verify($senha, $hash) && is_array($linha) && !in_array($linha['situacao'], [Usuario::BANIDA, Usuario::EXCLUIDA], true);

        $this->pdo->prepare('INSERT INTO captcha_tentativa_entrada (email, ip, sucesso) VALUES (?, ?, ?)')
            ->execute([mb_substr($email, 0, 190), @inet_pton($ip) ?: '', (int) $certa]);
        if (!$certa) {
            return null;
        }
        if (PoliticaSenha::precisaRefazer($hash)) {
            $this->pdo->prepare('UPDATE captcha_usuario SET senha_hash = ? WHERE id = ?')->execute([PoliticaSenha::hash($senha), $linha['id']]);
        }
        // E-mail ainda não confirmado: a senha não basta (a mesma resposta da senha errada). O link vai de novo.
        if ($linha['email_confirmado_em'] === null) {
            if ($this->limite->contar('confirmar-de-novo:' . (int) $linha['id'], 3, 3600)) {
                $this->enviarConfirmacao((int) $linha['id'], $email);
            }
            return null;
        }
        if ($linha['situacao'] === Usuario::PENDENTE) {
            throw new ContaPendente();
        }
        return (int) $linha['id'];
    }

    /** Confere a senha de quem já está logado (ex.: antes de ligar ou desligar as duas etapas). */
    public function senhaConfere(Usuario $usuario, string $senha): bool
    {
        return $senha !== '' && $usuario->senhaHash !== null && password_verify($senha, $usuario->senhaHash);
    }

    /** Abre a sessão da conta. */
    public function entrarPorId(int $usuarioId, Requisicao $requisicao): void
    {
        $consulta = $this->pdo->prepare('SELECT versao_sessao FROM captcha_usuario WHERE id = ?');
        $consulta->execute([$usuarioId]);
        $this->sessao->regenerar();
        $this->sessao->definir('usuario_id', $usuarioId);
        // Número da senha atual: quando a senha muda, as sessões com o número antigo saem (ver usuario()).
        $this->sessao->definir('versao_sessao', (int) $consulta->fetchColumn());
        $this->sessao->definir('ultimo_clique', time());
        $this->sessao->definir('entrou_em', time());
        $this->cache = null;
        $this->acessos->registrar($usuarioId, 'entrada', $requisicao->ip, $requisicao->cabecalho('user-agent'));
    }

    public function sair(): void
    {
        $this->sessao->destruir();
        $this->cache = null;
    }

    /** A conta da sessão, ou null (visitante, senha trocada em outro aparelho, tempo sem clicar estourado). */
    public function usuario(): ?Usuario
    {
        $id = $this->sessao->obter('usuario_id');
        if (!is_int($id)) {
            return null;
        }
        if ($this->cache !== null && $this->cache->id === $id) {
            return $this->cache;
        }
        $usuario = $this->encontrar($id);
        if ($usuario !== null && $usuario->versaoSessao !== (int) $this->sessao->obter('versao_sessao', 0)) {
            $usuario = null;
        }
        $ultimoClique = $this->sessao->obter('ultimo_clique');
        if ($usuario !== null && Inatividade::venceu($usuario, is_int($ultimoClique) ? $ultimoClique : null, time())) {
            $usuario = null;
        }
        if ($usuario === null) {
            $this->sair();
            return null;
        }
        // Cada clique recomeça o prazo.
        $this->sessao->definir('ultimo_clique', time());
        $this->sessao->manterPor(Inatividade::segundosPara($usuario));
        return $this->cache = $usuario;
    }

    /** Registro de acessos: no máximo um por hora para o mesmo IP, sem consultar o banco a cada clique. */
    public function marcarAcesso(Usuario $usuario, Requisicao $requisicao): void
    {
        $marca = $requisicao->ip . '|' . intdiv(time(), 3600);
        if ($this->sessao->obter('acesso_registrado') !== $marca) {
            $this->sessao->definir('acesso_registrado', $marca);
            $this->acessos->registrar($usuario->id, 'acesso', $requisicao->ip, $requisicao->cabecalho('user-agent'));
        }
    }

    /** Conta que pode usar o site (não banida, excluída nem pendente), ou null. */
    public function encontrar(int $id): ?Usuario
    {
        $usuario = $this->porId($id);
        return $usuario === null || in_array($usuario->situacao, [Usuario::BANIDA, Usuario::EXCLUIDA, Usuario::PENDENTE], true) ? null : $usuario;
    }

    /** Conta em qualquer situação (para o Painel), ou null. */
    public function porId(int $id): ?Usuario
    {
        $consulta = $this->pdo->prepare('SELECT * FROM captcha_usuario WHERE id = ?');
        $consulta->execute([$id]);
        $linha = $consulta->fetch();
        return $linha === false ? null : Usuario::daLinha($linha);
    }

    public function esquecerCache(): void
    {
        $this->cache = null;
    }

    public function enviarConfirmacao(int $usuarioId, string $email): void
    {
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare(
            "INSERT INTO captcha_token_verificacao (usuario_id, finalidade, token_hash, vence_em)
             VALUES (?, 'email', ?, UTC_TIMESTAMP() + INTERVAL ? SECOND)"
        )->execute([$usuarioId, hash('sha256', $token), self::VALIDADE_CONFIRMACAO]);
        $link = rtrim($this->urlBase, '/') . '/verificar-email?token=' . $token;
        $this->carteiro->enviar($email, 'Confirme seu e-mail na Captcha',
            "Olá!\n\nPara ativar sua conta, confirme seu e-mail pelo link abaixo (válido por 24 horas):\n\n{$link}\n\n"
            . 'Se você não criou uma conta, ignore esta mensagem.');
    }

    /** Confirma o e-mail pelo link. Devolve o número da conta, ou null se o link não vale. */
    public function confirmarEmail(string $token): ?int
    {
        if (!ctype_xdigit($token) || strlen($token) !== 64) {
            return null;
        }
        $consulta = $this->pdo->prepare(
            "SELECT id, usuario_id FROM captcha_token_verificacao
             WHERE token_hash = ? AND finalidade = 'email' AND usado_em IS NULL AND vence_em > UTC_TIMESTAMP()"
        );
        $consulta->execute([hash('sha256', $token)]);
        $linha = $consulta->fetch();
        if ($linha === false) {
            return null;
        }
        $usar = $this->pdo->prepare('UPDATE captcha_token_verificacao SET usado_em = UTC_TIMESTAMP() WHERE id = ? AND usado_em IS NULL');
        $usar->execute([$linha['id']]);
        if ($usar->rowCount() === 0) {
            return null; // dois toques ao mesmo tempo: só um vale
        }
        $usuarioId = (int) $linha['usuario_id'];
        $this->pdo->prepare('UPDATE captcha_usuario SET email_confirmado_em = UTC_TIMESTAMP() WHERE id = ? AND email_confirmado_em IS NULL')->execute([$usuarioId]);
        ($this->aoConfirmarEmail)($usuarioId);
        $this->cache = null;
        return $usuarioId;
    }
}
