<?php use Captcha\Nucleo\Visao; use Captcha\Acesso\Aparelho;
/** @var Captcha\Acesso\Usuario $conta */
$hora = static fn (?string $utc): string => $utc === null ? '—' : (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i');
?>
<h1>Minha conta</h1>
<section class="cartao">
    <h2>Dados</h2>
    <dl class="dados">
        <dt>Nome</dt><dd><?= Visao::escapar($conta->nome) ?></dd>
        <dt>E-mail</dt><dd><?= Visao::escapar($conta->email) ?></dd>
        <dt>Grupo</dt><dd><?= Visao::escapar($grupo ?? '—') ?></dd>
        <dt>Conta criada em</dt><dd><?= Visao::escapar($hora($conta->criadoEm)) ?></dd>
    </dl>
</section>

<section class="cartao">
    <h2>Trocar senha</h2>
    <form method="post" action="/conta/senha">
        <?= $campos->csrf() ?>
        <label>Senha atual <input type="password" name="senha_atual" required autocomplete="current-password"></label>
        <?php if (!empty($erros['senha_atual'])): ?><p class="erro-campo"><?= Visao::escapar($erros['senha_atual']) ?></p><?php endif; ?>
        <label>Senha nova <input type="password" name="senha" required minlength="10" autocomplete="new-password"></label>
        <?php if (!empty($erros['senha'])): ?><p class="erro-campo"><?= Visao::escapar($erros['senha']) ?></p><?php endif; ?>
        <label>Repita a senha nova <input type="password" name="senha_confirmacao" required minlength="10" autocomplete="new-password"></label>
        <?php if (!empty($erros['senha_confirmacao'])): ?><p class="erro-campo"><?= Visao::escapar($erros['senha_confirmacao']) ?></p><?php endif; ?>
        <button type="submit">Trocar senha</button>
        <small class="suave">Os outros aparelhos são desconectados.</small>
    </form>
</section>

<section class="cartao">
    <h2>Entrada em duas etapas</h2>
    <?php if ($duasObrigatoria): ?>
        <p>Sua conta é da equipe: depois da senha, um código chega sempre no seu e-mail.</p>
    <?php else: ?>
        <p><?= $duasLigada ? 'Ligada: depois da senha, um código chega no seu e-mail.' : 'Desligada.' ?></p>
        <form method="post" action="/conta/duas-etapas">
            <?= $campos->csrf() ?>
            <input type="hidden" name="ligar" value="<?= $duasLigada ? '0' : '1' ?>">
            <label>Sua senha <input type="password" name="senha" required autocomplete="current-password"></label>
            <?php if (!empty($erros['duas_etapas'])): ?><p class="erro-campo"><?= Visao::escapar($erros['duas_etapas']) ?></p><?php endif; ?>
            <button type="submit"><?= $duasLigada ? 'Desligar' : 'Ligar' ?></button>
        </form>
    <?php endif; ?>
</section>

<section class="cartao">
    <h2>Últimas entradas</h2>
    <?php if ($entradas === []): ?>
        <p class="suave">Nenhuma ainda.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Quando</th><th>IP</th><th>Aparelho</th></tr></thead>
            <tbody>
            <?php foreach ($entradas as $e): ?>
                <tr><td><?= Visao::escapar($hora($e['em'])) ?></td><td><?= Visao::escapar($e['ip']) ?></td><td><?= Visao::escapar(Aparelho::descrever($e['agente'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="suave">Não reconhece alguma? Troque a senha acima.</p>
    <?php endif; ?>
</section>
