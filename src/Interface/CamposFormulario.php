<?php

declare(strict_types=1);

namespace Captcha\Interface;

use Captcha\Nucleo\Visao;
use Captcha\Seguranca\Csrf;
use Captcha\Seguranca\GuardaFormulario;

/** Os campos ocultos que todo formulário leva: o token CSRF e, nos públicos, a isca e o tempo assinado. */
final class CamposFormulario
{
    public function __construct(
        public readonly string $csrf,
        private readonly ?string $carimbo,
    ) {
    }

    /** Só o CSRF (formulários de quem já está logado). */
    public function csrf(): string
    {
        return '<input type="hidden" name="' . Csrf::CAMPO . '" value="' . Visao::escapar($this->csrf) . '">';
    }

    /** CSRF + isca + tempo assinado (entrar, cadastro, esqueci a senha). */
    public function publico(): string
    {
        return $this->csrf()
            . '<input type="hidden" name="' . GuardaFormulario::CAMPO_TEMPO . '" value="' . Visao::escapar((string) $this->carimbo) . '">'
            . '<div class="isca" aria-hidden="true"><label>Site <input type="text" name="' . GuardaFormulario::CAMPO_ISCA
            . '" tabindex="-1" autocomplete="off"></label></div>';
    }
}
