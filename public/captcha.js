/*
 * Captcha de encaixe do Lingoist (https://captcha.lingoist.com.br/captcha.js).
 *
 * Uso no site:
 *   <div data-captcha-site="idioma" data-captcha-nonce="<código da sessão>" data-captcha-enviar="1"></div>
 *   <script src="https://captcha.lingoist.com.br/captcha.js" defer></script>
 *
 * Dentro do <form> mais próximo, o captcha cria o campo oculto captcha_passe. Ao acertar, preenche o campo,
 * dispara o evento "captcha:passou" no div (detail.passe) e, com data-captcha-enviar="1", envia o formulário.
 * O site confere o passe no servidor (assinatura Ed25519, nome do site, nonce e validade).
 *
 * Mecânica: o objeto cinza chega reto; ao começar a carregar, ele inclina. A pessoa solta em cima do objeto
 * igual, com a mesma inclinação. Sem ímã: o objeto fica onde foi solto e o servidor decide.
 */
(function () {
  'use strict';
  var BASE = (function () {
    var s = document.currentScript && document.currentScript.src;
    return s ? s.replace(/\/captcha\.js.*$/, '') : 'https://captcha.lingoist.com.br';
  })();



  // O estilo vem num arquivo (captcha.css): os sites usam CSP sem estilo embutido.
  function estilo() {
    if (document.getElementById('clg-estilo')) return;
    var l = document.createElement('link');
    l.id = 'clg-estilo';
    l.rel = 'stylesheet';
    l.href = BASE + '/captcha.css?v=2';
    document.head.appendChild(l);
  }

  function el(tag, classe, pai) {
    var e = document.createElement(tag);
    if (classe) e.className = classe;
    if (pai) pai.appendChild(e);
    return e;
  }

  function post(caminho, dados) {
    var corpo = new URLSearchParams();
    Object.keys(dados).forEach(function (k) { corpo.append(k, dados[k]); });
    return fetch(BASE + caminho, { method: 'POST', body: corpo, credentials: 'omit', mode: 'cors' })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
  }

  function Captcha(raiz) {
    this.raiz = raiz;
    this.site = raiz.getAttribute('data-captcha-site') || '';
    this.nonce = raiz.getAttribute('data-captcha-nonce') || '';
    this.enviar = raiz.getAttribute('data-captcha-enviar') === '1';
    this.montar();
    this.novo();
  }

  Captcha.prototype.montar = function () {
    var r = this.raiz;
    r.innerHTML = '';
    var c = this.caixa = el('div', 'clg', r);
    c.setAttribute('role', 'group');
    c.setAttribute('aria-label', 'Verificação contra robôs');
    el('div', 'clg-titulo', c).textContent = 'Leve o objeto cinza ao lugar certo';
    this.palco = el('div', 'clg-palco', c);
    this.palco.tabIndex = -1;
    this.bandeja = el('div', 'clg-bandeja', c);
    this.msg = el('div', 'clg-msg', c);
    this.msg.setAttribute('role', 'status');
    var pe = el('div', 'clg-pe', c);
    var outro = el('button', '', pe);
    outro.type = 'button';
    outro.textContent = 'Outro desafio';
    outro.addEventListener('click', this.novo.bind(this));
    el('span', '', pe).textContent = 'Lingoist';
    var form = r.closest('form');
    if (form) {
      this.campo = form.querySelector('input[name="captcha_passe"]') || el('input', '', form);
      this.campo.type = 'hidden';
      this.campo.name = 'captcha_passe';
      this.campo.value = '';
      this.form = form;
    }
    this.palco.addEventListener('click', this.tocouPalco.bind(this));
    this.palco.addEventListener('keydown', this.teclaPalco.bind(this));
  };

  Captcha.prototype.aviso = function (texto, erro) {
    this.msg.textContent = texto;
    this.msg.className = 'clg-msg' + (erro ? ' clg-erro' : '');
  };

  Captcha.prototype.novo = function () {
    var self = this;
    this.soltarMao();
    this.feito = false;
    this.ocupado = true;
    if (this.campo) this.campo.value = '';
    this.aviso('Carregando…');
    post('/api/desafio', { site: this.site, nonce: this.nonce }).then(function (d) {
      self.ocupado = false;
      if (!d || !d.ok) {
        self.aviso(d && d.erro === 'aguarde' ? 'Muitas tentativas. Espere alguns minutos e tente de novo.' : 'Não foi possível carregar o desafio. Tente “Outro desafio”.', true);
        return;
      }
      self.d = d;
      self.inclinado = null;
      var p = self.palco;
      p.className = 'clg-palco';
      void p.offsetWidth;
      p.classList.add('clg-entra');
      p.innerHTML = '';
      ['a', 'b'].forEach(function (k) {
        var i = el('img', 'clg-fundo clg-' + k, p);
        i.alt = '';
        i.draggable = false;
        i.src = d[k];
      });
      self.bandeja.innerHTML = '';
      var b = self.botao = el('button', 'clg-objeto', self.bandeja);
      b.type = 'button';
      b.setAttribute('aria-label', 'Objeto cinza: pegue e solte em cima do objeto igual, com a mesma inclinação');
      var img = el('img', '', b);
      img.alt = '';
      img.draggable = false;
      img.src = d.objeto;
      b.addEventListener('pointerdown', self.apertou.bind(self));
      b.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); self.pegarTeclado(); }
      });
      self.aviso('');
    });
  };

  /** Pede ao servidor a imagem inclinada: ela só existe depois que a pessoa pega o objeto. */
  Captcha.prototype.pegar = function () {
    var self = this;
    if (this.pegando) return this.pegando;
    this.pegando = post('/api/pegar', { id: this.d.id }).then(function (r) {
      if (r && r.ok) {
        self.inclinado = r.inclinado;
        if (self.mao) self.mao.querySelector('img').src = r.inclinado;
      } else if (r && r.novo) {
        self.novo();
      }
      return r;
    });
    return this.pegando;
  };

  Captcha.prototype.criarMao = function () {
    if (this.mao) return;
    var tam = this.palco.getBoundingClientRect().width * this.d.quadro / this.d.largura;
    var m = this.mao = el('div', 'clg-mao', document.body);
    m.style.width = m.style.height = tam + 'px';
    var img = el('img', '', m);
    img.alt = '';
    img.src = this.inclinado || this.d.objeto;
    this.botao.style.visibility = 'hidden';
  };

  Captcha.prototype.soltarMao = function () {
    if (this.mao) { this.mao.remove(); this.mao = null; }
    this.pegando = null;
    this.segurando = false;
    if (this.movendo) { removeEventListener('pointermove', this.movendo); this.movendo = null; }
  };

  Captcha.prototype.moverMao = function (x, y) {
    if (!this.mao) return;
    this.mao.style.left = x + 'px';
    this.mao.style.top = y + 'px';
  };

  /** Ponto da tela → pixels da cena (400 x 300). */
  Captcha.prototype.naCena = function (x, y) {
    var r = this.palco.getBoundingClientRect();
    return [(x - r.left) / r.width * this.d.largura, (y - r.top) / r.height * this.d.altura];
  };

  Captcha.prototype.apertou = function (e) {
    if (this.ocupado || this.feito || !this.d) return;
    e.preventDefault();
    var self = this, x0 = e.clientX, y0 = e.clientY, t0 = performance.now(), moveu = false, trajeto = [];
    // No dedo (ou caneta), o objeto anda um pouco ACIMA do ponto de toque, para a inclinação não ficar escondida
    // debaixo do dedo. Vale onde o objeto aparece e onde ele é solto: solta onde se vê.
    var acima = 0;
    this.pegar();
    var mv = function (ev) {
      if (!moveu && Math.hypot(ev.clientX - x0, ev.clientY - y0) < 6) return;
      if (!moveu) {
        moveu = true;
        self.criarMao();
        if (e.pointerType === 'touch' || e.pointerType === 'pen') acima = self.mao.offsetHeight * 0.45 + 24;
      }
      self.moverMao(ev.clientX, ev.clientY - acima);
      var p = self.naCena(ev.clientX, ev.clientY - acima);
      trajeto.push([Math.round(performance.now() - t0), Math.round(p[0] * 10) / 10, Math.round(p[1] * 10) / 10]);
      if (trajeto.length > 390) trajeto.splice(1, 1);
    };
    var up = function (ev) {
      removeEventListener('pointermove', mv);
      removeEventListener('pointerup', up);
      removeEventListener('pointercancel', up);
      if (!moveu) {
        // Toque: o objeto fica "na mão", já inclinado, e o próximo toque na cena solta ali.
        self.segurando = true;
        self.criarMao();
        var r = self.botao.getBoundingClientRect();
        self.moverMao(r.left + r.width / 2, r.top + r.height / 2);
        self.movendo = function (m) { self.moverMao(m.clientX, m.clientY); };
        addEventListener('pointermove', self.movendo);
        return;
      }
      var p = self.naCena(ev.clientX, ev.clientY - acima);
      self.largar(p[0], p[1], 'arrastar', trajeto);
    };
    addEventListener('pointermove', mv);
    addEventListener('pointerup', up);
    addEventListener('pointercancel', up);
  };

  Captcha.prototype.tocouPalco = function (e) {
    if (!this.segurando || this.feito) return;
    var p = this.naCena(e.clientX, e.clientY);
    this.largar(p[0], p[1], 'tocar', []);
  };

  /** Teclado: Enter pega; setas movem o objeto na cena; Enter solta. */
  Captcha.prototype.pegarTeclado = function () {
    if (this.ocupado || this.feito || !this.d) return;
    this.pegar();
    this.segurando = true;
    this.teclado = [this.d.largura / 2, this.d.altura / 2];
    this.criarMao();
    this.mostrarTeclado();
    this.palco.focus();
  };

  Captcha.prototype.mostrarTeclado = function () {
    var r = this.palco.getBoundingClientRect();
    this.moverMao(r.left + this.teclado[0] / this.d.largura * r.width, r.top + this.teclado[1] / this.d.altura * r.height);
  };

  Captcha.prototype.teclaPalco = function (e) {
    if (!this.segurando || !this.teclado) return;
    var passo = e.shiftKey ? 2 : 10, t = this.teclado;
    if (e.key === 'ArrowLeft') t[0] = Math.max(0, t[0] - passo);
    else if (e.key === 'ArrowRight') t[0] = Math.min(this.d.largura, t[0] + passo);
    else if (e.key === 'ArrowUp') t[1] = Math.max(0, t[1] - passo);
    else if (e.key === 'ArrowDown') t[1] = Math.min(this.d.altura, t[1] + passo);
    else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.largar(t[0], t[1], 'teclado', []); return; }
    else return;
    e.preventDefault();
    this.mostrarTeclado();
  };

  Captcha.prototype.largar = function (x, y, modo, trajeto) {
    var self = this, d = this.d;
    this.ocupado = true;
    this.teclado = null;
    // O objeto fica onde foi solto (sem ímã), já inclinado.
    var solto = el('img', 'clg-solto', this.palco);
    solto.alt = '';
    var tam = d.quadro / d.largura * 100;
    solto.style.width = tam + '%';
    solto.style.left = (x / d.largura * 100 - tam / 2) + '%';
    solto.style.top = (y / d.altura * 100 - tam * d.largura / d.altura / 2) + '%';
    solto.src = this.inclinado || d.objeto;
    this.soltarMao();
    this.botao.remove();
    (this.pegando || this.pegar()).then(function () {
      return post('/api/conferir', { id: d.id, x: x.toFixed(1), y: y.toFixed(1), modo: modo, trajeto: JSON.stringify(trajeto) });
    }).then(function (r) {
      if (r && r.ok && r.passe) {
        self.feito = true;
        self.palco.classList.add('clg-feito');
        var v = el('div', 'clg-visto', self.palco);
        v.setAttribute('role', 'img');
        v.setAttribute('aria-label', 'Verificado');
        v.innerHTML = '<svg viewBox="0 0 100 100" aria-hidden="true"><circle cx="50" cy="50" r="46"/><path d="M28 52l15 15 30-32"/></svg>';
        self.aviso('');
        if (self.campo) self.campo.value = r.passe;
        self.raiz.dispatchEvent(new CustomEvent('captcha:passou', { bubbles: true, detail: { passe: r.passe } }));
        if (self.enviar && self.form) setTimeout(function () { self.form.requestSubmit ? self.form.requestSubmit() : self.form.submit(); }, 700);
        return;
      }
      self.caixa.classList.remove('clg-treme');
      void self.caixa.offsetWidth;
      self.caixa.classList.add('clg-treme');
      self.aviso('Não foi dessa vez. Vem outro desafio.', true);
      setTimeout(function () { self.novo(); }, 1300);
    });
  };

  function iniciar() {
    estilo();
    var todos = document.querySelectorAll('[data-captcha-site]:not([data-captcha-pronto])');
    for (var i = 0; i < todos.length; i++) {
      todos[i].setAttribute('data-captcha-pronto', '1');
      new Captcha(todos[i]);
    }
  }

  window.CaptchaLingoist = { iniciar: iniciar };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
})();
