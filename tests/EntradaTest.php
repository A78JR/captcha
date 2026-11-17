<?php

declare(strict_types=1);

namespace Captcha\Testes;

use Captcha\Seguranca\BloqueioEntrada;
use Captcha\Testes\Apoio\TesteComBanco;

final class EntradaTest extends TesteComBanco
{
    public function testCadastroNovoFicaPendenteAteOWebmasterAprovar(): void
    {
        $this->pedir('POST', '/cadastro', ['nome' => 'Zé Silva', 'email' => 'ze@fora.teste', 'senha' => 'senha-forte-123', 'senha_confirmacao' => 'senha-forte-123']);
        self::assertSame('pendente', $this->pdo->query("SELECT situacao FROM captcha_usuario WHERE email = 'ze@fora.teste'")->fetchColumn());

        $token = $this->carteiro->achar('ze@fora.teste', '#/verificar-email\?token=([0-9a-f]{64})#');
        $this->pedir('POST', '/verificar-email', ['token' => (string) $token]);
        // O webmaster recebe o aviso do cadastro novo.
        self::assertStringContainsString('ze@fora.teste', $this->carteiro->ultimoPara('alfredolingoist@gmail.com')['texto']);

        $resposta = $this->entrar('ze@fora.teste');
        self::assertSame(403, $resposta->status);
        self::assertStringContainsString('aguardando a aprovação do webmaster', $resposta->corpo);
        self::assertNull($this->sessao->obter('usuario_id'));

        $id = (int) $this->pdo->query("SELECT id FROM captcha_usuario WHERE email = 'ze@fora.teste'")->fetchColumn();
        self::assertTrue($this->app->servicos()->aprovacao()->aprovar($id));
        self::assertStringContainsString('foi aprovada', $this->carteiro->ultimoPara('ze@fora.teste')['assunto']);
        self::assertSame('/conta', $this->entrar('ze@fora.teste')->cabecalho('Location'));
    }

    public function testWebmasterNasceAprovado(): void
    {
        $id = $this->criarContaSemAprovar('alfredolingoist@gmail.com');
        self::assertSame(['ativa', 1], array_values(array_map(fn ($v) => is_numeric($v) ? (int) $v : $v,
            $this->pdo->query("SELECT situacao, grupo_id FROM captcha_usuario WHERE id = {$id}")->fetch())));
    }

    public function testCadastroConfirmacaoEEntrada(): void
    {
        $this->pedir('POST', '/cadastro', ['nome' => 'Ana Souza', 'email' => 'ana@captcha.teste', 'senha' => 'senha-forte-123', 'senha_confirmacao' => 'senha-forte-123']);

        // Antes de confirmar o e-mail a senha não basta (e o link vai de novo).
        $antes = $this->entrar('ana@captcha.teste');
        self::assertSame(422, $antes->status);

        $token = $this->carteiro->achar('ana@captcha.teste', '#/verificar-email\?token=([0-9a-f]{64})#');
        self::assertSame(200, $this->pedir('GET', '/verificar-email?token=' . $token)->status);
        self::assertSame('/entrar', $this->pedir('POST', '/verificar-email', ['token' => (string) $token])->cabecalho('Location'));
        $this->app->servicos()->aprovacao()->aprovar((int) $this->pdo->query("SELECT id FROM captcha_usuario WHERE email = 'ana@captcha.teste'")->fetchColumn());

        $resposta = $this->entrar('ana@captcha.teste');
        self::assertSame('/conta', $resposta->cabecalho('Location'));
        $conta = $this->pedir('GET', '/conta');
        self::assertSame(200, $conta->status);
        self::assertStringContainsString('Ana Souza', $conta->corpo);
        self::assertStringContainsString('action="/sair"', $conta->corpo);
        self::assertStringContainsString('Minha conta', $conta->corpo);
        self::assertStringNotContainsString('Painel webmaster', $conta->corpo);
        self::assertSame(1, $this->sessao->regeneracoes(), 'A sessão troca de identificador ao entrar');
    }

    public function testCadastroComEmailQueJaTemContaRespondeIgualEAvisaODono(): void
    {
        $this->criarConta('ana@captcha.teste');
        $quantos = count($this->carteiro->enviados);

        $resposta = $this->pedir('POST', '/cadastro', ['nome' => 'Outra', 'email' => 'ana@captcha.teste', 'senha' => 'outra-senha-123', 'senha_confirmacao' => 'outra-senha-123']);

        self::assertSame(200, $resposta->status);
        self::assertStringContainsString('Confira seu e-mail', $resposta->corpo);
        self::assertCount($quantos + 1, $this->carteiro->enviados);
        self::assertStringContainsString('Tentaram criar uma conta', $this->carteiro->ultimoPara('ana@captcha.teste')['assunto']);
    }

