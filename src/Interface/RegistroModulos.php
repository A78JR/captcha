<?php

declare(strict_types=1);

namespace Captcha\Interface;

use Captcha\Interface\Modulos\ModuloCaptcha;
use Captcha\Interface\Modulos\ModuloConta;
use Captcha\Interface\Modulos\ModuloEquipe;
use Captcha\Interface\Modulos\ModuloInicio;

/** Lista das áreas do site, na ordem em que aparecem no menu. */
final class RegistroModulos
{
    /** @param list<Modulo> $modulos */
    public function __construct(private readonly array $modulos)
    {
    }

    public static function padrao(): self
    {
        return new self([
            new ModuloInicio(),
            new ModuloCaptcha(),
            new ModuloConta(),
            new ModuloEquipe(),
        ]);
    }

    /** @return list<GrupoMenu> blocos com pelo menos um item para esta pessoa */
    public function grupos(ContextoMenu $contexto): array
    {
        $grupos = [];
        foreach ($this->modulos as $modulo) {
            $itens = $modulo->itens($contexto);
            if ($itens !== []) {
                $grupos[] = new GrupoMenu($modulo->titulo($contexto), $itens, $modulo->icone());
            }
        }
        return $grupos;
    }
}
