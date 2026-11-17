<?php use Captcha\Nucleo\Visao; ?>
<section class="cartao estreito">
    <?php if ($feito === true): ?>
        <h1>Entrada autorizada</h1>
        <p>Pronto. Volte para a janela onde você pediu para entrar: ela entra sozinha em alguns segundos.</p>
    <?php elseif ($feito === false): ?>
        <h1>Link vencido</h1>
        <p>Este link já foi usado ou passou dos 10 minutos. Entre de novo para receber outro.</p>
        <p><a href="/entrar">Entrar</a></p>
    <?php else: ?>
        <h1>Autorizar a entrada?</h1>
        <p>Toque no botão só se foi você que acabou de digitar a senha do Captcha.</p>
        <form method="post" action="/entrar/autorizar">
            <?= $campos->csrf() ?>
            <input type="hidden" name="t" value="<?= Visao::escapar($token) ?>">
            <button type="submit">Autorizar</button>
        </form>
    <?php endif; ?>
</section>
