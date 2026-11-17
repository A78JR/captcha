<?php

declare(strict_types=1);

namespace Captcha\Interface;

/**
 * Um item do menu lateral (ou de um submenu): nome, endereço, ícone e, se houver, um contador.
 * $tambem: outros endereços que também acendem este item (ex.: Painel webmaster vale em /painel/usuarios).
 */
final class ItemMenu
{
    /** @param list<string> $tambem */
    public function __construct(
        public readonly string $rotulo,
        public readonly string $caminho,
        public readonly string $icone,
        public readonly int $contador = 0,
        public readonly array $tambem = [],
    ) {
    }

    /** Tamanho do endereço do item que combina com $caminho (o mais longo), ou 0 se nenhum combina. */
    public function combina(string $caminho): int
    {
        if ($this->caminho === '/') {
            return $caminho === '/' ? 1 : 0;
        }
        $melhor = 0;
        foreach ([$this->caminho, ...$this->tambem] as $prefixo) {
            if (($caminho === $prefixo || str_starts_with($caminho, $prefixo . '/')) && strlen($prefixo) > $melhor) {
                $melhor = strlen($prefixo);
            }
        }
        return $melhor;
    }

    /**
     * O item da página aberta: o de endereço mais longo que combina (/conta/senha ganha de /conta).
     *
     * @param list<ItemMenu> $itens
     */
    public static function melhor(array $itens, string $caminho): ?self
    {
        $melhor = null;
        $tamanho = 0;
        foreach ($itens as $item) {
            $n = $item->combina($caminho);
            if ($n > $tamanho) {
                [$melhor, $tamanho] = [$item, $n];
            }
        }
        return $melhor;
    }
}
