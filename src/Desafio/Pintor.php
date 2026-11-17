<?php

declare(strict_types=1);

namespace Captcha\Desafio;

use GdImage;
use RuntimeException;

/**
 * Desenha as imagens do desafio com o GD do PHP: a cena em duas versões (a e b, que o navegador alterna com
 * fade) com as 12 peças coloridas gravadas nela, e o objeto cinza que a pessoa carrega, reto (na bandeja) e
 * inclinado (só quando ela pega). Cada versão tem fundo, cores e ruído próprios; as peças são as mesmas.
 */
final class Pintor
{
    public function __construct(private readonly string $pastaObjetos)
    {
    }

    /** Lado da imagem do objeto cinza: cabe o objeto girado até 45 graus. */
    public static function quadro(int $tamanho): int
    {
        return (int) ceil($tamanho * 1.42);
    }

    /** PNG do fundo, versão 0 (a) ou 1 (b). */
    public function fundo(Plano $plano, int $versao): string
    {
        $img = imagecreatetruecolor(Plano::LARGURA, Plano::ALTURA);
        $a = new Aleatorio($plano->semente ^ ($versao === 0 ? 0x5A5A5A5A : 0x13579BDF));
        if ($plano->cena === Cena::Cozinha) {
            $this->cozinha($img, $plano, $versao, $a);
        } else {
            $this->quarto($img, $plano, $versao, $a);
        }
        $this->ruido($img, $a, $a->inteiro(700, 1500));
        foreach ($plano->pecas as $peca) {
            // Na versão b a cor de cada peça muda um pouco, para o fade não deixar nada parado.
            $this->peca($img, $peca, $versao === 0 ? 0 : 18);
        }
        $this->ruido($img, $a, $a->inteiro(100, 280));
        return $this->png($img);
    }

    /** PNG transparente do objeto certo em cinza, no tamanho dele: reto ($inclinado false) ou com o giro certo. */
    public function objeto(Plano $plano, bool $inclinado): string
    {
        $certa = $plano->certa();
        // Cinza um pouco diferente a cada desafio, com grão: o desenho nunca é igual ao arquivo do objeto,
        // e um robô não acha a peça só comparando pixels.
        $a = new Aleatorio($plano->semente ^ 0x2C1B3C6D);
        $img = $this->desenho($certa->objeto, $certa->tamanho);
        imagefilter($img, IMG_FILTER_GRAYSCALE);
        imagefilter($img, IMG_FILTER_BRIGHTNESS, $a->inteiro(5, 35));
        imagefilter($img, IMG_FILTER_CONTRAST, $a->inteiro(-15, 10));
        $this->grao($img, $a);
        if ($inclinado) {
            $img = $this->girar($img, $certa->giro);
        }
        // Reto ou inclinado, a imagem sai sempre no mesmo quadro (quadro()), com o objeto no centro: assim o
        // navegador mostra as duas do mesmo tamanho e o centro da imagem é o centro do objeto.
        $lado = self::quadro($certa->tamanho);
        $quadro = imagecreatetruecolor($lado, $lado);
        imagealphablending($quadro, false);
        imagesavealpha($quadro, true);
        imagefill($quadro, 0, 0, imagecolorallocatealpha($quadro, 0, 0, 0, 127));
        imagealphablending($quadro, true);
        imagecopy($quadro, $img, intdiv($lado - imagesx($img), 2), intdiv($lado - imagesy($img), 2), 0, 0, imagesx($img), imagesy($img));
        return $this->png($quadro);
    }

