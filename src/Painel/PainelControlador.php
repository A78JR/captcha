<?php

declare(strict_types=1);

namespace Captcha\Painel;

use Captcha\Acesso\Usuario;
use Captcha\Interface\ContextoMenu;
use Captcha\Interface\MenuPainel;
use Captcha\Nucleo\Migrador;
use Captcha\Nucleo\Requisicao;
use Captcha\Nucleo\Resposta;
use Captcha\Nucleo\Roteador;
use Captcha\Nucleo\Servicos;
use Captcha\Seguranca\ChaveApp;
use Throwable;

/**
 * Painel webmaster: Visão geral, Sistema, Equipe e Usuários. Para quem não tem a permissão de uma tela,
 * ela "não existe" (404), como no Bazar de Tudo.
 */
final class PainelControlador
{
    public function __construct(private readonly Servicos $s)
    {
    }

    public function registrar(Roteador $r): void
    {
        $g = $this->s->guardas();
        $painel = $g->pode(PainelWebmaster::PERMISSAO, true);
        $sistema = $g->pode('painel.sistema', true);
        $equipe = $g->pode(Equipe::PERMISSAO, true);
        $usuarios = $g->pode(AdminUsuarios::PERMISSAO, true);
        $csrf = $g->csrf();

        $r->get('/painel', fn (Requisicao $q): Resposta => $this->visaoGeral($q), [$painel]);
        $r->get('/painel/sistema', fn (Requisicao $q): Resposta => $this->sistema($q), [$sistema]);
        $r->post('/painel/sistema/email-teste', fn (Requisicao $q): Resposta => $this->emailTeste(), [$sistema, $csrf, $g->limitar('email-teste', 5, 3600)]);
        $r->get('/painel/equipe', fn (Requisicao $q): Resposta => $this->equipe($q), [$equipe]);
        $r->post('/painel/equipe/convidar', fn (Requisicao $q): Resposta => $this->convidar($q), [$equipe, $csrf]);
        $r->post('/painel/equipe/{id}/remover', fn (Requisicao $q, array $p): Resposta => $this->remover((string) $p['id']), [$equipe, $csrf]);
        $r->post('/painel/equipe/convite/{id}/revogar', fn (Requisicao $q, array $p): Resposta => $this->revogar((string) $p['id']), [$equipe, $csrf]);
        $r->get('/painel/usuarios', fn (Requisicao $q): Resposta => $this->usuarios($q), [$usuarios]);
        $r->post('/painel/usuarios/{id}/{acao}', fn (Requisicao $q, array $p): Resposta => $this->agir($q, (string) $p['id'], (string) $p['acao']), [$usuarios, $csrf]);
    }

    /** @param array<string, mixed> $dados */
    private function tela(Requisicao $q, string $template, string $titulo, array $dados = []): Resposta
    {
        $usuario = $this->equipeLogada();
        $abas = MenuPainel::itens(new ContextoMenu($usuario, $this->s->permissoes()));
        $abasHtml = $this->s->visao()->parcial('parciais/abas_painel', ['abas' => $abas, 'abaAtual' => MenuPainel::atual($abas, $q->caminho)]);
        return $this->s->pagina()->html($q, $template, $dados + ['abasHtml' => $abasHtml], $titulo);
    }

    private function visaoGeral(Requisicao $q): Resposta
    {
        return $this->tela($q, 'painel/visao_geral', 'Painel webmaster', ['quadros' => $this->s->painel()->quadros($this->equipeLogada())]);
    }

    private function sistema(Requisicao $q): Resposta
    {
        $migrador = new Migrador($this->s->pdo(), $this->s->raiz() . '/db/migracoes');
        try {
            $feitas = $this->s->pdo()->query('SELECT arquivo FROM captcha_migracao')->fetchAll(\PDO::FETCH_COLUMN);
        } catch (Throwable) {
            $feitas = [];
        }
        $config = $this->s->config();
        $conferencias = [
            'Chave do Captcha (chave_app)' => strlen((string) $config->obter('chave_app', '')) >= ChaveApp::MINIMO,
            'Endereço do site (url)' => str_starts_with((string) $config->obter('url', ''), 'https://'),
            'Remetente dos e-mails (email_remetente)' => filter_var((string) $config->obter('email_remetente', ''), FILTER_VALIDATE_EMAIL) !== false,
            'Entrada em duas etapas ligada' => $config->obter('duas_etapas', true) !== false,
            'Cadastros fechados (só convidados)' => $config->obter('cadastro.aberto', false) !== true,
        ];
        return $this->tela($q, 'painel/sistema', 'Sistema', [
            'php' => PHP_VERSION,
            'feitas' => $feitas,
            'pendentes' => $migrador->pendentes($feitas),
            'conferencias' => $conferencias,
            'eventos' => $this->s->registroSeguranca()->ultimos(40),
            'acoes' => $this->s->registroPainel()->ultimas(20),
        ]);
    }

