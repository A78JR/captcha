<?php

declare(strict_types=1);

namespace Captcha\Acesso;

use Captcha\Nucleo\Requisicao;
use Captcha\Nucleo\Resposta;
use Captcha\Nucleo\Roteador;
use Captcha\Nucleo\Servicos;
use Captcha\Seguranca\BloqueioEntrada;

/**
 * Entrar, sair, segunda etapa, cadastro, confirmação de e-mail e "esqueci minha senha".
 *
 * Na entrada valem, nesta ordem: o bloqueio do IP por falhas seguidas (BloqueioEntrada), a isca e o tempo
 * mínimo do formulário (GuardaFormulario, com a primeira tentativa liberada do tempo porque o navegador
 * preenche a senha), o limite de 10 senhas erradas por conta por hora (contra ataques espalhados em muitos
 * IPs; só os erros contam, senão qualquer um trancaria a conta de outra pessoa) e, por fim, a senha.
 */
final class EntradaControlador
{
    public const CHAVE_ALCA = 'alca_entrada';
    private const CHAVE_TENTOU = 'tentou_entrar';
    public const ERROS_POR_CONTA = 10;

    public function __construct(private readonly Servicos $s)
    {
    }

    public function registrar(Roteador $r): void
    {
        $g = $this->s->guardas();
        $r->get('/entrar', fn (Requisicao $q): Resposta => $this->formEntrar($q));
        $r->post('/entrar', fn (Requisicao $q): Resposta => $this->entrar($q), [$g->limitar('entrar', 30, 900), $g->csrf()]);
        $r->post('/sair', fn (Requisicao $q): Resposta => $this->sair(), [$g->csrf()]);

        $r->get('/entrar/codigo', fn (Requisicao $q): Resposta => $this->formCodigo($q));
        $r->post('/entrar/codigo', fn (Requisicao $q): Resposta => $this->codigo($q), [$g->limitar('codigo', 30, 900), $g->csrf()]);
        $r->post('/entrar/codigo/reenviar', fn (Requisicao $q): Resposta => $this->reenviarCodigo($q), [$g->limitar('reenviar', 10, 3600), $g->csrf()]);
        $r->get('/entrar/codigo/situacao', fn (Requisicao $q): Resposta => $this->situacao($q), [$g->limitar('situacao', 400, 900)]);
        $r->get('/entrar/autorizar', fn (Requisicao $q): Resposta => $this->formAutorizar($q));
        $r->post('/entrar/autorizar', fn (Requisicao $q): Resposta => $this->autorizar($q), [$g->limitar('autorizar', 30, 900), $g->csrf()]);

        $r->get('/cadastro', fn (Requisicao $q): Resposta => $this->formCadastro($q));
        $r->post('/cadastro', fn (Requisicao $q): Resposta => $this->cadastrar($q), [$g->limitar('cadastro', 10, 3600), $g->csrf()]);
        $r->get('/verificar-email', fn (Requisicao $q): Resposta => $this->formConfirmar($q));
        $r->post('/verificar-email', fn (Requisicao $q): Resposta => $this->confirmar($q), [$g->limitar('confirmar', 30, 3600), $g->csrf()]);

        $r->get('/esqueci-senha', fn (Requisicao $q): Resposta => $this->s->pagina()->html($q, 'acesso/esqueci', [], 'Esqueci minha senha'));
        $r->post('/esqueci-senha', fn (Requisicao $q): Resposta => $this->esqueci($q), [$g->limitar('esqueci', 10, 3600), $g->csrf()]);
        $r->get('/nova-senha', fn (Requisicao $q): Resposta => $this->formNovaSenha($q));
        $r->post('/nova-senha', fn (Requisicao $q): Resposta => $this->novaSenha($q), [$g->limitar('nova-senha', 20, 3600), $g->csrf()]);
    }

    private function formEntrar(Requisicao $q, array $dados = [], int $status = 200): Resposta
    {
        if ($status === 200 && $this->s->autenticacao()->usuario() !== null) {
            return Resposta::redirecionar('/conta');
        }
        return $this->s->pagina()->html($q, 'acesso/entrar', $dados + ['erros' => [], 'antigo' => []], 'Entrar', $status);
    }

