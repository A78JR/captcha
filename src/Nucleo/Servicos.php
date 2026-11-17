<?php

declare(strict_types=1);

namespace Captcha\Nucleo;

use Closure;
use Captcha\Acesso\AprovacaoCadastro;
use Captcha\Acesso\AvisoEntrada;
use Captcha\Desafio\Assinatura;
use Captcha\Desafio\Regras;
use Captcha\Desafio\RepositorioDesafios;
use Captcha\Desafio\RepositorioSites;
use Captcha\Acesso\DestinoAposEntrar;
use Captcha\Acesso\EntradaDuasEtapas;
use Captcha\Acesso\Guardas;
use Captcha\Acesso\Permissoes;
use Captcha\Acesso\PortaCadastro;
use Captcha\Acesso\RegistroAcessos;
use Captcha\Acesso\ServicoAutenticacao;
use Captcha\Acesso\ServicoSenha;
use Captcha\Acesso\Webmasters;
use Captcha\Interface\Pagina;
use Captcha\Painel\AdminUsuarios;
use Captcha\Painel\Equipe;
use Captcha\Painel\PainelWebmaster;
use Captcha\Painel\RegistroPainel;
use Captcha\Seguranca\BloqueioEntrada;
use Captcha\Seguranca\ChaveApp;
use Captcha\Seguranca\Csrf;
use Captcha\Seguranca\GuardaFormulario;
use Captcha\Seguranca\GuardaSondagem;
use Captcha\Seguranca\LimiteTaxa;
use Captcha\Seguranca\RegistroSeguranca;
use PDO;

/**
 * Onde as peças do Captcha são montadas, uma vez por requisição e só quando alguém pede: a página inicial
 * não abre o banco, e /sistema/saude não abre a sessão.
 */
final class Servicos
{
    /** @var array<string, object> */
    private array $prontos = [];

    public function __construct(
        private readonly string $raiz,
        private readonly Config $config,
        private readonly Sessao $sessao,
        private readonly Carteiro $carteiro,
        private readonly Conexao $conexao,
    ) {
    }

