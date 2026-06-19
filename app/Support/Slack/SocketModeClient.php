<?php

namespace App\Support\Slack;

use Illuminate\Support\Facades\Log;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use React\EventLoop\LoopInterface;

/**
 * Slack Socket Mode transport — opens an OUTBOUND WebSocket to Slack (via
 * apps.connections.open) and streams events over it, so the bot needs no public
 * inbound endpoint. This is what keeps the bot "fully local".
 *
 * Built on ratchet/pawl over the ReactPHP loop already vendored by Reverb.
 * Deliberately thin: it acks each envelope (Slack requires an ack within 3s)
 * and hands the decoded payload to a callback — all routing/auth lives in
 * SlackEventRouter, which is testable without a socket. Socket Mode URLs are
 * single-use, so every (re)connect mints a fresh one and we auto-reconnect on
 * close.
 */
class SocketModeClient
{
    public function __construct(
        private readonly SlackApi $api,
    ) {}

    /**
     * Connect and dispatch envelopes to $onPayload until the loop is stopped.
     *
     * @param  callable(array<string,mixed>):mixed  $onPayload
     */
    public function run(LoopInterface $loop, callable $onPayload): void
    {
        $this->connect($loop, $onPayload);
    }

    /**
     * @param  callable(array<string,mixed>):mixed  $onPayload
     */
    private function connect(LoopInterface $loop, callable $onPayload): void
    {
        $url = $this->api->openConnection();
        if ($url === '') {
            Log::error('SocketMode: apps.connections.open failed; retrying in 5s.');
            $loop->addTimer(5.0, fn () => $this->connect($loop, $onPayload));

            return;
        }

        (new Connector($loop))($url)->then(
            function (WebSocket $conn) use ($loop, $onPayload): void {
                Log::info('SocketMode: connected.');

                $conn->on('message', function ($msg) use ($conn, $onPayload): void {
                    $this->onMessage($conn, (string) $msg, $onPayload);
                });

                $conn->on('close', function ($code = null, $reason = null) use ($loop, $onPayload): void {
                    Log::warning("SocketMode: connection closed ({$code} {$reason}); reconnecting in 2s.");
                    $loop->addTimer(2.0, fn () => $this->connect($loop, $onPayload));
                });
            },
            function (\Throwable $e) use ($loop, $onPayload): void {
                Log::error('SocketMode: connect failed — '.$e->getMessage().'; retrying in 5s.');
                $loop->addTimer(5.0, fn () => $this->connect($loop, $onPayload));
            },
        );
    }

    /**
     * Decode, ack, and dispatch one frame.
     *
     * @param  callable(array<string,mixed>):mixed  $onPayload
     */
    private function onMessage(WebSocket $conn, string $raw, callable $onPayload): void
    {
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return;
        }

        $type = (string) ($data['type'] ?? '');
        if ($type === 'hello') {
            return; // handshake greeting — nothing to do
        }
        if ($type === 'disconnect') {
            // Slack is rotating us off this socket; close → the 'close' handler reconnects.
            $conn->close();

            return;
        }

        // Ack FIRST (within 3s) so Slack doesn't retry the delivery.
        if (isset($data['envelope_id'])) {
            $conn->send((string) json_encode(['envelope_id' => $data['envelope_id']]));
        }

        try {
            $onPayload($data);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