    private function entrar(Requisicao $q): Resposta
    {
        $email = mb_strtolower($q->entrada('email'));
        $antigo = ['email' => $email];
        $bloqueio = $this->s->bloqueio();
        if (($espera = $bloqueio->bloqueadoPor($q->ip)) > 0) {
            return $this->formEntrar($q, ['erros' => ['formulario' => BloqueioEntrada::mensagem($espera)], 'antigo' => $antigo], 429)
                ->comCabecalho('Retry-After', (string) $espera);
        }
        $limite = $this->s->limite();
        $balde = 'entrar:email:' . hash('sha256', $email);
        $primeira = $this->s->sessao()->obter(self::CHAVE_TENTOU) === null
            && !$bloqueio->falhouAgora($q->ip)
            && $limite->contagem($balde, 3600) === 0;
        $this->s->sessao()->definir(self::CHAVE_TENTOU, 1);

        if (($robo = $this->s->guardaFormulario()->conferir($q, null, $primeira)) !== null) {
            $bloqueio->registrarFalha($q);
            return $this->formEntrar($q, ['erros' => ['formulario' => $robo], 'antigo' => $antigo], 422);
        }
        if ($limite->estourado($balde, self::ERROS_POR_CONTA, 3600)) {
            $this->s->registroSeguranca()->evento('conta_sob_ataque', $q);
            return $this->formEntrar($q, ['erros' => ['formulario' => 'Muitas tentativas para esta conta. Tente novamente em uma hora.'], 'antigo' => $antigo], 429);
        }
        try {
            $id = $this->s->autenticacao()->verificarCredenciais($email, $q->senha('senha'), $q->ip);
        } catch (ContaPendente $pendente) {
            $bloqueio->acertar($q->ip);
            return $this->formEntrar($q, ['erros' => ['formulario' => $pendente->getMessage()], 'antigo' => $antigo], 403);
        }
        if ($id === null) {
            $limite->contar($balde, self::ERROS_POR_CONTA, 3600);
            $bloqueio->registrarFalha($q);
            return $this->formEntrar($q, ['erros' => ['formulario' => 'E-mail ou senha incorretos. Se você acabou de criar a conta, entre pelo link que enviamos ao seu e-mail.'], 'antigo' => $antigo], 422);
        }
        $bloqueio->acertar($q->ip);
        return $this->senhaCerta($id, $q);
    }

    /** Senha certa: entra, ou leva para a segunda etapa. */
    private function senhaCerta(int $id, Requisicao $q): Resposta
    {
        $usuario = $this->s->autenticacao()->encontrar($id);
        $duas = $this->s->duasEtapas();
        if ($usuario !== null && $duas->exigida($usuario)) {
            $this->s->sessao()->esquecer('usuario_id');
            $this->s->sessao()->regenerar();
            $iniciada = $duas->iniciar($usuario, $q->ip, $q->cabecalho('user-agent'));
            if ($iniciada === null) {
                return $this->formEntrar($q, ['erros' => ['formulario' => 'Você já pediu muitos códigos. Tente de novo daqui a uma hora.'], 'antigo' => ['email' => $usuario->email]], 429);
            }
            $this->s->sessao()->definir(self::CHAVE_ALCA, $iniciada['alca']);
            if (!$iniciada['enviado']) {
                error_log('[captcha] Entrada em duas etapas: o e-mail com o código não saiu (confira email_remetente no config).');
                $this->s->sessao()->avisar('erro', 'Não conseguimos enviar o e-mail com o código. Tente de novo em alguns minutos.');
            }
            return Resposta::redirecionar('/entrar/codigo');
        }
        if ($usuario !== null) {
            $this->s->avisoEntrada()->aposEntrar($usuario, false, $q->ip, $q->cabecalho('user-agent'));
        }
        return Resposta::redirecionar($this->abrirConta($id, $q));
    }

    /** Abre a sessão e devolve para onde ir (a página que a pessoa pediu antes de entrar, ou Minha conta). */
    private function abrirConta(int $id, Requisicao $q): string
    {
        $this->s->autenticacao()->entrarPorId($id, $q);
        $this->s->sessao()->esquecer(self::CHAVE_TENTOU);
        return $this->s->destino()->tirar() ?? '/conta';
    }

    private function sair(): Resposta
    {
        $this->s->autenticacao()->sair();
        return Resposta::redirecionar('/');
    }

    private function alca(): ?string
    {
        $alca = $this->s->sessao()->obter(self::CHAVE_ALCA);
        return is_string($alca) ? $alca : null;
    }

    private function formCodigo(Requisicao $q, array $erros = [], int $status = 200): Resposta
    {
        $alca = $this->alca();
        $email = $alca !== null ? $this->s->duasEtapas()->emailMascarado($alca) : null;
        if ($email === null) {
            return Resposta::redirecionar('/entrar');
        }
        return $this->s->pagina()->html($q, 'acesso/codigo', ['email' => $email, 'erros' => $erros], 'Confirme que é você', $status);
    }

