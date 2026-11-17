# Captcha

Captcha de encaixe próprio dos sites do Lingoist, publicado em https://captcha.lingoist.com.br e usado pelo
Espanhol/Português (idioma), Estofados (furniture), Genealogia, Escolas (school) e Kirk. PHP orientado a objetos
com MySQL (banco compartilhado `burgucom_projetos`, tabelas `captcha_`). Login, segurança, menu lateral e painel
webmaster são os dos outros projetos; o webmaster vem do config.local.php e conta nova só entra depois de
aprovada.

## Como funciona

1. O objeto cinza chega reto na bandeja. Quando a pessoa começa a carregar, ele mostra uma inclinação.
2. As prateleiras têm de 11 a 14 objetos coloridos, sobrepostos, numa cena de cozinha ou de quarto de brinquedos
   (sorteada). O objeto certo aparece de 2 a 4 vezes, em cores e inclinações diferentes; outros também se repetem.
3. A pessoa solta em cima do objeto igual, com a mesma inclinação. Não há ímã: passa se o centro do objeto solto
   ficar a até 20% do tamanho do objeto, contando do centro do certo (`Desafio\Conferencia::FOLGA`).
4. O fundo tem duas versões que se alternam com fade o tempo todo. Acertou: check verde grande, sem texto.
5. Uma resposta por desafio: errou, vem outro.

Tudo que decide o desafio fica no servidor. O navegador só recebe imagens PNG desenhadas pelo GD a partir de uma
semente (`Desafio\Plano`, `Desafio\Pintor`); a imagem do objeto inclinado só existe depois que a pessoa pega o
objeto (`/api/pegar`). Além do lugar, a conferência olha o tempo desde o desafio e desde que a pessoa pegou o
objeto e, no arraste, o trajeto (pontos, duração e desvio da linha reta).

O código é público, então os números dessas regras não estão nele: `Desafio\Regras` sorteia os valores desta
instalação na primeira vez (dentro de faixas) e guarda com um sal secreto em `captcha_config`; cada desafio ainda
soma uma variação tirada do HMAC do número do desafio. A quantidade de peças, de cópias, as inclinações, os
tamanhos, o ruído e o cinza (com grão) do objeto também mudam a cada desafio. O navegador nunca sabe por que errou. Seis erros em 15 minutos no mesmo
IP, ou 40 desafios, fazem esperar 15 minutos.

## Ligar num site

    <form method="post" action="/verificacao">
        <!-- campos do formulário do site (CSRF etc.) -->
        <div data-captcha-site="idioma" data-captcha-nonce="<?= $nonce ?>" data-captcha-enviar="1"></div>
    </form>
    <script src="https://captcha.lingoist.com.br/captcha.js" defer></script>

- `$nonce`: `VerificadorPasse::novoNonce()`, guardado na sessão do site.
- Ao acertar, o captcha.js preenche o campo oculto `captcha_passe` e envia o formulário.
- No servidor do site: `(new VerificadorPasse('idioma', $raiz . '/temp'))->conferir($passe, $nonceDaSessao)`.
  `src/Cliente/VerificadorPasse.php` não depende de nada daqui: é copiado para cada sistema trocando o namespace.
- CSP do site: `captcha.lingoist.com.br` em `script-src`, `style-src`, `img-src` e `connect-src`.
- O site precisa estar em `captcha_site` (slug e endereços de origem): migração nova aqui.

O passe é `dados.assinatura` em base64url, assinado com Ed25519. A chave secreta fica só aqui (`captcha_config`);
a pública sai em `/api/chave` e o site guarda por um dia. O passe leva o site, o nonce da sessão e vale 2 minutos.

## API

| Pedido | O que faz |
|---|---|
| `POST /api/desafio` (site, nonce) | Desafio novo: endereços das imagens, tamanho do objeto |
| `GET /api/imagem/{id}/{a,b,o,g}` | Cena nas duas versões, objeto cinza reto, objeto inclinado (só depois de pegar) |
| `POST /api/pegar` (id) | A pessoa começou a carregar: libera a imagem inclinada |
| `POST /api/conferir` (id, x, y, modo, trajeto) | Passe assinado, ou "não passou" e desafio novo |
| `GET /api/chave` | Chave pública Ed25519 |
| `/demo` | O captcha funcionando de ponta a ponta |
| `/painel/captcha` | Estatísticas por site e motivos das falhas (webmaster e assistentes) |
| `POST /sistema/migrar` | Migrações (deploy, com `X-Captcha-Token`) |

## Banco (`captcha_`)

`db/migracoes/`: 001 a 003 login e segurança (iguais aos outros projetos), 004 `captcha_site`, `captcha_desafio`
(um por linha, com semente, peça certa, nonce, IP misturado com a chave, situação e motivo) e `captcha_config`.
Desafios com mais de 90 dias são apagados a cada publicação. Mudança = arquivo novo.

## Servidor (cPanel)

1. Subdomínio `captcha.lingoist.com.br` com raiz em `/projetos/captcha/public`.
2. A publicação é o workflow "Captcha" do repositório (privado) A78JR/projetos, que já tem os segredos do FTP:
   baixa este código e envia para `/projetos/captcha`. Este repositório só roda os testes, nos PRs.
3. A primeira publicação cria `/projetos/captcha/config/config.local.php` com o banco da Genealogia.

## Rodar localmente

    composer install
    cp config/config.exemplo.php config/config.local.php
    php -S localhost:8000 -t public
    vendor/bin/phpunit   # testes com banco usam CAPTCHA_TESTE_HOST/BANCO/USUARIO/SENHA
