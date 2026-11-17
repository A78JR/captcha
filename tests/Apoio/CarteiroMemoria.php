<?php

declare(strict_types=1);

namespace Captcha\Testes\Apoio;

use Captcha\Nucleo\Carteiro;

/** Guarda os e-mails na memória para os testes lerem códigos e links. */
final class CarteiroMemoria implements Carteiro
{
    /** @var list<array{para: string, assunto: string, texto: string}> */
    public array $enviados = [];

    public function enviar(string $para, string $assunto, string $texto): bool
    {
        $this->enviados[] = ['para' => $para, 'assunto' => $assunto, 'texto' => $texto];
        return true;
    }

    /** @return array{para: string, assunto: string, texto: string}|null o último e-mail para este endereço */
    public function ultimoPara(string $email): ?array
    {
        for ($i = count($this->enviados) - 1; $i >= 0; $i--) {
            if ($this->enviados[$i]['para'] === $email) {
                return $this->enviados[$i];
            }
        }
        return null;
    }

    /** Primeiro trecho do último e-mail para o endereço que casa com $padrao (grupo 1). */
    public function achar(string $email, string $padrao): ?string
    {
        $ultimo = $this->ultimoPara($email);
        return $ultimo !== null && preg_match($padrao, $ultimo['texto'], $m) === 1 ? $m[1] : null;
    }
}