    public function testSenhaErradaEVisitanteVaiParaEntrarEVoltaDepois(): void
    {
        $this->criarConta('ana@captcha.teste');
        self::assertSame(422, $this->entrar('ana@captcha.teste', 'errada-123456')->status);
        self::assertSame(422, $this->entrar('naoexiste@captcha.teste', 'errada-123456')->status);

        self::assertSame('/entrar', $this->pedir('GET', '/conta')->cabecalho('Location'));
        self::assertSame('/conta', $this->entrar('ana@captcha.teste')->cabecalho('Location'));
    }

    public function testOitoFalhasSeguidasBloqueiamOIp(): void
    {
        $this->criarConta('ana@captcha.teste');
        for ($i = 0; $i < BloqueioEntrada::MAXIMO_FALHAS; $i++) {
            $this->entrar('ana@captcha.teste', 'errada-' . $i . 'abc', '198.51.100.9');
        }

        $resposta = $this->entrar('ana@captcha.teste', 'senha-forte-123', '198.51.100.9');
        self::assertSame(429, $resposta->status);
        // 15 min do bloqueio; se o relógio virou de segundo entre a 8ª falha e este pedido, sobra 899.
        $espera = (int) $resposta->cabecalho('Retry-After');
        self::assertGreaterThanOrEqual(899, $espera);
        self::assertLessThanOrEqual(900, $espera);
        // Outro IP continua entrando.
        self::assertSame('/conta', $this->entrar('ana@captcha.teste', 'senha-forte-123', '198.51.100.20')->cabecalho('Location'));
        self::assertStringContainsString('ip_bloqueado_entrada', (string) file_get_contents($this->registro));
    }

    public function testIscaPreenchidaEFormularioRapidoSaoBarrados(): void
    {
        $this->criarConta('ana@captcha.teste');
        $isca = $this->pedir('POST', '/entrar', ['email' => 'ana@captcha.teste', 'senha' => 'senha-forte-123', 'site' => 'http://spam']);
        self::assertSame(422, $isca->status);

        $this->novaJanela();
        $this->pedir('POST', '/entrar', ['email' => 'ana@captcha.teste', 'senha' => 'errada-1234567']);
        $rapido = $this->pedir('POST', '/entrar', ['email' => 'ana@captcha.teste', 'senha' => 'senha-forte-123',
            '_ts' => $this->app->servicos()->guardaFormulario()->carimbo(time())]);
        self::assertSame(422, $rapido->status);
        self::assertStringContainsString('rápido demais', $rapido->corpo);
    }

    public function testFormularioSemCsrfEhRecusado(): void
    {
        $this->criarConta('ana@captcha.teste');
        $resposta = $this->pedir('POST', '/entrar', ['email' => 'ana@captcha.teste', 'senha' => 'senha-forte-123', '_csrf' => 'falso']);

        self::assertSame(303, $resposta->status);
        self::assertNull($this->sessao->obter('usuario_id'));
        self::assertStringContainsString('formulario_sem_csrf', (string) file_get_contents($this->registro));
    }

    public function testEsqueciASenhaTrocaASenhaEDerrubaAsOutrasSessoes(): void
    {
        $this->criarConta('ana@captcha.teste');
        $this->entrar('ana@captcha.teste');
        $janelaAntiga = [$this->app, $this->sessao];
        self::assertSame(200, $this->pedir('GET', '/conta')->status);

        $this->novaJanela();
        $this->pedir('POST', '/esqueci-senha', ['email' => 'ana@captcha.teste']);
        $token = $this->carteiro->achar('ana@captcha.teste', '#/nova-senha\?token=([0-9a-f]{64})#');
        self::assertNotNull($token);
        self::assertSame(200, $this->pedir('GET', '/nova-senha?token=' . $token)->status);
        $this->pedir('POST', '/nova-senha', ['token' => (string) $token, 'senha' => 'nova-senha-456', 'senha_confirmacao' => 'nova-senha-456']);
        // O link vale uma vez só.
        self::assertSame(410, $this->pedir('GET', '/nova-senha?token=' . $token)->status);

        self::assertSame(422, $this->entrar('ana@captcha.teste')->status);
        self::assertSame('/conta', $this->entrar('ana@captcha.teste', 'nova-senha-456')->cabecalho('Location'));

        [$this->app, $this->sessao] = $janelaAntiga;
        self::assertSame('/entrar', $this->pedir('GET', '/conta')->cabecalho('Location'), 'A sessão do outro aparelho caiu');
    }

