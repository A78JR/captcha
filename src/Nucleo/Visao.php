<?php

declare(strict_types=1);

namespace Captcha\Nucleo;

use RuntimeException;

/** Renderiza templates de views/ dentro do layout. Templates só exibem, não decidem. */
final class Visao
{
    public function __construct(private readonly string $pasta)
    {
    }

    /**
     * @param array<string, mixed> $dados  o que o template recebe
     * @param array<string, mixed> $layout o que o layout recebe além do conteúdo e do título (menu, avisos...)
     */
    public function renderizar(string $template, array $dados = [], string $titulo = Marca::NOME, array $layout = []): string
    {
        $conteudo = $this->incluir($template, $dados);
        return $this->incluir('layout', ['conteudo' => $conteudo, 'titulo' => $titulo] + $layout + ['menu' => null]);
    }

    /** @param array<string, mixed> $dados um pedaço de página (views/parciais/...), sem o layout */
    public function parcial(string $template, array $dados = []): string
    {
        return $this->incluir($template, $dados);
    }

    public static function escapar(mixed $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, mixed> $dados */
    private function incluir(string $template, array $dados): string
    {
        $arquivo = $this->pasta . '/' . $template . '.php';
        if (!is_file($arquivo)) {
            throw new RuntimeException("Template inexistente: {$template}");
        }
        $renderizar = static function (string $__arquivo, array $__dados): string {
            extract($__dados, EXTR_SKIP);
            ob_start();
            require $__arquivo;
            return (string) ob_get_clean();
        };
        return $renderizar($arquivo, $dados);
    }
}