    private function cozinha(GdImage $img, Plano $p, int $v, Aleatorio $a): void
    {
        $parede = $v === 0 ? [216, 236, 240] : [246, 227, 201];
        $azulejo = $v === 0 ? [188, 221, 228] : [239, 210, 173];
        $madeira = $v === 0 ? [123, 143, 153] : [168, 111, 69];
        $frente = $v === 0 ? [159, 177, 186] : [201, 140, 92];
        $this->retangulo($img, 0, 0, Plano::LARGURA, Plano::ALTURA, $parede);
        $lado = $a->inteiro(34, 44);
        $desloca = $a->inteiro(0, $lado);
        for ($y = -$lado; $y < Plano::ALTURA; $y += $lado) {
            for ($x = -$lado; $x < Plano::LARGURA + $lado; $x += $lado) {
                $this->retangulo($img, $x + $desloca + 2, $y + 2, $x + $desloca + $lado - 2, $y + $lado - 2, $this->variar($azulejo, $a, 6));
            }
        }
        // Janela ou relógio no alto, em lugar sorteado.
        $jx = $a->inteiro(20, 330);
        if ($v === 0) {
            $this->retangulo($img, $jx, 14, $jx + 56, 60, [250, 252, 252]);
            $this->retangulo($img, $jx + 27, 14, $jx + 29, 60, $madeira);
        } else {
            imagefilledellipse($img, $jx + 26, 38, 44, 44, $this->cor($img, [255, 244, 214]));
            imagesetthickness($img, 3);
            imageline($img, $jx + 26, 38, $jx + 26, 24, $this->cor($img, $madeira));
            imageline($img, $jx + 26, 38, $jx + 36, 42, $this->cor($img, $madeira));
            imagesetthickness($img, 1);
        }
        [$l1, $l2, $l3] = $p->linhas;
        $this->retangulo($img, 0, $l1, Plano::LARGURA, $l1 + 8, $madeira);
        $this->retangulo($img, 0, $l2, Plano::LARGURA, $l2 + 8, $madeira);
        $this->retangulo($img, 0, $l3 - 34, Plano::LARGURA, Plano::ALTURA, $frente);
        $this->retangulo($img, 0, $l3 - 40, Plano::LARGURA, $l3 - 33, $madeira);
        for ($x = $a->inteiro(4, 40); $x < Plano::LARGURA; $x += $a->inteiro(50, 70)) {
            $this->retangulo($img, $x, $l1 + 8, $x + 4, $l1 + 22, $this->escurecer($madeira, 0.8));
            $this->retangulo($img, $x + 20, $l2 + 8, $x + 24, $l2 + 22, $this->escurecer($madeira, 0.8));
        }
    }

    private function quarto(GdImage $img, Plano $p, int $v, Aleatorio $a): void
    {
        $parede = $v === 0 ? [227, 230, 255] : [253, 226, 236];
        $estrela = $v === 0 ? [195, 200, 255] : [247, 184, 207];
        $piso = $v === 0 ? [167, 185, 138] : [201, 162, 122];
        $tapete = $v === 0 ? [111, 195, 214] : [255, 159, 110];
        $this->retangulo($img, 0, 0, Plano::LARGURA, Plano::ALTURA, $parede);
        $passo = $a->inteiro(32, 42);
        $dx = $a->inteiro(0, $passo);
        $dy = $a->inteiro(0, $passo);
        for ($y = -$passo; $y < $p->linhas[2] - 50; $y += $passo) {
            for ($x = -$passo; $x < Plano::LARGURA + $passo; $x += $passo) {
                $this->estrela($img, $x + $dx + (($y / $passo) % 2) * $passo / 2, $y + $dy, $a->inteiro(6, 9), $this->variar($estrela, $a, 8));
            }
        }
        [$l1, $l2, $l3] = $p->linhas;
        $chao = $l3 - 50;
        $this->retangulo($img, 0, $chao, Plano::LARGURA, Plano::ALTURA, $piso);
        for ($x = $a->inteiro(0, 30); $x < Plano::LARGURA + 40; $x += $a->inteiro(40, 60)) {
            imageline($img, $x, $chao, $x - 30, Plano::ALTURA, $this->cor($img, $this->escurecer($piso, 0.92)));
        }
        imagefilledellipse($img, $a->inteiro(160, 240), $l3 - 12, $a->inteiro(300, 380), 40, $this->cor($img, $tapete));
        $tabua = $this->escurecer($piso, 0.82);
        $this->retangulo($img, 0, $l1, Plano::LARGURA, $l1 + 8, $tabua);
        $this->retangulo($img, 0, $l2, Plano::LARGURA, $l2 + 8, $tabua);
    }

