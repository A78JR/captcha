<?php

declare(strict_types=1);

namespace Captcha\Painel;

use Captcha\Acesso\Permissoes;
use Captcha\Acesso\Usuario;
use Captcha\Seguranca\BloqueioEntrada;
use PDO;

/**
 * Painel webmaster (/painel), a Visão geral: o que está acontecendo, num lugar só. Cada quadro com endereço
 * é um botão que abre a tela do assunto e só aparece para quem tem a permissão daquela tela; quem não tem
 * não recebe nem o número.
 */
final class PainelWebmaster
{
    public const PERMISSAO = 'painel';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Permissoes $permissoes,
        private readonly BloqueioEntrada $bloqueio,
    ) {
    }

    /** @return list<array{rotulo: string, numero: int, caminho: string|null}> */
    public function quadros(Usuario $usuario): array
    {
        $quadros = [];
        if ($this->permissoes->pode($usuario, AdminUsuarios::PERMISSAO)) {
            $quadros[] = $this->quadro('Cadastros aguardando aprovação', "SELECT COUNT(*) FROM captcha_usuario WHERE situacao = 'pendente'", '/painel/usuarios?situacao=pendente');
            $quadros[] = $this->quadro('Contas novas em 7 dias', 'SELECT COUNT(*) FROM captcha_usuario WHERE criado_em >= UTC_TIMESTAMP() - INTERVAL 7 DAY', '/painel/usuarios');
            $quadros[] = $this->quadro('Contas sem e-mail confirmado', "SELECT COUNT(*) FROM captcha_usuario WHERE situacao = 'ativa' AND email_confirmado_em IS NULL", '/painel/usuarios?situacao=sem_email');
            $quadros[] = $this->quadro('Contas suspensas ou banidas', "SELECT COUNT(*) FROM captcha_usuario WHERE situacao IN ('suspensa', 'banida')", '/painel/usuarios?situacao=suspensa');
        }
        if ($this->permissoes->pode($usuario, Equipe::PERMISSAO)) {
            $quadros[] = $this->quadro('Convites abertos para a equipe',
                'SELECT COUNT(*) FROM captcha_convite_equipe WHERE aceito_em IS NULL AND revogado_em IS NULL AND criado_em > UTC_TIMESTAMP() - INTERVAL ' . Equipe::VALIDADE_DIAS . ' DAY', '/painel/equipe');
        }
        if ($this->permissoes->pode($usuario, 'painel.sistema')) {
            $quadros[] = $this->quadro('Senhas erradas em 24 horas', 'SELECT COUNT(*) FROM captcha_tentativa_entrada WHERE sucesso = 0 AND criado_em >= UTC_TIMESTAMP() - INTERVAL 1 DAY', '/painel/sistema');
            $quadros[] = ['rotulo' => 'IPs bloqueados na entrada agora', 'numero' => $this->bloqueio->bloqueadosAgora(), 'caminho' => '/painel/sistema'];
        }
        $quadros[] = $this->quadro('Entradas em 24 horas', "SELECT COUNT(*) FROM captcha_registro_acesso WHERE evento = 'entrada' AND criado_em >= UTC_TIMESTAMP() - INTERVAL 1 DAY", null);
        $quadros[] = $this->quadro('Contas ativas', "SELECT COUNT(*) FROM captcha_usuario WHERE situacao = 'ativa'", null);
        return $quadros;
    }

    /** @return array{rotulo: string, numero: int, caminho: string|null} */
    private function quadro(string $rotulo, string $sql, ?string $caminho): array
    {
        return ['rotulo' => $rotulo, 'numero' => (int) $this->pdo->query($sql)->fetchColumn(), 'caminho' => $caminho];
    }
}
