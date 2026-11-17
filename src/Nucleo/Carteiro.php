<?php

declare(strict_types=1);

namespace Captcha\Nucleo;

/** Envio de e-mail. Em desenvolvimento grava num arquivo (CarteiroArquivo); no servidor usa o mail() do PHP. */
interface Carteiro
{
    /** Devolve se o e-mail saiu (ou foi gravado). */
    public function enviar(string $para, string $assunto, string $texto): bool;
}