    /** Pontinhos e riscos fracos em lugares diferentes em cada versão. */
    private function ruido(GdImage $img, Aleatorio $a, int $pontos = 900): void
    {
        for ($i = 0; $i < $pontos; $i++) {
            $x = $a->inteiro(0, Plano::LARGURA - 1);
            $y = $a->inteiro(0, Plano::ALTURA - 1);
            $rgb = imagecolorat($img, $x, $y);
            $d = $a->inteiro(-18, 18);
            imagesetpixel($img, $x, $y, $this->cor($img, [(($rgb >> 16) & 255) + $d, (($rgb >> 8) & 255) + $d, ($rgb & 255) + $d]));
        }
        for ($i = 0; $i < intdiv($pontos, 130); $i++) {
            $cor = imagecolorallocatealpha($img, $a->inteiro(60, 200), $a->inteiro(60, 200), $a->inteiro(60, 200), 112);
            imagesetthickness($img, $a->inteiro(1, 3));
            imageline($img, $a->inteiro(0, 400), $a->inteiro(0, 300), $a->inteiro(0, 400), $a->inteiro(0, 300), $cor);
        }
        imagesetthickness($img, 1);
    }

    /** Grão só nos pixels do objeto (os transparentes ficam como estão). */
    private function grao(GdImage $img, Aleatorio $a): void
    {
        imagealphablending($img, false);
        $w = imagesx($img);
        $h = imagesy($img);
        for ($i = 0, $n = intdiv($w * $h, $a->inteiro(6, 12)); $i < $n; $i++) {
            $x = $a->inteiro(0, $w - 1);
            $y = $a->inteiro(0, $h - 1);
            $rgba = imagecolorat($img, $x, $y);
            $alfa = ($rgba >> 24) & 127;
            if ($alfa > 100) {
                continue;
            }
            $d = $a->inteiro(-14, 14);
            $c = static fn (int $v): int => max(0, min(255, $v + $d));
            imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $c(($rgba >> 16) & 255), $c(($rgba >> 8) & 255), $c($rgba & 255), $alfa));
        }
        imagealphablending($img, true);
    }

    /** Uma peça colorida, girada, por cima do que já está desenhado. */
    private function peca(GdImage $img, Peca $p, int $desvioMatiz): void
    {
        $desenho = $this->desenho($p->objeto, $p->tamanho);
        $this->matiz($desenho, ($p->matiz + $desvioMatiz) % 360);
        $desenho = $this->girar($desenho, $p->giro);
        imagealphablending($img, true);
        imagecopy($img, $desenho, $p->x - intdiv(imagesx($desenho), 2), $p->y - intdiv(imagesy($desenho), 2), 0, 0, imagesx($desenho), imagesy($desenho));
    }

    /** Gira no sentido horário, com o fundo transparente. */
    private function girar(GdImage $img, int $graus): GdImage
    {
        if ($graus === 0) {
            return $img;
        }
        $girado = imagerotate($img, (float) -$graus, imagecolorallocatealpha($img, 0, 0, 0, 127));
        if ($girado === false) {
            return $img;
        }
        imagealphablending($girado, false);
        imagesavealpha($girado, true);
        return $girado;
    }

    /** Troca a cor do desenho girando o matiz (HSV), pixel a pixel, sem mexer na transparência. */
    private function matiz(GdImage $img, int $graus): void
    {
        if ($graus === 0) {
            return;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($img, $x, $y);
                $alfa = ($c >> 24) & 127;
                if ($alfa === 127) {
                    continue;
                }
                $r = (($c >> 16) & 255) / 255;
                $g = (($c >> 8) & 255) / 255;
                $b = ($c & 255) / 255;
                $max = max($r, $g, $b);
                $min = min($r, $g, $b);
                $d = $max - $min;
                if ($d < 0.02) {
                    continue; // branco, preto e cinza ficam como estão
                }
                $hue = match (true) {
                    $max === $r => fmod(($g - $b) / $d, 6),
                    $max === $g => ($b - $r) / $d + 2,
                    default => ($r - $g) / $d + 4,
                } * 60 + $graus;
                $hue = fmod($hue + 720, 360) / 60;
                $s = $d / $max;
                $v = $max;
                $i = (int) floor($hue);
                $f = $hue - $i;
                [$p, $q, $t] = [$v * (1 - $s), $v * (1 - $s * $f), $v * (1 - $s * (1 - $f))];
                [$r, $g, $b] = match ($i % 6) {
                    0 => [$v, $t, $p],
                    1 => [$q, $v, $p],
                    2 => [$p, $v, $t],
                    3 => [$p, $q, $v],
                    4 => [$t, $p, $v],
                    default => [$v, $p, $q],
                };
                imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, (int) round($r * 255), (int) round($g * 255), (int) round($b * 255), $alfa));
            }
        }
    }

    /** O desenho do objeto, quadrado de $tamanho pixels, com fundo transparente. */
    private function desenho(string $objeto, int $tamanho): GdImage
    {
        if (preg_match('/^[a-z]+$/', $objeto) !== 1) {
            throw new RuntimeException('Objeto inválido: ' . $objeto);
        }
        $original = @imagecreatefrompng($this->pastaObjetos . '/' . $objeto . '.png');
        if ($original === false) {
            throw new RuntimeException('Desenho não encontrado: ' . $objeto);
        }
        $img = imagecreatetruecolor($tamanho, $tamanho);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagecopyresampled($img, $original, 0, 0, 0, 0, $tamanho, $tamanho, imagesx($original), imagesy($original));
        return $img;
    }

    private function estrela(GdImage $img, float $cx, float $cy, int $r, array $rgb): void
    {
        $pontos = [];
        for ($i = 0; $i < 10; $i++) {
            $raio = $i % 2 === 0 ? $r : $r * 0.45;
            $ang = -M_PI / 2 + $i * M_PI / 5;
            $pontos[] = (int) round($cx + cos($ang) * $raio);
            $pontos[] = (int) round($cy + sin($ang) * $raio);
        }
        imagefilledpolygon($img, $pontos, $this->cor($img, $rgb));
    }

    /** @param array{int, int, int} $rgb */
    private function retangulo(GdImage $img, float $x1, float $y1, float $x2, float $y2, array $rgb): void
    {
        imagefilledrectangle($img, (int) $x1, (int) $y1, (int) $x2, (int) $y2, $this->cor($img, $rgb));
    }

    /** @param array<int, int|float> $rgb */
    private function cor(GdImage $img, array $rgb): int
    {
        [$r, $g, $b] = array_map(static fn ($c): int => max(0, min(255, (int) $c)), $rgb);
        return imagecolorallocate($img, $r, $g, $b);
    }

    /** @param array{int, int, int} $rgb */
    private function variar(array $rgb, Aleatorio $a, int $quanto): array
    {
        $d = $a->inteiro(-$quanto, $quanto);
        return [$rgb[0] + $d, $rgb[1] + $d, $rgb[2] + $d];
    }

    /** @param array{int, int, int} $rgb */
    private function escurecer(array $rgb, float $fator): array
    {
        return [(int) ($rgb[0] * $fator), (int) ($rgb[1] * $fator), (int) ($rgb[2] * $fator)];
    }

    private function png(GdImage $img): string
    {
        ob_start();
        imagepng($img, null, 6);
        return (string) ob_get_clean();
    }
}
