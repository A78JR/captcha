<?php use Captcha\Nucleo\Visao; ?>
<h1>Sistema</h1>
<?= $abasHtml ?>
<section class="cartao">
    <h2>Conferências</h2>
    <ul class="conferencias">
        <?php foreach ($conferencias as $nome => $ok): ?>
            <li class="<?= $ok ? 'ok' : 'falta' ?>"><?= $ok ? 'Certo' : 'Falta' ?>: <?= Visao::escapar($nome) ?></li>
        <?php endforeach; ?>
    </ul>
    <p class="suave">PHP <?= Visao::escapar($php) ?>.</p>
    <form method="post" action="/painel/sistema/email-teste">
        <?= $campos->csrf() ?>
        <button type="submit" class="secundario">Mandar um e-mail de teste para mim</button>
    </form>
</section>
<section class="cartao">
    <h2>Migrações do banco</h2>
    <p><?= count($feitas) ?> aplicadas<?= $pendentes !== [] ? ', ' . count($pendentes) . ' esperando: ' . Visao::escapar(implode(', ', $pendentes)) : '' ?>.</p>
</section>
<section class="cartao">
    <h2>Registro de segurança</h2>
    <?php if ($eventos === []): ?>
        <p class="suave">Nada suspeito registrado.</p>
    <?php else: ?>
        <div class="rolar"><table>
            <thead><tr><th>Quando (UTC)</th><th>O quê</th><th>IP</th><th>Caminho</th><th>Detalhe</th></tr></thead>
            <tbody>
            <?php foreach ($eventos as $e): ?>
                <tr>
                    <td><?= Visao::escapar(str_replace(['T', 'Z'], [' ', ''], (string) ($e['em'] ?? ''))) ?></td>
                    <td><?= Visao::escapar($e['tipo'] ?? '') ?></td>
                    <td><?= Visao::escapar($e['ip'] ?? '') ?></td>
                    <td><?= Visao::escapar($e['caminho'] ?? '') ?></td>
                    <td><?= Visao::escapar(implode(' · ', array_map(static fn ($k, $v): string => "{$k}: {$v}", array_keys(array_diff_key($e, array_flip(['em', 'tipo', 'ip', 'caminho', 'metodo', 'agente']))), array_diff_key($e, array_flip(['em', 'tipo', 'ip', 'caminho', 'metodo', 'agente']))))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<section class="cartao">
    <h2>Últimas ações da equipe</h2>
    <?php if ($acoes === []): ?>
        <p class="suave">Nenhuma ainda.</p>
    <?php else: ?>
        <div class="rolar"><table>
            <thead><tr><th>Quando (UTC)</th><th>Quem</th><th>Ação</th><th>Conta</th><th>Motivo</th></tr></thead>
            <tbody>
            <?php foreach ($acoes as $a): ?>
                <tr><td><?= Visao::escapar($a['criado_em']) ?></td><td><?= Visao::escapar($a['autor_nome'] ?? 'automático') ?></td><td><?= Visao::escapar($a['acao']) ?></td>
                    <td>#<?= (int) $a['alvo_id'] ?> <?= Visao::escapar($a['alvo_nome'] ?? '') ?></td><td><?= Visao::escapar($a['nota'] ?? '') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
