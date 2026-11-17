<?php

declare(strict_types=1);

namespace Captcha\Interface\Modulos;

use Captcha\Interface\ContextoMenu;
use Captcha\Interface\ItemMenu;
use Captcha\Interface\MenuPainel;
use Captcha\Interface\Modulo;
use Captcha\Painel\PainelWebmaster;

/**
 * Equipe na barra lateral: só o Painel webmaster; as outras telas são abas dele (MenuPainel).
 * Quem é da equipe mas não abre o painel vê na barra as telas que pode usar.
 * Para quem não tem a permissão, nem o nome nem o endereço vão no HTML.
 */
final class ModuloEquipe extends Modulo
{
    public function titulo(ContextoMenu $contexto): ?string
    {
        return 'Equipe';
    }

    public function itens(ContextoMenu $contexto): array
    {
        if ($contexto->pode(PainelWebmaster::PERMISSAO)) {
            [$caminho, $icone] = MenuPainel::ABAS[PainelWebmaster::PERMISSAO];
            return [new ItemMenu('Painel webmaster', $caminho, $icone, tambem: MenuPainel::outrosCaminhos($contexto))];
        }
        return MenuPainel::itens($contexto);
    }
}
