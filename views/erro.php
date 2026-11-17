<?php use Captcha\Nucleo\Visao; ?>
<section class="cartao">
    <h1><?= (int) $codigo ?></h1>
    <p><?= Visao::escapar($mensagem) ?></p>
    <p><a href="/">Voltar ao início</a></p>
</section>
