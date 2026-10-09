<?php

namespace Tests\Feature;

use App\Services\BunnyStreamService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BunnyStreamTest extends TestCase
{
    private const LIBRARY = '775365';

    private const API_KEY = 'api-key-secreta';

    private const READONLY_KEY = 'readonly-key-secreta';

    private const TOKEN_KEY = 'token-key-secreta';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bunny.library_id' => self::LIBRARY,
            'bunny.api_key' => self::API_KEY,
            'bunny.readonly_api_key' => self::READONLY_KEY,
            'bunny.token_key' => self::TOKEN_KEY,
            'bunny.cdn_hostname' => 'vz-teste.b-cdn.net',
            'bunny.tus_ttl' => 3600,
            'bunny.embed_ttl' => 300,
        ]);
    }

    public function test_assinatura_do_envio_tus_segue_a_formula_da_documentacao(): void
    {
        $credentials = app(BunnyStreamService::class)->tusCredentials('video-1', now: 1_000_000);

        $expire = 1_003_600;

        $this->assertSame('https://video.bunnycdn.com/tusupload', $credentials['endpoint']);
        $this->assertSame($expire, $credentials['headers']['AuthorizationExpire']);
        $this->assertSame(hash('sha256', self::LIBRARY.self::API_KEY.$expire.'video-1'), $credentials['headers']['AuthorizationSignature']);
        $this->assertSame('video-1', $credentials['headers']['VideoId']);
        $this->assertSame(self::LIBRARY, $credentials['headers']['LibraryId']);
        $this->assertStringNotContainsString(self::API_KEY, json_encode($credentials));
    }

    public function test_link_do_player_tem_token_e_validade(): void
    {
        $url = app(BunnyStreamService::class)->signedEmbedUrl('video-1', now: 2_000_000);

        $expires = 2_000_300;
        $token = hash('sha256', self::TOKEN_KEY.'video-1'.$expires);

        $this->assertSame("https://player.mediadelivery.net/embed/775365/video-1?token={$token}&expires={$expires}", $url);
        $this->assertStringNotContainsString(self::TOKEN_KEY, $url);
    }

    public function test_webhook_valido_so_com_hmac_do_corpo_bruto_e_cabecalhos_corretos(): void
    {
        $service = app(BunnyStreamService::class);
        $body = '{"VideoLibraryId":775365,"VideoGuid":"abc","Status":3}';
        $signature = hash_hmac('sha256', $body, self::READONLY_KEY);

        $this->assertTrue($service->isValidWebhookSignature($body, $signature, 'v1', 'hmac-sha256'));
        $this->assertTrue($service->isValidWebhookSignature($body, strtoupper($signature), 'v1', 'hmac-sha256'));
        $this->assertFalse($service->isValidWebhookSignature($body.' ', $signature, 'v1', 'hmac-sha256'));
        $this->assertFalse($service->isValidWebhookSignature($body, $signature, 'v2', 'hmac-sha256'));
        $this->assertFalse($service->isValidWebhookSignature($body, $signature, 'v1', 'sha1'));
        $this->assertFalse($service->isValidWebhookSignature($body, null, 'v1', 'hmac-sha256'));
    }

    public function test_criar_video_envia_a_chave_no_cabecalho_e_devolve_credenciais_sem_vazar_a_chave(): void
    {
        Http::fake(['video.bunnycdn.com/*' => Http::response(['guid' => 'novo-guid', 'title' => 'Aula 1'], 200)]);

        $response = $this->postJson('/api/videos', ['title' => 'Aula 1']);

        $response->assertCreated()->assertJsonPath('video_id', 'novo-guid');
        $this->assertStringNotContainsString(self::API_KEY, $response->getContent());

        Http::assertSent(fn ($request) => $request->url() === 'https://video.bunnycdn.com/library/775365/videos'
            && $request->hasHeader('AccessKey', self::API_KEY)
            && $request['title'] === 'Aula 1');
    }

    public function test_erro_do_bunny_volta_como_502_com_a_mensagem_dele(): void
    {
        Http::fake(['video.bunnycdn.com/*' => Http::response(['Message' => 'Unauthorized'], 401)]);

        $this->getJson('/api/videos')
            ->assertStatus(502)
            ->assertJsonPath('bunny_status', 401);
    }

    public function test_lista_de_videos_traduz_o_status_e_monta_a_miniatura(): void
    {
        Http::fake(['video.bunnycdn.com/*' => Http::response([
            'totalItems' => 1,
            'items' => [['guid' => 'g1', 'title' => 'Aula', 'status' => 4, 'length' => 125, 'thumbnailFileName' => 'thumbnail.jpg', 'availableResolutions' => '240p,720p']],
        ])]);

        $this->getJson('/api/videos')
            ->assertOk()
            ->assertJsonPath('items.0.status_label', 'pronto')
            ->assertJsonPath('items.0.length_seconds', 125)
            ->assertJsonPath('items.0.thumbnail', 'https://vz-teste.b-cdn.net/g1/thumbnail.jpg');
    }

    public function test_endpoint_de_configuracao_nao_expoe_os_valores(): void
    {
        $response = $this->getJson('/api/config')->assertOk()->assertJsonPath('BUNNY_API_KEY', true);

        $this->assertStringNotContainsString(self::API_KEY, $response->getContent());
        $this->assertStringNotContainsString(self::TOKEN_KEY, $response->getContent());
    }

    public function test_sem_chave_a_chamada_falha_com_mensagem_clara(): void
    {
        config(['bunny.token_key' => null]);

        $this->getJson('/api/videos/qualquer/play')
            ->assertStatus(422)
            ->assertJsonPath('error', 'A chave bunny.token_key não está no .env.');
    }

    public function test_webhook_com_assinatura_valida_e_gravado_e_invalido_e_recusado(): void
    {
        Storage::fake('local');

        $body = '{"VideoLibraryId":775365,"VideoGuid":"abc","Status":3}';
        $headers = [
            'X-BunnyStream-Signature-Version' => 'v1',
            'X-BunnyStream-Signature-Algorithm' => 'hmac-sha256',
            'X-BunnyStream-Signature' => hash_hmac('sha256', $body, self::READONLY_KEY),
        ];

        $this->call('POST', '/webhooks/bunny', [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertOk();

        $headers['X-BunnyStream-Signature'] = str_repeat('0', 64);
        $this->call('POST', '/webhooks/bunny', [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertUnauthorized();

        $this->getJson('/api/webhook-events')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.signature_valid', false)
            ->assertJsonPath('1.signature_valid', true)
            ->assertJsonPath('1.status_label', 'terminou');
    }
}
