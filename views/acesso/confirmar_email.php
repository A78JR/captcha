<?php use Captcha\Nucleo\Visao; ?>
<section class="cartao estreito">
    <h1>Confirmar e-mail</h1>
    <form method="post" action="/verificar-email" data-enviar-sozinho>
        <?= $campos->csrf() ?>
        <input type="hidden" name="token" value="<?= Visao::escapar($token) ?>">
        <button type="submit">Confirmar meu e-mail</button>
    </form>
</section>
