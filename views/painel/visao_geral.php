<?php use Captcha\Nucleo\Visao; ?>
<h1>Painel webmaster</h1>
<?= $abasHtml ?>
<div class="quadros">
    <?php foreach ($quadros as $quadro): ?>
        <?php if ($quadro['caminho'] !== null): ?>
            <a class="quadro" href="<?= Visao::escapar($quadro['caminho']) ?>"><strong><?= (int) $quadro['numero'] ?></strong> <?= Visao::escapar($quadro['rotulo']) ?></a>
        <?php else: ?>
            <div class="quadro"><strong><?= (int) $quadro['numero'] ?></strong> <?= Visao::escapar($quadro['rotulo']) ?></div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
