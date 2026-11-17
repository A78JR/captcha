<?php

declare(strict_types=1);

namespace Captcha\Nucleo;

/** Sessão guardada só na memória do processo: para os testes e para chamadas sem navegador. */
final class SessaoMemoria implements Sessao
{
    /** @var array<string, mixed> */
    private array $dados = [];
    private int $regeneracoes = 0;

    public function obter(string $chave, mixed $padrao = null): mixed
    {
        return $this->dados[$chave] ?? $padrao;
    }

    public function definir(string $chave, mixed $valor): void
    {
        $this->dados[$chave] = $valor;
    }

    public function esquecer(string $chave): void
    {
        unset($this->dados[$chave]);
    }

    public function avisar(string $tipo, string $mensagem): void
    {
        $this->dados['_aviso'][$tipo] = $mensagem;
    }

    public function aviso(string $tipo): ?string
    {
        $valor = $this->dados['_aviso'][$tipo] ?? null;
        unset($this->dados['_aviso'][$tipo]);
        return $valor;
    }

    public function regenerar(): void
    {
        $this->regeneracoes++;
    }

    public function destruir(): void
    {
        $this->dados = [];
    }

    public function manterPor(int $segundos): void
    {
    }

    /** Quantas vezes o identificador foi trocado (para os testes conferirem a troca no login). */
    public function regeneracoes(): int
    {
        return $this->regeneracoes;
    }
}
