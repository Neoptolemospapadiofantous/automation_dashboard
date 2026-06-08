<?php

namespace App\Services\Voiceflow\Dto;

/**
 * One trace frame from a Voiceflow `/v4/interact` response.
 *
 * Voiceflow returns a heterogeneous array of frames per turn (text, speak,
 * choice, end, visual, …). This DTO holds the shape consistent across types
 * and exposes the raw payload for type-specific consumers.
 */
final readonly class Trace
{
    public function __construct(
        public string $type,
        public array $payload,
    ) {}

    /**
     * Construct a Trace from one raw frame as returned by Voiceflow.
     *
     * @param  array{type?: string, payload?: array<string,mixed>}  $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            type: (string) ($raw['type'] ?? ''),
            payload: is_array($raw['payload'] ?? null) ? $raw['payload'] : [],
        );
    }

    /**
     * Construct a list of Traces from a Voiceflow `/v4/interact` response body.
     *
     * @param  array<int, array<string,mixed>>  $traces
     * @return list<self>
     */
    public static function listFromArray(array $traces): array
    {
        $out = [];
        foreach ($traces as $raw) {
            if (is_array($raw)) {
                $out[] = self::fromArray($raw);
            }
        }

        return $out;
    }

    /**
     * The visible text on a text/speak trace; empty string for non-textual types.
     */
    public function text(): string
    {
        $msg = $this->payload['message'] ?? null;

        return is_string($msg) ? $msg : '';
    }

    /**
     * Whether this trace marks the end of the dialog.
     */
    public function isEnd(): bool
    {
        return $this->type === 'end';
    }
}
