<?php

declare(strict_types=1);

namespace Captcha\Acesso;

use Captcha\Nucleo\Carteiro;
use Captcha\Nucleo\Marca;
use PDO;

/**
 * Aprovação das contas novas pelo webmaster. A conta pedida em /cadastro nasce pendente (PortaCadastro); quando
 * a pessoa confirma o e-mail, os webmasters recebem um aviso; o webmaster aprova (a conta fica ativa e a pessoa
 * recebe um e-mail) ou recusa (a conta é excluída) em Painel > Usuários.
 */
final class AprovacaoCadastro
{
    /** @param list<string> $webmasters e-mails que recebem o aviso de cadastro novo */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Carteiro $carteiro,
        private readonly string $urlBase,
        private readonly array $webmasters,
    ) {
    }

    public function quantasPendentes(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM captcha_usuario WHERE situacao = 'pendente' AND email_confirmado_em IS NOT NULL")->fetchColumn();
    }

    /** A conta confirmou o e-mail: se está pendente, avisa os webmasters que há um cadastro para aprovar. */
    public function avisarWebmasters(int $usuarioId): void
    {
        $consulta = $this->pdo->prepare("SELECT nome, email FROM captcha_usuario WHERE id = ? AND situacao = 'pendente'");
        $consulta->execute([$usuarioId]);
        $conta = $consulta->fetch();
        if ($conta === false) {
            return;
        }
        $link = rtrim($this->urlBase, '/') . '/painel/usuarios?situacao=pendente#conta-' . $usuarioId;
        foreach ($this->webmasters as $email) {
            $this->carteiro->enviar($email, 'Cadastro novo aguardando aprovação no ' . Marca::NOME,
                "Olá!\n\n{$conta['nome']} ({$conta['email']}) criou uma conta no " . Marca::NOME . " e confirmou o e-mail.\n\n"
                . "A conta só entra depois que você aprovar. Aprove ou recuse por aqui:\n{$link}\n");
        }
    }

    /** Aprova a conta pendente e avisa a pessoa. Devolve false se a conta não estava pendente. */
    public function aprovar(int $usuarioId): bool
    {
        $mudar = $this->pdo->prepare("UPDATE captcha_usuario SET situacao = 'ativa' WHERE id = ? AND situacao = 'pendente'");
        $mudar->execute([$usuarioId]);
        if ($mudar->rowCount() === 0) {
            return false;
        }
        $consulta = $this->pdo->prepare('SELECT email FROM captcha_usuario WHERE id = ?');
        $consulta->execute([$usuarioId]);
        $base = rtrim($this->urlBase, '/');
        $this->carteiro->enviar((string) $consulta->fetchColumn(), 'Sua conta no ' . Marca::NOME . ' foi aprovada',
            "Olá!\n\nO webmaster aprovou a sua conta. Entre por aqui:\n{$base}/entrar\n");
        return true;
    }

    /** Recusa a conta pendente (fica excluída). Devolve false se a conta não estava pendente. */
    public function recusar(int $usuarioId): bool
    {
        $mudar = $this->pdo->prepare("UPDATE captcha_usuario SET situacao = 'excluida', versao_sessao = versao_sessao + 1 WHERE id = ? AND situacao = 'pendente'");
        $mudar->execute([$usuarioId]);
        return $mudar->rowCount() > 0;
    }
}
