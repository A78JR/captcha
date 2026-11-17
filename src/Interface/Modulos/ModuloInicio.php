<?php

declare(strict_types=1);

namespace Captcha\Interface\Modulos;

use Captcha\Interface\ContextoMenu;
use Captcha\Interface\ItemMenu;
use Captcha\Interface\Modulo;

/** O Início, solto no topo do menu. */
final class ModuloInicio extends Modulo
{
    public function itens(ContextoMenu $contexto): array
    {
        return [new ItemMenu('Início', '/', 'inicio')];
    }
}
