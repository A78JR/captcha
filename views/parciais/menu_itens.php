<?php use Captcha\Nucleo\Visao; use Captcha\Interface\Icone;
/** @var list<Captcha\Interface\ItemMenu> $itens */ ?>
<ul class="menu-lista<?= !empty($sub) ? ' menu-sub' : '' ?>">
    <?php foreach ($itens as $item): ?>
        <li><a class="menu-link" data-icone="<?= Visao::escapar($item->icone) ?>" href="<?= Visao::escapar($item->caminho) ?>" title="<?= Visao::escapar($item->rotulo) ?>"<?= $item === $atual ? ' aria-current="page"' : '' ?>>
            <?= Icone::svg($item->icone) ?><span class="rotulo"><?= Visao::escapar($item->rotulo) ?></span>
            <?php if ($item->contador > 0): ?><span class="contador"><?= $item->contador > 99 ? '99+' : $item->contador ?></span><?php endif; ?>
        </a></li>
    <?php endforeach; ?>
</ul>
