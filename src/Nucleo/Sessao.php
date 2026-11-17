<?php

declare(strict_types=1);

namespace Captcha\Nucleo;

/**
 * A sessão de quem está usando o site. O resto do código nunca toca em $_SESSION: pede a esta interface.
 * No site vale a SessaoNativa (cookie do PHP); nos testes, a SessaoMemoria.
 */
interface Sessao
{
    public function obter(string $chave, mixed $padrao = null): mixed;

    public function definir(string $chave, mixed $valor): void;

    public function esquecer(string $chave): void;

    /** Recado que aparece uma vez na próxima página (ex.: "Senha trocada."). */
    public function avisar(string $tipo, string $mensagem): void;

    /** Lê e apaga o recado guardado. */
    public function aviso(string $tipo): ?string;

    /** Troca o identificador da sessão (no login), contra fixação de sessão. */
    public function regenerar(): void;

    /** Apaga tudo (sair da conta). */
    public function destruir(): void;

    /** Mantém o login aberto, mesmo fechando o navegador, por até $segundos depois do último clique. */
    public function manterPor(int $segundos): void;
}
