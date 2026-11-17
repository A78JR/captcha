<?php

declare(strict_types=1);

namespace Captcha\Desafio;

use PDO;

/** Os sistemas que usam o captcha (captcha_site). */
final class RepositorioSites
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id: int, slug: string, nome: string, origens: list<string>}|null só sites ativos */
    public function porSlug(string $slug): ?array
    {
        $consulta = $this->pdo->prepare('SELECT id, slug, nome, origens FROM captcha_site WHERE slug = ? AND ativo = 1');
        $consulta->execute([$slug]);
        $linha = $consulta->fetch(PDO::FETCH_ASSOC);
        return $linha === false ? null : self::montar($linha);
    }

    /** @return array{id: int, slug: string, nome: string, origens: list<string>}|null */
    public function porId(int $id): ?array
    {
        $consulta = $this->pdo->prepare('SELECT id, slug, nome, origens FROM captcha_site WHERE id = ?');
        $consulta->execute([$id]);
        $linha = $consulta->fetch(PDO::FETCH_ASSOC);
        return $linha === false ? null : self::montar($linha);
    }

    /** @return list<array{id: int, slug: string, nome: string, origens: list<string>, ativo: bool}> */
    public function todos(): array
    {
        $sites = [];
        foreach ($this->pdo->query('SELECT id, slug, nome, origens, ativo FROM captcha_site ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $sites[] = self::montar($linha) + ['ativo' => (bool) $linha['ativo']];
        }
        return $sites;
    }

    /** @param array<string, mixed> $linha */
    private static function montar(array $linha): array
    {
        return [
            'id' => (int) $linha['id'],
            'slug' => (string) $linha['slug'],
            'nome' => (string) $linha['nome'],
            'origens' => array_values(array_filter(preg_split('/\s+/', (string) $linha['origens']) ?: [])),
        ];
    }
}
