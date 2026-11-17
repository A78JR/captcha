<?php

declare(strict_types=1);

namespace Captcha\Seguranca;

use Captcha\Nucleo\Requisicao;

/**
 * Proteção contra robôs nos formulários públicos (entrar, cadastro, esqueci a senha):
 *  1. Isca: campo invisível ("site") que pessoas deixam vazio.
 *  2. Tempo mínimo: o formulário leva a hora em que foi aberto, assinada (campo _ts); enviado em menos de
 *     3 segundos é automação, e com mais de 1 hora expirou.
 * O limite de taxa (LimiteTaxa) e o bloqueio de IP por falhas (BloqueioEntrada) ficam à parte.
 */
final class GuardaFormulario
{
    public const CAMPO_ISCA = 'site';
    public const CAMPO_TEMPO = '_ts';
    public const MINIMO_SEGUNDOS = 3;
    public const MAXIMO_SEGUNDOS = 3600;

    public function __construct(
        private readonly ChaveApp $chave,
        private readonly RegistroSeguranca $registro,
    ) {
    }

    /** Valor do campo oculto _ts: a hora de agora, assinada. */
    public function carimbo(?int $agora = null): string
    {
        $agora ??= time();
        return $agora . '.' . $this->chave->assinar('formulario', (string) $agora);
    }

    /**
     * Devolve a mensagem para a pessoa quando o envio parece de robô, ou null para seguir.
     * $permitirRapido: não confere o tempo mínimo (primeira tentativa de entrar, com a senha preenchida pelo navegador).
     */
    public function conferir(Requisicao $requisicao, ?int $agora = null, bool $permitirRapido = false): ?string
    {
        $motivo = $this->motivo($requisicao, $agora ?? time(), $permitirRapido);
        if ($motivo === null) {
            return null;
        }
        $this->registro->evento('robo_barrado', $requisicao, ['motivo' => $motivo[1]]);
        return $motivo[0];
    }

    /** @return array{0: string, 1: string}|null */
    private function motivo(Requisicao $requisicao, int $agora, bool $permitirRapido): ?array
    {
        if ($requisicao->entrada(self::CAMPO_ISCA) !== '') {
            return ['Envio bloqueado.', 'isca'];
        }
        $partes = explode('.', $requisicao->entrada(self::CAMPO_TEMPO), 2);
        if (count($partes) !== 2 || !ctype_digit($partes[0]) || !hash_equals($this->chave->assinar('formulario', $partes[0]), $partes[1])) {
            return ['Formulário inválido. Recarregue a página e tente novamente.', 'tempo-invalido'];
        }
        $passou = $agora - (int) $partes[0];
        if ($passou < self::MINIMO_SEGUNDOS && !$permitirRapido) {
            return ['Envio rápido demais. Aguarde alguns segundos e tente novamente.', 'rapido-demais'];
        }
        if ($passou > self::MAXIMO_SEGUNDOS) {
            return ['O formulário expirou. Recarregue a página e tente novamente.', 'expirado'];
        }
        return null;
    }
}
