<?php

declare(strict_types=1);

namespace Captcha\Interface;

/**
 * Um bloco do menu. Com $icone, é um assunto que abre e fecha (Verbos → Aulas, Quiz…): o título vira uma linha
 * com ícone e os itens ficam dentro, como subitens. Sem ícone, é um bloco simples, com título opcional (Equipe).
 */
final class GrupoMenu
{
    /** @param list<ItemMenu> $itens */
    public function __construct(
        public readonly ?string $titulo,
        public readonly array $itens,
        public readonly ?string $icone = null,
    ) {
    }

    public function abre(): bool
    {
        return $this->icone !== null && $this->titulo !== null;
    }

    /** O assunto já vem aberto quando a página atual é de um dos seus itens. */
    public function contem(?ItemMenu $item): bool
    {
        return $item !== null && in_array($item, $this->itens, true);
    }
}
