<?php

declare(strict_types=1);

namespace Captcha\Painel;

use Captcha\Interface\ContextoMenu;
use Captcha\Interface\MenuPainel;
use Captcha\Nucleo\Requisicao;
use Captcha\Nucleo\Resposta;
use Captcha\Nucleo\Roteador;
use Captcha\Nucleo\Servicos;

/** Painel > Captcha: desafios por site (emitidos, resolvidos, falhos e por quê) e os sites ligados. */
final class EstatisticasControlador
{
    public const PERMISSAO = 'captcha.estatisticas';

    /** Períodos que a tela oferece, em dias. */
    public const PERIODOS = [1, 7, 30];

    public function __construct(private readonly Servicos $s)
    {
    }

    public function registrar(Roteador $r): void
    {
        $r->get('/painel/captcha', fn (Requisicao $q): Resposta => $this->tela($q), [$this->s->guardas()->pode(self::PERMISSAO, true)]);
    }

    private function tela(Requisicao $q): Resposta
    {
        $dias = (int) $q->entrada('dias');
        $dias = in_array($dias, self::PERIODOS, true) ? $dias : 7;
        $usuario = $this->s->autenticacao()->usuario();
        $abas = MenuPainel::itens(new ContextoMenu($usuario, $this->s->permissoes()));
        $abasHtml = $this->s->visao()->parcial('parciais/abas_painel', ['abas' => $abas, 'abaAtual' => MenuPainel::atual($abas, $q->caminho)]);
        return $this->s->pagina()->html($q, 'painel/captcha', [
            'abasHtml' => $abasHtml,
            'dias' => $dias,
            'linhas' => $this->s->desafios()->estatisticas($dias),
            'sites' => $this->s->sites()->todos(),
        ], 'Captcha');
    }
}
