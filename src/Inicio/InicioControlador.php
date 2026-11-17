<?php

declare(strict_types=1);

namespace Captcha\Inicio;

use Captcha\Nucleo\Requisicao;
use Captcha\Nucleo\Resposta;
use Captcha\Nucleo\Roteador;
use Captcha\Nucleo\Servicos;

/** Página inicial: o que é o serviço e o caminho para a demonstração. Aberta a todos. */
final class InicioControlador
{
    public function __construct(private readonly Servicos $s)
    {
    }

    public function registrar(Roteador $roteador): void
    {
        $roteador->get('/', fn (Requisicao $r): Resposta => $this->s->pagina()->html($r, 'inicio'));
    }
}
