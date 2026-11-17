<?php

declare(strict_types=1);

namespace Captcha\Desafio;

/** Uma linha de captcha_desafio, já com a idade calculada pelo banco. */
final class Desafio
{
    private ?Plano $plano = null;

    private function __construct(
        public readonly string $id,
        public readonly int $siteId,
        public readonly string $siteSlug,
        public readonly int $semente,
        public readonly string $nonce,
        public readonly string $chaveIp,
        public readonly string $situacao,
        public readonly int $idadeMs,
        public readonly ?int $desdePegouMs,
    ) {
    }

    /** @param array<string, mixed> $l */
    public static function daLinha(array $l): self
    {
        return new self(
            (string) $l['id'], (int) $l['site_id'], (string) $l['site_slug'], (int) $l['semente'], (string) $l['nonce_site'],
            (string) $l['chave_ip'], (string) $l['situacao'], (int) $l['idade_ms'],
            $l['desde_pegou_ms'] !== null ? (int) $l['desde_pegou_ms'] : null,
        );
    }

    public function plano(): Plano
    {
        return $this->plano ??= Plano::daSemente($this->semente);
    }

    public function aberto(): bool
    {
        return $this->situacao === 'aberto' && $this->idadeMs <= RepositorioDesafios::VALIDADE * 1000;
    }

    public function pegou(): bool
    {
        return $this->desdePegouMs !== null;
    }
}
