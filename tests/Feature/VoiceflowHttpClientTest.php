<?php

namespace Tests\Feature;

use App\Services\Voiceflow\Client\VoiceflowHttpClient;
use App\Services\Voiceflow\Exceptions\AuthException;
use App\Services\Voiceflow\Exceptions\MisconfiguredException;
use App\Services\Voiceflow\Exceptions\NotFoundException;
use App\Services\Voiceflow\Exceptions\RateLimitedException;
use App\Services\Voiceflow\Exceptions\UpstreamException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceflowHttpClientTest extends TestCase
{
    public function test_misconfigured_when_api_key_empty(): void
    {
        $this->expectException(MisconfiguredException::class);

        (new VoiceflowHttpClient)->client('https://example.test', '');
    }

    public function test_ensure_ok_passes_through_2xx(): void
    {
        Http::fake(['https://example.test/ok' => Http::response(['hello' => 'world'], 200)]);

        $http = new VoiceflowHttpClient;
        $response = $http->client('https://example.test', 'fake-key')->get('/ok');

        $checked = $http->ensureOk($response, 'test.ok');

        $this->assertSame(['hello' => 'world'], $checked->json());
    }

    public function test_ensure_ok_maps_401_to_auth_exception(): void
    {
        Http::fake(['https://example.test/auth' => Http::response('nope', 401)]);

        $response = (new VoiceflowHttpClient)->client('https://example.test', 'fake-key')->get('/auth');

        $this->expectException(AuthException::class);

        (new VoiceflowHttpClient)->ensureOk($response, 'test.auth');
    }

    public function test_ensure_ok_maps_404_to_not_found_exception(): void
    {
        Http::fake(['https://example.test/missing' => Http::response('gone', 404)]);

        $response = (new VoiceflowHttpClient)->client('https://example.test', 'fake-key')->get('/missing');

        $this->expectException(NotFoundException::class);

        (new VoiceflowHttpClient)->ensureOk($response, 'test.missing');
    }

    public function test_ensure_ok_maps_429_with_retry_after(): void
    {
        Http::fake([
            'https://example.test/throttle' => Http::response('slow down', 429, ['Retry-After' => '7']),
        ]);

        $response = (new VoiceflowHttpClient)->client('https://example.test', 'fake-key')->get('/throttle');

        try {
            (new VoiceflowHttpClient)->ensureOk($response, 'test.throttle');
            $this->fail('Expected RateLimitedException');
        } catch (RateLimitedException $e) {
            $this->assertSame(7, $e->retryAfterSeconds);
            $this->assertSame(429, $e->httpStatus);
            $this->assertSame('test.throttle', $e->endpoint);
        }
    }

    public function test_ensure_ok_maps_5xx_to_upstream_exception(): void
    {
        Http::fake(['https://example.test/boom' => Http::response('upstream broken', 503)]);

        $response = (new VoiceflowHttpClient)->client('https://example.test', 'fake-key')->get('/boom');

        try {
            (new VoiceflowHttpClient)->ensureOk($response, 'test.boom');
            $this->fail('Expected UpstreamException');
        } catch (UpstreamException $e) {
            $this->assertSame(503, $e->httpStatus);
            $this->assertSame('test.boom', $e->endpoint);
        }
    }

    public function test_log_context_includes_endpoint_and_status(): void
    {
        Http::fake(['https://example.test/x' => Http::response('nope', 500)]);

        $response = (new VoiceflowHttpClient)->client('https://example.test', 'fake-key')->get('/x');

        try {
            (new VoiceflowHttpClient)->ensureOk($response, 'test.x', ['extra' => 'context']);
            $this->fail('Expected UpstreamException');
        } catch (UpstreamException $e) {
            $context = $e->toLogContext();
            $this->assertSame(500, $context['voiceflow_status']);
            $this->assertSame('test.x', $context['voiceflow_endpoint']);
            $this->assertSame('context', $context['extra']);
        }
    }
}
