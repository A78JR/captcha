<?php use Captcha\Nucleo\Visao; ?>
<section class="cartao estreito">
    <h1>Criar conta</h1>
    <p class="suave">Depois de confirmar o e-mail, a conta espera a aprovação do webmaster para entrar.</p>
    <?php if (!empty($erros['formulario'])): ?><p class="aviso aviso-erro" role="alert"><?= Visao::escapar($erros['formulario']) ?></p><?php endif; ?>
    <form method="post" action="/cadastro">
        <?= $campos->publico() ?>
        <label>Nome <input type="text" name="nome" value="<?= Visao::escapar($antigo['nome'] ?? '') ?>" required maxlength="120" autocomplete="name"></label>
        <?php if (!empty($erros['nome'])): ?><p class="erro-campo"><?= Visao::escapar($erros['nome']) ?></p><?php endif; ?>
        <label>E-mail <input type="email" name="email" value="<?= Visao::escapar($antigo['email'] ?? '') ?>" required maxlength="190" autocomplete="email"></label>
        <?php if (!empty($erros['email'])): ?><p class="erro-campo"><?= Visao::escapar($erros['email']) ?></p><?php endif; ?>
        <label>Senha <input type="password" name="senha" required minlength="10" autocomplete="new-password"></label>
        <?php if (!empty($erros['senha'])): ?><p class="erro-campo"><?= Visao::escapar($erros['senha']) ?></p><?php endif; ?>
        <small class="suave">10 caracteres ou mais, com letras e números.</small>
        <label>Repita a senha <input type="password" name="senha_confirmacao" required minlength="10" autocomplete="new-password"></label>
        <?php if (!empty($erros['senha_confirmacao'])): ?><p class="erro-campo"><?= Visao::escapar($erros['senha_confirmacao']) ?></p><?php endif; ?>
        <button type="submit">Criar conta</button>
    </form>
    <p class="suave">Já tem conta? <a href="/entrar">Entre</a>.</p>
</section>
