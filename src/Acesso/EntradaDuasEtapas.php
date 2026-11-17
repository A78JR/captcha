<?php

declare(strict_types=1);

namespace Captcha\Acesso;

use DateTimeImmutable;
use DateTimeZone;
use Captcha\Nucleo\Carteiro;
use Captcha\Seguranca\ChaveApp;
use Captcha\Seguranca\LimiteTaxa;
use Captcha\Seguranca\RegistroSeguranca;
use PDO;

/**
 * Entrada em duas etapas, como no burgu e no Bazar de Tudo: depois da senha certa, o site manda um e-mail
 * com um código de 3 números e um botão "Autorizar". A pessoa digita o código na janela onde pediu a entrada,
 * ou toca no botão e essa janela entra sozinha (ela pergunta ao site a cada poucos segundos).
 * O botão não abre a conta em outro aparelho: só confirma a tentativa que já passou pela senha.
 *
 * Obrigatória para quem tem a permissão entrada.duas_etapas (o webmaster tem todas). Qualquer outra pessoa
 * pode ligar a sua em Minha conta. 'duas_etapas' => false no config.local.php desliga tudo: é a saída se o
 * e-mail do servidor parar e o webmaster não conseguir entrar.
 *
 * Contra chutes (3 números são só mil combinações):
 *  - cada código aceita 3 tentativas e vale 10 minutos;
 *  - no máximo 6 e-mails de código por hora para a mesma conta;
 *  - 5 códigos errados em 24 horas travam o código para a conta (só o botão do e-mail funciona) e vão para o
 *    registro de segurança.
 */
final class EntradaDuasEtapas
{
    public const PERMISSAO = 'entrada.duas_etapas';
    public const VALIDADE = 600;
    public const MAXIMO_TENTATIVAS = 3;
    public const MAXIMO_ENVIOS_HORA = 6;
    public const MAXIMO_ERROS_DIA = 5;
    /** Depois do toque em "Autorizar", tempo que a janela tem para perceber e entrar. */
    private const FOLGA_AUTORIZADA = 300;

