<?php

declare(strict_types=1);

namespace Captcha\Nucleo;

/** A requisição HTTP atual, sem acesso direto às superglobais fora daqui. */
final class Requisicao
{
    /**
     * @param array<string, mixed>  $consulta
     * @param array<string, mixed>  $corpo
     * @param array<string, string> $cabecalhos
     */
    public function __construct(
        public readonly string $metodo,
        public readonly string $caminho,
        public readonly array $consulta = [],
        public readonly array $corpo = [],
        public readonly array $cabecalhos = [],
        public readonly string $ip = '127.0.0.1',
        public readonly bool $https = false,
        public readonly string $bruto = '',
    ) {
    }

    public static function daGlobal(): self
    {
        $caminho = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $cabecalhos = [];
        foreach ($_SERVER as $nome => $valor) {
            if (str_starts_with((string) $nome, 'HTTP_')) {
                $cabecalhos[strtolower(str_replace('_', '-', substr((string) $nome, 5)))] = (string) $valor;
            }
        }
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            '/' . trim($caminho, '/'),
            $_GET,
            $_POST,
            $cabecalhos,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            !in_array((string) ($_SERVER['HTTPS'] ?? ''), ['', 'off'], true),
            // Corpo cru só para JSON (a API do X-13), até 3 MB.
            str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'json')
                ? (string) file_get_contents('php://input', false, null, 0, 3_000_000) : '',
        );
    }

    public function cabecalho(string $nome): string
    {
        return $this->cabecalhos[strtolower($nome)] ?? '';
    }

    /** Campo do formulário (ou, sem ele, da consulta), sempre como texto aparado. Lista vira texto vazio. */
    public function entrada(string $nome): string
    {
        $valor = $this->corpo[$nome] ?? $this->consulta[$nome] ?? '';
        return is_string($valor) ? trim($valor) : '';
    }

    /** Campo do formulário sem aparar (senhas: espaço no começo ou no fim faz parte da senha). */
    public function senha(string $nome): string
    {
        $valor = $this->corpo[$nome] ?? '';
        return is_string($valor) ? $valor : '';
    }

    /** @return array<string, mixed>|null o corpo JSON, se veio um objeto */
    public function json(): ?array
    {
        $dados = $this->bruto !== '' ? json_decode($this->bruto, true, 8) : null;
        return is_array($dados) ? $dados : null;
    }

    /** O navegador pediu uma página (e não um pedido de fundo, como a conferência da segunda etapa)? */
    public function pedePagina(): bool
    {
        $destino = $this->cabecalho('sec-fetch-dest');
        return $destino === '' || $destino === 'document';
    }
}
