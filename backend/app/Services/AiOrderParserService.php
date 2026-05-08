<?php

namespace App\Services;

use App\Models\AiParserRule;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiOrderParserService
{
    private const OPENROUTER_FREE_MODELS = [
        'deepseek/deepseek-chat-v3-0324:free',
        'qwen/qwen3-32b:free',
        'google/gemma-3-27b-it:free',
        'meta-llama/llama-3.3-70b-instruct:free',
    ];

    private const FALLBACK_STATUSES = [400, 404, 408, 429, 500, 502, 503];
    private const REQUEST_TIMEOUT_SECONDS = 15;
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const SLOW_THRESHOLD_SECONDS = 15.0;
    private const SLOW_CACHE_SECONDS = 600;

    public function __construct(
        private readonly SettingService $settings,
        private readonly AiParserRuleService $rules,
        private readonly OrderTextNormalizer $normalizer,
    )
    {
    }

    public function parse(User $user, string $text): ?array
    {
        $learned = $this->parseFromLearnedRule($user, $text);
        if ($learned !== null) {
            return $learned;
        }

        if (! $this->isReady()) {
            return null;
        }

        try {
            $data = $this->request($user, $text);
            if (! is_array($data)) {
                return null;
            }

            $parsed = $this->toParsedOrder($user, $text, $data, 'ai_parser');
            if ($parsed !== null) {
                $this->rules->remember($text, $data, $this->provider(), (string) ($data['_model_used'] ?? $this->model()));
            }

            return $parsed;
        } catch (Throwable $exception) {
            Log::warning('ai_order_parser.failed', [
                'provider' => $this->provider(),
                'message' => $exception->getMessage(),
            ]);
            Log::channel('ai')->warning('ai_order_parser.failed', [
                'provider' => $this->provider(),
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function parseFromLearnedRule(User $user, string $text): ?array
    {
        $rule = $this->rules->find($text);
        if (! $rule instanceof AiParserRule) {
            return null;
        }

        $parsed = $this->toParsedOrder($user, $text, $rule->ai_data ?? [], 'ai_parser_db');
        if ($parsed === null) {
            return null;
        }

        $this->rules->markUsed($rule);

        $parsed['ai_parser_cached'] = true;
        $parsed['ai_parser_rule_id'] = $rule->id;
        $parsed['payload']['service_payload']['source'] = 'ai_parser_db';
        $parsed['payload']['service_payload']['ai_parser_rule_id'] = $rule->id;

        return $parsed;
    }

    public function isReady(): bool
    {
        return $this->settings->bool('ai_assistant_enabled', false)
            && filled($this->apiKey())
            && filled($this->baseUrl())
            && filled($this->model());
    }

    private function request(User $user, string $text): ?array
    {
        $normalizedText = $this->normalizer->normalize($text);
        $aliases = $this->normalizer->detectedAliases($text);

        $basePayload = [
            'temperature' => 0.1,
            'max_tokens' => $this->maxTokens(),
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->systemPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'profile' => [
                            'name' => $user->name,
                            'phone' => $user->phone,
                            'address' => $user->address,
                        ],
                        'text' => $text,
                        'normalized_text' => $normalizedText,
                        'detected_aliases' => $aliases,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
        ];

        $attempts = 0;
        $fallbackCount = 0;
        $lastError = null;

        foreach ($this->modelsForRequest() as $model) {
            $attempts++;
            $startedAt = microtime(true);

            try {
                $payload = ['model' => $model, ...$basePayload];
                $response = $this->sendCompletionRequest($payload);

                if ($response->status() === 400 && str($response->body())->lower()->contains('response_format')) {
                    unset($payload['response_format']);
                    $response = $this->sendCompletionRequest($payload);
                }

                $elapsed = round(microtime(true) - $startedAt, 3);
                $this->markSlowIfNeeded($model, $elapsed);

                if ($this->shouldFallbackResponse($response)) {
                    $lastError = sprintf('HTTP %s: %s', $response->status(), str($response->body())->limit(300)->toString());
                    $this->logAiAttempt('warning', 'ai_order_parser.model_fallback', $model, $elapsed, $fallbackCount, $lastError);
                    $fallbackCount++;
                    continue;
                }

                if (! $response->successful()) {
                    $lastError = sprintf('HTTP %s: %s', $response->status(), str($response->body())->limit(300)->toString());
                    $this->logAiAttempt('warning', 'ai_order_parser.http_failed', $model, $elapsed, $fallbackCount, $lastError);

                    return null;
                }

                $content = data_get($response->json(), 'choices.0.message.content');
                if (! is_string($content) || trim($content) === '') {
                    $lastError = 'empty response';
                    $this->logAiAttempt('warning', 'ai_order_parser.model_fallback', $model, $elapsed, $fallbackCount, $lastError);
                    $fallbackCount++;
                    continue;
                }

                $decoded = json_decode($content, true);

                if (! is_array($decoded)) {
                    $json = $this->extractJsonObject($content);
                    $decoded = $json ? json_decode($json, true) : null;
                }

                if (! is_array($decoded)) {
                    $lastError = 'invalid json response';
                    $this->logAiAttempt('warning', 'ai_order_parser.invalid_json', $model, $elapsed, $fallbackCount, $lastError);

                    return null;
                }

                $decoded['_model_used'] = $model;
                $this->logAiAttempt('info', 'ai_order_parser.model_success', $model, $elapsed, $fallbackCount);

                return $decoded;
            } catch (ConnectionException $exception) {
                $elapsed = round(microtime(true) - $startedAt, 3);
                $lastError = $exception->getMessage();
                $this->markSlowIfNeeded($model, $elapsed, true);
                $this->logAiAttempt('warning', 'ai_order_parser.model_timeout_or_connection_failed', $model, $elapsed, $fallbackCount, $lastError);
                $fallbackCount++;
            } catch (Throwable $exception) {
                $elapsed = round(microtime(true) - $startedAt, 3);
                $lastError = $exception->getMessage();
                $this->markSlowIfNeeded($model, $elapsed);
                $this->logAiAttempt('warning', 'ai_order_parser.model_exception', $model, $elapsed, $fallbackCount, $lastError);
                $fallbackCount++;
            }
        }

        Log::channel('ai')->warning('ai_order_parser.all_models_failed', [
            'provider' => $this->provider(),
            'attempts' => $attempts,
            'fallback_count' => $fallbackCount,
            'error' => $lastError,
        ]);

        return null;
    }

    private function sendCompletionRequest(array $payload): Response
    {
        return Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->acceptJson()
            ->withHeaders($this->headers())
            ->withToken($this->apiKey())
            ->post(rtrim($this->baseUrl(), '/').'/chat/completions', $payload);
    }

    private function shouldFallbackResponse(Response $response): bool
    {
        return in_array($response->status(), self::FALLBACK_STATUSES, true);
    }

    private function modelsForRequest(): array
    {
        $models = $this->provider() === 'openrouter'
            ? self::OPENROUTER_FREE_MODELS
            : [$this->model()];

        if ($this->provider() !== 'openrouter') {
            return $models;
        }

        $fastModels = array_values(array_filter($models, fn (string $model): bool => ! $this->slowModelIsMarked($model)));
        $slowModels = array_values(array_filter($models, fn (string $model): bool => $this->slowModelIsMarked($model)));

        return [...$fastModels, ...$slowModels];
    }

    private function markSlowIfNeeded(string $model, float $elapsed, bool $timeout = false): void
    {
        if ($this->provider() !== 'openrouter') {
            return;
        }

        if (! $timeout && $elapsed < self::SLOW_THRESHOLD_SECONDS) {
            return;
        }

        $this->rememberSlowModel($model, [
            'model' => $model,
            'response_time' => $elapsed,
            'timeout' => $timeout,
            'marked_at' => now()->toDateTimeString(),
        ]);
    }

    private function slowModelIsMarked(string $model): bool
    {
        try {
            return Cache::store('redis')->has($this->slowModelCacheKey($model));
        } catch (Throwable) {
            return Cache::has($this->slowModelCacheKey($model));
        }
    }

    private function rememberSlowModel(string $model, array $payload): void
    {
        try {
            Cache::store('redis')->put($this->slowModelCacheKey($model), $payload, self::SLOW_CACHE_SECONDS);
        } catch (Throwable) {
            Cache::put($this->slowModelCacheKey($model), $payload, self::SLOW_CACHE_SECONDS);
        }
    }

    private function slowModelCacheKey(string $model): string
    {
        return 'slow_model:'.$model;
    }

    private function logAiAttempt(string $level, string $event, string $model, float $elapsed, int $fallbackCount, ?string $error = null): void
    {
        Log::channel('ai')->{$level}($event, [
            'provider' => $this->provider(),
            'model' => $model,
            'response_time_seconds' => $elapsed,
            'fallback_count' => $fallbackCount,
            'error' => $error,
        ]);
    }

    private function toParsedOrder(User $user, string $text, array $data, string $source = 'ai_parser'): ?array
    {
        $serviceType = $this->normalizeService((string) ($data['service_type'] ?? ''));
        if ($serviceType === null) {
            return null;
        }

        $branch = $this->branch($user);
        $profileAddress = $this->profileAddress($user, $branch);
        $pickupLat = (float) ($user->lat ?: $user->currentLocation?->lat ?: $branch?->latitude ?: -7.7063);
        $pickupLng = (float) ($user->lng ?: $user->currentLocation?->lng ?: $branch?->longitude ?: 114.0098);
        $items = $this->items($data['items'] ?? []);
        $storeLocation = $this->clean($data['store_location'] ?? $data['purchase_address'] ?? null);
        $pickupAddress = $this->clean($data['pickup_address'] ?? null);
        $destinationAddress = $this->clean($data['destination_address'] ?? null);
        $notes = $this->clean($data['notes'] ?? null);
        $customerName = $this->clean($data['customer_name'] ?? $data['name'] ?? null);
        $customerPhone = $this->clean($data['customer_phone'] ?? $data['phone'] ?? null);
        $customerAddress = $this->clean($data['customer_address'] ?? $data['address'] ?? null);
        $passengers = max(1, (int) ($data['passengers'] ?? 1));

        if (in_array($serviceType, ['DO', 'belanja', 'gift_order'], true)) {
            if ($items === [] && $serviceType !== 'gift_order') {
                return null;
            }

            if (! $storeLocation) {
                return null;
            }

            $pickupAddress = $storeLocation ?: $profileAddress;
            $destinationAddress = $destinationAddress ?: $profileAddress;
        } else {
            $pickupAddress = $pickupAddress ?: $profileAddress;
            if (! $destinationAddress) {
                return null;
            }
        }

        $servicePayload = [
            'source' => $source,
            'provider' => $this->provider(),
            'raw_text' => $text,
            'store_location' => $storeLocation,
            'passengers' => $passengers,
        ];

        return [
            'service_type' => $serviceType,
            'customer_id' => $user->id,
            'name' => $customerName,
            'phone' => $customerPhone,
            'address' => $customerAddress,
            'items' => $items,
            'store_location' => $storeLocation ?: $pickupAddress,
            'destination' => $destinationAddress,
            'passengers' => $passengers,
            'customer' => [
                'name' => $customerName,
                'phone' => $customerPhone,
                'address' => $customerAddress,
            ],
            'stops' => [],
            'branch' => $branch,
            'ai_parser' => true,
            'payload' => [
                'service_type' => $serviceType,
                'pickup_address' => $pickupAddress,
                'pickup_lat' => $pickupLat,
                'pickup_lng' => $pickupLng,
                'destination_address' => $destinationAddress,
                'destination_lat' => $pickupLat + 0.018,
                'destination_lng' => $pickupLng + 0.018,
                'branch_id' => $branch?->id,
                'stops' => 1,
                'destination_text' => $destinationAddress,
                'notes' => trim(implode("\n", array_filter([
                    'AI parser input: '.$text,
                    $notes,
                    $passengers > 1 ? 'Jumlah penumpang: '.$passengers : null,
                ]))),
                'service_payload' => $servicePayload,
                'items' => $items,
                'points' => [],
            ],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Kamu adalah smart parser order JOJOBOT. Tugasmu hanya ekstrak data order ke JSON valid.
Jangan menentukan harga, jangan membuat order, jangan menebak koordinat.
Input berisi text asli dan normalized_text. Pakai normalized_text untuk memahami typo, slang, singkatan, dan bahasa Indonesia informal, tapi tetap jaga maksud dari text asli.
Input sering berasal dari voice-to-text Android sehingga bisa berisi pengulangan kata/frasa. Abaikan pengulangan seperti "pesan pesan pesan", "belikan belikan", atau frasa yang muncul berulang karena noise.
Return JSON object saja dengan schema:
{
  "service_type": "DO|ojek|kurir|belanja|gift_order|travel|joker_mobil|null",
  "pickup_address": string|null,
  "destination_address": string|null,
  "store_location": string|null,
  "purchase_address": string|null,
  "customer_name": string|null,
  "customer_phone": string|null,
  "customer_address": string|null,
  "items": [{"name": string, "quantity": number}],
  "passengers": number|null,
  "notes": string|null,
  "missing_fields": string[]
}
Definisi field:
- pickup_address = alamat jemput customer / titik awal driver untuk ojek/kurir/travel saja.
- store_location atau purchase_address = alamat pembelian, toko, resto, warung, pasar, area pembelian.
- destination_address = alamat antar/tujuan akhir. Untuk DO/belanja jika user bilang alamat saya/rumah/profile, isi dari profile.address.
- customer_name/customer_phone/customer_address = data pemesan jika ada pada teks. Jika tidak ada, null.
- Untuk DO/belanja/gift_order, JANGAN masukkan alamat pembelian ke pickup_address. Masukkan ke store_location/purchase_address.
- Untuk ojek/joker_mobil, frasa "dari/jemput di/alamat jemput" adalah pickup_address dan "ke/tujuan/alamat antar" adalah destination_address. Jangan tertukar.
Aturan layanan:
- beli/belikan/pesan makanan/barang => DO atau belanja
- antar/kirim barang/dokumen => kurir
- ojek/antar orang/penumpang => ojek
- mobil/joker mobil/citycar/penumpang mobil => joker_mobil
- gift/kado/hadiah => gift_order
- travel => travel
Jika user berkata alamat saya/rumah/profile, gunakan alamat profile yang diberikan.
Contoh:
"pesen ojol jemput di smasa ke katolik penumpang 2" => service_type ojek, pickup_address smasa, destination_address katolik, passengers 2.
"belikan bakso pak gani antar ke rumah saya" => service_type DO, items bakso, store_location pak gani, destination_address profile.address.
PROMPT;
    }

    private function extractJsonObject(string $content): ?string
    {
        $content = trim($content);

        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/isu', $content, $match) === 1) {
            return $match[1];
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($content, $start, $end - $start + 1);
    }

    private function items(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->filter(fn (mixed $item): bool => is_array($item) && filled($item['name'] ?? null))
            ->map(fn (array $item): array => [
                'name' => trim((string) $item['name']),
                'qty' => max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1)),
                'quantity' => max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1)),
            ])
            ->values()
            ->all();
    }

    private function normalizeService(string $serviceType): ?string
    {
        $serviceType = mb_strtolower(trim($serviceType));

        return match ($serviceType) {
            'do', 'delivery', 'delivery order' => 'DO',
            'oj', 'ojek' => 'ojek',
            'kr', 'kurir' => 'kurir',
            'bl', 'belanja' => 'belanja',
            'go', 'gift', 'gift order', 'gift_order' => 'gift_order',
            'tv', 'travel' => 'travel',
            'jm', 'joker', 'joker mobil', 'joker_mobil' => 'joker_mobil',
            default => null,
        };
    }

    private function provider(): string
    {
        return strtolower((string) $this->settings->get('ai_provider', 'openai'));
    }

    private function apiKey(): ?string
    {
        return match ($this->provider()) {
            'kimi' => $this->settings->get('kimi_api_key'),
            'blackbox' => $this->settings->get('blackbox_api_key'),
            'openrouter' => $this->settings->get('openrouter_api_key'),
            default => $this->settings->get('openai_api_key'),
        };
    }

    private function baseUrl(): string
    {
        $custom = $this->settings->get('ai_base_url');
        if (filled($custom)) {
            return (string) $custom;
        }

        return match ($this->provider()) {
            'openrouter' => 'https://openrouter.ai/api/v1',
            'kimi' => 'https://konektika.web.id/v1',
            'blackbox' => 'https://api.blackbox.ai/v1',
            default => 'https://api.openai.com/v1',
        };
    }

    private function model(): string
    {
        $custom = $this->settings->get('ai_model');
        if (filled($custom)) {
            return (string) $custom;
        }

        return match ($this->provider()) {
            'openrouter' => self::OPENROUTER_FREE_MODELS[0],
            'kimi' => 'kimi-pro',
            'blackbox' => 'blackboxai/openai/gpt-4o-mini',
            default => 'gpt-4o-mini',
        };
    }

    private function maxTokens(): int
    {
        return max(200, min(1500, $this->settings->int('ai_max_tokens', 700)));
    }

    private function headers(): array
    {
        if ($this->provider() !== 'openrouter') {
            return [];
        }

        return [
            'HTTP-Referer' => config('app.url'),
            'X-Title' => 'JOJO AI Parser',
        ];
    }

    private function branch(User $user): ?Branch
    {
        if ($user->branch_id) {
            return Branch::query()->find($user->branch_id);
        }

        return Branch::query()->whereNotNull('latitude')->whereNotNull('longitude')->first();
    }

    private function profileAddress(User $user, ?Branch $branch): string
    {
        return $user->address ?: ($branch?->display_name ?? $branch?->name ?? 'Alamat customer');
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || strtolower($value) === 'null' ? null : $value;
    }
}
