<?php use Captcha\Nucleo\Visao; use Captcha\Interface\Icone; $sufixo = isset($consulta) && $consulta !== [] ? '?' . http_build_query($consulta) : ''; ?>
<nav class="abas" aria-label="<?= Visao::escapar($rotulo ?? 'Painel webmaster') ?>">
    <?php foreach ($abas as $aba): ?>
        <a href="<?= Visao::escapar($aba->caminho . $sufixo) ?>"<?= $aba === $abaAtual ? ' aria-current="page"' : '' ?>><?= Icone::svg($aba->icone) ?><span><?= Visao::escapar($aba->rotulo) ?></span></a>
    <?php endforeach; ?>
</nav>
