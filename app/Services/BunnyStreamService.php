<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Única classe que fala com o Bunny Stream.
 *
 * Mesmo desenho previsto para o Alvras: a chave da API fica só no servidor e o
 * navegador recebe apenas assinaturas com validade.
 */
class BunnyStreamService
{
    /**
     * Quais variáveis do .env estão preenchidas, sem expor os valores.
     *
     * @return array<string, bool>
     */
    public function configStatus(): array
    {
        return [
            'BUNNY_LIBRARY_ID' => filled(config('bunny.library_id')),
            'BUNNY_API_KEY' => filled(config('bunny.api_key')),
            'BUNNY_READONLY_API_KEY' => filled(config('bunny.readonly_api_key')),
            'BUNNY_TOKEN_KEY' => filled(config('bunny.token_key')),
            'BUNNY_CDN_HOSTNAME' => filled(config('bunny.cdn_hostname')),
        ];
    }

    /**
     * Cria o vídeo vazio no Bunny. O arquivo é enviado depois, direto do navegador.
     *
     * @return array<string, mixed>
     */
    public function createVideo(string $title, ?string $collectionId = null): array
    {
        $payload = array_filter(['title' => $title, 'collectionId' => $collectionId]);

        return $this->client()->post($this->libraryUrl('/videos'), $payload)->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getVideo(string $videoId): array
    {
        return $this->client()->get($this->libraryUrl("/videos/{$videoId}"))->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function listVideos(int $page = 1, int $perPage = 50): array
    {
        return $this->client()
            ->get($this->libraryUrl('/videos'), ['page' => $page, 'itemsPerPage' => $perPage, 'orderBy' => 'date'])
            ->throw()
            ->json();
    }

    public function deleteVideo(string $videoId): void
    {
        $this->client()->delete($this->libraryUrl("/videos/{$videoId}"))->throw();
    }

    /**
     * @return array<string, mixed>
     */
    public function createCollection(string $name): array
    {
        return $this->client()->post($this->libraryUrl('/collections'), ['name' => $name])->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function listCollections(): array
    {
        return $this->client()->get($this->libraryUrl('/collections'), ['itemsPerPage' => 100])->throw()->json();
    }

    /**
     * Assinatura do envio TUS: SHA256(library_id + api_key + expiração + video_id).
     *
     * @return array{endpoint: string, headers: array<string, string|int>}
     */
    public function tusCredentials(string $videoId, ?int $now = null): array
    {
        $expire = ($now ?? time()) + (int) config('bunny.tus_ttl');
        $libraryId = (string) config('bunny.library_id');

        return [
            'endpoint' => config('bunny.tus_endpoint'),
            'headers' => [
                'AuthorizationSignature' => hash('sha256', $libraryId.$this->requireKey('api_key').$expire.$videoId),
                'AuthorizationExpire' => $expire,
                'VideoId' => $videoId,
                'LibraryId' => $libraryId,
            ],
        ];
    }

    /**
     * Link do player com token e validade: SHA256(chave + video_id + expira).
     */
    public function signedEmbedUrl(string $videoId, ?int $now = null): string
    {
        $expires = ($now ?? time()) + (int) config('bunny.embed_ttl');
        $token = hash('sha256', $this->requireKey('token_key').$videoId.$expires);

        return sprintf(
            '%s/%s/%s?token=%s&expires=%d',
            config('bunny.player_base'),
            config('bunny.library_id'),
            $videoId,
            $token,
            $expires,
        );
    }

    public function thumbnailUrl(string $videoId, ?string $fileName): ?string
    {
        if (blank(config('bunny.cdn_hostname')) || blank($fileName)) {
            return null;
        }

        return sprintf('https://%s/%s/%s', config('bunny.cdn_hostname'), $videoId, $fileName);
    }

    /**
     * Confere a assinatura do webhook: HMAC-SHA256 do corpo bruto, com a chave somente leitura.
     */
    public function isValidWebhookSignature(string $rawBody, ?string $signature, ?string $version, ?string $algorithm): bool
    {
        if ($version !== 'v1' || $algorithm !== 'hmac-sha256' || blank($signature)) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->requireKey('readonly_api_key'));

        return hash_equals($expected, strtolower($signature));
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders(['AccessKey' => $this->requireKey('api_key'), 'Accept' => 'application/json'])
            ->timeout(20);
    }

    private function libraryUrl(string $path): string
    {
        $libraryId = config('bunny.library_id');

        if (blank($libraryId)) {
            throw new RuntimeException('BUNNY_LIBRARY_ID não está no .env.');
        }

        return config('bunny.api_base')."/library/{$libraryId}{$path}";
    }

    private function requireKey(string $key): string
    {
        $value = config("bunny.{$key}");

        if (blank($value)) {
            throw new RuntimeException('A chave bunny.'.$key.' não está no .env.');
        }

        return (string) $value;
    }
}
