# Bunny Stream: sandbox

Aplicação Laravel pequena, descartável, usada para validar o Bunny Stream antes
de construir o módulo de Cursos do Alvras (issue KAN-80). Ela não faz parte do
Alvras: existe só para testar contra o Bunny de verdade e documentar o que
funciona.

## O que ela testa

| Funcionalidade | Como é testada |
| --- | --- |
| Criar vídeo na biblioteca | O servidor chama a API do Bunny com a chave da biblioteca |
| Envio direto do navegador (TUS) | O servidor assina o envio; o navegador envia o arquivo ao Bunny sem passar pelo servidor |
| Pausar e retomar o envio | Botões na página, usando o protocolo TUS |
| Conversão, duração, resoluções e miniatura | A lista de vídeos consulta a API e atualiza sozinha |
| Link do player com validade | O servidor assina a URL do player com token e prazo |
| Coleções | Criar e listar |
| Excluir vídeo | Botão na lista |
| Webhook assinado | Endpoint que confere o HMAC e registra os eventos |

## Requisitos

- Uma biblioteca no [Bunny Stream](https://dash.bunny.net/stream).
- Docker (recomendado) ou PHP 8.4 com Composer.

## Instalação

```bash
git clone https://github.com/liddera/Bunny-Stream.git
cd Bunny-Stream
composer install
cp .env.example .env
php artisan key:generate
```

Sem PHP no computador, use o Docker:

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 composer install
cp .env.example .env
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.4-cli php artisan key:generate
```

## Configuração

Preencha estas variáveis no `.env`. Elas ficam só nesse arquivo, que não vai
para o Git.

| Variável | Onde encontrar no painel do Bunny |
| --- | --- |
| `BUNNY_LIBRARY_ID` | Stream > sua biblioteca > API > Video library ID |
| `BUNNY_API_KEY` | Stream > sua biblioteca > API > API Key |
| `BUNNY_READONLY_API_KEY` | Stream > sua biblioteca > API > Read-only API Key (assina o webhook) |
| `BUNNY_TOKEN_KEY` | Stream > sua biblioteca > Security > Token authentication key |
| `BUNNY_CDN_HOSTNAME` | Stream > sua biblioteca > API > CDN hostname (miniaturas) |

Opcionais:

| Variável | Padrão | Função |
| --- | --- | --- |
| `BUNNY_TUS_TTL` | 3600 | Validade, em segundos, da assinatura do envio |
| `BUNNY_EMBED_TTL` | 300 | Validade, em segundos, do link do player |

A "Token authentication key" é diferente da API Key. Ela só aparece depois de
ligar a opção **Embed view token authentication** em Security. Com essa opção
ligada, o player recusa qualquer link sem assinatura.

## Executando

```bash
php artisan serve
```

Com Docker:

```bash
docker run -d --name bunny-sandbox -u "$(id -u):$(id -g)" -p 8090:8000 \
  -v "$PWD":/app -w /app php:8.4-cli php artisan serve --host=0.0.0.0 --port=8000
```

Abra http://localhost:8090. Para parar: `docker rm -f bunny-sandbox`. O servidor
reinicia sozinho quando o `.env` muda.

## Usando a página

1. **Configuração:** confira que as cinco variáveis aparecem como preenchidas.
2. **Enviar vídeo:** dê um título, escolha um arquivo e clique em Enviar. Use
   Pausar e Retomar para testar a retomada.
3. **Vídeos da biblioteca:** acompanhe o status. O número do status aparece ao
   lado do rótulo, para conferir com a API.
4. **Assistir:** abre o player com link assinado e mostra por quantos segundos
   ele vale.
5. **Webhook:** lista os eventos recebidos e se a assinatura era válida.

## Testes automatizados

```bash
php artisan test
```

Os testes nunca chamam o Bunny: eles trocam a rede por um fake. Cobrem as contas
de assinatura (envio e player), a verificação do webhook, o tratamento de erro da
API e a garantia de que nenhuma chave aparece nas respostas.

## Como as assinaturas funcionam

- **Envio TUS:** `SHA256(library_id + api_key + expiração + video_id)`. O
  navegador recebe só a assinatura, a expiração e os identificadores; a chave
  da API nunca sai do servidor.
- **Player:** `SHA256(token_key + video_id + expira)`, na URL
  `https://player.mediadelivery.net/embed/{library}/{video}?token=...&expires=...`.
- **Webhook:** HMAC-SHA256 do corpo bruto da requisição, com a Read-only API
  Key, enviado nos cabeçalhos `X-BunnyStream-Signature*`.

## Webhook em ambiente local

O Bunny só entrega o aviso se alcançar a sua máquina por uma URL pública. Use um
túnel (por exemplo cloudflared ou ngrok) apontando para a porta 8090 e informe
`https://SEU-TUNEL/webhooks/bunny` em Stream > biblioteca > API > Webhook URL.
Sem o túnel, a lista de vídeos continua se atualizando por consulta.

## O que foi validado

Contra a biblioteca real, em 09/10/2026:

- Criação de vídeo, assinatura do envio TUS aceita pelo Bunny e exclusão.
- Envio de um vídeo real direto do navegador, com miniatura gerada.
- Status da API de vídeos: 2 (processando), 3 (convertendo) e 4 (pronto).
  Um vídeo de 13 segundos ficou pronto em cerca de 1 minuto, com as resoluções
  240p, 360p, 480p, 720p e 1080p e a duração preenchida.
- Link assinado do player, sem corte da aula quando o prazo do link vence
  durante a reprodução.

Ainda não validado:

- O bloqueio (403) do player sem token.
- Pausar e retomar um envio grande.
- Entrega do webhook, que depende de URL pública.
- Os códigos de status 5 e 6 da API de vídeos (erro e envio falhou).

## Rotas

| Método | Caminho | Função |
| --- | --- | --- |
| GET | `/` | Página de testes |
| GET | `/api/config` | Quais variáveis estão preenchidas (sem valores) |
| GET, POST | `/api/videos` | Listar e criar vídeo (devolve a assinatura do envio) |
| GET, DELETE | `/api/videos/{id}` | Consultar e excluir |
| GET | `/api/videos/{id}/play` | Link assinado do player |
| GET, POST | `/api/collections` | Listar e criar coleções |
| POST | `/webhooks/bunny` | Recebe o webhook do Bunny (sem CSRF, protegido pelo HMAC) |
| GET | `/api/webhook-events` | Últimos eventos recebidos |

## Estrutura

- `app/Services/BunnyStreamService.php`: única classe que fala com o Bunny.
- `app/Http/Controllers/BunnyTestController.php`: rotas da API e do webhook.
- `config/bunny.php`: leitura das variáveis do `.env`.
- `resources/views/bunny.blade.php`: página de testes.
- `tests/Feature/BunnyStreamTest.php`: testes automatizados.

## Segurança

- Nunca coloque chaves no código nem as envie em conversas ou prints. Se uma
  chave aparecer em algum lugar, gere outra no painel do Bunny (botão de
  recarregar ao lado dela).
- O endpoint que assina o link do player aqui é aberto, só para teste. No
  Alvras, a checagem de quem pode assistir vem antes da assinatura.
- Este projeto é só para testes: não publique em um servidor aberto.
