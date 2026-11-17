<?php use Captcha\Nucleo\Visao; use Captcha\Nucleo\Marca; use Captcha\Interface\Icone; use Captcha\Interface\Tema; $tema = Tema::daRequisicao(); ?>
<!doctype html>
<html lang="pt-BR"<?= $tema->atributoHtml() ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Visao::escapar($titulo) ?><?= $titulo !== Marca::NOME ? ' · ' . Marca::NOME : '' ?></title>
    <link rel="stylesheet" href="/css/painel.css?v=<?= @filemtime(dirname(__DIR__) . '/public/css/painel.css') ?: 1 ?>">
    <script src="/js/painel.js?v=<?= @filemtime(dirname(__DIR__) . '/public/js/painel.js') ?: 1 ?>" defer></script>
</head>
<body>
<div class="moldura">
<?= $menuHtml ?? '' ?>
<div class="pagina">
    <header class="topo">
        <?php if ($menu !== null): ?>
            <button type="button" class="botao-menu" data-menu-abrir aria-controls="menu-lateral" aria-expanded="false"><?= Icone::svg('menu') ?><span>Menu</span></button>
        <?php endif; ?>
        <a class="marca" href="/"><?= Visao::escapar(Marca::NOME) ?></a>
        <?php if ($menu === null): ?><?= $tema->botao() ?><?php endif; ?>
    </header>
    <main>
        <?php foreach ($avisos ?? [] as $tipo => $texto): ?>
            <p class="aviso aviso-<?= Visao::escapar($tipo) ?>" role="<?= $tipo === 'erro' ? 'alert' : 'status' ?>"><?= Visao::escapar($texto) ?></p>
        <?php endforeach; ?>
        <?= $conteudo ?>
    </main>
</div>
</div>
</body>
</html>
