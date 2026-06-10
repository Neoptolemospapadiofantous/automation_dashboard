<?php

namespace App\Runtime\Knowledge;

use App\Runtime\Exceptions\Misconfigured;
use App\Runtime\Exceptions\UpstreamUnavailable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Text → vector via OpenAI's embeddings endpoint.
 *
 * Dimension enforcement (audit finding): every returned vector is
 * validated against config('runtime.embeddings.dimensions') — mixing
 * dimensions inside one agent's chunk set silently breaks cosine math,
 * so we fail loudly at ingest/query time instead.
 */
class EmbeddingService
{
    /**
     * Embed a batch of texts. Order of results matches input order.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $apiKey = (string) config('runtime.embeddings.openai_api_key');
        if ($apiKey === '') {
            throw new Misconfigured('OPENAI_API_KEY is not set — the runtime cannot embed knowledge-base text.');
        }

        $model = (string) config('runtime.embeddings.model');
        $expectedDims = (int) config('runtime.embeddings.dimensions');

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->withToken($apiKey)
                ->timeout(60)
                ->retry(2, 300, function (Throwable $e): bool {
                    return $e instanceof RequestException
                        && in_array($e->response->status(), [429, 500, 502, 503], true);
                }, throw: false)
                ->post('/v1/embeddings', [
                    'model' => $model,
                    'input' => $texts,
                ]);
        } catch (Throwable $e) {
            throw new UpstreamUnavailable('OpenAI embeddings unreachable: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            $detail = (string) $response->json('error.message', $response->body());

            throw new UpstreamUnavailable(
                'OpenAI embeddings returned HTTP '.$response->status().': '.mb_substr($detail, 0, 300),
            );
        }

        $rows = $response->json('data');
        if (! is_array($rows) || count($rows) !== count($texts)) {
            throw new UpstreamUnavailable('OpenAI embeddings returned '.(is_array($rows) ? count($rows) : 0).' vectors for '.count($texts).' inputs.');
        }

        // The API may return rows out of order — sort by index.
        usort($rows, fn (array $a, array $b) => ((int) ($a['index'] ?? 0)) <=> ((int) ($b['index'] ?? 0)));

        $vectors = [];
        foreach ($rows as $row) {
            $vector = $row['embedding'] ?? null;
            if (! is_array($vector) || count($vector) !== $expectedDims) {
                throw new UpstreamUnavailable(
                    'Embedding dimension mismatch: expected '.$expectedDims.', got '.(is_array($vector) ? count($vector) : 0)
                    .'. Check RUNTIME_EMBEDDINGS_MODEL vs RUNTIME_EMBEDDINGS_DIMENSIONS.',
                );
            }
            $vectors[] = array_map('floatval', $vector);
        }

        return $vectors;
    }

    public function model(): string
    {
        return (string) config('runtime.embeddings.model');
    }

    protected function baseUrl(): string
    {
        $url = (string) config('runtime.embeddings.openai_base_url', '');

        return $url !== '' ? $url : 'https://api.openai.com';
    }
}
