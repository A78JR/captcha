-- O captcha de encaixe. Tudo com o prefixo captcha_ no banco compartilhado burgucom_projetos.
-- O Migrador separa os comandos por ponto e vírgula: nada de ponto e vírgula dentro de textos ou COMMENT.

-- Cada sistema que usa o captcha. As origens são os endereços (https://...) de onde o captcha.js pode
-- pedir desafios, separados por espaço. O passe assinado leva o slug, e o site confere que é o dele.
CREATE TABLE captcha_site (
    id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug       VARCHAR(30)       NOT NULL COMMENT 'nome curto usado no captcha.js e no passe (ex.: idioma)',
    nome       VARCHAR(80)       NOT NULL,
    origens    VARCHAR(600)      NOT NULL COMMENT 'origens permitidas, separadas por espaço',
    ativo      TINYINT(1)        NOT NULL DEFAULT 1,
    criado_em  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY captcha_site_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO captcha_site (slug, nome, origens) VALUES
 ('demo', 'Demonstração (o próprio captcha)', 'https://captcha.lingoist.com.br'),
 ('idioma', 'Espanhol e Português', 'https://espanhol.lingoist.com.br https://portugues.lingoist.com.br'),
 ('furniture', 'Curso de estofados', 'https://furniture.lingoist.com.br'),
 ('genealogia', 'Genealogia', 'https://genealogia.lingoist.com.br'),
 ('school', 'Escolas nos EUA', 'https://school.lingoist.com.br'),
 ('kirk', 'Kirk', 'https://kirk.lingoist.com.br');

-- Um desafio por linha. As imagens não ficam guardadas: saem de novo da semente a cada pedido
-- (Captcha\Desafio\Plano). A peça certa (objeto, centro e giro) fica copiada aqui para a conferência e
-- para as estatísticas, sem precisar refazer o sorteio.
CREATE TABLE captcha_desafio (
    id            CHAR(32)          NOT NULL COMMENT 'código aleatório (hex) que o navegador usa',
    site_id       SMALLINT UNSIGNED NOT NULL,
    semente       INT UNSIGNED      NOT NULL,
    cena          ENUM('cozinha','brinquedos') NOT NULL,
    objeto        VARCHAR(20)       NOT NULL,
    alvo_x        SMALLINT          NOT NULL,
    alvo_y        SMALLINT          NOT NULL,
    alvo_giro     SMALLINT          NOT NULL,
    nonce_site    VARCHAR(64)       NOT NULL COMMENT 'código da sessão do site, devolvido dentro do passe',
    chave_ip      CHAR(64)          NOT NULL COMMENT 'sha256 do IP com a chave_app (sem guardar o IP)',
    situacao      ENUM('aberto','resolvido','falhou') NOT NULL DEFAULT 'aberto',
    motivo        VARCHAR(30)       NULL     COMMENT 'por que falhou: objeto, inclinacao, fora, rapido, movimento, expirado',
    criado_em     DATETIME(3)       NOT NULL,
    pegou_em      DATETIME(3)       NULL     COMMENT 'quando a pessoa começou a carregar (pediu a imagem inclinada)',
    respondido_em DATETIME(3)       NULL,
    PRIMARY KEY (id),
    KEY captcha_desafio_site_dia (site_id, criado_em),
    KEY captcha_desafio_ip (chave_ip, criado_em),
    CONSTRAINT captcha_desafio_site_fk FOREIGN KEY (site_id) REFERENCES captcha_site (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chaves e ajustes do serviço (ex.: o par de chaves Ed25519 que assina os passes, criado na 1ª vez).
CREATE TABLE captcha_config (
    nome  VARCHAR(40)  NOT NULL,
    valor VARCHAR(255) NOT NULL,
    PRIMARY KEY (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO captcha_permissao (slug, descricao) VALUES
 ('captcha.estatisticas', 'Ver as estatísticas do captcha e os sites ligados');

INSERT INTO captcha_grupo_permissao (grupo_id, permissao_id)
SELECT g.id, p.id FROM captcha_grupo_acesso g JOIN captcha_permissao p ON p.slug = 'captcha.estatisticas'
WHERE g.slug = 'assistente'
