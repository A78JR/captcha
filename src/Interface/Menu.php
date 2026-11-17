<?php

declare(strict_types=1);

namespace Captcha\Interface;

/**
 * O menu lateral pronto para desenhar (views/parciais/menu_lateral.php): os blocos de cada módulo e qual
 * item está aberto agora. No computador: ícone e nome, e o botão "Recolher menu" deixa só os ícones (a
 * escolha fica no navegador). No celular ele fica escondido e o botão Menu do topo o abre por cima da página.
 * Ordem fixa no fim: Recolher menu e, por último, Sair.
 */
final class Menu
{
    /** @param list<GrupoMenu> $grupos */
    public function __construct(
        public readonly array $grupos,
        public readonly bool $logado,
        private readonly string $caminho,
    ) {
    }

    public static function montar(ContextoMenu $contexto, string $caminho, ?RegistroModulos $registro = null): self
    {
        return new self(($registro ?? RegistroModulos::padrao())->grupos($contexto), $contexto->logado(), $caminho);
    }

    public function atual(): ?ItemMenu
    {
        return ItemMenu::melhor($this->itens(), $this->caminho);
    }

    /** @return list<ItemMenu> todos os itens, na ordem do menu */
    public function itens(): array
    {
        return array_merge([], ...array_map(static fn (GrupoMenu $g): array => $g->itens, $this->grupos));
    }
}
