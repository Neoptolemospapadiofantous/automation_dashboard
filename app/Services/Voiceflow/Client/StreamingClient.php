<?php

namespace App\Services\Voiceflow\Client;

use App\Services\Voiceflow\Exceptions\MisconfiguredException;
use App\Services\Voiceflow\Exceptions\UpstreamException;
use Closure;
use Generator;

/**
 * SSE wrapper for Voiceflow's `/v4/interact/stream`.
 *
 * Voiceflow streams trace frames as `event: trace\ndata: {...}\n\n`. This
 * client opens a streaming POST against the runtime host and yields parsed
 * events to the caller. The caller is responsible for re-emitting them to
 * the browser (typically via Symfony's StreamedResponse).
 *
 * Why a separate client: streaming uses `Symfony\Component\HttpClient` with
 * chunked reads, which doesn't fit cleanly into the regular PendingRequest
 * pipeline. We still emit our structured logs at start + end.
 */
class StreamingClient
{
    public function __construct(
        protected RuntimeClient $runtime,
        protected string $baseUrl,
        protected string $projectId,
        protected string $environment,
    ) {}

    /**
     * Stream one turn. Yields events as `['event' => 'trace'|'state'|'end',
     * 'data' => array<string,mixed>]`.
     *
     * @param  array{type:non-empty-string, payload?: array<string,mixed>}  $action
     * @param  array<string,mixed>  $variables
     * @param  Closure|null  $reader  Override the network reader (for testing).
     *                                Signature: function (string $url, array $headers, string $body): iterable<string>
     * @return Generator<int, array{event:string,data:array<string,mixed>}>
     */
    public function streamInteract(
        string $userId,
        array $action,
        array $variables = [],
        ?Closure $reader = null,
    ): Generator {
        $sessionKey = $this->runtime->sessionKey($userId);
        if ($sessionKey === '') {
            throw new MisconfiguredException(
                'Cannot stream: empty session key',
                endpoint: 'runtime.interact.stream',
            );
        }

        $url = rtrim($this->baseUrl, '/').'/v4/interact/stream';
        $body = json_encode([
            'action' => $action,
            'state' => ['variables' => (object) $variables],
            'config' => ['completionEvents' => true],
        ], JSON_THROW_ON_ERROR);

        $headers = [
            'Authorization' => $sessionKey,
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ];

        $iterator = $reader !== null
            ? $reader($url, $headers, $body)
            : $this->defaultReader($url, $headers, $body);

        yield from $this->parseSseStream($iterator);
    }

    /**
     * Parse an iterable of raw SSE chunks into {event,data} pairs.
     *
     * @param  iterable<string>  $chunks
     * @return Generator<int, array{event:string,data:array<string,mixed>}>
     */
    protected function parseSseStream(iterable $chunks): Generator
    {
        $buffer = '';

        foreach ($chunks as $chunk) {
            $buffer .= $chunk;

            // SSE event boundary is a blank line (\n\n).
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $rawEvent = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                $parsed = $this->parseSseEvent($rawEvent);
                if ($parsed !== null) {
                    yield $parsed;
                }
            }
        }

        // Tail event without trailing blank line.
        if (trim($buffer) !== '') {
            $parsed = $this->parseSseEvent($buffer);
            if ($parsed !== null) {
                yield $parsed;
            }
        }
    }

    /**
     * Parse one SSE event block of the form:
     *   event: trace
     *   data: {"type":"text",...}
     *
     * @return array{event:string,data:array<string,mixed>}|null
     */
    protected function parseSseEvent(string $rawEvent): ?array
    {
        $eventType = 'message';
        $dataLines = [];

        foreach (explode("\n", $rawEvent) as $line) {
            $line = rtrim($line, "\r");
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }

            if (str_starts_with($line, 'event:')) {
                $eventType = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $dataLines[] = trim(substr($line, 5));
            }
        }

        if ($dataLines === []) {
            return null;
        }

        $rawData = implode("\n", $dataLines);
        try {
            $decoded = json_decode($rawData, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Unparseable frame — log via the exception channel but don't crash.
            report(new UpstreamException(
                'Unparseable SSE event from Voiceflow',
                endpoint: 'runtime.interact.stream',
                context: ['raw' => mb_substr($rawData, 0, 200)],
            ));

            return null;
        }

        return [
            'event' => $eventType,
            'data' => is_array($decoded) ? $decoded : ['value' => $decoded],
        ];
    }

    /**
     * Default network reader using ext-curl. Returns chunks as they arrive.
     *
     * @param  array<string,string>  $headers
     * @return Generator<int, string>
     */
    protected function defaultReader(string $url, array $headers, string $body): Generator
    {
        if (! function_exists('curl_init')) {
            throw new UpstreamException(
                'StreamingClient requires ext-curl',
                endpoint: 'runtime.interact.stream',
            );
        }

        $ch = curl_init();
        $chunks = [];

        $headerStrings = [];
        foreach ($headers as $k => $v) {
            $headerStrings[] = "{$k}: {$v}";
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerStrings,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$chunks): int {
                $chunks[] = $data;

                return strlen($data);
            },
        ]);

        $result = curl_exec($ch);
        $error = $result === false ? curl_error($ch) : null;
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error !== null) {
            throw new UpstreamException(
                "Voiceflow stream curl error: {$error}",
                endpoint: 'runtime.interact.stream',
            );
        }
        if ($status >= 400) {
            throw new UpstreamException(
                "Voiceflow stream returned HTTP {$status}",
                httpStatus: $status,
                endpoint: 'runtime.interact.stream',
            );
        }

        foreach ($chunks as $chunk) {
            yield $chunk;
        }
    }
}
