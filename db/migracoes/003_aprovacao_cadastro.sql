-- Cadastro com aprovação do webmaster: a conta nova nasce 'pendente' e só entra depois de aprovada
-- (Painel > Usuários). O Painel registra as ações 'aprovar' e 'recusar'.

ALTER TABLE captcha_usuario
    MODIFY situacao ENUM('pendente', 'ativa', 'suspensa', 'banida', 'excluida') NOT NULL DEFAULT 'ativa';

ALTER TABLE captcha_registro_painel
    MODIFY acao ENUM('aprovar', 'recusar', 'suspender', 'banir', 'reativar', 'grupo', 'senha', 'desconectar', 'confirmacao', 'equipe_entrou', 'equipe_saiu') NOT NULL;
