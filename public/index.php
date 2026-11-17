<?php

declare(strict_types=1);

// Front controller: o único arquivo procedural do Captcha. Só cria a aplicação e a executa.

require dirname(__DIR__) . '/vendor/autoload.php';

\Captcha\Nucleo\Aplicacao::criar(dirname(__DIR__))->executar();
