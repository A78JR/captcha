<?php

declare(strict_types=1);

namespace Captcha\Acesso;

/**
 * Senha certa e e-mail confirmado, mas a conta ainda não foi aprovada pelo webmaster (AprovacaoCadastro).
 * Quem chama mostra o aviso de espera em vez do erro de senha.
 */
final class ContaPendente extends \RuntimeException
{
    public const MENSAGEM = 'Sua conta está aguardando a aprovação do webmaster. Você recebe um e-mail assim que ela for aprovada.';

    public function __construct()
    {
        parent::__construct(self::MENSAGEM);
    }
}
