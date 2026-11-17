<?php

declare(strict_types=1);

namespace Captcha\Desafio;

use PDO;

/**
 * Os limites da conferência que não ficam no código (o repositório é público). Na primeira vez, o serviço
 * sorteia os valores-base desta instalação dentro das faixas abaixo e guarda em captcha_config ('regras'),
 * junto com um sal secreto. Cada desafio ainda puxa os limites um pouco para cima, por um valor tirado do
 * HMAC do número do desafio com o sal. Assim, quem lê o código sabe as faixas, mas não os números de verdade,
 * e eles mudam de desafio para desafio. A folga de 20% do encaixe (regra do Alfredo) não entra no sorteio.
 */
final class Regras
{
    private const NOME = 'regras';

    /** Faixas da base de cada instalação: [mínimo, máximo]. */
    private const FAIXAS = [
        'criado_ms' => [900, 1500],
        'pegou_ms' => [180, 320],
        'pontos' => [4, 6],
        'duracao_ms' => [120, 200],
        'desvio_px' => [12, 22], // em décimos de pixel
    ];

    /** Quanto cada desafio pode somar à base: [mínimo, máximo]. */
    private const VARIACAO = [
        'criado_ms' => [0, 450],
        'pegou_ms' => [0, 140],
        'pontos' => [0, 2],
        'duracao_ms' => [0, 70],
        'desvio_px' => [0, 8],
    ];

    /** @var array<string, int|string>|null */
    private ?array $base = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function paraDesafio(string $id): Limites
    {
        $base = $this->base();
        $bytes = hash_hmac('sha256', $id, (string) $base['sal'], true);
        $v = [];
        $i = 0;
        foreach (self::VARIACAO as $nome => [$min, $max]) {
            $v[$nome] = (int) $base[$nome] + $min + ord($bytes[$i++]) % ($max - $min + 1);
        }
        return new Limites($v['criado_ms'], $v['pegou_ms'], $v['pontos'], $v['duracao_ms'], $v['desvio_px'] / 10);
    }

    /** @return array<string, int|string> */
    private function base(): array
    {
        if ($this->base !== null) {
            return $this->base;
        }
        $ler = fn (): string|false => $this->pdo->query("SELECT valor FROM captcha_config WHERE nome = '" . self::NOME . "'")->fetchColumn();
        $valor = $ler();
        if ($valor === false) {
            $novo = ['sal' => bin2hex(random_bytes(32))];
            foreach (self::FAIXAS as $nome => [$min, $max]) {
                $novo[$nome] = random_int($min, $max);
            }
            $this->pdo->prepare('INSERT IGNORE INTO captcha_config (nome, valor) VALUES (?, ?)')
                ->execute([self::NOME, json_encode($novo)]);
            $valor = $ler();
        }
        $base = json_decode((string) $valor, true);
        foreach (array_keys(self::FAIXAS) as $nome) {
            if (!is_array($base) || !is_int($base[$nome] ?? null)) {
                throw new \RuntimeException('Regras inválidas em captcha_config');
            }
        }
        return $this->base = $base;
    }
}
