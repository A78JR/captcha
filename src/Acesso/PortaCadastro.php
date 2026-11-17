<?php

declare(strict_types=1);

namespace Captcha\Acesso;

use Captcha\Nucleo\Config;

/**
 * Porta dos cadastros: qualquer pessoa pede uma conta, mas ela nasce pendente e só entra depois que o webmaster
 * aprova (Painel > Usuários, ver AprovacaoCadastro). Já nascem aprovados só os e-mails de Webmasters (o login do
 * Alfredo e a lista 'webmasters' do config.local.php) e quem o webmaster convidou para a equipe (Painel > Equipe).
 * Regra de todos os sistemas do projeto lingoist.
 */
final class PortaCadastro
{
    /** @param \Closure(string): bool $convidado diz se há convite aberto para o e-mail */
    public function __construct(
        private readonly Config $config,
        private readonly \Closure $convidado,
    ) {
    }

    /** O webmaster já aprovou este e-mail (é webmaster ou foi convidado para a equipe)? */
    public function jaAprovado(string $email): bool
    {
        $email = mb_strtolower(trim($email));
        return in_array($email, Webmasters::emails($this->config), true) || ($this->convidado)($email);
    }

    /** Situação com que a conta nova nasce: ativa (já aprovada) ou pendente. */
    public function situacaoInicial(string $email): string
    {
        return $this->jaAprovado($email) ? Usuario::ATIVA : Usuario::PENDENTE;
    }
}
