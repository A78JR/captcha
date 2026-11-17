<?php

// MODELO. Copie para config/config.local.php e preencha. O config.local.php
// fica fora do git e nunca é enviado pelo deploy: no servidor ele é criado pela primeira publicação
// uma vez, em /projetos/captcha/config/config.local.php.

return [
    'fuso' => 'America/Sao_Paulo',
    'depurar' => false,

    'banco' => [
        'host' => 'localhost',
        'porta' => 3306,
        'nome' => 'burgucom_projetos',   // banco único dos projetos; as tabelas do Captcha começam com captcha_
        'usuario' => 'lingoist_projetos',
        'senha' => '',
    ],

    // O mesmo valor vai no segredo GENEALOGIA_MIGRAR_TOKEN do repositório. Gere com:
    // php -r "echo bin2hex(random_bytes(32));"
    'migrar_token' => '',

    // Endereço do site, usado nos links dos e-mails. Com https, os cookies só viajam cifrados.
    'url' => 'https://captcha.lingoist.com.br',

    // Chave secreta do Captcha (32 caracteres ou mais): assina os formulários e os códigos de entrada.
    // Gere com: php -r "echo bin2hex(random_bytes(32));"   Trocar a chave invalida os códigos em aberto.
    'chave_app' => '',

    // E-mails. Sem 'email_de_verdade' => true, eles não saem: ficam em temp/logs/emails.log.
    'email_remetente' => 'nao-responda@lingoist.com.br',
    'email_de_verdade' => true,

    // O login alfredolingoist@gmail.com é sempre webmaster (Captcha\Acesso\Webmasters). Aqui, só e-mails extras.
    'webmasters' => [],

    // Cadastro: qualquer pessoa pede uma conta, mas ela só entra depois que o webmaster aprovar em
    // Painel > Usuários (webmasters e convidados da equipe já nascem aprovados).

    // Entrada em duas etapas (código por e-mail). false desliga para todos: é a saída se o e-mail do
    // servidor parar e o webmaster não conseguir entrar.
    'duas_etapas' => true,
];
