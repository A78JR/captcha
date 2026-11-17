<?php

declare(strict_types=1);

namespace Captcha\Acesso;

use PDO;
use PDOException;

/**
 * Registro de acessos (Marco Civil da Internet, art. 15): quem entrou, de qual IP, com qual navegador e
 * quando. 'entrada' a cada login; 'acesso' no máximo uma vez por hora para o mesmo IP. O registro nunca
 * derruba a página: se o banco falhar, só anota no log do PHP.
 */
final class RegistroAcessos
{
    public const EVENTOS = ['entrada', 'acesso'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function registrar(int $usuarioId, string $evento, string $ip, ?string $agente): void
    {
        if (!in_array($evento, self::EVENTOS, true) || $ip === '' || @inet_pton($ip) === false) {
            return;
        }
        $agente = $agente !== null ? mb_substr(trim($agente), 0, 255) : '';
        try {
            $this->pdo->prepare('INSERT INTO captcha_registro_acesso (usuario_id, evento, ip, agente) VALUES (?, ?, ?, ?)')
                ->execute([$usuarioId, $evento, inet_pton($ip), $agente !== '' ? $agente : null]);
            $this->pdo->prepare('UPDATE captcha_usuario SET ultimo_acesso_em = UTC_TIMESTAMP() WHERE id = ?')->execute([$usuarioId]);
        } catch (PDOException $erro) {
            error_log('[captcha] Registro de acesso falhou: ' . $erro->getMessage());
        }
    }

    /**
     * Entradas anteriores da conta, da mais nova para a mais antiga.
     *
     * @return list<array{ip: string, agente: ?string, em: string}>
     */
    public function entradas(int $usuarioId, int $limite = 50): array
    {
        $consulta = $this->pdo->prepare(
            "SELECT ip, agente, criado_em FROM captcha_registro_acesso WHERE usuario_id = ? AND evento = 'entrada'
             ORDER BY id DESC LIMIT " . max(1, min(500, $limite))
        );
        $consulta->execute([$usuarioId]);
        $linhas = [];
        foreach ($consulta->fetchAll() as $linha) {
            $ip = @inet_ntop((string) $linha['ip']);
            if ($ip !== false) {
                $linhas[] = ['ip' => $ip, 'agente' => $linha['agente'], 'em' => (string) $linha['criado_em']];
            }
        }
        return $linhas;
    }
}
