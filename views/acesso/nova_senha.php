<?php use Captcha\Nucleo\Visao; ?>
<section class="cartao estreito">
    <h1>Senha nova</h1>
    <?php if (!$vale): ?>
        <p>Este link expirou ou já foi usado. <a href="/esqueci-senha">Peça um novo</a>.</p>
    <?php else: ?>
        <?php if (!empty($erros['formulario'])): ?><p class="aviso aviso-erro" role="alert"><?= Visao::escapar($erros['formulario']) ?></p><?php endif; ?>
        <form method="post" action="/nova-senha">
            <?= $campos->csrf() ?>
            <input type="hidden" name="token" value="<?= Visao::escapar($token) ?>">
            <label>Senha nova <input type="password" name="senha" required minlength="10" autocomplete="new-password"></label>
            <?php if (!empty($erros['senha'])): ?><p class="erro-campo"><?= Visao::escapar($erros['senha']) ?></p><?php endif; ?>
            <small class="suave">10 caracteres ou mais, com letras e números.</small>
            <label>Repita a senha <input type="password" name="senha_confirmacao" required minlength="10" autocomplete="new-password"></label>
            <?php if (!empty($erros['senha_confirmacao'])): ?><p class="erro-campo"><?= Visao::escapar($erros['senha_confirmacao']) ?></p><?php endif; ?>
            <button type="submit">Gravar senha nova</button>
        </form>
    <?php endif; ?>
</section>
