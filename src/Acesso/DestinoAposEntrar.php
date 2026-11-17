<?php

declare(strict_types=1);

namespace Captcha\Acesso;

use Captcha\Nucleo\Resposta;
use Captcha\Nucleo\Sessao;

/**
 * Para onde voltar logo depois de entrar. Guardado quando um visitante pede uma página que exige conta
 * (Guardas::logado): /painel/usuarios volta para /painel/usuarios. Só caminhos deste site; as próprias
 * telas de entrar e sair nunca são destino.
 */
final class DestinoAposEntrar
{
    private const CHAVE = 'apos_entrar';
    private const NUNCA = ['/entrar', '/sair', '/cadastro', '/esqueci-senha', '/nova-senha', '/verificar-email'];

    public function __construct(private readonly Sessao $sessao)
    {
    }

    public function lembrar(string $destino): void
    {
        $limpo = self::limpar($destino);
        if ($limpo !== null) {
            $this->sessao->definir(self::CHAVE, $limpo);
        }
    }

    /** Devolve o destino guardado (e apaga), ou null. */
    public function tirar(): ?string
    {
        $destino = $this->sessao->obter(self::CHAVE);
        $this->sessao->esquecer(self::CHAVE);
        return is_string($destino) ? self::limpar($destino) : null;
    }

    public static function limpar(string $destino): ?string
    {
        if (strlen($destino) > 500 || !Resposta::caminhoInterno($destino)) {
            return null;
        }
        $caminho = rtrim((string) parse_url($destino, PHP_URL_PATH), '/') ?: '/';
        $consulta = (string) parse_url($destino, PHP_URL_QUERY);
        foreach (self::NUNCA as $nunca) {
            if ($caminho === $nunca || str_starts_with($caminho, $nunca . '/')) {
                return null;
            }
        }
        return $caminho . ($consulta !== '' ? '?' . $consulta : '');
    }
}
