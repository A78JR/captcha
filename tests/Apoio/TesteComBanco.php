<?php

declare(strict_types=1);

namespace Captcha\Testes\Apoio;

use Captcha\Nucleo\Aplicacao;
use Captcha\Nucleo\Config;
use Captcha\Nucleo\Migrador;
use Captcha\Nucleo\Requisicao;
use Captcha\Nucleo\Resposta;
use Captcha\Nucleo\SessaoMemoria;
use Captcha\Seguranca\Csrf;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Base dos testes que usam MySQL de verdade. O banco vem das variáveis CAPTCHA_TESTE_HOST, CAPTCHA_TESTE_BANCO,
 * CAPTCHA_TESTE_USUARIO e CAPTCHA_TESTE_SENHA (padrão: localhost, captcha_teste, captcha, captcha). Sem banco, os testes
 * são pulados. Cada teste começa com o banco vazio e todas as migrações aplicadas.
 */
abstract class TesteComBanco extends TestCase
{
    protected CarteiroMemoria $carteiro;
    protected SessaoMemoria $sessao;
    protected Aplicacao $app;
    protected PDO $pdo;
    protected string $registro = '';

    /** @var array<string, mixed> */
    protected array $configExtra = [];

    protected function setUp(): void
    {
        $banco = self::banco();
        try {
            $this->pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $banco['host'], $banco['nome']), $banco['usuario'], $banco['senha'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $erro) {
            self::markTestSkipped('Sem MySQL para os testes: ' . $erro->getMessage());
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        // Só as tabelas do Captcha: o banco pode ser dividido com outros projetos.
        foreach ($this->pdo->query("SHOW TABLES LIKE 'captcha\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $tabela) {
            $this->pdo->exec("DROP TABLE `{$tabela}`");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->pdo->exec("SET time_zone = '+00:00'");
        (new Migrador($this->pdo, dirname(__DIR__, 2) . '/db/migracoes'))->migrar();
        $this->registro = sys_get_temp_dir() . '/captcha-seguranca-' . getmypid() . '.log';
        @unlink($this->registro);
        $this->novaJanela();
    }

    protected function tearDown(): void
    {
        // Sem MySQL o setUp pula o teste antes de criar o registro: não há o que apagar.
        if ($this->registro !== '') {
            @unlink($this->registro);
        }
    }

    /** @return array{host: string, nome: string, usuario: string, senha: string} */
    protected static function banco(): array
    {
        return [
            'host' => getenv('CAPTCHA_TESTE_HOST') ?: 'localhost',
            'nome' => getenv('CAPTCHA_TESTE_BANCO') ?: 'captcha_teste',
            'usuario' => getenv('CAPTCHA_TESTE_USUARIO') ?: 'captcha',
            'senha' => getenv('CAPTCHA_TESTE_SENHA') !== false ? (string) getenv('CAPTCHA_TESTE_SENHA') : 'captcha',
        ];
    }

    /** @return array<string, mixed> */
    protected function config(): array
    {
        $banco = self::banco();
        return array_replace([
            'url' => 'https://captcha.teste',
            'chave_app' => str_repeat('k', 40),
            'banco' => ['host' => $banco['host'], 'nome' => $banco['nome'], 'usuario' => $banco['usuario'], 'senha' => $banco['senha']],
            'webmasters' => ['dono@captcha.teste'],
            'registro_seguranca' => $this->registro,
            'migrar_token' => 'segredo',
        ], $this->configExtra);
    }

    /** Outro navegador (sessão nova), com o mesmo banco e a mesma caixa de e-mails. */
    protected function novaJanela(): void
    {
        $this->carteiro ??= new CarteiroMemoria();
        $this->sessao = new SessaoMemoria();
        $this->app = Aplicacao::criar(dirname(__DIR__, 2), new Config($this->config()), $this->sessao, $this->carteiro);
    }

    /**
     * Pedido como o navegador faria: nos POST vão o CSRF desta sessão e o tempo assinado (com 10 segundos).
     *
     * @param array<string, string> $corpo
     */
    protected function pedir(string $metodo, string $caminho, array $corpo = [], string $ip = '203.0.113.7', array $cabecalhos = []): Resposta
    {
        $consulta = [];
        if (str_contains($caminho, '?')) {
            [$caminho, $texto] = explode('?', $caminho, 2);
            parse_str($texto, $consulta);
        }
        if ($metodo === 'POST') {
            $corpo += [
                Csrf::CAMPO => $this->app->servicos()->csrf()->token(),
                '_ts' => $this->app->servicos()->guardaFormulario()->carimbo(time() - 10),
            ];
        }
        $cabecalhos += ['user-agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0'];
        return $this->app->atender(new Requisicao($metodo, $caminho, $consulta, $corpo, $cabecalhos, $ip, true));
    }

    /** Cria a conta pelo cadastro, confirma o e-mail pelo link e aprova a conta (como o webmaster). Devolve o número da conta. */
    protected function criarConta(string $email, string $nome = 'Ana Souza', string $senha = 'senha-forte-123'): int
    {
        $this->pedir('POST', '/cadastro', ['nome' => $nome, 'email' => $email, 'senha' => $senha, 'senha_confirmacao' => $senha]);
        $token = $this->carteiro->achar($email, '#/verificar-email\?token=([0-9a-f]{64})#');
        self::assertNotNull($token, 'O link de confirmação não chegou para ' . $email);
        $this->pedir('POST', '/verificar-email', ['token' => $token]);
        $id = (int) $this->pdo->query('SELECT id FROM captcha_usuario WHERE email = ' . $this->pdo->quote($email))->fetchColumn();
        $this->app->servicos()->aprovacao()->aprovar($id);
        return $id;
    }

    /** Entra com e-mail e senha (e, se pedir, com o código do e-mail). */
    protected function entrar(string $email, string $senha = 'senha-forte-123', string $ip = '203.0.113.7'): Resposta
    {
        $resposta = $this->pedir('POST', '/entrar', ['email' => $email, 'senha' => $senha], $ip);
        if ($resposta->cabecalho('Location') === '/entrar/codigo') {
            $codigo = $this->carteiro->achar($email, '/código para entrar na Captcha: (\d{3})/');
            $resposta = $this->pedir('POST', '/entrar/codigo', ['codigo' => (string) $codigo], $ip);
        }
        return $resposta;
    }
}
