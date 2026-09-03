# Rekapp for WooCommerce

Plugin WordPress que captura **carrinhos abandonados** de lojas WooCommerce e os
expõe ao [Rekapp](https://rekapp.com.br) para recuperação por WhatsApp.

O WooCommerce não persiste carrinho de convidado em lugar acessível por API — o
carrinho vive na sessão. Este plugin fecha esse buraco: espelha cada carrinho
numa tabela própria, captura o contato **antes** de o checkout ser submetido, e
serve tudo por REST autenticada com as mesmas consumer keys que o lojista já
entregou ao Rekapp no fluxo `/wc-auth`. **Nada é enviado para fora da loja** —
é o backend do Rekapp que consulta a loja por polling.

## Requisitos

- WordPress ≥ 6.2, PHP ≥ 7.4
- WooCommerce ≥ 7.0 (compatível com HPOS e com o checkout em blocos)
- Loja conectada ao Rekapp (consumer key/secret via `/wc-auth`)

## Instalação

1. Envie o `.zip` em **Plugins > Adicionar novo > Enviar plugin** e ative.
2. Nada para configurar: a tabela `wp_rekapp_carts` é criada na ativação e a
   purga diária é agendada. O Rekapp detecta o plugin pelo `GET /rekapp/v1/ping`.

## Como funciona

| Momento | O que acontece |
|---|---|
| Carrinho muda | `woocommerce_cart_updated` espelha o carrinho na tabela (token de 32 hex por sessão). Carrinho esvaziado pelo cliente apaga a linha ativa — desistência explícita não é abandono. |
| Checkout aberto | JS captura e-mail/telefone/nome no `blur` e em pausa de digitação (checkout clássico **e** em blocos; flush final via `sendBeacon` no fechar da aba). |
| Pedido criado | `woocommerce_checkout_order_processed` (clássico) e `woocommerce_store_api_checkout_order_processed` (blocos) marcam a linha como `converted` e rotacionam o token. |
| Cliente clica no link do WhatsApp | `{loja}/?rekapp_restore_cart={token}` recompõe o carrinho (itens indisponíveis ficam de fora com aviso), pré-preenche o contato e leva ao checkout. |
| Diariamente | Cron `rekapp_purge_carts` apaga linhas paradas há mais de 30 dias. |

Carrinho de convidado **sem e-mail nem telefone não é recuperável** — a REST o
omite por padrão (`contactable=false`).

## REST API (namespace `rekapp/v1`)

Autenticação: Basic Auth com as consumer keys do WooCommerce, sobre HTTPS
(o plugin inclui o namespace na autenticação por chave do próprio WooCommerce
via filtro `woocommerce_rest_is_request_to_rest_api`; a permissão exigida é
`manage_woocommerce`).

### `GET /wp-json/rekapp/v1/ping`

```json
{ "plugin": "rekapp-for-woocommerce", "version": "1.0.0", "woocommerce": "10.0.0" }
```

### `GET /wp-json/rekapp/v1/carts`

Parâmetros: `modified_after` (ISO 8601, GMT), `status` (`active` | `converted` |
`all`, default `active`), `include_uncontactable` (default `false`), `page`,
`per_page` (máx. 100). Paginação via headers `X-WP-Total` / `X-WP-TotalPages`.
Ordenado por `updated_at_gmt` crescente (cursor incremental do backend).

Item de resposta:

```json
{
  "cart_token": "3f2a…(32 hex)",
  "status": "active",
  "contactable": true,
  "email": "maria@exemplo.com",
  "phone": "+55 11 99999-0000",
  "first_name": "Maria",
  "last_name": "Silva",
  "cart_total": "149.90",
  "currency": "BRL",
  "line_items": [
    { "product_id": 12, "variation_id": 0, "variation": {}, "name": "Camiseta",
      "quantity": 2, "unit_price": "74.95", "image_url": "https://…" }
  ],
  "order_id": null,
  "restore_url": "https://loja.com/?rekapp_restore_cart=3f2a…",
  "created_at_gmt": "2026-09-02T21:04:11",
  "updated_at_gmt": "2026-09-02T21:11:42"
}
```

Linhas `converted` entram mesmo sem contato — é com elas que o backend fecha o
caso como convertido.

## Filtros para o lojista/desenvolvedor

- `rekapp_cart_tracking_enabled` (bool, default `true`) — desliga captura e
  rastreio sem desativar o plugin.
- `rekapp_carts_retention_days` (int, default `30`) — retenção antes da purga.

## LGPD

- O contato é capturado no momento da digitação, antes do submit — trate isso na
  sua política de privacidade (base legal típica: legítimo interesse na
  recuperação da própria compra iniciada).
- Retenção limitada (30 dias, configurável) e purga automática.
- **Desinstalar o plugin apaga a tabela inteira** (uninstall.php) — nenhum dado
  órfão fica para trás.

## Desenvolvimento

Estrutura:

```
rekapp-for-woocommerce.php        bootstrap, declarações de compatibilidade (HPOS, blocks)
includes/class-rekapp-carts-table.php      schema (dbDelta) e versão
includes/class-rekapp-cart-tracker.php     espelho do carrinho + conversão
includes/class-rekapp-contact-capture.php  AJAX de captura (nonce)
includes/class-rekapp-cart-restorer.php    ?rekapp_restore_cart={token}
includes/class-rekapp-rest-api.php         rekapp/v1 (ping, carts)
includes/class-rekapp-purge.php            cron de retenção
assets/js/rekapp-capture.js                captura no checkout (clássico + blocos)
uninstall.php                              drop da tabela
```

Gerar o `.zip` de distribuição:

```powershell
./build-zip.ps1   # gera dist/rekapp-for-woocommerce.zip
```