    public const CERTO = 'certo';
    public const ERRADO = 'errado';
    public const VENCIDO = 'vencido';
    public const TRAVADO = 'travado';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Permissoes $permissoes,
        private readonly LimiteTaxa $limite,
        private readonly Carteiro $carteiro,
        private readonly ChaveApp $chave,
        private readonly RegistroSeguranca $registro,
        private readonly string $urlBase,
        private readonly bool $ligada = true,
    ) {
    }

    /** A conta entra em duas etapas? Equipe sempre; os outros, se ligaram em Minha conta. */
    public function exigida(?Usuario $usuario): bool
    {
        return $usuario !== null && $this->ligada && ($usuario->duasEtapas || $this->obrigatoria($usuario));
    }

    /** A conta é da equipe, que entra sempre em duas etapas (não dá para desligar)? Vale também com a conta suspensa. */
    public function obrigatoria(Usuario $usuario): bool
    {
        return $this->permissoes->grupoPode($usuario->grupoId, self::PERMISSAO);
    }

    /**
     * Liga ou desliga em Minha conta, com a senha atual (quem pegou a sessão aberta de outra pessoa não
     * consegue mexer). Avisa por e-mail. Devolve a mensagem de erro, ou null quando deu certo.
     */
    public function definir(Usuario $usuario, bool $ligar, bool $senhaConfere): ?string
    {
        if (!$senhaConfere) {
            return 'Senha incorreta.';
        }
        if (!$ligar && $this->obrigatoria($usuario)) {
            return 'Contas da equipe entram sempre em duas etapas.';
        }
        $this->pdo->prepare('UPDATE captcha_usuario SET duas_etapas = ? WHERE id = ?')->execute([(int) $ligar, $usuario->id]);
        $this->carteiro->enviar($usuario->email,
            $ligar ? 'Entrada em duas etapas ligada na Captcha' : 'Entrada em duas etapas desligada na Captcha',
            $ligar
                ? 'A entrada em duas etapas foi ligada na sua conta. A partir de agora, depois da senha, enviamos um código para este e-mail.'
                : 'A entrada em duas etapas foi desligada na sua conta. Se não foi você, troque sua senha agora: ' . rtrim($this->urlBase, '/') . '/esqueci-senha');
        return null;
    }

    /**
     * Cria a tentativa e manda o e-mail. Uma tentativa nova encerra as anteriores ainda abertas.
     *
     * @return array{alca: string, enviado: bool}|null null se passou do limite de e-mails por hora
     */
    public function iniciar(Usuario $usuario, string $ip, ?string $agente): ?array
    {
        if (!$this->limite->contar('duas-etapas:envio:' . $usuario->id, self::MAXIMO_ENVIOS_HORA, 3600)) {
            return null;
        }
        $this->pdo->prepare('UPDATE captcha_desafio_entrada SET vence_em = UTC_TIMESTAMP() WHERE usuario_id = ? AND usado_em IS NULL AND vence_em > UTC_TIMESTAMP()')
            ->execute([$usuario->id]);
        $alca = bin2hex(random_bytes(32));
        $link = bin2hex(random_bytes(32));
        $codigo = sprintf('%03d', random_int(0, 999));
        $agente = $agente === null ? null : mb_substr($agente, 0, 255);
        $this->pdo->prepare(
            'INSERT INTO captcha_desafio_entrada (usuario_id, alca_hash, link_hash, codigo_hash, ip, agente, vence_em)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND)'
        )->execute([$usuario->id, hash('sha256', $alca), hash('sha256', $link), $this->hashCodigo($alca, $codigo), @inet_pton($ip) ?: '', $agente, self::VALIDADE]);
        return ['alca' => $alca, 'enviado' => $this->enviarEmail($usuario, $codigo, $link, $ip, $agente)];
    }

    /**
     * Confere o código digitado.
     *
     * @return array{0: string, 1: int|null} [resultado, número da conta quando CERTO]
     */
    public function conferirCodigo(string $alca, string $codigo): array
    {
        $linha = $this->aberto($alca);
        if ($linha === null) {
            return [self::VENCIDO, null];
        }
        $usuarioId = (int) $linha['usuario_id'];
        if ($this->limite->estourado('duas-etapas:erro:' . $usuarioId, self::MAXIMO_ERROS_DIA, 86400)) {
            return [self::TRAVADO, null];
        }
        // Gasta uma tentativa antes de comparar: duas requisições ao mesmo tempo não ganham chutes a mais.
        $gastar = $this->pdo->prepare(
            'UPDATE captcha_desafio_entrada SET tentativas = tentativas + 1
             WHERE id = ? AND tentativas < ? AND usado_em IS NULL AND vence_em > UTC_TIMESTAMP()'
        );
        $gastar->execute([$linha['id'], self::MAXIMO_TENTATIVAS]);
        if ($gastar->rowCount() !== 1) {
            return [self::VENCIDO, null];
        }
        $codigo = preg_replace('/\D/', '', $codigo) ?? '';
        if (strlen($codigo) === 3 && hash_equals((string) $linha['codigo_hash'], $this->hashCodigo($alca, $codigo)) && $this->usar((int) $linha['id'], false)) {
            return [self::CERTO, $usuarioId];
        }
        if (!$this->limite->contar('duas-etapas:erro:' . $usuarioId, self::MAXIMO_ERROS_DIA, 86400)) {
            $this->registro->evento('codigo_de_entrada_atacado', null, ['conta' => $usuarioId]);
            return [self::TRAVADO, null];
        }
        return [(int) $linha['tentativas'] + 1 >= self::MAXIMO_TENTATIVAS ? self::VENCIDO : self::ERRADO, null];
    }

    /** Botão "Autorizar" do e-mail: confirma a tentativa. Devolve o número interno dela, ou null se o link não vale. */
    public function autorizar(string $link): ?int
    {
        if (!ctype_xdigit($link) || strlen($link) !== 64) {
            return null;
        }
        $consulta = $this->pdo->prepare('SELECT id FROM captcha_desafio_entrada WHERE link_hash = ?');
        $consulta->execute([hash('sha256', $link)]);
        $id = $consulta->fetchColumn();
        if ($id === false) {
            return null;
        }
        $atualizar = $this->pdo->prepare(
            'UPDATE captcha_desafio_entrada SET autorizado_em = COALESCE(autorizado_em, UTC_TIMESTAMP()),
                    vence_em = GREATEST(vence_em, UTC_TIMESTAMP() + INTERVAL ? SECOND)
             WHERE id = ? AND usado_em IS NULL AND vence_em > UTC_TIMESTAMP()'
        );
        $atualizar->execute([self::FOLGA_AUTORIZADA, $id]);
        return $atualizar->rowCount() === 1 || $this->autorizado((int) $id) ? (int) $id : null;
    }

    /**
     * A janela que pediu a entrada pergunta se já pode entrar. Se o botão foi tocado, gasta a tentativa e
     * devolve o número da conta (uma vez só).
     *
     * @return array{0: string, 1: int|null} [CERTO | ERRADO (= ainda esperando) | VENCIDO, número da conta]
     */
    public function perguntar(string $alca): array
    {
        $linha = $this->aberto($alca);
        if ($linha === null) {
            return [self::VENCIDO, null];
        }
        if ($linha['autorizado_em'] !== null && $this->usar((int) $linha['id'], true)) {
            return [self::CERTO, (int) $linha['usuario_id']];
        }
        return [self::ERRADO, null];
    }

    /** Número interno da tentativa (para saber se o botão foi tocado no mesmo navegador). */
    public function idDa(string $alca): ?int
    {
        $linha = $this->aberto($alca);
        return $linha === null ? null : (int) $linha['id'];
    }

    /** Conta da tentativa aberta (para mandar outro código). */
    public function contaDa(string $alca): ?int
    {
        $linha = $this->aberto($alca);
        return $linha === null ? null : (int) $linha['usuario_id'];
    }

    /** E-mail (mascarado) para onde o código foi, para mostrar na tela. */
    public function emailMascarado(string $alca): ?string
    {
        $consulta = $this->pdo->prepare('SELECT u.email FROM captcha_desafio_entrada d JOIN captcha_usuario u ON u.id = d.usuario_id WHERE d.alca_hash = ?');
        $consulta->execute([hash('sha256', $alca)]);
        $email = $consulta->fetchColumn();
        if ($email === false) {
            return null;
        }
        [$nome, $dominio] = explode('@', (string) $email, 2) + [1 => ''];
        return mb_substr($nome, 0, 2) . str_repeat('•', max(1, min(6, mb_strlen($nome) - 2))) . '@' . $dominio;
    }

    /** @return array<string, mixed>|null tentativa aberta (sem uso e dentro do prazo) */
    private function aberto(string $alca): ?array
    {
        if (!ctype_xdigit($alca) || strlen($alca) !== 64) {
            return null;
        }
        $consulta = $this->pdo->prepare(
            'SELECT id, usuario_id, codigo_hash, tentativas, autorizado_em FROM captcha_desafio_entrada
             WHERE alca_hash = ? AND usado_em IS NULL AND vence_em > UTC_TIMESTAMP()'
        );
        $consulta->execute([hash('sha256', $alca)]);
        $linha = $consulta->fetch();
        return $linha === false ? null : $linha;
    }

    private function autorizado(int $id): bool
    {
        $consulta = $this->pdo->prepare('SELECT autorizado_em IS NOT NULL FROM captcha_desafio_entrada WHERE id = ? AND usado_em IS NULL');
        $consulta->execute([$id]);
        return (bool) $consulta->fetchColumn();
    }

    private function usar(int $id, bool $soAutorizada): bool
    {
        $consulta = $this->pdo->prepare(
            'UPDATE captcha_desafio_entrada SET usado_em = UTC_TIMESTAMP()
             WHERE id = ? AND usado_em IS NULL AND vence_em > UTC_TIMESTAMP()' . ($soAutorizada ? ' AND autorizado_em IS NOT NULL' : '')
        );
        $consulta->execute([$id]);
        return $consulta->rowCount() === 1;
    }

    /** O código é guardado misturado com a tentativa e a chave do Captcha: o hash não serve para outra tentativa. */
    private function hashCodigo(string $alca, string $codigo): string
    {
        return $this->chave->assinar('duas-etapas', hash('sha256', $alca) . ':' . $codigo);
    }

    private function enviarEmail(Usuario $usuario, string $codigo, string $link, string $ip, ?string $agente): bool
    {
        $base = rtrim($this->urlBase, '/');
        $quando = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i');
        $texto = "Olá, {$usuario->primeiroNome()}.\n\n"
            . "Seu código para entrar na Captcha: {$codigo}\n\n"
            . "Ou autorize por aqui: {$base}/entrar/autorizar?t={$link}\n\n"
            . 'Vale por ' . intdiv(self::VALIDADE, 60) . " minutos. Não foi você? Não use o código nem o botão: alguém sabe a sua senha.\n"
            . "Esqueci minha senha: {$base}/esqueci-senha\n\n"
            . "Data e hora: {$quando} (horário de Brasília)\nIP: {$ip}\nNavegador: " . Aparelho::descrever($agente);
        return $this->carteiro->enviar($usuario->email, "{$codigo} é o seu código de acesso à Captcha", $texto);
    }
}