    public function raiz(): string
    {
        return $this->raiz;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function sessao(): Sessao
    {
        return $this->sessao;
    }

    public function carteiro(): Carteiro
    {
        return $this->carteiro;
    }

    public function conexao(): Conexao
    {
        return $this->conexao;
    }

    public function pdo(): PDO
    {
        return $this->conexao->pdo();
    }

    /** Endereço do site nos e-mails ('url' no config.local.php). */
    public function urlBase(): string
    {
        return rtrim((string) $this->config->obter('url', 'http://localhost:8000'), '/');
    }

    public function visao(): Visao
    {
        return $this->unico('visao', fn (): Visao => new Visao($this->raiz . '/views'));
    }

    public function pagina(): Pagina
    {
        return $this->unico('pagina', fn (): Pagina => new Pagina($this));
    }

    public function guardas(): Guardas
    {
        return $this->unico('guardas', fn (): Guardas => new Guardas($this));
    }

    public function csrf(): Csrf
    {
        return $this->unico('csrf', fn (): Csrf => new Csrf($this->sessao));
    }

    public function chave(): ChaveApp
    {
        return $this->unico('chave', fn (): ChaveApp => new ChaveApp($this->config));
    }

    public function registroSeguranca(): RegistroSeguranca
    {
        return $this->unico('registroSeguranca', fn (): RegistroSeguranca => new RegistroSeguranca(
            (string) $this->config->obter('registro_seguranca', $this->raiz . '/temp/logs/seguranca.log'),
        ));
    }

    public function limite(): LimiteTaxa
    {
        return $this->unico('limite', fn (): LimiteTaxa => new LimiteTaxa($this->pdo()));
    }

    public function bloqueio(): BloqueioEntrada
    {
        return $this->unico('bloqueio', fn (): BloqueioEntrada => new BloqueioEntrada($this->pdo(), $this->registroSeguranca()));
    }

    public function guardaFormulario(): GuardaFormulario
    {
        return $this->unico('guardaFormulario', fn (): GuardaFormulario => new GuardaFormulario($this->chave(), $this->registroSeguranca()));
    }

    public function guardaSondagem(): GuardaSondagem
    {
        return $this->unico('guardaSondagem', fn (): GuardaSondagem => new GuardaSondagem($this->limite(), $this->registroSeguranca()));
    }

    public function permissoes(): Permissoes
    {
        return $this->unico('permissoes', fn (): Permissoes => new Permissoes($this->pdo()));
    }

    public function acessos(): RegistroAcessos
    {
        return $this->unico('acessos', fn (): RegistroAcessos => new RegistroAcessos($this->pdo()));
    }

    public function destino(): DestinoAposEntrar
    {
        return $this->unico('destino', fn (): DestinoAposEntrar => new DestinoAposEntrar($this->sessao));
    }

    public function porta(): PortaCadastro
    {
        return $this->unico('porta', fn (): PortaCadastro => new PortaCadastro($this->config, fn (string $email): bool => $this->equipe()->convidado($email)));
    }

    public function autenticacao(): ServicoAutenticacao
    {
        return $this->unico('autenticacao', fn (): ServicoAutenticacao => new ServicoAutenticacao(
            $this->pdo(), $this->sessao, $this->carteiro, $this->limite(), $this->acessos(), $this->porta(), $this->urlBase(),
            function (int $usuarioId): void {
                // Convidado para a equipe entra ao confirmar o e-mail; os e-mails de Webmasters viram webmaster.
                $this->equipe()->aceitarPendente($usuarioId);
                $this->permissoes()->sincronizarWebmasters(Webmasters::emails($this->config));
                $this->permissoes()->esquecerCache();
                // Conta pendente: os webmasters recebem o aviso para aprovar em Painel > Usuários.
                $this->aprovacao()->avisarWebmasters($usuarioId);
            },
        ));
    }

    public function aprovacao(): AprovacaoCadastro
    {
        return $this->unico('aprovacao', fn (): AprovacaoCadastro => new AprovacaoCadastro(
            $this->pdo(), $this->carteiro, $this->urlBase(), Webmasters::emails($this->config),
        ));
    }

        public function senhas(): ServicoSenha
    {
        return $this->unico('senhas', fn (): ServicoSenha => new ServicoSenha($this->pdo(), $this->carteiro, $this->urlBase()));
    }

    public function duasEtapas(): EntradaDuasEtapas
    {
        return $this->unico('duasEtapas', fn (): EntradaDuasEtapas => new EntradaDuasEtapas(
            $this->pdo(), $this->permissoes(), $this->limite(), $this->carteiro, $this->chave(), $this->registroSeguranca(), $this->urlBase(),
            $this->config->obter('duas_etapas', true) !== false,
        ));
    }

    public function avisoEntrada(): AvisoEntrada
    {
        return $this->unico('avisoEntrada', fn (): AvisoEntrada => new AvisoEntrada($this->acessos(), $this->limite(), $this->carteiro, $this->urlBase()));
    }

    public function registroPainel(): RegistroPainel
    {
        return $this->unico('registroPainel', fn (): RegistroPainel => new RegistroPainel($this->pdo()));
    }

    public function equipe(): Equipe
    {
        return $this->unico('equipe', fn (): Equipe => new Equipe($this->pdo(), $this->permissoes(), $this->registroPainel(), $this->carteiro, $this->urlBase()));
    }

    public function adminUsuarios(): AdminUsuarios
    {
        return $this->unico('adminUsuarios', fn (): AdminUsuarios => new AdminUsuarios(
            $this->pdo(), $this->permissoes(), $this->autenticacao(), $this->senhas(), $this->registroPainel(), $this->aprovacao(),
        ));
    }

    public function painel(): PainelWebmaster
    {
        return $this->unico('painel', fn (): PainelWebmaster => new PainelWebmaster($this->pdo(), $this->permissoes(), $this->bloqueio()));
    }

    public function sites(): RepositorioSites
    {
        return $this->unico('sites', fn (): RepositorioSites => new RepositorioSites($this->pdo()));
    }

    public function desafios(): RepositorioDesafios
    {
        return $this->unico('desafios', fn (): RepositorioDesafios => new RepositorioDesafios($this->pdo()));
    }

    public function assinatura(): Assinatura
    {
        return $this->unico('assinatura', fn (): Assinatura => new Assinatura($this->pdo()));
    }

    public function regras(): Regras
    {
        return $this->unico('regras', fn (): Regras => new Regras($this->pdo()));
    }

    /**
     * @template T of object
     * @param Closure(): T $criar
     * @return T
     */
    private function unico(string $nome, Closure $criar): object
    {
        return $this->prontos[$nome] ??= $criar();
    }
}
