<?php use Captcha\Nucleo\Visao; use Captcha\Painel\AdminUsuarios;
$ocultos = '';
foreach (['q' => 'f_q', 'grupo' => 'f_grupo', 'situacao' => 'f_situacao'] as $de => $para) {
    $ocultos .= '<input type="hidden" name="' . $para . '" value="' . Visao::escapar($filtros[$de] ?? '') . '">';
}
$ocultos .= '<input type="hidden" name="f_pagina" value="' . (int) $resultado['pagina'] . '">';
?>
<h1>Usuários</h1>
<?= $abasHtml ?>
<div class="quadros">
    <a class="quadro" href="/painel/usuarios?situacao=pendente"><strong><?= $contagens['pendente'] ?></strong> aguardando aprovação</a>
    <a class="quadro" href="/painel/usuarios"><strong><?= $contagens['total'] ?></strong> contas</a>
    <a class="quadro" href="/painel/usuarios?situacao=ativa"><strong><?= $contagens['ativa'] ?></strong> ativas</a>
    <a class="quadro" href="/painel/usuarios?situacao=sem_email"><strong><?= $contagens['sem_email'] ?></strong> sem e-mail confirmado</a>
    <a class="quadro" href="/painel/usuarios?situacao=suspensa"><strong><?= $contagens['suspensa'] ?></strong> suspensas</a>
    <a class="quadro" href="/painel/usuarios?situacao=banida"><strong><?= $contagens['banida'] ?></strong> banidas</a>
</div>
<form method="get" action="/painel/usuarios" class="linha filtros">
    <label>Busca <input type="search" name="q" value="<?= Visao::escapar($filtros['q']) ?>" placeholder="nome, e-mail ou número"></label>
    <label>Grupo <select name="grupo"><option value="">todos</option>
        <?php foreach ($grupos as $g): ?><option value="<?= (int) $g['id'] ?>"<?= (string) $g['id'] === $filtros['grupo'] ? ' selected' : '' ?>><?= Visao::escapar($g['nome']) ?></option><?php endforeach; ?>
    </select></label>
    <label>Situação <select name="situacao"><option value="">todas</option>
        <?php foreach (AdminUsuarios::SITUACOES + ['sem_email' => 'sem e-mail confirmado'] as $valor => $nome): ?><option value="<?= $valor ?>"<?= $valor === $filtros['situacao'] ? ' selected' : '' ?>><?= Visao::escapar($nome) ?></option><?php endforeach; ?>
    </select></label>
    <button type="submit">Filtrar</button>
</form>
<p class="suave"><?= $resultado['total'] ?> contas encontradas.</p>
<div class="rolar"><table class="usuarios">
    <thead><tr><th>Nº</th><th>Conta</th><th>Grupo</th><th>Situação</th><th>Duas etapas</th><th>Acessos (90 dias)</th><th>Último acesso (UTC)</th><th>Ações</th></tr></thead>
    <tbody>
    <?php foreach ($resultado['linhas'] as $u): $id = (int) $u['id']; ?>
        <tr id="conta-<?= $id ?>">
            <td><?= $id ?></td>
            <td><?= Visao::escapar($u['nome']) ?><br><small><?= Visao::escapar($u['email']) ?><?= $u['email_confirmado_em'] === null ? ' (e-mail não confirmado)' : '' ?></small></td>
            <td><?= Visao::escapar($u['grupo_nome'] ?? '') ?></td>
            <td><?= Visao::escapar(AdminUsuarios::SITUACOES[$u['situacao']] ?? $u['situacao']) ?></td>
            <td><?= (int) $u['duas_etapas'] === 1 || in_array((int) $u['grupo_id'], [1, 2], true) ? 'sim' : 'não' ?></td>
            <td><?= (int) $u['acessos_90'] ?></td>
            <td><?= Visao::escapar($u['ultimo_acesso_em'] ?? '—') ?></td>
            <td>
                <?php if ($u['situacao'] === 'pendente' && $podeGrupos): ?>
                    <form method="post" action="/painel/usuarios/<?= $id ?>/aprovar" class="linha">
                        <?= $campos->csrf() . $ocultos ?>
                        <?php if (count(AdminUsuarios::GRUPOS_LIVRES) > 1): ?>
                            <select name="grupo" aria-label="Grupo da conta aprovada"><?php foreach ($grupos as $g): if (in_array((int) $g['id'], AdminUsuarios::GRUPOS_LIVRES, true)): ?><option value="<?= (int) $g['id'] ?>"<?= (int) $g['id'] === AdminUsuarios::GRUPOS_LIVRES[array_key_last(AdminUsuarios::GRUPOS_LIVRES)] ? ' selected' : '' ?>><?= Visao::escapar($g['nome']) ?></option><?php endif; endforeach; ?></select>
                        <?php endif; ?>
                        <button type="submit">Aprovar</button>
                    </form>
                    <form method="post" action="/painel/usuarios/<?= $id ?>/recusar" class="linha"><?= $campos->csrf() . $ocultos ?><button type="submit" class="secundario">Recusar</button></form>
                <?php endif; ?>
                <details>
                    <summary>Agir</summary>
                    <?php foreach (['suspender' => 'Suspender', 'banir' => 'Banir', 'reativar' => 'Reativar'] as $acao => $rotulo): ?>
                        <form method="post" action="/painel/usuarios/<?= $id ?>/<?= $acao ?>" class="linha">
                            <?= $campos->csrf() . $ocultos ?>
                            <input type="text" name="motivo" placeholder="motivo" required minlength="5" maxlength="500">
                            <button type="submit" class="secundario"><?= $rotulo ?></button>
                        </form>
                    <?php endforeach; ?>
                    <?php if ($podeGrupos): ?>
                        <form method="post" action="/painel/usuarios/<?= $id ?>/grupo" class="linha">
                            <?= $campos->csrf() . $ocultos ?>
                            <select name="grupo"><?php foreach ($grupos as $g): if (in_array((int) $g['id'], AdminUsuarios::GRUPOS_LIVRES, true)): ?><option value="<?= (int) $g['id'] ?>"><?= Visao::escapar($g['nome']) ?></option><?php endif; endforeach; ?></select>
                            <button type="submit" class="secundario">Trocar grupo</button>
                        </form>
                    <?php endif; ?>
                    <?php foreach (['senha' => 'Mandar link de senha nova', 'desconectar' => 'Desconectar de todos os aparelhos', 'confirmacao' => 'Reenviar confirmação de e-mail'] as $acao => $rotulo): ?>
                        <form method="post" action="/painel/usuarios/<?= $id ?>/<?= $acao ?>"><?= $campos->csrf() . $ocultos ?><button type="submit" class="link"><?= $rotulo ?></button></form>
                    <?php endforeach; ?>
                </details>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php if ($resultado['paginas'] > 1): ?>
    <nav class="paginas" aria-label="Páginas">
        <?php for ($p = 1; $p <= $resultado['paginas']; $p++): ?>
            <a href="/painel/usuarios?<?= Visao::escapar(http_build_query(array_filter($filtros) + ['pagina' => $p])) ?>"<?= $p === $resultado['pagina'] ? ' aria-current="page"' : '' ?>><?= $p ?></a>
        <?php endfor; ?>
    </nav>
<?php endif; ?>