    private function codigo(Requisicao $q): Resposta
    {
        $alca = $this->alca();
        if ($alca === null) {
            return Resposta::redirecionar('/entrar');
        }
        [$resultado, $id] = $this->s->duasEtapas()->conferirCodigo($alca, $q->entrada('codigo'));
        return match ($resultado) {
            EntradaDuasEtapas::CERTO => $this->concluirSegundaEtapa((int) $id, $q),
            EntradaDuasEtapas::ERRADO => $this->formCodigo($q, ['codigo' => 'Código errado. Confira o e-mail e tente de novo.'], 422),
            EntradaDuasEtapas::TRAVADO => $this->formCodigo($q, ['codigo' => 'Muitos códigos errados hoje. Use o botão Autorizar do e-mail.'], 429),
            default => $this->recomecar('O código venceu ou já foi usado. Entre de novo para receber outro.'),
        };
    }

    private function concluirSegundaEtapa(int $id, Requisicao $q): Resposta
    {
        $this->s->sessao()->esquecer(self::CHAVE_ALCA);
        return Resposta::redirecionar($this->abrirConta($id, $q));
    }

    private function recomecar(string $mensagem): Resposta
    {
        $this->s->sessao()->esquecer(self::CHAVE_ALCA);
        $this->s->sessao()->avisar('erro', $mensagem);
        return Resposta::redirecionar('/entrar');
    }

    private function reenviarCodigo(Requisicao $q): Resposta
    {
        $alca = $this->alca();
        $id = $alca !== null ? $this->s->duasEtapas()->contaDa($alca) : null;
        $usuario = $id !== null ? $this->s->autenticacao()->encontrar($id) : null;
        if ($usuario === null) {
            return $this->recomecar('O código venceu. Entre de novo para receber outro.');
        }
        $iniciada = $this->s->duasEtapas()->iniciar($usuario, $q->ip, $q->cabecalho('user-agent'));
        if ($iniciada === null) {
            return $this->formCodigo($q, ['codigo' => 'Você já pediu muitos códigos. Tente de novo daqui a uma hora.'], 429);
        }
        $this->s->sessao()->definir(self::CHAVE_ALCA, $iniciada['alca']);
        $this->s->sessao()->avisar('sucesso', 'Mandamos outro código. Ele substitui o anterior.');
        return Resposta::redirecionar('/entrar/codigo');
    }

    /** A janela da segunda etapa pergunta a cada poucos segundos se o botão Autorizar do e-mail já foi tocado. */
    private function situacao(Requisicao $q): Resposta
    {
        $alca = $this->alca();
        if ($alca === null) {
            return Resposta::json(['estado' => 'vencido']);
        }
        [$resultado, $id] = $this->s->duasEtapas()->perguntar($alca);
        if ($resultado === EntradaDuasEtapas::CERTO) {
            $this->s->sessao()->esquecer(self::CHAVE_ALCA);
            return Resposta::json(['estado' => 'pronto', 'destino' => $this->abrirConta((int) $id, $q)]);
        }
        return Resposta::json(['estado' => $resultado === EntradaDuasEtapas::VENCIDO ? 'vencido' : 'esperando']);
    }

    /**
     * O link do e-mail só abre o botão: robôs que abrem links para conferir (antivírus de e-mail) não
     * autorizam a entrada de ninguém.
     */
    private function formAutorizar(Requisicao $q): Resposta
    {
        return $this->s->pagina()->html($q, 'acesso/autorizar', ['token' => $q->entrada('t'), 'feito' => null], 'Autorizar entrada');
    }

    private function autorizar(Requisicao $q): Resposta
    {
        $id = $this->s->duasEtapas()->autorizar($q->entrada('t'));
        if ($id === null) {
            return $this->s->pagina()->html($q, 'acesso/autorizar', ['token' => '', 'feito' => false], 'Autorizar entrada', 410);
        }
        $alca = $this->alca();
        if ($alca !== null && $this->s->duasEtapas()->idDa($alca) === $id) {
            return Resposta::redirecionar('/entrar/codigo'); // mesmo navegador: a página do código entra sozinha
        }
        return $this->s->pagina()->html($q, 'acesso/autorizar', ['token' => '', 'feito' => true], 'Entrada autorizada');
    }