    private function emailTeste(): Resposta
    {
        $usuario = $this->equipeLogada();
        $saiu = $this->s->carteiro()->enviar($usuario->email, 'Teste de envio do Captcha',
            "Olá!\n\nEste é o e-mail de teste pedido no Painel webmaster > Sistema.\n"
            . 'Se ele chegou, os e-mails do Captcha (confirmação de cadastro, senha nova, código de entrada) estão saindo.');
        $this->s->sessao()->avisar($saiu ? 'sucesso' : 'erro', $saiu
            ? "E-mail de teste enviado para {$usuario->email}. Se não chegar em alguns minutos, olhe a caixa de spam."
            : 'O servidor recusou o envio. Confira o email_remetente no config.local.php e o envio de e-mail da hospedagem.');
        return Resposta::redirecionar('/painel/sistema');
    }

    private function equipe(Requisicao $q): Resposta
    {
        return $this->tela($q, 'painel/equipe', 'Equipe', [
            'membros' => $this->s->equipe()->membros(),
            'convites' => $this->s->equipe()->convitesAbertos(),
        ]);
    }

    private function convidar(Requisicao $q): Resposta
    {
        $resultado = $this->s->equipe()->convidar($q->entrada('email'), $this->equipeLogada()->id);
        [$tipo, $mensagem] = match ($resultado) {
            'entrou' => ['sucesso', 'A conta já existia e entrou na equipe agora.'],
            'convidado' => ['sucesso', 'Convite enviado. A pessoa entra na equipe ao confirmar o e-mail.'],
            default => ['erro', $resultado],
        };
        $this->s->sessao()->avisar($tipo, $mensagem);
        return Resposta::redirecionar('/painel/equipe');
    }

    private function remover(string $id): Resposta
    {
        $ok = ctype_digit($id) && $this->s->equipe()->remover((int) $id, $this->equipeLogada()->id);
        $this->s->sessao()->avisar($ok ? 'sucesso' : 'erro', $ok ? 'A pessoa saiu da equipe.' : 'Só assistentes saem da equipe por aqui.');
        return Resposta::redirecionar('/painel/equipe');
    }

    private function revogar(string $id): Resposta
    {
        $ok = ctype_digit($id) && $this->s->equipe()->revogar((int) $id);
        $this->s->sessao()->avisar($ok ? 'sucesso' : 'erro', $ok ? 'Convite cancelado.' : 'Este convite já não estava aberto.');
        return Resposta::redirecionar('/painel/equipe');
    }

    private function usuarios(Requisicao $q): Resposta
    {
        $admin = $this->s->adminUsuarios();
        $filtros = ['q' => $q->entrada('q'), 'grupo' => $q->entrada('grupo'), 'situacao' => $q->entrada('situacao')];
        $pagina = ctype_digit($q->entrada('pagina')) ? (int) $q->entrada('pagina') : 1;
        $equipe = $this->equipeLogada();
        return $this->tela($q, 'painel/usuarios', 'Usuários', [
            'contagens' => $admin->contagens(),
            'grupos' => $this->s->permissoes()->grupos(),
            'resultado' => $admin->buscar($filtros, $pagina),
            'filtros' => $filtros,
            'podeGrupos' => $this->s->permissoes()->pode($equipe, AdminUsuarios::PERMISSAO_GRUPOS),
            'equipe' => $equipe,
        ]);
    }

    private function agir(Requisicao $q, string $id, string $acao): Resposta
    {
        [$ok, $mensagem] = $this->s->adminUsuarios()->agir(
            $this->equipeLogada(), ctype_digit($id) ? (int) $id : 0, $acao, $q->entrada('motivo'), $q->entrada('grupo'),
        );
        $this->s->sessao()->avisar($ok ? 'sucesso' : 'erro', $mensagem);
        $volta = http_build_query(array_filter([
            'q' => $q->entrada('f_q'), 'grupo' => $q->entrada('f_grupo'), 'situacao' => $q->entrada('f_situacao'), 'pagina' => $q->entrada('f_pagina'),
        ], static fn (string $v): bool => $v !== ''));
        return Resposta::redirecionar('/painel/usuarios' . ($volta !== '' ? '?' . $volta : '') . (ctype_digit($id) ? '#conta-' . $id : ''));
    }

    private function equipeLogada(): Usuario
    {
        $usuario = $this->s->autenticacao()->usuario();
        if ($usuario === null) {
            throw new \LogicException('Rota do Painel sem a guarda pode()');
        }
        return $usuario;
    }
}
