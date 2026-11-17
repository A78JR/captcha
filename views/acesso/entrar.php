<?php use Captcha\Nucleo\Visao; ?>
<section class="cartao estreito">
    <h1>Entrar</h1>
    <?php if (!empty($erros['formulario'])): ?><p class="aviso aviso-erro" role="alert"><?= Visao::escapar($erros['formulario']) ?></p><?php endif; ?>
    <form method="post" action="/entrar">
        <?= $campos->publico() ?>
        <label>E-mail <input type="email" name="email" value="<?= Visao::escapar($antigo['email'] ?? '') ?>" required autocomplete="email" maxlength="190"></label>
        <label>Senha <input type="password" name="senha" required autocomplete="current-password"></label>
        <p class="direita"><a href="/esqueci-senha">Esqueci minha senha</a></p>
        <button type="submit">Entrar</button>
    </form>
    <p class="suave">Ainda não tem conta? <a href="/cadastro">Crie a sua</a>.</p>
</section>
