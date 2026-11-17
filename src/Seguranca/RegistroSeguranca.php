<?php

declare(strict_types=1);

namespace Captcha\Seguranca;

use Captcha\Nucleo\Requisicao;

/**
 * Registro das tentativas suspeitas (limite estourado, robô barrado, formulário sem CSRF, IP bloqueado,
 * código de entrada atacado) em temp/logs/seguranca.log, uma linha JSON por evento. Guarda IP, caminho e
 * navegador, nunca senha, e-mail ou conteúdo. O Painel > Sistema mostra as últimas linhas.
 */
final class RegistroSeguranca
{
    public const MAXIMO_BYTES = 2_000_000;

    public function __construct(private readonly string $arquivo)
    {
    }

    /** @param array<string, scalar|null> $extra */
    public function evento(string $tipo, ?Requisicao $requisicao = null, array $extra = []): void
    {
        $linha = ['em' => gmdate('Y-m-d\TH:i:s\Z'), 'tipo' => $tipo];
        if ($requisicao !== null) {
            $linha += [
                'ip' => $requisicao->ip,
                'metodo' => $requisicao->metodo,
                'caminho' => mb_substr($requisicao->caminho, 0, 200),
                'agente' => mb_substr($requisicao->cabecalho('user-agent'), 0, 200),
            ];
        }
        $linha += $extra;
        $pasta = dirname($this->arquivo);
        if (!is_dir($pasta)) {
            @mkdir($pasta, 0700, true);
        }
        if (is_file($this->arquivo) && (int) @filesize($this->arquivo) > self::MAXIMO_BYTES) {
            @rename($this->arquivo, $this->arquivo . '.1');
        }
        @file_put_contents($this->arquivo, json_encode($linha, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Últimos eventos, do mais novo para o mais antigo.
     *
     * @return list<array<string, mixed>>
     */
    public function ultimos(int $quantos = 50): array
    {
        if (!is_file($this->arquivo)) {
            return [];
        }
        $linhas = file($this->arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $eventos = [];
        foreach (array_reverse(array_slice($linhas, -$quantos)) as $linha) {
            $evento = json_decode($linha, true);
            if (is_array($evento)) {
                $eventos[] = $evento;
            }
        }
        return $eventos;
    }
}
