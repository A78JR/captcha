<?php

declare(strict_types=1);

namespace Captcha\Interface;

use Captcha\Painel\AdminUsuarios;
use Captcha\Painel\Equipe;
use Captcha\Painel\EstatisticasControlador;
use Captcha\Painel\PainelWebmaster;

/**
 * Abas do Painel webmaster: Visão geral, Sistema, Equipe, Usuários e Captcha. Cada aba só aparece para quem tem a
 * permissão; para os outros, nem o nome nem o endereço vão no HTML. Visão geral primeiro e as outras em
 * ordem alfabética (como no Bazar de Tudo), então ABAS pode ficar na ordem em que as telas foram criadas.
 */
final class MenuPainel
{
    /** permissão => [endereço, ícone, nome]; a Visão geral abre só no endereço exato */
    public const ABAS = [
        PainelWebmaster::PERMISSAO => ['/painel', 'painel', 'Visão geral'],
        'painel.sistema' => ['/painel/sistema', 'sistema', 'Sistema'],
        Equipe::PERMISSAO => ['/painel/equipe', 'equipe', 'Equipe'],
        AdminUsuarios::PERMISSAO => ['/painel/usuarios', 'usuarios', 'Usuários'],
        EstatisticasControlador::PERMISSAO => ['/painel/captcha', 'encaixe', 'Captcha'],
    ];

    /** @return list<ItemMenu> */
    public static function itens(ContextoMenu $contexto): array
    {
        $itens = [];
        foreach (self::ABAS as $permissao => [$caminho, $icone, $rotulo]) {
            if ($contexto->pode($permissao)) {
                $itens[] = new ItemMenu($rotulo, $caminho, $icone);
            }
        }
        return self::ordenar($itens);
    }

    /** Endereços das abas que esta pessoa pode abrir, menos o da Visão geral (acendem o item da barra lateral). */
    public static function outrosCaminhos(ContextoMenu $contexto): array
    {
        $caminhos = [];
        foreach (self::ABAS as $permissao => [$caminho]) {
            if ($permissao !== PainelWebmaster::PERMISSAO && $contexto->pode($permissao)) {
                $caminhos[] = $caminho;
            }
        }
        return $caminhos;
    }

    /**
     * @param list<ItemMenu> $itens
     * @return list<ItemMenu>
     */
    public static function ordenar(array $itens): array
    {
        $inicio = self::ABAS[PainelWebmaster::PERMISSAO][0];
        $collator = class_exists(\Collator::class) ? new \Collator('pt_BR') : null;
        $collator?->setStrength(\Collator::PRIMARY);
        usort($itens, static function (ItemMenu $a, ItemMenu $b) use ($inicio, $collator): int {
            if (($a->caminho === $inicio) !== ($b->caminho === $inicio)) {
                return $a->caminho === $inicio ? -1 : 1;
            }
            return $collator !== null ? (int) $collator->compare($a->rotulo, $b->rotulo) : strnatcasecmp($a->rotulo, $b->rotulo);
        });
        return $itens;
    }

    /** A aba da página aberta (só o endereço exato acende a Visão geral). */
    public static function atual(array $itens, string $caminho): ?ItemMenu
    {
        foreach ($itens as $item) {
            if ($item->caminho === $caminho) {
                return $item;
            }
        }
        return ItemMenu::melhor(array_values(array_filter($itens, static fn (ItemMenu $i): bool => $i->caminho !== '/painel')), $caminho);
    }
}
