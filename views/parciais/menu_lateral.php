<?php use Captcha\Nucleo\Visao; use Captcha\Nucleo\Marca; use Captcha\Interface\Icone;
// Menu lateral (Captcha\Interface\Menu), no molde do Bazar de Tudo. No computador: ícone e nome, e o botão
// "Recolher menu" deixa só os ícones. No celular ele fica escondido: o botão Menu do topo o abre por cima da página.
// Ordem fixa no fim: Tema claro/escuro, Recolher menu e, por último, Sair.
/** @var Captcha\Interface\Menu $menu */
$atual = $menu->atual();
?>
<nav class="menu-lateral" id="menu-lateral" aria-label="Menu">
    <a class="menu-marca" href="/" title="<?= Visao::escapar(Marca::NOME) ?>"><span class="logo" aria-hidden="true"><?= Visao::escapar(Marca::INICIAL) ?></span><span class="rotulo"><?= Visao::escapar(Marca::NOME) ?></span></a>
    <?php foreach ($menu->grupos as $grupo): ?>
        <?php if ($grupo->abre()): /* assunto que abre em subitens (Verbos → Aulas, Quiz…) */ ?>
            <details class="menu-assunto" data-assunto="<?= Visao::escapar($grupo->titulo) ?>"<?= $grupo->contem($atual) ? ' open' : '' ?>>
                <summary class="menu-link" title="<?= Visao::escapar($grupo->titulo) ?>"><?= Icone::svg($grupo->icone) ?><span class="rotulo"><?= Visao::escapar($grupo->titulo) ?></span><span class="seta" aria-hidden="true"></span></summary>
                <?php $itens = $grupo->itens; $sub = true; require __DIR__ . '/menu_itens.php'; ?>
            </details>
        <?php else: ?>
            <?php if ($grupo->titulo !== null): ?><p class="menu-titulo"><span class="rotulo"><?= Visao::escapar($grupo->titulo) ?></span></p><?php endif; ?>
            <?php $itens = $grupo->itens; $sub = false; require __DIR__ . '/menu_itens.php'; ?>
        <?php endif; ?>
    <?php endforeach; ?>
    <div class="menu-pe">
        <?= \Captcha\Interface\Tema::daRequisicao()->botao() ?>
        <button type="button" class="menu-link menu-recolher" data-menu-recolher aria-controls="menu-lateral" aria-expanded="true"
                data-rotulo-fechar="Recolher menu" data-rotulo-abrir="Abrir menu" title="Recolher menu">
            <?= Icone::svg('recolher') ?><span class="rotulo">Recolher menu</span>
        </button>
        <?php if ($menu->logado): ?>
            <form method="post" action="/sair">
                <?= $campos->csrf() ?>
                <button type="submit" class="menu-link" title="Sair"><?= Icone::svg('sair') ?><span class="rotulo">Sair</span></button>
            </form>
        <?php endif; ?>
    </div>
</nav>
