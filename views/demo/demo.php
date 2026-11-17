<?php use Captcha\Nucleo\Visao; ?>
<h1>Experimentar</h1>
<p>Pegue o objeto cinza. Ao começar a carregar, ele inclina. Solte em cima do objeto igual, com a mesma inclinação.</p>
<?php if ($passou === true): ?>
    <p class="aviso aviso-sucesso" role="status">O servidor conferiu o passe: liberado.</p>
<?php elseif ($passou === false): ?>
    <p class="aviso aviso-erro" role="alert">O servidor não aceitou o passe. Tente de novo.</p>
<?php endif; ?>
<form method="post" action="/demo">
    <?= $campos->csrf() ?>
    <div data-captcha-site="demo" data-captcha-nonce="<?= Visao::escapar($nonce) ?>" data-captcha-enviar="1"></div>
</form>
<script src="/captcha.js?v=<?= @filemtime(dirname(__DIR__, 2) . '/public/captcha.js') ?: 1 ?>" defer></script>
