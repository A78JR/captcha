// Captcha no navegador: o menu lateral (recolher no computador, abrir por cima no celular), a espera da
// entrada em duas etapas e o botão que se toca sozinho na confirmação de e-mail. Sem bibliotecas.
'use strict';

class Preferencias {
  static obter(nome) {
    try { return window.localStorage.getItem('genealogia.' + nome); } catch (e) { return null; }
  }

  static definir(nome, valor) {
    try {
      if (valor === null) window.localStorage.removeItem('genealogia.' + nome);
      else window.localStorage.setItem('genealogia.' + nome, valor);
    } catch (e) { /* navegador sem armazenamento: vale só nesta página */ }
  }
}

// Tema claro/escuro: sem escolha segue o aparelho; o botão do topo troca e guarda a escolha no navegador
// e num cookie (o servidor lê o cookie e já manda a página na cor certa, sem piscar).
class Tema {
  constructor(botao) {
    this.botao = botao;
    this.raiz = document.documentElement;
    this.aparelho = window.matchMedia('(prefers-color-scheme: dark)');
    const guardado = Preferencias.obter('tema');
    if ((guardado === 'claro' || guardado === 'escuro') && !this.raiz.hasAttribute('data-tema')) this.aplicar(guardado);
    botao.addEventListener('click', () => this.aplicar(this.atual() === 'escuro' ? 'claro' : 'escuro'));
    this.aparelho.addEventListener('change', () => this.rotular());
    this.rotular();
  }

  atual() {
    return this.raiz.getAttribute('data-tema') || (this.aparelho.matches ? 'escuro' : 'claro');
  }

  aplicar(tema) {
    this.raiz.setAttribute('data-tema', tema);
    Preferencias.definir('tema', tema);
    document.cookie = 'tema=' + tema + '; path=/; max-age=31536000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    this.rotular();
    document.dispatchEvent(new CustomEvent('tema-trocado', { detail: { tema } }));
  }

  rotular() {
    const rotulo = this.atual() === 'escuro' ? 'Tema claro' : 'Tema escuro';
    this.botao.title = rotulo;
    const texto = this.botao.querySelector('.rotulo');
    if (texto) texto.textContent = rotulo;
  }
}

class MenuLateral {
  constructor(nav) {
    this.nav = nav;
    this.raiz = document.documentElement;
    this.recolher = nav.querySelector('[data-menu-recolher]');
    this.abrir = document.querySelector('[data-menu-abrir]');
    this.celular = window.matchMedia('(max-width: 760px)');
    if (Preferencias.obter('menu') === 'recolhido') this.raiz.setAttribute('data-menu', 'recolhido');
    this.recolher?.addEventListener('click', () => this.alternarRecolhido());
    this.abrir?.addEventListener('click', () => this.alternarAberto());
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') this.fechar(); });
    document.addEventListener('click', (e) => {
      if (this.raiz.classList.contains('menu-aberto') && !this.nav.contains(e.target) && !e.target.closest('[data-menu-abrir]')) this.fechar();
    });
    this.sincronizar();
  }

  recolhido() { return this.raiz.getAttribute('data-menu') === 'recolhido'; }

  alternarRecolhido() {
    if (this.recolhido()) {
      this.raiz.removeAttribute('data-menu');
      Preferencias.definir('menu', null);
    } else {
      this.raiz.setAttribute('data-menu', 'recolhido');
      Preferencias.definir('menu', 'recolhido');
    }
    this.sincronizar();
  }

  alternarAberto() {
    this.raiz.classList.toggle('menu-aberto');
    this.sincronizar();
    if (this.raiz.classList.contains('menu-aberto')) this.nav.querySelector('.menu-link')?.focus();
  }

  fechar() {
    if (!this.raiz.classList.contains('menu-aberto')) return;
    this.raiz.classList.remove('menu-aberto');
    this.sincronizar();
    this.abrir?.focus();
  }

  sincronizar() {
    if (this.recolher) {
      const rotulo = this.recolhido() ? this.recolher.dataset.rotuloAbrir : this.recolher.dataset.rotuloFechar;
      this.recolher.setAttribute('aria-expanded', String(!this.recolhido()));
      this.recolher.title = rotulo;
      const texto = this.recolher.querySelector('.rotulo');
      if (texto) texto.textContent = rotulo;
    }
    this.abrir?.setAttribute('aria-expanded', String(this.raiz.classList.contains('menu-aberto')));
  }
}

// Segunda etapa: pergunta ao site a cada 3 segundos se o botão Autorizar do e-mail já foi tocado.
class EsperaDuasEtapas {
  constructor(secao) {
    this.endereco = secao.dataset.duasEtapas;
    this.vezes = 0;
    this.agendar();
  }

  agendar() {
    if (this.vezes++ < 200) window.setTimeout(() => this.perguntar(), 3000);
  }

  async perguntar() {
    if (document.hidden) { this.agendar(); return; }
    try {
      const resposta = await fetch(this.endereco, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const dados = await resposta.json();
      if (dados.estado === 'pronto') { window.location.assign(dados.destino || '/conta'); return; }
      if (dados.estado === 'vencido') { window.location.assign('/entrar'); return; }
    } catch (e) { /* sem rede agora: tenta de novo */ }
    this.agendar();
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const nav = document.getElementById('menu-lateral');
  if (nav) new MenuLateral(nav);
  const botaoTema = document.querySelector('[data-tema-trocar]');
  if (botaoTema) new Tema(botaoTema);
  const espera = document.querySelector('[data-duas-etapas]');
  if (espera) new EsperaDuasEtapas(espera);
  // Confirmação de e-mail: o link só abre o botão, e o navegador o toca (robôs que abrem links não confirmam).
  document.querySelector('form[data-enviar-sozinho]')?.requestSubmit();
});
