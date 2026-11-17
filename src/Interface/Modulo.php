<?php

declare(strict_types=1);

namespace Captcha\Interface;

/**
 * Uma área do site no menu lateral (Ferramentas, Minha conta, Equipe...).
 * Cada módulo diz quais itens mostra para quem está vendo; o menu junta todos (RegistroModulos).
 * Para criar uma área nova: uma classe em Captcha\Interface\Modulos e uma linha em RegistroModulos::padrao().
 */
abstract class Modulo
{
    /** Título do bloco no menu, ou null para itens soltos. */
    public function titulo(ContextoMenu $contexto): ?string
    {
        return null;
    }

    /**
     * Ícone do assunto. Com ícone e título, o módulo vira um assunto que abre em subitens no menu
     * (ex.: Verbos → Aulas, Quiz, Quadro). Para um curso novo: um módulo com titulo(), icone() e itens().
     */
    public function icone(): ?string
    {
        return null;
    }

    /** @return list<ItemMenu> itens que esta pessoa pode ver (vazio = o módulo some do menu) */
    abstract public function itens(ContextoMenu $contexto): array;
}
