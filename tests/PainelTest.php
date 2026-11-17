<?php

declare(strict_types=1);

namespace Captcha\Testes;

use Captcha\Testes\Apoio\TesteComBanco;

final class PainelTest extends TesteComBanco
{
    private function webmaster(): int
    {
        $id = $this->criarConta('dono@captcha.teste', 'Dono do Captcha');
        $this->entrar('dono@captcha.teste');
        return $id;
    }

    public function testPainelNaoExisteParaVisitanteNemParaContaComum(): void
    {
        self::assertSame(404, $this->pedir('GET', '/painel')->status);
        self::assertSame(404, $this->pedir('GET', '/painel/usuarios')->status);

        $this->criarConta('ana@captcha.teste');
        $this->entrar('ana@captcha.teste');
        self::assertSame(404, $this->pedir('GET', '/painel')->status);
        self::assertSame(404, $this->pedir('POST', '/painel/usuarios/1/banir', ['motivo' => 'qualquer coisa'])->status);
    }

    public function testWebmasterVeTodasAsAbasEOsQuadros(): void
    {
        $this->webmaster();
        $painel = $this->pedir('GET', '/painel');

        self::assertSame(200, $painel->status);
        foreach (['Visão geral', 'Equipe', 'Sistema', 'Usuários', 'Contas novas em 7 dias', 'IPs bloqueados'] as $texto) {
            self::assertStringContainsString($texto, $painel->corpo);
        }
        // Abas: Visão geral primeiro, as outras em ordem alfabética.
        preg_match_all('#<nav class="abas".*?</nav>#s', $painel->corpo, $nav);
        preg_match_all('#<span>([^<]+)</span>#', $nav[0][0], $abas);
        self::assertSame(['Visão geral', 'Captcha', 'Equipe', 'Sistema', 'Usuários'], $abas[1]);
        // No menu lateral, o Painel webmaster acende também nas abas.
        self::assertMatchesRegularExpression('#href="/painel"[^>]*aria-current="page"#', $this->pedir('GET', '/painel/usuarios')->corpo);
        self::assertSame(200, $this->pedir('GET', '/painel/sistema')->status);
        self::assertSame(200, $this->pedir('GET', '/painel/equipe')->status);
    }

    public function testConvidadoDaEquipeSeCadastraComCadastroFechadoEViraAssistente(): void
    {
        $this->webmaster();
        $this->pedir('POST', '/painel/equipe/convidar', ['email' => 'carla@captcha.teste']);
        self::assertStringContainsString('/cadastro?email=carla%40captcha.teste', $this->carteiro->ultimoPara('carla@captcha.teste')['texto']);

        $this->novaJanela();
        $id = $this->criarConta('carla@captcha.teste', 'Carla');
        self::assertSame(2, (int) $this->pdo->query("SELECT grupo_id FROM captcha_usuario WHERE id = {$id}")->fetchColumn());

        // Assistente entra em duas etapas, abre o painel e os usuários, mas não a equipe nem o sistema.
        self::assertSame('/entrar/codigo', $this->pedir('POST', '/entrar', ['email' => 'carla@captcha.teste', 'senha' => 'senha-forte-123'])->cabecalho('Location'));
        $this->novaJanela();
        $this->entrar('carla@captcha.teste');
        self::assertSame(200, $this->pedir('GET', '/painel')->status);
        self::assertSame(200, $this->pedir('GET', '/painel/usuarios')->status);
        self::assertSame(404, $this->pedir('GET', '/painel/equipe')->status);
        self::assertSame(404, $this->pedir('GET', '/painel/sistema')->status);
    }