    public function testTrocarSenhaEmMinhaContaMantemEstaSessao(): void
    {
        $this->criarConta('ana@captcha.teste');
        $this->entrar('ana@captcha.teste');

        $errada = $this->pedir('POST', '/conta/senha', ['senha_atual' => 'nao-e-essa-1', 'senha' => 'nova-senha-456', 'senha_confirmacao' => 'nova-senha-456']);
        self::assertSame(422, $errada->status);

        $certa = $this->pedir('POST', '/conta/senha', ['senha_atual' => 'senha-forte-123', 'senha' => 'nova-senha-456', 'senha_confirmacao' => 'nova-senha-456']);
        self::assertSame('/conta', $certa->cabecalho('Location'));
        self::assertSame(200, $this->pedir('GET', '/conta')->status);
    }

    public function testWebmasterEntraEmDuasEtapasComOCodigo(): void
    {
        $id = $this->criarConta('dono@captcha.teste', 'Dono do Captcha');
        self::assertSame(1, (int) $this->pdo->query("SELECT grupo_id FROM captcha_usuario WHERE id = {$id}")->fetchColumn(), 'Vira webmaster ao confirmar o e-mail');

        $resposta = $this->pedir('POST', '/entrar', ['email' => 'dono@captcha.teste', 'senha' => 'senha-forte-123']);
        self::assertSame('/entrar/codigo', $resposta->cabecalho('Location'));
        self::assertNull($this->sessao->obter('usuario_id'), 'A senha sozinha não abre a conta');
        self::assertStringContainsString('do•', $this->pedir('GET', '/entrar/codigo')->corpo);

        $codigo = (string) $this->carteiro->achar('dono@captcha.teste', '/código para entrar na Captcha: (\d{3})/');
        $errado = sprintf('%03d', ((int) $codigo + 1) % 1000);
        self::assertSame(422, $this->pedir('POST', '/entrar/codigo', ['codigo' => $errado])->status);
        self::assertSame('/conta', $this->pedir('POST', '/entrar/codigo', ['codigo' => $codigo])->cabecalho('Location'));
        self::assertStringContainsString('Painel webmaster', $this->pedir('GET', '/conta')->corpo);
    }

    public function testBotaoAutorizarDoEmailFazAJanelaEntrarSozinha(): void
    {
        $this->criarConta('dono@captcha.teste', 'Dono do Captcha');
        $this->pedir('POST', '/entrar', ['email' => 'dono@captcha.teste', 'senha' => 'senha-forte-123']);
        $janela = [$this->app, $this->sessao];
        self::assertSame('esperando', json_decode($this->pedir('GET', '/entrar/codigo/situacao')->corpo, true)['estado']);

        // O e-mail aberto no celular: outra sessão.
        $this->novaJanela();
        $link = (string) $this->carteiro->achar('dono@captcha.teste', '#/entrar/autorizar\?t=([0-9a-f]{64})#');
        self::assertStringContainsString('Autorizar a entrada?', $this->pedir('GET', '/entrar/autorizar?t=' . $link)->corpo);
        self::assertStringContainsString('Entrada autorizada', $this->pedir('POST', '/entrar/autorizar', ['t' => $link])->corpo);
        self::assertNull($this->sessao->obter('usuario_id'), 'O botão não abre a conta no celular');

        [$this->app, $this->sessao] = $janela;
        $situacao = json_decode($this->pedir('GET', '/entrar/codigo/situacao')->corpo, true);
        self::assertSame('pronto', $situacao['estado']);
        self::assertSame(200, $this->pedir('GET', '/painel')->status);
    }

    public function testAvisoDeEntradaDeRedeEAparelhoNovos(): void
    {
        $this->criarConta('ana@captcha.teste');
        $this->entrar('ana@captcha.teste', 'senha-forte-123', '203.0.113.7');
        $this->novaJanela();
        $this->entrar('ana@captcha.teste', 'senha-forte-123', '203.0.113.50');
        self::assertStringNotContainsString('foi você', $this->carteiro->ultimoPara('ana@captcha.teste')['assunto']);

        $this->novaJanela();
        $this->app->atender(new \Captcha\Nucleo\Requisicao('GET', '/'));
        $resposta = $this->pedir('POST', '/entrar', ['email' => 'ana@captcha.teste', 'senha' => 'senha-forte-123'], '192.0.2.80',
            ['user-agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1']);
        self::assertSame('/conta', $resposta->cabecalho('Location'));
        self::assertStringContainsString('foi você que entrou', $this->carteiro->ultimoPara('ana@captcha.teste')['assunto']);
    }

    private function criarContaSemAprovar(string $email): int
    {
        $this->pedir('POST', '/cadastro', ['nome' => 'Alfredo', 'email' => $email, 'senha' => 'senha-forte-123', 'senha_confirmacao' => 'senha-forte-123']);
        $this->pedir('POST', '/verificar-email', ['token' => (string) $this->carteiro->achar($email, '#/verificar-email\?token=([0-9a-f]{64})#')]);
        return (int) $this->pdo->query('SELECT id FROM captcha_usuario WHERE email = ' . $this->pdo->quote($email))->fetchColumn();
    }
}
