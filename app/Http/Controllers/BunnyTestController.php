<?php

namespace App\Http\Controllers;

use App\Services\BunnyStreamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BunnyTestController extends Controller
{
    private const WEBHOOK_LOG = 'webhook-events.jsonl';

    /**
     * Status do vídeo na API de vídeos (campo `status`).
     *
     * ATENÇÃO: escrito de memória, ainda não conferido contra a API real. O
     * sandbox mostra o número bruto ao lado do rótulo para validar isto.
     *
     * @var array<int, string>
     */
    private const VIDEO_STATUS = [
        0 => 'criado',
        1 => 'enviado',
        2 => 'processando',
        3 => 'convertendo',
        4 => 'pronto',
        5 => 'erro',
        6 => 'envio falhou',
    ];

    /**
     * Status enviados no webhook, conforme https://bunny.net/docs/stream/webhooks.md.
     * É outra numeração, diferente da API de vídeos.
     *
     * @var array<int, string>
     */
    private const WEBHOOK_STATUS = [
        0 => 'na fila',
        1 => 'processando',
        2 => 'convertendo',
        3 => 'terminou',
        4 => 'resolução pronta',
        5 => 'falhou',
        6 => 'envio direto iniciado',
        7 => 'envio direto terminou',
        8 => 'envio direto falhou',
        9 => 'legendas geradas',
        10 => 'título ou descrição gerados',
    ];

    public function __construct(private readonly BunnyStreamService $bunny) {}

    public function index()
    {
        return view('bunny');
    }

    public function config(): JsonResponse
    {
        return response()->json($this->bunny->configStatus());
    }

    public function listVideos(): JsonResponse
    {
        return $this->guard(function () {
            $result = $this->bunny->listVideos();

            return [
                'total' => $result['totalItems'] ?? 0,
                'items' => collect($result['items'] ?? [])->map(fn (array $video) => $this->present($video))->all(),
            ];
        });
    }

    public function showVideo(string $videoId): JsonResponse
    {
        return $this->guard(fn () => $this->present($this->bunny->getVideo($videoId)));
    }

    /**
     * Cria o vídeo vazio e devolve a assinatura para o navegador enviar o arquivo por TUS.
     */
    public function storeVideo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'collection_id' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->guard(function () use ($data) {
            $video = $this->bunny->createVideo($data['title'], $data['collection_id'] ?? null);

            return [
                'video_id' => $video['guid'],
                'upload' => $this->bunny->tusCredentials($video['guid']),
            ];
        }, 201);
    }

    /**
     * Link do player com validade. Em produção, a checagem de quem pode assistir vem antes daqui.
     */
    public function play(string $videoId): JsonResponse
    {
        return $this->guard(fn () => [
            'url' => $this->bunny->signedEmbedUrl($videoId),
            'expires_in' => (int) config('bunny.embed_ttl'),
        ]);
    }

    public function destroyVideo(string $videoId): JsonResponse
    {
        return $this->guard(function () use ($videoId) {
            $this->bunny->deleteVideo($videoId);

            return ['deleted' => $videoId];
        });
    }

    public function listCollections(): JsonResponse
    {
        return $this->guard(fn () => $this->bunny->listCollections());
    }

    public function storeCollection(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);

        return $this->guard(fn () => $this->bunny->createCollection($data['name']), 201);
    }

    /**
     * Recebe o aviso do Bunny. Guarda tudo, inclusive os inválidos, para inspeção.
     */
    public function webhook(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        try {
            $valid = $this->bunny->isValidWebhookSignature(
                $raw,
                $request->header('X-BunnyStream-Signature'),
                $request->header('X-BunnyStream-Signature-Version'),
                $request->header('X-BunnyStream-Signature-Algorithm'),
            );
        } catch (RuntimeException) {
            $valid = false;
        }

        $payload = json_decode($raw, true) ?: [];

        Storage::append(self::WEBHOOK_LOG, json_encode([
            'received_at' => now()->toIso8601String(),
            'signature_valid' => $valid,
            'video_guid' => $payload['VideoGuid'] ?? null,
            'status' => $payload['Status'] ?? null,
            'status_label' => self::WEBHOOK_STATUS[$payload['Status'] ?? -1] ?? 'outro',
        ]));

        return response()->json(['ok' => true], $valid ? 200 : 401);
    }

    public function webhookEvents(): JsonResponse
    {
        $path = Storage::path(self::WEBHOOK_LOG);

        $lines = File::exists($path) ? array_filter(explode("\n", File::get($path))) : [];

        return response()->json(
            collect($lines)->map(fn (string $line) => json_decode($line, true))->reverse()->values()->take(30)->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $video
     * @return array<string, mixed>
     */
    private function present(array $video): array
    {
        $status = (int) ($video['status'] ?? -1);

        return [
            'guid' => $video['guid'] ?? null,
            'title' => $video['title'] ?? null,
            'status' => $status,
            'status_label' => self::VIDEO_STATUS[$status] ?? 'desconhecido',
            'encode_progress' => $video['encodeProgress'] ?? null,
            'length_seconds' => $video['length'] ?? null,
            'resolutions' => $video['availableResolutions'] ?? null,
            'collection_id' => $video['collectionId'] ?? null,
            'thumbnail' => $this->bunny->thumbnailUrl($video['guid'] ?? '', $video['thumbnailFileName'] ?? null),
        ];
    }

    /**
     * Roda a chamada e devolve JSON; erro do Bunny volta com o código e a mensagem dele.
     */
    private function guard(callable $call, int $successStatus = 200): JsonResponse
    {
        try {
            return response()->json($call(), $successStatus);
        } catch (RequestException $e) {
            return response()->json([
                'error' => 'O Bunny recusou a chamada.',
                'bunny_status' => $e->response->status(),
                'bunny_body' => $e->response->json() ?? $e->response->body(),
            ], 502);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
