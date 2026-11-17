<?php

declare(strict_types=1);

namespace Captcha\Desafio;

/**
 * Tudo o que a semente decide: a cena, o objeto certo e as peças coloridas nas três prateleiras (o objeto
 * certo aparece de 2 a 4 vezes, só uma com a inclinação certa). O navegador só recebe imagens; qual peça é
 * a certa, só o servidor sabe. A semente é sorteada com random_int e fica só no banco.
 */
final class Plano
{
    public const LARGURA = 400;
    public const ALTURA = 300;
    /** Quantas peças: sorteado entre estes dois, a cada desafio. */
    public const PECAS_MIN = 11;
    public const PECAS_MAX = 14;
    /** Inclinação de cada peça: de 8 a 45 graus para um lado ou outro; cópias do mesmo objeto, 15 graus longe. */
    private const GIRO_MIN = 8;
    private const GIRO_MAX = 45;
    private const GIRO_SEPARACAO = 15;

    /**
     * @param list<Peca> $pecas na ordem de desenho (de baixo para cima)
     * @param list<int>  $linhas altura (y) da base de cada prateleira
     */
    private function __construct(
        public readonly int $semente,
        public readonly Cena $cena,
        public readonly array $pecas,
        public readonly array $linhas,
    ) {
    }

    /**
     * Quase tudo é sorteado a cada desafio: quantas peças (11 a 14), quantas cópias do objeto certo (2 a 4,
     * só uma com a inclinação certa), quais outros objetos repetem, as inclinações, os tamanhos, os lugares
     * e as camadas. Um robô não pode contar com um número fixo de nada.
     */
    public static function daSemente(int $semente): self
    {
        $a = new Aleatorio($semente);
        $cena = Cena::sortear($a);
        $objetos = $a->embaralhar($cena->objetos());
        $linhas = [$a->inteiro(104, 118), $a->inteiro(200, 214), self::ALTURA - $a->inteiro(2, 8)];
        $total = $a->inteiro(self::PECAS_MIN, self::PECAS_MAX);
        $copias = $a->inteiro(2, 4);

        // O que vai nas prateleiras: [objeto, certa?]. Os outros quatro objetos aparecem pelo menos uma vez.
        $lista = array_fill(0, $copias, $objetos[0]);
        $outros = array_slice($objetos, 1);
        for ($i = 0; $i < $total - $copias; $i++) {
            $lista[] = $i < count($outros) ? $outros[$i] : $outros[$a->inteiro(0, count($outros) - 1)];
        }
        $giros = [];
        $itens = [];
        foreach ($lista as $i => $objeto) {
            $giro = self::sortearGiro($a, $giros[$objeto] ?? []);
            $giros[$objeto][] = $giro;
            $itens[] = [$objeto, $giro, $i === 0];
        }

        // Quatro ou cinco lugares em cada prateleira, encostados: os objetos ficam uns por cima dos outros.
        $porLinha = (int) ceil($total / 3);
        $lugares = [];
        foreach ($linhas as $linha) {
            $faixa = (self::LARGURA - 32) / $porLinha;
            for ($i = 0; $i < $porLinha; $i++) {
                $lugares[] = [16 + (int) ($faixa * $i + $faixa / 2) + $a->inteiro(-7, 7), $linha];
            }
        }
        $lugares = $a->embaralhar($lugares);
        $camadas = $a->embaralhar(range(1, $total));
        $camadaMinima = (int) ceil($total * 0.55);

        $pecas = [];
        foreach ($itens as $i => [$objeto, $giro, $certa]) {
            $tamanho = $a->inteiro(112, 134);
            [$x, $linha] = $lugares[$i];
            // A certa nunca fica muito por baixo: pelo menos acima de metade das camadas.
            $camada = $certa ? max($camadaMinima, $camadas[$i]) : $camadas[$i];
            $pecas[] = new Peca($objeto, $x, $linha - (int) ($tamanho * $a->inteiro(39, 45) / 100), $tamanho, $giro, $a->inteiro(0, 359), $camada, $certa);
        }
        usort($pecas, static fn (Peca $p, Peca $q): int => [$p->camada, $p->certa ? 1 : 0] <=> [$q->camada, $q->certa ? 1 : 0]);
        return new self($semente, $cena, $pecas, $linhas);
    }

    /** @param list<int> $usados inclinações das outras cópias do mesmo objeto */
    private static function sortearGiro(Aleatorio $a, array $usados): int
    {
        $giro = 0;
        for ($tentativa = 0; $tentativa < 40; $tentativa++) {
            $giro = $a->inteiro(self::GIRO_MIN, self::GIRO_MAX) * ($a->inteiro(0, 1) === 0 ? -1 : 1);
            $longe = true;
            foreach ($usados as $outro) {
                if (abs($giro - $outro) < self::GIRO_SEPARACAO) {
                    $longe = false;
                }
            }
            if ($longe) {
                return $giro;
            }
        }
        return $giro;
    }

    public function certa(): Peca
    {
        foreach ($this->pecas as $peca) {
            if ($peca->certa) {
                return $peca;
            }
        }
        throw new \LogicException('Plano sem peça certa');
    }

    /** A peça de cima naquele ponto (a última desenhada que contém o ponto), ou null. */
    public function pecaEm(float $x, float $y): ?Peca
    {
        foreach (array_reverse($this->pecas) as $peca) {
            if ($peca->contem($x, $y)) {
                return $peca;
            }
        }
        return null;
    }
}
