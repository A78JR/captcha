<?php use Captcha\Nucleo\Visao; ?>
<section class="cartao estreito">
    <h1>Esqueci minha senha</h1>
    <?php if (!empty($erro)): ?><p class="aviso aviso-erro" role="alert"><?= Visao::escapar($erro) ?></p><?php endif; ?>
    <p>Digite o e-mail da conta. Mandamos um link para criar uma senha nova (vale por 1 hora).</p>
    <form method="post" action="/esqueci-senha">
        <?= $campos->publico() ?>
        <label>E-mail <input type="email" name="email" required maxlength="190" autocomplete="email"></label>
        <button type="submit">Mandar o link</button>
    </form>
</section>
