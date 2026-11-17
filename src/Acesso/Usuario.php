<?php

declare(strict_types=1);

namespace Captcha\Acesso;

/** Uma conta do Captcha (linha da tabela usuario), só para leitura. */
final class Usuario
{
    public const ATIVA = 'ativa';
    /** Cadastro pedido, esperando o webmaster aprovar (AprovacaoCadastro). */
    public const PENDENTE = 'pendente';
    public const SUSPENSA = 'suspensa';
    public const BANIDA = 'banida';
    public const EXCLUIDA = 'excluida';

    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly string $email,
        public readonly ?string $senhaHash,
        public readonly string $situacao,
        public readonly int $grupoId,
        public readonly string $credencial,
        public readonly ?string $emailConfirmadoEm,
        public readonly int $versaoSessao,
        public readonly bool $duasEtapas,
        public readonly string $criadoEm = '',
        public readonly ?string $ultimoAcessoEm = null,
    ) {
    }

    /** @param array<string, mixed> $linha */
    public static function daLinha(array $linha): self
    {
        return new self(
            (int) $linha['id'],
            (string) $linha['nome'],
            (string) $linha['email'],
            $linha['senha_hash'] !== null && $linha['senha_hash'] !== '' ? (string) $linha['senha_hash'] : null,
            (string) $linha['situacao'],
            (int) $linha['grupo_id'],
            (string) $linha['credencial'],
            $linha['email_confirmado_em'] !== null ? (string) $linha['email_confirmado_em'] : null,
            (int) $linha['versao_sessao'],
            (int) $linha['duas_etapas'] === 1,
            (string) ($linha['criado_em'] ?? ''),
            isset($linha['ultimo_acesso_em']) ? (string) $linha['ultimo_acesso_em'] : null,
        );
    }

    public function ativa(): bool
    {
        return $this->situacao === self::ATIVA;
    }

    public function pendente(): bool
    {
        return $this->situacao === self::PENDENTE;
    }

    public function emailConfirmado(): bool
    {
        return $this->emailConfirmadoEm !== null;
    }

    public function temSenha(): bool
    {
        return $this->senhaHash !== null;
    }

    /** Webmaster ou assistente (grupos 1 e 2). */
    public function daEquipe(): bool
    {
        return in_array($this->grupoId, [Permissoes::GRUPO_WEBMASTER, Permissoes::GRUPO_ASSISTENTE], true);
    }

    /** Primeiro nome, para as saudações. */
    public function primeiroNome(): string
    {
        return rtrim(explode(' ', trim($this->nome))[0], '.');
    }
}
