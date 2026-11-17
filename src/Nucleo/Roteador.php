<?php

declare(strict_types=1);

namespace Captcha\Nucleo;

use Closure;

/**
 * Liga método + caminho a um manipulador. Aceita parâmetros como /series/{codigo}.
 * Cada rota pode ter guardas (Captcha\Acesso\Guardas): funções que recebem a requisição e devolvem uma
 * resposta para barrar (login, permissão, CSRF, limite) ou null para seguir. Rodam na ordem dada.
 */
final class Roteador
{
    /** @var list<array{metodo: string, padrao: string, manipulador: Closure, guardas: list<Closure>}> */
    private array $rotas = [];

    /** @param list<Closure(Requisicao): ?Resposta> $guardas */
    public function get(string $caminho, Closure $manipulador, array $guardas = []): void
    {
        $this->adicionar('GET', $caminho, $manipulador, $guardas);
    }

    /** @param list<Closure(Requisicao): ?Resposta> $guardas */
    public function post(string $caminho, Closure $manipulador, array $guardas = []): void
    {
        $this->adicionar('POST', $caminho, $manipulador, $guardas);
    }

    public function despachar(Requisicao $requisicao): Resposta
    {
        $metodoErrado = false;
        // HEAD é um GET sem corpo (o servidor web descarta o corpo).
        $metodo = $requisicao->metodo === 'HEAD' ? 'GET' : $requisicao->metodo;
        foreach ($this->rotas as $rota) {
            if (!preg_match($rota['padrao'], $requisicao->caminho, $achados)) {
                continue;
            }
            if ($rota['metodo'] !== $metodo) {
                $metodoErrado = true;
                continue;
            }
            foreach ($rota['guardas'] as $guarda) {
                $barrada = $guarda($requisicao);
                if ($barrada !== null) {
                    return $barrada;
                }
            }
            $parametros = array_filter($achados, 'is_string', ARRAY_FILTER_USE_KEY);
            return ($rota['manipulador'])($requisicao, $parametros);
        }
        return $metodoErrado
            ? new Resposta('Método não permitido', 405)
            : new Resposta('Página não encontrada', 404);
    }

    /** @param list<Closure> $guardas */
    private function adicionar(string $metodo, string $caminho, Closure $manipulador, array $guardas): void
    {
        $caminho = '/' . trim($caminho, '/');
        $padrao = preg_replace('#\{([a-z_][a-z0-9_]*)\}#i', '(?P<$1>[^/]+)', $caminho);
        $this->rotas[] = ['metodo' => $metodo, 'padrao' => '#^' . $padrao . '$#', 'manipulador' => $manipulador, 'guardas' => $guardas];
    }
}
