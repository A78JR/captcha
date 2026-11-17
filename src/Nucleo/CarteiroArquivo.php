<?php

declare(strict_types=1);

namespace Captcha\Nucleo;

/** Fora do servidor os e-mails não saem: ficam em temp/emails.log para conferir links e códigos. */
final class CarteiroArquivo implements Carteiro
{
    public function __construct(private readonly string $arquivo)
    {
    }

    public function enviar(string $para, string $assunto, string $texto): bool
    {
        $pasta = dirname($this->arquivo);
        if (!is_dir($pasta)) {
            @mkdir($pasta, 0700, true);
        }
        $registro = sprintf("[%s] Para: %s\nAssunto: %s\n\n%s\n%s\n", gmdate('c'), $para, $assunto, $texto, str_repeat('-', 60));
        return @file_put_contents($this->arquivo, $registro, FILE_APPEND | LOCK_EX) !== false;
    }
}
