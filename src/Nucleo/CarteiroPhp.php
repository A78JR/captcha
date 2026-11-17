<?php

declare(strict_types=1);

namespace Captcha\Nucleo;

/**
 * E-mail pelo mail() da hospedagem (cPanel). O remetente do envelope (-f) é o mesmo do From, para o SPF e
 * o DMARC passarem com o domínio do site e não com o usuário interno da hospedagem.
 */
final class CarteiroPhp implements Carteiro
{
    public function __construct(private readonly string $remetente)
    {
    }

    public function enviar(string $para, string $assunto, string $texto): bool
    {
        if (filter_var($this->remetente, FILTER_VALIDATE_EMAIL) === false || filter_var($para, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $cabecalhos = [
            'From' => '=?UTF-8?B?' . base64_encode('Captcha') . '?= <' . $this->remetente . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=utf-8',
        ];
        return mail($para, '=?UTF-8?B?' . base64_encode($assunto) . '?=', $texto, $cabecalhos, '-f' . $this->remetente);
    }
}
