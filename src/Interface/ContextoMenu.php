<?php

declare(strict_types=1);

namespace Captcha\Interface;

use Captcha\Acesso\Permissoes;
use Captcha\Acesso\Usuario;

/** O que os módulos precisam saber para decidir quais itens mostrar a quem está vendo. */
final class ContextoMenu
{
    public function __construct(
        public readonly ?Usuario $usuario,
        private readonly ?Permissoes $permissoes = null,
    ) {
    }

    public function logado(): bool
    {
        return $this->usuario !== null;
    }

    /** Tem a permissão (Permissoes)? Visitante nunca tem. */
    public function pode(string $permissao): bool
    {
        return $this->usuario !== null && $this->permissoes !== null && $this->permissoes->pode($this->usuario, $permissao);
    }
}
