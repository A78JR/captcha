<?php

declare(strict_types=1);

namespace Captcha\Acesso;

use DateTimeImmutable;
use DateTimeZone;
use Captcha\Nucleo\Carteiro;
use Captcha\Seguranca\LimiteTaxa;
use Throwable;

/**
 * Aviso por e-mail de entrada fora do comum ("foi você que entrou agora?").
 *
 * Chamado logo depois da senha certa e antes de a entrada ir para o registro de acessos: o histórico
 * consultado é o das entradas anteriores. A entrada é fora do comum quando a rede (mesma faixa /24 no IPv4,
 * /48 no IPv6) e o aparelho ("Chrome no Windows") são novos ao mesmo tempo. Só um dos dois novo é o dia a
 * dia (celular trocando de antena, notebook novo em casa).
 *
 * Não avisa: na primeira entrada (não há com o que comparar), quem entra em duas etapas (já recebeu o
 * código com os mesmos dados), e-mail não confirmado e depois de MAXIMO_POR_DIA avisos no dia para a conta.
 * O aviso nunca impede a entrada: se falhar, só anota.
 */
final class AvisoEntrada
{
    public const HISTORICO = 50;
    public const MAXIMO_POR_DIA = 3;

    public function __construct(
        private readonly RegistroAcessos $acessos,
        private readonly LimiteTaxa $limite,
        private readonly Carteiro $carteiro,
        private readonly string $urlBase,
    ) {
    }

    /** Confere a entrada e, se for fora do comum, manda o aviso. Devolve se o e-mail saiu. */
    public function aposEntrar(Usuario $usuario, bool $duasEtapas, string $ip, ?string $agente): bool
    {
        try {
            if (!$usuario->emailConfirmado() || $duasEtapas) {
                return false;
            }
            $historico = array_map(static fn (array $e): array => ['ip' => $e['ip'], 'agente' => $e['agente']], $this->acessos->entradas($usuario->id, self::HISTORICO));
            if (!self::foraDoComum(['ip' => $ip, 'agente' => $agente], $historico)) {
                return false;
            }
            if (!$this->limite->contar('aviso-entrada:' . $usuario->id, self::MAXIMO_POR_DIA, 86400)) {
                return false;
            }
            [$assunto, $texto] = $this->compor($usuario, $ip, $agente, new DateTimeImmutable('now'));
            return $this->carteiro->enviar($usuario->email, $assunto, $texto);
        } catch (Throwable $erro) {
            error_log('[captcha] Aviso de entrada fora do comum falhou: ' . $erro->getMessage());
            return false;
        }
    }

    /**
     * @param array{ip: string, agente: ?string} $agora
     * @param list<array{ip: string, agente: ?string}> $historico entradas anteriores, da mais nova para a mais antiga
     */
    public static function foraDoComum(array $agora, array $historico): bool
    {
        if ($historico === []) {
            return false;
        }
        $rede = self::rede($agora['ip']);
        $aparelho = Aparelho::descrever($agora['agente']);
        foreach ($historico as $antiga) {
            if (($rede !== null && self::rede($antiga['ip']) === $rede) || Aparelho::descrever($antiga['agente']) === $aparelho) {
                return false;
            }
        }
        return true;
    }

    /** Faixa de rede do IP (/24 no IPv4, /48 no IPv6), para o IP que muda dentro do mesmo provedor contar como conhecido. */
    public static function rede(string $ip): ?string
    {
        $binario = @inet_pton($ip);
        if ($binario === false) {
            return null;
        }
        return bin2hex(substr($binario, 0, strlen($binario) === 4 ? 3 : 6));
    }

    /** @return array{0: string, 1: string} assunto e texto */
    public function compor(Usuario $usuario, string $ip, ?string $agente, DateTimeImmutable $quando): array
    {
        $local = $quando->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        $texto = "Olá, {$usuario->primeiroNome()}.\n\n"
            . "Alguém acabou de entrar na sua conta do Captcha de um lugar e de um aparelho que ainda não conhecíamos.\n\n"
            . 'Data e hora: ' . $local->format('d/m/Y H:i') . " (horário de Brasília)\n"
            . "IP: {$ip}\n"
            . 'Aparelho: ' . Aparelho::descrever($agente) . "\n\n"
            . "Foi você? Então está tudo certo, não precisa fazer nada.\n\n"
            . "Não foi você? Crie uma senha nova agora: quem entrou é desconectado na hora.\n"
            . rtrim($this->urlBase, '/') . "/esqueci-senha\n\n"
            . 'Este aviso é automático e nunca pede senha nem código.';
        return ["{$usuario->primeiroNome()}, foi você que entrou na Captcha agora?", $texto];
    }
}
