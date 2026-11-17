<?php

declare(strict_types=1);

namespace Captcha\Sistema;

use Captcha\Acesso\Webmasters;
use Captcha\Desafio\RepositorioDesafios;
use Captcha\Nucleo\Config;
use Captcha\Nucleo\Conexao;
use Captcha\Nucleo\Migrador;
use Captcha\Nucleo\Requisicao;
use Captcha\Nucleo\Resposta;
use Captcha\Nucleo\Roteador;
use Captcha\Nucleo\Servicos;
use Captcha\Seguranca\TokenErrado;

/** Rotas de manutenção: saúde do site e migrações do banco (chamadas pelo deploy). */
final class SistemaControlador
{
    public function __construct(
        private readonly Config $config,
        private readonly Conexao $conexao,
        private readonly string $pastaMigracoes,
        private readonly ?Servicos $servicos = null,
    ) {
    }

    public function registrar(Roteador $roteador): void
    {
        $roteador->get('/sistema/saude', fn (Requisicao $r): Resposta => $this->saude());
        $roteador->post('/sistema/migrar', fn (Requisicao $r): Resposta => $this->migrar($r));
    }

    public function saude(): Resposta
    {
        return Resposta::json(['ok' => true, 'hora' => date('c')]);
    }

    public function migrar(Requisicao $requisicao): Resposta
    {
        $freio = new TokenErrado($this->servicos);
        if ($freio->esgotado($requisicao)) {
            return Resposta::json(['ok' => false, 'erro' => 'muitas tentativas'], 429);
        }
        $token = (string) $this->config->obter('migrar_token', '');
        if ($token === '' || !hash_equals($token, $requisicao->cabecalho('x-captcha-token'))) {
            $freio->contar($requisicao);
            return Resposta::json(['ok' => false, 'erro' => 'não autorizado'], 401);
        }
        $aplicadas = (new Migrador($this->conexao->pdo(), $this->pastaMigracoes))->migrar();
        // O login do Alfredo e os e-mails extras de 'webmasters' viram webmaster quando o e-mail estiver confirmado.
        $webmasters = $this->servicos?->permissoes()->sincronizarWebmasters(Webmasters::emails($this->config)) ?? 0;
        // Desafios com mais de 90 dias saem do banco (as estatísticas olham no máximo 30 dias).
        $apagados = (new RepositorioDesafios($this->conexao->pdo()))->limpar();
        // A chave de assinatura nasce aqui, se ainda não existe, e /api/chave já responde depois da migração.
        $chave = $this->servicos?->assinatura()->publica();
        return Resposta::json(['ok' => true, 'aplicadas' => $aplicadas, 'webmasters' => $webmasters, 'desafios_apagados' => $apagados, 'chave' => $chave]);
    }
}
