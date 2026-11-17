-- Login e segurança do Captcha, iguais aos dos outros projetos (vieram do Kirk), com o prefixo captcha_.

CREATE TABLE IF NOT EXISTS captcha_usuario (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    nome VARCHAR(120) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    papel ENUM('admin', 'usuario') NOT NULL DEFAULT 'usuario',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
