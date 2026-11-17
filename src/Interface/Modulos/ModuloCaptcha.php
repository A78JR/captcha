<?php

declare(strict_types=1);

namespace Captcha\Interface\Modulos;

use Captcha\Interface\ContextoMenu;
use Captcha\Interface\ItemMenu;
use Captcha\Interface\Modulo;

/** A demonstração do captcha, aberta a todos. */
final class ModuloCaptcha extends Modulo
{
    public function itens(ContextoMenu $contexto): array
    {
        return [new ItemMenu('Experimentar', '/demo', 'encaixe')];
    }
}
