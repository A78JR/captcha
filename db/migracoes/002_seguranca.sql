-- Segurança, login e painel webmaster, trazidos do Bazar de Tudo.
-- Horários em UTC (a Conexao fixa time_zone = +00:00). Os bloqueios de IP usam segundos Unix.

-- A conta ganha situação, grupo (como no burgu: grupo_id + credencial), e-mail confirmado,
-- número da sessão (sobe quando a senha muda e derruba os outros aparelhos) e duas etapas.
ALTER TABLE captcha_usuario
    DROP COLUMN papel,
    MODIFY senha_hash VARCHAR(255) NULL,
    ADD COLUMN situacao ENUM('ativa', 'suspensa', 'banida', 'excluida') NOT NULL DEFAULT 'ativa' AFTER senha_hash,
    ADD COLUMN grupo_id SMALLINT UNSIGNED NOT NULL DEFAULT 3 AFTER situacao,
    ADD COLUMN credencial VARCHAR(40) NOT NULL DEFAULT 'usuario' AFTER grupo_id,
    ADD COLUMN email_confirmado_em DATETIME NULL AFTER credencial,
    ADD COLUMN senha_definida_em DATETIME NULL AFTER email_confirmado_em,
    ADD COLUMN versao_sessao INT UNSIGNED NOT NULL DEFAULT 0 AFTER senha_definida_em,
    ADD COLUMN duas_etapas TINYINT(1) NOT NULL DEFAULT 0 AFTER versao_sessao,
    ADD COLUMN ultimo_acesso_em DATETIME NULL AFTER duas_etapas,
    ADD KEY usuario_grupo (grupo_id);

