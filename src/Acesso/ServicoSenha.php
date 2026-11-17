<?php

declare(strict_types=1);

namespace Captcha\Acesso;

use Captcha\Nucleo\Carteiro;
use Captcha\Seguranca\PoliticaSenha;
use PDO;

/**
 * Troca de senha (em Minha conta) e "Esqueci minha senha" (link por e-mail, 1 hora, uma vez só).
 * Toda troca grava a senha nova, desconecta os outros aparelhos (sobe usuario.versao_sessao, que
 * ServicoAutenticacao::usuario() confere) e avisa por e-mail.
 */
final class ServicoSenha
{
    public const VALIDADE_LINK = 3600;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Carteiro $carteiro,
        private readonly string $urlBase,
    ) {
    }

    /** @return array<string, string> erros por campo (vazio = trocou) */
    public function trocar(Usuario $usuario, string $atual, string $nova): array
    {
        if ($usuario->senhaHash === null || !password_verify($atual, $usuario->senhaHash)) {
            return ['senha_atual' => 'A senha atual não confere.'];
        }
        if (($problema = PoliticaSenha::problema($nova)) !== null) {
            return ['senha' => $problema];
        }
        if (hash_equals($atual, $nova)) {
            return ['senha' => 'A senha nova precisa ser diferente da atual.'];
        }
        $this->gravar($usuario->id, $nova);
        $this->avisarTroca($usuario->email);
        return [];
    }

    /** Manda o link para criar uma senha nova. A tela responde sempre igual: ninguém descobre quem tem conta. */
    public function pedirLink(string $email): void
    {
        $consulta = $this->pdo->prepare("SELECT id, email FROM captcha_usuario WHERE email = ? AND situacao NOT IN ('banida', 'excluida')");
        $consulta->execute([mb_strtolower(trim($email))]);
        $linha = $consulta->fetch();
        if ($linha !== false) {
            $this->enviarLink((int) $linha['id'], (string) $linha['email']);
        }
    }

    /** O mesmo link, mandado pela equipe no Painel > Usuários. */
    public function enviarLink(int $usuarioId, string $email): void
    {
        $token = $this->novoToken($usuarioId);
        $link = rtrim($this->urlBase, '/') . '/nova-senha?token=' . $token;
        $this->carteiro->enviar($email, 'Crie uma senha nova na Captcha',
            "Olá!\n\nPara criar uma senha nova, abra o link abaixo (válido por 1 hora):\n\n{$link}\n\n"
            . 'Se não foi você que pediu, ignore esta mensagem. Sua senha continua a mesma.');
    }

    /** O link ainda vale? Devolve o número da conta, ou null. */
    public function contaDoLink(string $token): ?int
    {
        if (!ctype_xdigit($token) || strlen($token) !== 64) {
            return null;
        }
        $consulta = $this->pdo->prepare(
            "SELECT t.usuario_id FROM captcha_token_verificacao t JOIN captcha_usuario u ON u.id = t.usuario_id
             WHERE t.token_hash = ? AND t.finalidade = 'senha' AND t.usado_em IS NULL
               AND t.vence_em > UTC_TIMESTAMP() AND u.situacao NOT IN ('banida', 'excluida')"
        );
        $consulta->execute([hash('sha256', $token)]);
        $id = $consulta->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return array<string, string>|int erros por campo, ou o número da conta */
    public function redefinir(string $token, string $nova): array|int
    {
        $usuarioId = $this->contaDoLink($token);
        if ($usuarioId === null) {
            return ['formulario' => 'Este link expirou ou já foi usado. Peça um novo.'];
        }
        if (($problema = PoliticaSenha::problema($nova)) !== null) {
            return ['senha' => $problema];
        }
        // Marca o link como usado antes de trocar: dois envios ao mesmo tempo não passam os dois.
        $usar = $this->pdo->prepare('UPDATE captcha_token_verificacao SET usado_em = UTC_TIMESTAMP() WHERE token_hash = ? AND usado_em IS NULL');
        $usar->execute([hash('sha256', $token)]);
        if ($usar->rowCount() === 0) {
            return ['formulario' => 'Este link expirou ou já foi usado. Peça um novo.'];
        }
        $this->gravar($usuarioId, $nova);
        // O link chegou pelo e-mail: ele fica confirmado.
        $this->pdo->prepare('UPDATE captcha_usuario SET email_confirmado_em = UTC_TIMESTAMP() WHERE id = ? AND email_confirmado_em IS NULL')->execute([$usuarioId]);
        $email = $this->pdo->prepare('SELECT email FROM captcha_usuario WHERE id = ?');
        $email->execute([$usuarioId]);
        $this->avisarTroca((string) $email->fetchColumn());
        return $usuarioId;
    }

    /** Desconecta todos os aparelhos da conta (Painel > Usuários), sem mudar a senha. */
    public function desconectarTudo(int $usuarioId): void
    {
        $this->pdo->prepare('UPDATE captcha_usuario SET versao_sessao = versao_sessao + 1 WHERE id = ?')->execute([$usuarioId]);
    }

    private function novoToken(int $usuarioId): string
    {
        $this->pdo->prepare("UPDATE captcha_token_verificacao SET usado_em = UTC_TIMESTAMP() WHERE usuario_id = ? AND finalidade = 'senha' AND usado_em IS NULL")
            ->execute([$usuarioId]);
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare(
            "INSERT INTO captcha_token_verificacao (usuario_id, finalidade, token_hash, vence_em)
             VALUES (?, 'senha', ?, UTC_TIMESTAMP() + INTERVAL ? SECOND)"
        )->execute([$usuarioId, hash('sha256', $token), self::VALIDADE_LINK]);
        return $token;
    }

    private function gravar(int $usuarioId, string $nova): void
    {
        $this->pdo->prepare('UPDATE captcha_usuario SET senha_hash = ?, senha_definida_em = UTC_TIMESTAMP(), versao_sessao = versao_sessao + 1 WHERE id = ?')
            ->execute([PoliticaSenha::hash($nova), $usuarioId]);
        $this->pdo->prepare("UPDATE captcha_token_verificacao SET usado_em = UTC_TIMESTAMP() WHERE usuario_id = ? AND finalidade = 'senha' AND usado_em IS NULL")
            ->execute([$usuarioId]);
    }

    private function avisarTroca(string $email): void
    {
        if ($email === '') {
            return;
        }
        $this->carteiro->enviar($email, 'Sua senha do Captcha foi trocada',
            "Olá!\n\nA senha da sua conta acabou de ser trocada e os outros aparelhos foram desconectados.\n\n"
            . 'Se não foi você, use "Esqueci minha senha" na tela de entrar para criar outra agora mesmo: '
            . rtrim($this->urlBase, '/') . '/esqueci-senha');
    }
}
