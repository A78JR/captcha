<?php use Captcha\Nucleo\Visao; use Captcha\Painel\EstatisticasControlador;
$motivos = ['objeto' => 'objeto errado', 'inclinacao' => 'inclinação errada', 'fora' => 'fora de qualquer objeto', 'rapido' => 'rápido demais', 'movimento' => 'arraste em linha reta', 'sem_pegar' => 'não pegou o objeto', 'expirado' => 'demorou mais de 5 min']; ?>
<h1>Painel webmaster</h1>
<?= $abasHtml ?>
<h2>Desafios por site</h2>
<p class="suave">
    <?php foreach (EstatisticasControlador::PERIODOS as $p): ?>
        <?php if ($p === $dias): ?><strong><?= $p === 1 ? 'Últimas 24 horas' : "Últimos {$p} dias" ?></strong><?php else: ?><a href="/painel/captcha?dias=<?= $p ?>"><?= $p === 1 ? 'Últimas 24 horas' : "Últimos {$p} dias" ?></a><?php endif; ?>
        <?= $p !== 30 ? ' · ' : '' ?>
    <?php endforeach; ?>
</p>
<div class="tabela-rolagem">
<table class="numeros">
    <thead><tr><th>Site</th><th class="num">Emitidos</th><th class="num">Resolvidos</th><th class="num">Falharam</th><th class="num">Abandonados</th><th class="num">Tempo médio</th><th>Por que falharam</th></tr></thead>
    <tbody>
    <?php foreach ($linhas as $l): ?>
        <tr>
            <td><?= Visao::escapar($l['nome']) ?> <span class="suave">(<?= Visao::escapar($l['site']) ?>)</span></td>
            <td class="num"><?= $l['emitidos'] ?></td>
            <td class="num"><?= $l['resolvidos'] ?></td>
            <td class="num"><?= $l['falhos'] ?></td>
            <td class="num"><?= $l['abandonados'] ?></td>
            <td class="num"><?= $l['tempo_medio_ms'] !== null ? number_format($l['tempo_medio_ms'] / 1000, 1, ',', '.') . ' s' : '—' ?></td>
            <td class="motivos"><?php $partes = []; foreach ($l['motivos'] as $m => $n) { $partes[] = $n . ' ' . ($motivos[$m] ?? $m); } echo Visao::escapar(implode(', ', $partes) ?: '—'); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<h2>Sites ligados</h2>
<p class="suave">Cada site só pede desafios destes endereços. O passe assinado leva o nome curto do site, e o site confere que é o dele.</p>
<div class="tabela-rolagem">
<table class="tabela">
    <thead><tr><th>Nome curto</th><th>Site</th><th>Endereços</th><th>Situação</th></tr></thead>
    <tbody>
    <?php foreach ($sites as $s): ?>
        <tr><td><code><?= Visao::escapar($s['slug']) ?></code></td><td><?= Visao::escapar($s['nome']) ?></td><td><?= Visao::escapar(implode(' ', $s['origens'])) ?></td><td><?= $s['ativo'] ? 'ativo' : 'desligado' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