-- Grupos e permissões. O código pergunta sempre "pode fazer X?" (Captcha\Acesso\Permissoes::pode),
-- nunca "é do grupo Y?". A permissão "*" vale por todas (só o webmaster tem). Os números são fixos.
CREATE TABLE captcha_grupo_acesso (
    id SMALLINT UNSIGNED PRIMARY KEY,
    slug VARCHAR(40) NOT NULL UNIQUE,
    nome VARCHAR(80) NOT NULL,
    descricao VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE captcha_permissao (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(60) NOT NULL UNIQUE,
    descricao VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE captcha_grupo_permissao (
    grupo_id SMALLINT UNSIGNED NOT NULL,
    permissao_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (grupo_id, permissao_id),
    CONSTRAINT captcha_gp_grupo FOREIGN KEY (grupo_id) REFERENCES captcha_grupo_acesso (id) ON DELETE CASCADE,
    CONSTRAINT captcha_gp_permissao FOREIGN KEY (permissao_id) REFERENCES captcha_permissao (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO captcha_grupo_acesso (id, slug, nome, descricao) VALUES
 (1, 'webmaster', 'Webmaster', 'Dono do site: pode tudo, inclusive montar a equipe e mudar grupos.'),
 (2, 'assistente', 'Assistente do webmaster', 'Ajuda a cuidar do site: painel e contas, sem mexer na equipe nem em grupos.'),
 (3, 'usuario', 'Usuário', 'Conta comum. Grupo padrão de todo cadastro.');

INSERT INTO captcha_permissao (slug, descricao) VALUES
 ('*', 'Todas as permissões'),
 ('painel', 'Abrir o Painel webmaster (Visão geral)'),
 ('painel.sistema', 'Ver o Sistema: migrações, conferências e registro de segurança'),
 ('painel.equipe', 'Chamar e tirar assistentes da equipe'),
 ('painel.usuarios', 'Suspender, banir e reativar contas, mandar link de senha e desconectar'),
 ('painel.grupos', 'Mudar o grupo de uma conta e agir sobre contas da equipe'),
 ('entrada.duas_etapas', 'Entrar sempre em duas etapas (código por e-mail)');

INSERT INTO captcha_grupo_permissao (grupo_id, permissao_id)
SELECT g.id, p.id FROM captcha_grupo_acesso g JOIN captcha_permissao p ON
    (g.slug = 'webmaster' AND p.slug = '*')
 OR (g.slug = 'assistente' AND p.slug IN ('painel', 'painel.usuarios', 'entrada.duas_etapas'));

-- Limite de tentativas por janela fixa (Captcha\Seguranca\LimiteTaxa).
CREATE TABLE captcha_limite_taxa (
    balde VARCHAR(190) NOT NULL PRIMARY KEY,
    tentativas INT UNSIGNED NOT NULL DEFAULT 0,
    inicio DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bloqueio do IP depois de falhas seguidas na entrada e no cadastro (Captcha\Seguranca\BloqueioEntrada).
CREATE TABLE captcha_bloqueio_entrada (
    chave_ip VARCHAR(64) NOT NULL PRIMARY KEY,
    falhas INT UNSIGNED NOT NULL DEFAULT 0,
    primeira_falha INT UNSIGNED NOT NULL DEFAULT 0,
    advertencias INT UNSIGNED NOT NULL DEFAULT 0,
    ultima_advertencia INT UNSIGNED NOT NULL DEFAULT 0,
    bloqueado_ate INT UNSIGNED NOT NULL DEFAULT 0,
    KEY bloqueio_visto (primeira_falha, ultima_advertencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada conferência de senha, certa ou errada.
CREATE TABLE captcha_tentativa_entrada (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip VARBINARY(16) NOT NULL,
    sucesso TINYINT(1) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY tentativa_email (email, criado_em),
    KEY tentativa_ip (ip, criado_em),
    KEY tentativa_quando (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de acessos (Marco Civil, art. 15): entradas e, no máximo uma vez por hora, os acessos.
CREATE TABLE captcha_registro_acesso (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    evento ENUM('entrada', 'acesso') NOT NULL,
    ip VARBINARY(16) NOT NULL,
    agente VARCHAR(255) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY acesso_usuario (usuario_id, criado_em),
    KEY acesso_quando (criado_em),
    CONSTRAINT captcha_acesso_usuario FOREIGN KEY (usuario_id) REFERENCES captcha_usuario (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Links de confirmação de e-mail e de senha nova. Só o hash do token fica guardado.
CREATE TABLE captcha_token_verificacao (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    finalidade ENUM('email', 'senha') NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    vence_em DATETIME NOT NULL,
    usado_em DATETIME NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY token_usuario (usuario_id, finalidade),
    CONSTRAINT captcha_token_usuario FOREIGN KEY (usuario_id) REFERENCES captcha_usuario (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tentativas da entrada em duas etapas (Captcha\Acesso\EntradaDuasEtapas).
CREATE TABLE captcha_desafio_entrada (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    alca_hash CHAR(64) NOT NULL UNIQUE,
    link_hash CHAR(64) NOT NULL UNIQUE,
    codigo_hash CHAR(64) NOT NULL,
    ip VARBINARY(16) NOT NULL,
    agente VARCHAR(255) NULL,
    tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
    autorizado_em DATETIME NULL,
    usado_em DATETIME NULL,
    vence_em DATETIME NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY desafio_usuario (usuario_id, vence_em),
    CONSTRAINT captcha_desafio_usuario FOREIGN KEY (usuario_id) REFERENCES captcha_usuario (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Convites para a equipe (Painel webmaster > Equipe).
CREATE TABLE captcha_convite_equipe (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    convidado_por INT UNSIGNED NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aceito_em DATETIME NULL,
    usuario_aceito_id INT UNSIGNED NULL,
    revogado_em DATETIME NULL,
    removido_em DATETIME NULL,
    removido_por INT UNSIGNED NULL,
    KEY convite_email (email),
    KEY convite_usuario (usuario_aceito_id),
    CONSTRAINT captcha_convite_por FOREIGN KEY (convidado_por) REFERENCES captcha_usuario (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O que a equipe fez nas contas pelo Painel: quem, em quem, quando e por quê.
CREATE TABLE captcha_registro_painel (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alvo_id INT UNSIGNED NOT NULL,
    acao ENUM('suspender', 'banir', 'reativar', 'grupo', 'senha', 'desconectar', 'confirmacao', 'equipe_entrou', 'equipe_saiu') NOT NULL,
    autor_id INT UNSIGNED NULL,
    nota VARCHAR(1000) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY painel_alvo (alvo_id),
    KEY painel_autor (autor_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