    public function testTravasDasAcoesSobreContas(): void
    {
        $dono = $this->webmaster();
        $janelaDono = [$this->app, $this->sessao];
        $this->novaJanela();
        $ana = $this->criarConta('ana@captcha.teste');
        $this->entrar('ana@captcha.teste');
        $janelaAna = [$this->app, $this->sessao];

        [$this->app, $this->sessao] = $janelaDono;
        $this->pedir('POST', "/painel/usuarios/{$ana}/suspender", ['motivo' => 'oi']);
        self::assertSame('Escreva o motivo (pelo menos 5 letras).', $this->sessao->aviso('erro'));

        $this->pedir('POST', "/painel/usuarios/{$dono}/banir", ['motivo' => 'teste de trava']);
        self::assertSame('Ninguém age sobre a própria conta por aqui.', $this->sessao->aviso('erro'));

        $this->pedir('POST', "/painel/usuarios/{$ana}/suspender", ['motivo' => 'conta de teste suspensa']);
        self::assertSame('Conta suspensa e desconectada de todos os aparelhos.', $this->sessao->aviso('sucesso'));
        self::assertSame('suspensa', $this->pdo->query("SELECT situacao FROM captcha_usuario WHERE id = {$ana}")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM captcha_registro_painel WHERE alvo_id = {$ana} AND acao = 'suspender' AND autor_id = {$dono}")->fetchColumn());

        [$this->app, $this->sessao] = $janelaAna;
        self::assertSame('/entrar', $this->pedir('GET', '/conta')->cabecalho('Location'), 'Suspensa: desconectada');

        [$this->app, $this->sessao] = $janelaDono;
        $this->pedir('POST', "/painel/usuarios/{$ana}/reativar", ['motivo' => 'tudo certo agora']);
        self::assertSame('Conta reativada.', $this->sessao->aviso('sucesso'));
        $this->pedir('POST', "/painel/usuarios/{$ana}/senha");
        self::assertNotNull($this->carteiro->achar('ana@captcha.teste', '#/nova-senha\?token=([0-9a-f]{64})#'));
    }

    public function testWebmasterAprovaERecusaCadastrosNovos(): void
    {
        $this->webmaster();
        $janelaDono = [$this->app, $this->sessao];
        $this->novaJanela();
        foreach (['ana@captcha.teste' => 'Ana Souza', 'bia@captcha.teste' => 'Bia Lima'] as $email => $nome) {
            $this->pedir('POST', '/cadastro', ['nome' => $nome, 'email' => $email, 'senha' => 'senha-forte-123', 'senha_confirmacao' => 'senha-forte-123']);
            $this->pedir('POST', '/verificar-email', ['token' => (string) $this->carteiro->achar($email, '#/verificar-email\?token=([0-9a-f]{64})#')]);
        }
        $ana = (int) $this->pdo->query("SELECT id FROM captcha_usuario WHERE email = 'ana@captcha.teste'")->fetchColumn();
        $bia = (int) $this->pdo->query("SELECT id FROM captcha_usuario WHERE email = 'bia@captcha.teste'")->fetchColumn();

        [$this->app, $this->sessao] = $janelaDono;
        $lista = $this->pedir('GET', '/painel/usuarios?situacao=pendente');
        self::assertStringContainsString('aguardando aprovação', $lista->corpo);
        self::assertStringContainsString("/painel/usuarios/{$ana}/aprovar", $lista->corpo);
        self::assertStringContainsString('Cadastros aguardando aprovação', $this->pedir('GET', '/painel')->corpo);

        $this->pedir('POST', "/painel/usuarios/{$ana}/suspender", ['motivo' => 'antes de aprovar']);
        self::assertSame('Conta aguardando aprovação: aprove ou recuse primeiro.', $this->sessao->aviso('erro'));

        $this->pedir('POST', "/painel/usuarios/{$ana}/aprovar");
        self::assertSame('Conta aprovada. A pessoa recebeu um e-mail avisando.', $this->sessao->aviso('sucesso'));
        self::assertSame('ativa', $this->pdo->query("SELECT situacao FROM captcha_usuario WHERE id = {$ana}")->fetchColumn());
        $this->pedir('POST', "/painel/usuarios/{$ana}/aprovar");
        self::assertSame('Esta conta não está aguardando aprovação.', $this->sessao->aviso('erro'));

        $this->pedir('POST', "/painel/usuarios/{$bia}/recusar");
        self::assertSame('excluida', $this->pdo->query("SELECT situacao FROM captcha_usuario WHERE id = {$bia}")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM captcha_registro_painel WHERE alvo_id = {$bia} AND acao = 'recusar'")->fetchColumn());

        $this->novaJanela();
        self::assertSame('/conta', $this->entrar('ana@captcha.teste')->cabecalho('Location'));
        self::assertSame(422, $this->entrar('bia@captcha.teste')->status);
    }

    public function testAssistenteNaoAgeSobreAEquipeNemTrocaGrupo(): void
    {
        $dono = $this->webmaster();
        $this->pedir('POST', '/painel/equipe/convidar', ['email' => 'carla@captcha.teste']);
        $this->novaJanela();
        $this->criarConta('carla@captcha.teste', 'Carla');
        $bia = $this->criarConta('bia@captcha.teste', 'Bia');
        $this->entrar('carla@captcha.teste');

        $this->pedir('POST', "/painel/usuarios/{$dono}/suspender", ['motivo' => 'tentando derrubar o dono']);
        self::assertNotNull($this->sessao->aviso('erro'));
        self::assertSame('ativa', $this->pdo->query("SELECT situacao FROM captcha_usuario WHERE id = {$dono}")->fetchColumn());

        $this->pedir('POST', "/painel/usuarios/{$bia}/grupo", ['grupo' => '3']);
        self::assertSame('Só o webmaster muda o grupo de uma conta.', $this->sessao->aviso('erro'));
    }

    public function testTirarAssistenteDaEquipe(): void
    {
        $this->webmaster();
        $janelaDono = [$this->app, $this->sessao];
        $this->pedir('POST', '/painel/equipe/convidar', ['email' => 'carla@captcha.teste']);
        $this->novaJanela();
        $carla = $this->criarConta('carla@captcha.teste', 'Carla');

        [$this->app, $this->sessao] = $janelaDono;
        $this->pedir('POST', "/painel/equipe/{$carla}/remover");
        self::assertSame('A pessoa saiu da equipe.', $this->sessao->aviso('sucesso'));
        self::assertSame(3, (int) $this->pdo->query("SELECT grupo_id FROM captcha_usuario WHERE id = {$carla}")->fetchColumn());
    }

    public function testMigrarSincronizaOsWebmasters(): void
    {
        $this->configExtra = ['webmasters' => []];
        $this->novaJanela();
        $ana = $this->criarConta('ana@captcha.teste');
        $alfredo = $this->criarConta('alfredolingoist@gmail.com');

        $resposta = $this->pedir('POST', '/sistema/migrar', [], '203.0.113.7', ['x-captcha-token' => 'segredo']);
        self::assertSame(200, $resposta->status);
        // A conta 1 não vira webmaster sozinha: só o login do Alfredo (e os extras de 'webmasters').
        self::assertSame(3, (int) $this->pdo->query("SELECT grupo_id FROM captcha_usuario WHERE id = {$ana}")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT grupo_id FROM captcha_usuario WHERE id = {$alfredo}")->fetchColumn());
    }
}
