<?php

declare(strict_types=1);

namespace Captcha\Interface;

/**
 * Ícones do menu, desenhados aqui mesmo (traço simples, 24x24) para não depender de biblioteca, no mesmo
 * estilo dos do Bazar de Tudo. A cor vem do texto em volta (currentColor), então acompanham o tema.
 */
final class Icone
{
    /** @var array<string, string> nome => conteúdo do <svg> */
    private const DESENHOS = [
        'inicio' => '<path d="M3 11 12 4l9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'aulas' => '<path d="M3 6.5 12 3l9 3.5-9 3.5z"/><path d="M6.5 8v5c0 1.7 2.5 3 5.5 3s5.5-1.3 5.5-3V8"/><path d="M21 6.5V12"/>',
        'cognatos' => '<path d="M3 5h11v8H8l-5 4z"/><path d="M14 9h7v8l-4-3h-5"/><path d="M6 8h5M6 10.5h3"/>',
        'quiz' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/><path d="m15 15.5 1.5 1.5 3-3"/>',
        'gramatica' => '<path d="M4 4.5h6a2 2 0 0 1 2 2V20a2 2 0 0 0-2-2H4z"/><path d="M20 4.5h-6a2 2 0 0 0-2 2V20a2 2 0 0 1 2-2h6z"/><path d="M6.5 9h3M14.5 9h3M14.5 12.5h3"/>',
        'quadro' => '<rect x="3" y="4" width="18" height="16" rx="1.5"/><path d="M3 9h18M3 14.5h18M9 4v16M15 4v16"/>',
        'turma' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'conta' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'seguranca' => '<path d="M12 3 5 6v5c0 4.5 3 8.3 7 10 4-1.7 7-5.5 7-10V6z"/><path d="m9 12 2 2 4-4"/>',
        'painel' => '<rect x="3" y="3" width="7.5" height="9" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="5" rx="1.5"/><rect x="13.5" y="11" width="7.5" height="10" rx="1.5"/><rect x="3" y="15" width="7.5" height="6" rx="1.5"/>',
        'sistema' => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/>',
        'equipe' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14.5a5 5 0 0 1 5.5 5"/>',
        'usuarios' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2.5"/><path d="M5.5 17a3.5 3.5 0 0 1 7 0M15 9h3M15 13h3"/>',
        'entrar' => '<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M4 12h11M11 8l4 4-4 4"/>',
        'cadastro' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M19 7v6M16 10h6"/>',
        'recolher' => '<path d="m11 7-5 5 5 5M18 7l-5 5 5 5"/>',
        'sair' => '<path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="M20 12H9M16 8l4 4-4 4"/>',
        'sol' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/>',
        'lua' => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'encaixe' => '<path d="M4 9h3a2 2 0 1 1 4 0h3v3a2 2 0 1 1 0 4v4H4z"/><path d="M14 12V8h3a2 2 0 1 1 3 2"/>',
    ];

    public static function existe(string $nome): bool
    {
        return isset(self::DESENHOS[$nome]);
    }

    /** SVG pronto para o HTML. É decorativo: o nome do item vai no texto ao lado. */
    public static function svg(string $nome): string
    {
        return '<svg class="icone" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"'
            . ' fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
            . (self::DESENHOS[$nome] ?? self::DESENHOS['inicio']) . '</svg>';
    }
}
