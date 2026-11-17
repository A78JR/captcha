<?php use Captcha\Nucleo\Visao; ?>
<section class="cartao estreito">
    <h1>Confira seu e-mail</h1>
    <p>Se o cadastro foi aceito, mandamos para <strong><?= Visao::escapar($email) ?></strong> um link para ativar a conta (vale por 24 horas).</p>
    <p class="suave">A conta só entra depois do link e da aprovação do webmaster (você recebe um e-mail quando ela for aprovada). Não chegou? Olhe a caixa de spam ou tente entrar: o link vai de novo.</p>
</section>
