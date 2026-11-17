<?php

declare(strict_types=1);

namespace Captcha\Interface;

/**
 * Tema claro/escuro escolhido no botão do topo (sol/lua). Sem escolha, o site segue o tema do aparelho.
 * A escolha fica no navegador (localStorage) e num cookie "tema", que o servidor lê para já mandar a
 * página na cor certa, sem piscar. Só "claro" e "escuro" valem; qualquer outro valor é ignorado.
 */
final class Tema
{
    public const COOKIE = 'tema';
    public const CLARO = 'claro';
    public const ESCURO = 'escuro';

    /** @param array<string, mixed> $cookies normalmente $_COOKIE */
    public function __construct(private readonly array $cookies)
    {
    }

    public static function daRequisicao(): self
    {
        return new self($_COOKIE);
    }

    /** "claro", "escuro" ou null (segue o aparelho). */
    public function escolhido(): ?string
    {
        $valor = $this->cookies[self::COOKIE] ?? null;
        return $valor === self::CLARO || $valor === self::ESCURO ? $valor : null;
    }

    /** Atributo para a tag <html>: vazio quando segue o aparelho. */
    public function atributoHtml(): string
    {
        $tema = $this->escolhido();
        return $tema === null ? '' : ' data-tema="' . $tema . '"';
    }

    /**
     * Botão que troca o tema: no rodapé do menu lateral (ícone e nome, como Recolher e Sair) e, nas páginas sem
     * menu (entrar, cadastro), no topo só com o ícone. O nome e o ícone certos são acertados pelo JavaScript.
     */
    public function botao(): string
    {
        $rotulo = $this->escolhido() === self::ESCURO ? 'Tema claro' : 'Tema escuro';
        return '<button type="button" class="menu-link botao-tema" data-tema-trocar title="' . $rotulo . '">'
            . '<span class="tema-lua">' . Icone::svg('lua') . '</span>'
            . '<span class="tema-sol">' . Icone::svg('sol') . '</span>'
            . '<span class="rotulo">' . $rotulo . '</span></button>';
    }
}
