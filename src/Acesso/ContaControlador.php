<?php

declare(strict_types=1);

namespace Captcha\Acesso;

use Captcha\Nucleo\Requisicao;
use Captcha\Nucleo\Resposta;
use Captcha\Nucleo\Roteador;
use Captcha\Nucleo\Servicos;

/** Minha conta: os dados, a troca de senha, a entrada em duas etapas e as últimas entradas na conta. */
final class ContaControlador
{
    public function __construct(private readonly Servicos $s)
    {
    }

    public function registrar(Roteador $r): void
    {
        $g = $this->s->guardas();
        $r->get('/conta', fn (Requisicao $q): Resposta => $this->conta($q), [$g->logado()]);
        $r->post('/conta/senha', fn (Requisicao $q): Resposta => $this->trocarSenha($q), [$g->logado(), $g->csrf(), $g->limitar('trocar-senha', 10, 3600)]);
        $r->post('/conta/duas-etapas', fn (Requisicao $q): Resposta => $this->duasEtapas($q), [$g->logado(), $g->csrf(), $g->limitar('duas-etapas', 10, 3600)]);
    }

    private function conta(Requisicao $q, array $erros = [], int $status = 200): Resposta
    {
        $usuario = $this->usuario();
        return $this->s->pagina()->html($q, 'acesso/conta', [
            'conta' => $usuario,
            'grupo' => $this->s->permissoes()->nomeDoGrupo($usuario->grupoId),
            'duasObrigatoria' => $this->s->duasEtapas()->obrigatoria($usuario),
            'duasLigada' => $this->s->duasEtapas()->exigida($usuario),
            'entradas' => $this->s->acessos()->entradas($usuario->id, 10),
            'erros' => $erros,
        ], 'Minha conta', $status);
    }

    private function trocarSenha(Requisicao $q): Resposta
    {
        $usuario = $this->usuario();
        $nova = $q->senha('senha');
        if ($nova !== $q->senha('senha_confirmacao')) {
            return $this->conta($q, ['senha_confirmacao' => 'As senhas não conferem.'], 422);
        }
        $erros = $this->s->senhas()->trocar($usuario, $q->senha('senha_atual'), $nova);
        if ($erros !== []) {
            return $this->conta($q, $erros, 422);
        }
        // A troca derruba todas as sessões; esta continua aberta, já com o número novo.
        $this->s->autenticacao()->entrarPorId($usuario->id, $q);
        $this->s->sessao()->avisar('sucesso', 'Senha trocada. Os outros aparelhos foram desconectados.');
        return Resposta::redirecionar('/conta');
    }

    private function duasEtapas(Requisicao $q): Resposta
    {
        $usuario = $this->usuario();
        $ligar = $q->entrada('ligar') === '1';
        $erro = $this->s->duasEtapas()->definir($usuario, $ligar, $this->s->autenticacao()->senhaConfere($usuario, $q->senha('senha')));
        if ($erro !== null) {
            return $this->conta($q, ['duas_etapas' => $erro], 422);
        }
        $this->s->autenticacao()->esquecerCache();
        $this->s->sessao()->avisar('sucesso', $ligar ? 'Entrada em duas etapas ligada.' : 'Entrada em duas etapas desligada.');
        return Resposta::redirecionar('/conta');
    }

    private function usuario(): Usuario
    {
        $usuario = $this->s->autenticacao()->usuario();
        if ($usuario === null) {
            throw new \LogicException('Rota de Minha conta sem a guarda logado()');
        }
        return $usuario;
    }
}
