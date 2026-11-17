<?php

declare(strict_types=1);

namespace Captcha\Interface\Modulos;

use Captcha\Interface\ContextoMenu;
use Captcha\Interface\ItemMenu;
use Captcha\Interface\Modulo;

/** Minha conta ou, para visitantes, Entrar e Criar conta. */
final class ModuloConta extends Modulo
{
    public function itens(ContextoMenu $contexto): array
    {
        if (!$contexto->logado()) {
            return [
                new ItemMenu('Entrar', '/entrar', 'entrar'),
                new ItemMenu('Criar conta', '/cadastro', 'cadastro'),
            ];
        }
        return [new ItemMenu('Minha conta', '/conta', 'conta')];
    }
}
