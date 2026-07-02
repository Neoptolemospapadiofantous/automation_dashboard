<?php

namespace App\Runtime\Collab;

use Illuminate\Support\Str;

/**
 * Append-only JSON record of a multi-agent "room" — the shared memory of who
 * said what, so a collaboration is auditable and resumable. Lives under
 * data/agents/rooms/<room>.json, matching the data/agents/* findings layout
 * the scheduled collectors already use. data/ is gitignored (local state).
 */
class RoomLedger
{
    /**
     * @return array{room: string, created_at: string, turns: list<array<string, mixed>>}
     */
    public function read(string $room): array
    {
        $path = $this->path($room);
        if (! is_file($path)) {
            return ['room' => $room, 'created_at' => now()->toIso8601String(), 'turns' => []];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) && isset($decoded['turns']) && is_array($decoded['turns'])
            ? [
                'room' => (string) ($decoded['room'] ?? $room),
                'created_at' => (string) ($decoded['created_at'] ?? now()->toIso8601String()),
                'turns' => array_values($decoded['turns']),
            ]
            : ['room' => $room, 'created_at' => now()->toIso8601String(), 'turns' => []];
    }

    /**
     * @param  array<string, mixed>  $turn
     */
    public function append(string $room, array $turn): void
    {
        $ledger = $this->read($room);
        $ledger['turns'][] = $turn + ['at' => now()->toIso8601String()];

        $dir = dirname($this->path($room));
        if (! is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
        file_put_contents(
            $this->path($room),
            json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    public function reset(string $room): void
    {
        $path = $this->path($room);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function path(string $room): string
    {
        return base_path('data/agents/rooms/'.Str::slug($room).'.json');
    }
}
