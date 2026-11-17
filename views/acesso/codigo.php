<?php use Captcha\Nucleo\Visao; ?>
<section class="cartao estreito" data-duas-etapas="/entrar/codigo/situacao">
    <h1>Confirme que é você</h1>
    <p>Mandamos um e-mail para <strong><?= Visao::escapar($email) ?></strong> com um código de 3 números e um botão Autorizar.</p>
    <form method="post" action="/entrar/codigo" novalidate>
        <?= $campos->csrf() ?>
        <label>Código do e-mail
            <input type="text" name="codigo" class="codigo" inputmode="numeric" pattern="[0-9]{3}" maxlength="3" autocomplete="one-time-code" required autofocus>
        </label>
        <?php if (!empty($erros['codigo'])): ?><p class="erro-campo"><?= Visao::escapar($erros['codigo']) ?></p><?php endif; ?>
        <button type="submit">Entrar</button>
    </form>
    <p class="suave">Se tocar em Autorizar no e-mail, esta página entra sozinha, mesmo que o e-mail esteja aberto no celular.</p>
    <form method="post" action="/entrar/codigo/reenviar">
        <?= $campos->csrf() ?>
        <p class="suave">Não chegou? Olhe a caixa de spam. <button type="submit" class="link">Mandar outro código</button></p>
    </form>
</section>