    private function formCadastro(Requisicao $q, array $erros = [], array $antigo = [], int $status = 200): Resposta
    {
        $antigo += ['email' => $q->entrada('email'), 'nome' => ''];
        return $this->s->pagina()->html($q, 'acesso/cadastro', [
            'erros' => $erros, 'antigo' => $antigo,
        ], 'Criar conta', $status);
    }

    private function cadastrar(Requisicao $q): Resposta
    {
        $antigo = ['nome' => $q->entrada('nome'), 'email' => mb_strtolower($q->entrada('email'))];
        if (($espera = $this->s->bloqueio()->bloqueadoPor($q->ip)) > 0) {
            return $this->formCadastro($q, ['formulario' => BloqueioEntrada::mensagem($espera)], $antigo, 429);
        }
        if (($robo = $this->s->guardaFormulario()->conferir($q)) !== null) {
            $this->s->bloqueio()->registrarFalha($q);
            return $this->formCadastro($q, ['formulario' => $robo], $antigo, 422);
        }
        $resultado = $this->s->autenticacao()->cadastrar([
            'nome' => $antigo['nome'], 'email' => $antigo['email'],
            'senha' => $q->senha('senha'), 'senha_confirmacao' => $q->senha('senha_confirmacao'),
        ]);
        if (is_array($resultado)) {
            return $this->formCadastro($q, $resultado, $antigo, 422);
        }
        // Conta nova ou e-mail que já tinha conta: a mesma resposta (ninguém descobre quem tem conta).
        return $this->s->pagina()->html($q, 'acesso/confira_email', ['email' => $antigo['email']], 'Confira seu e-mail');
    }

    private function formConfirmar(Requisicao $q): Resposta
    {
        $token = $q->entrada('token');
        if (!ctype_xdigit($token) || strlen($token) !== 64) {
            $this->s->sessao()->avisar('erro', 'Link inválido ou vencido. Tente entrar: mandamos outro.');
            return Resposta::redirecionar('/entrar');
        }
        return $this->s->pagina()->html($q, 'acesso/confirmar_email', ['token' => $token], 'Confirmar e-mail');
    }

    private function confirmar(Requisicao $q): Resposta
    {
        $id = $this->s->autenticacao()->confirmarEmail($q->entrada('token'));
        if ($id === null) {
            $this->s->sessao()->avisar('erro', 'Link inválido ou vencido. Tente entrar: mandamos outro.');
        } elseif ($this->s->autenticacao()->porId($id)?->pendente()) {
            $this->s->sessao()->avisar('sucesso', 'E-mail confirmado. Agora sua conta aguarda a aprovação do webmaster: você recebe um e-mail quando ela for aprovada.');
        } else {
            $this->s->sessao()->avisar('sucesso', 'E-mail confirmado. Agora é só entrar.');
        }
        return Resposta::redirecionar('/entrar');
    }

    private function esqueci(Requisicao $q): Resposta
    {
        if (($robo = $this->s->guardaFormulario()->conferir($q)) !== null) {
            return $this->s->pagina()->html($q, 'acesso/esqueci', ['erro' => $robo], 'Esqueci minha senha', 422);
        }
        $email = mb_strtolower($q->entrada('email'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && $this->s->limite()->contar('esqueci:' . hash('sha256', $email), 3, 3600)) {
            $this->s->senhas()->pedirLink($email);
        }
        $this->s->sessao()->avisar('sucesso', 'Se houver uma conta com este e-mail, o link para criar uma senha nova chega em alguns minutos.');
        return Resposta::redirecionar('/entrar');
    }

    private function formNovaSenha(Requisicao $q, array $erros = [], int $status = 200): Resposta
    {
        $token = $q->entrada('token');
        $vale = $this->s->senhas()->contaDoLink($token) !== null;
        return $this->s->pagina()->html($q, 'acesso/nova_senha', ['token' => $token, 'vale' => $vale, 'erros' => $erros], 'Senha nova', $vale ? $status : 410);
    }

    private function novaSenha(Requisicao $q): Resposta
    {
        $senha = $q->senha('senha');
        if ($senha !== $q->senha('senha_confirmacao')) {
            return $this->formNovaSenha($q, ['senha_confirmacao' => 'As senhas não conferem.'], 422);
        }
        $resultado = $this->s->senhas()->redefinir($q->entrada('token'), $senha);
        if (is_array($resultado)) {
            return $this->formNovaSenha($q, $resultado, 422);
        }
        $this->s->sessao()->avisar('sucesso', 'Senha nova gravada e os outros aparelhos desconectados. Entre com ela.');
        return Resposta::redirecionar('/entrar');
    }
}
