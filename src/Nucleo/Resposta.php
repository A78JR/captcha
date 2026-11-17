<?php

declare(strict_types=1);

namespace Captcha\Nucleo;

/** Resposta HTTP: status, cabeçalhos e corpo. Só é enviada em enviar(). */
final class Resposta
{
    /** @param array<string, string> $cabecalhos */
    public function __construct(
        public readonly string $corpo,
        public readonly int $status = 200,
        public readonly array $cabecalhos = ['Content-Type' => 'text/html; charset=utf-8'],
    ) {
    }

    public static function json(mixed $dados, int $status = 200): self
    {
        return new self(
            (string) json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    /** Volta para outro endereço deste site (303: o navegador pede o novo endereço com GET). */
    public static function redirecionar(string $destino, int $status = 303): self
    {
        if (!self::caminhoInterno($destino)) {
            $destino = '/';
        }
        return new self('', $status, ['Location' => $destino, 'Cache-Control' => 'no-store']);
    }

    /** Só caminhos deste site: "/conta" sim; "//outro.site", "https://..." e caminhos com barra invertida não. */
    public static function caminhoInterno(string $destino): bool
    {
        return str_starts_with($destino, '/') && !str_starts_with($destino, '//') && !str_contains($destino, '\\')
            && preg_match('/[\x00-\x1f]/', $destino) !== 1;
    }

    public function comCabecalho(string $nome, string $valor): self
    {
        return new self($this->corpo, $this->status, [$nome => $valor] + $this->cabecalhos);
    }

    /** @param array<string, string> $cabecalhos acrescentados sem trocar os que a resposta já tem */
    public function comCabecalhosPadrao(array $cabecalhos): self
    {
        return new self($this->corpo, $this->status, $this->cabecalhos + $cabecalhos);
    }

    public function cabecalho(string $nome): ?string
    {
        foreach ($this->cabecalhos as $chave => $valor) {
            if (strcasecmp($chave, $nome) === 0) {
                return $valor;
            }
        }
        return null;
    }

    public function enviar(): void
    {
        http_response_code($this->status);
        foreach ($this->cabecalhos as $nome => $valor) {
            header($nome . ': ' . $valor);
        }
        echo $this->corpo;
    }
}
