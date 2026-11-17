<?php

declare(strict_types=1);

namespace Captcha\Seguranca;

use Captcha\Nucleo\Requisicao;
use PDO;
use PDOException;

/**
 * Bloqueio temporário do IP depois de muitas falhas SEGUIDAS na entrada e no cadastro: senha errada,
 * campo-isca preenchido, formulário rápido demais.
 *
 *  - MAXIMO_FALHAS falhas em JANELA_FALHAS sem um acerto no meio = o IP fica bloqueado na entrada e no
 *    cadastro (o resto do site continua aberto, e quem já está logado não é afetado);
 *  - cada bloqueio é uma advertência e o próximo dura mais: 15 min, 1 h, 4 h e 24 h;
 *  - as advertências somem depois de 24 horas sem bloqueio novo; uma entrada certa zera só as falhas.
 *
 * O limite é 8, e não 3 ou 5, porque no Brasil muita gente de celular divide o mesmo IP (CGNAT). Um IPv6
 * vale pela faixa /64 (LimiteTaxa::chaveIp). Se o banco falhar, ninguém é bloqueado e valem os outros limites.
 */
final class BloqueioEntrada
{
    public const MAXIMO_FALHAS = 8;
    public const JANELA_FALHAS = 900;
    /** Duração do 1º, 2º, 3º e 4º bloqueio em diante. */
    public const BLOQUEIOS = [900, 3600, 14400, 86400];
    public const JANELA_ADVERTENCIAS = 86400;

    public function __construct(
        private readonly PDO $pdo,
        private readonly RegistroSeguranca $registro,
    ) {
    }

    /** Segundos que faltam para o IP poder tentar de novo (0 = liberado). */
    public function bloqueadoPor(string $ip, ?int $agora = null): int
    {
        $agora ??= time();
        try {
            $linha = $this->linha(LimiteTaxa::chaveIp($ip));
        } catch (PDOException $erro) {
            error_log('[captcha] BloqueioEntrada: ' . $erro->getMessage());
            return 0;
        }
        return $linha === null ? 0 : max(0, (int) $linha['bloqueado_ate'] - $agora);
    }

    /** Conta uma falha. Devolve por quantos segundos o IP ficou bloqueado, se esta falha bloqueou (0 = ainda não). */
    public function falhar(string $ip, ?int $agora = null): int
    {
        $agora ??= time();
        $chave = LimiteTaxa::chaveIp($ip);
        $inicioJanela = $agora - self::JANELA_FALHAS;
        // Uma instrução só (atômica): falhas de pedidos simultâneos não se perdem.
        $atualizar = $this->pdo->prepare(
            'UPDATE captcha_bloqueio_entrada SET
                falhas = CASE WHEN primeira_falha <= ? THEN 1 ELSE falhas + 1 END,
                primeira_falha = CASE WHEN primeira_falha <= ? THEN ? ELSE primeira_falha END
             WHERE chave_ip = ?'
        );
        $atualizar->execute([$inicioJanela, $inicioJanela, $agora, $chave]);
        if ($atualizar->rowCount() === 0) {
            try {
                $this->pdo->prepare(
                    'INSERT INTO captcha_bloqueio_entrada (chave_ip, falhas, primeira_falha, advertencias, ultima_advertencia, bloqueado_ate)
                     VALUES (?, 1, ?, 0, 0, 0)'
                )->execute([$chave, $agora]);
            } catch (PDOException) {
                $atualizar->execute([$inicioJanela, $inicioJanela, $agora, $chave]); // outro pedido criou a linha antes
            }
        }

        $linha = $this->linha($chave);
        if ($linha === null || (int) $linha['falhas'] < self::MAXIMO_FALHAS || (int) $linha['bloqueado_ate'] > $agora) {
            return 0;
        }
        $advertencias = (int) $linha['ultima_advertencia'] <= $agora - self::JANELA_ADVERTENCIAS ? 1 : (int) $linha['advertencias'] + 1;
        $segundos = self::BLOQUEIOS[min($advertencias, count(self::BLOQUEIOS)) - 1];
        // "bloqueado_ate <= ?": se dois pedidos chegarem juntos aqui, só um aplica o bloqueio.
        $bloquear = $this->pdo->prepare(
            'UPDATE captcha_bloqueio_entrada SET falhas = 0, advertencias = ?, ultima_advertencia = ?, bloqueado_ate = ?
             WHERE chave_ip = ? AND bloqueado_ate <= ?'
        );
        $bloquear->execute([$advertencias, $agora, $agora + $segundos, $chave, $agora]);
        return $bloquear->rowCount() > 0 ? $segundos : 0;
    }

    /** falhar() com o registro de segurança quando a falha bloqueia o IP. */
    public function registrarFalha(Requisicao $requisicao): void
    {
        try {
            $segundos = $this->falhar($requisicao->ip);
        } catch (PDOException $erro) {
            error_log('[captcha] BloqueioEntrada: ' . $erro->getMessage());
            return;
        }
        if ($segundos > 0) {
            $this->registro->evento('ip_bloqueado_entrada', $requisicao, ['segundos' => $segundos]);
        }
    }

    /** Entrada certa: zera as falhas seguidas (as advertências continuam valendo por 24 horas). */
    public function acertar(string $ip): void
    {
        try {
            $this->pdo->prepare('UPDATE captcha_bloqueio_entrada SET falhas = 0 WHERE chave_ip = ?')->execute([LimiteTaxa::chaveIp($ip)]);
        } catch (PDOException $erro) {
            error_log('[captcha] BloqueioEntrada: ' . $erro->getMessage());
        }
    }

    /** O IP teve falha na janela atual (15 min)? Erro no banco conta como falha. */
    public function falhouAgora(string $ip, ?int $agora = null): bool
    {
        $agora ??= time();
        try {
            $linha = $this->linha(LimiteTaxa::chaveIp($ip));
        } catch (PDOException $erro) {
            error_log('[captcha] BloqueioEntrada: ' . $erro->getMessage());
            return true;
        }
        return $linha !== null && (int) $linha['falhas'] > 0 && (int) $linha['primeira_falha'] > $agora - self::JANELA_FALHAS;
    }

    /** Quantos IPs estão bloqueados agora (quadro do Painel). */
    public function bloqueadosAgora(?int $agora = null): int
    {
        $consulta = $this->pdo->prepare('SELECT COUNT(*) FROM captcha_bloqueio_entrada WHERE bloqueado_ate > ?');
        $consulta->execute([$agora ?? time()]);
        return (int) $consulta->fetchColumn();
    }

    /** Aviso para a pessoa, com o tempo que falta. */
    public static function mensagem(int $segundos): string
    {
        if ($segundos < 3600) {
            return 'Muitas tentativas erradas seguidas deste endereço. Tente novamente em ' . max(1, (int) ceil($segundos / 60)) . ' minutos.';
        }
        return 'Muitas tentativas erradas seguidas deste endereço. Tente novamente em ' . (int) ceil($segundos / 3600) . ' horas.';
    }

    /** @return array<string, int|string>|null */
    private function linha(string $chave): ?array
    {
        $consulta = $this->pdo->prepare('SELECT falhas, primeira_falha, advertencias, ultima_advertencia, bloqueado_ate FROM captcha_bloqueio_entrada WHERE chave_ip = ?');
        $consulta->execute([$chave]);
        $linha = $consulta->fetch(PDO::FETCH_ASSOC);
        return $linha === false ? null : $linha;
    }
}
