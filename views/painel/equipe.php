<?php use Captcha\Nucleo\Visao; ?>
<h1>Equipe</h1>
<?= $abasHtml ?>
<section class="cartao">
    <h2>Chamar para a equipe</h2>
    <p class="suave">A pessoa vira assistente: abre o Painel e cuida das contas, mas não mexe na equipe nem em grupos. Ela entra sempre em duas etapas.</p>
    <form method="post" action="/painel/equipe/convidar" class="linha">
        <?= $campos->csrf() ?>
        <label>E-mail <input type="email" name="email" required maxlength="190"></label>
        <button type="submit">Convidar</button>
    </form>
</section>
<section class="cartao">
    <h2>Quem está na equipe</h2>
    <div class="rolar"><table>
        <thead><tr><th>Nome</th><th>E-mail</th><th>Papel</th><th>Ações no painel (1 dia / 7 dias)</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($membros as $m): ?>
            <tr>
                <td><?= Visao::escapar($m['nome']) ?></td>
                <td><?= Visao::escapar($m['email']) ?></td>
                <td><?= (int) $m['grupo_id'] === 1 ? 'Webmaster' : 'Assistente' ?><?= $m['situacao'] !== 'ativa' ? ' (' . Visao::escapar($m['situacao']) . ')' : '' ?></td>
                <td><?= (int) $m['acoes_dia'] ?> / <?= (int) $m['acoes_semana'] ?></td>
                <td><?php if ((int) $m['grupo_id'] === 2): ?>
                    <form method="post" action="/painel/equipe/<?= (int) $m['id'] ?>/remover"><?= $campos->csrf() ?><button type="submit" class="secundario">Tirar da equipe</button></form>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php if ($convites !== []): ?>
<section class="cartao">
    <h2>Convites abertos</h2>
    <ul>
        <?php foreach ($convites as $c): ?>
            <li><?= Visao::escapar($c['email']) ?> <span class="suave">(por <?= Visao::escapar($c['convidado_por_nome'] ?? '—') ?>, <?= Visao::escapar($c['criado_em']) ?> UTC)</span>
                <form method="post" action="/painel/equipe/convite/<?= (int) $c['id'] ?>/revogar" class="em-linha"><?= $campos->csrf() ?><button type="submit" class="link">cancelar</button></form></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
