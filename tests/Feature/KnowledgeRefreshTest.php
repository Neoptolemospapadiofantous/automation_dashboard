<?php

namespace Tests\Feature;

use App\Console\Commands\RefreshKnowledgeUrls;
use App\Models\Agent;
use App\Models\User;
use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\Models\KbDocument;
use App\Support\PublicWebPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class KnowledgeRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function urlDocument(Agent $agent, string $content = 'Old text about opening hours.'): KbDocument
    {
        return KbDocument::create([
            'agent_id' => $agent->id,
            'title' => 'Opening hours',
            'source' => 'url',
            'source_url' => 'https://example.com/hours',
            'raw_content' => $content,
            'metadata' => ['source' => 'url', 'source_url' => 'https://example.com/hours', 'content_hash' => hash('sha256', $content)],
            'chunk_count' => 1,
        ]);
    }

    public function test_unchanged_page_is_stamped_and_not_reingested(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $doc = $this->urlDocument($agent);

        $web = Mockery::mock(PublicWebPage::class);
        $web->shouldReceive('fetchText')->once()->with('https://example.com/hours')->andReturn('Old text about opening hours.');
        $store = Mockery::mock(KnowledgeStore::class);
        $store->shouldNotReceive('ingestDocument');

        $outcome = (new RefreshKnowledgeUrls)->refresh($doc, $store, $web);

        $this->assertSame('unchanged', $outcome);
        $doc->refresh();
        $this->assertNotNull($doc->refresh_checked_at);
        $this->assertNull($doc->refreshed_at);
    }

    public function test_changed_page_is_reingested_and_the_replacement_is_stamped(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $doc = $this->urlDocument($agent);
        $replacement = KbDocument::create([
            'agent_id' => $agent->id, 'title' => 'Opening hours', 'source' => 'url',
            'source_url' => 'https://example.com/hours', 'raw_content' => 'New text', 'metadata' => [], 'chunk_count' => 1,
        ]);

        $web = Mockery::mock(PublicWebPage::class);
        $web->shouldReceive('fetchText')->once()->andReturn('New text: we now open on Saturdays.');
        $store = Mockery::mock(KnowledgeStore::class);
        $store->shouldReceive('ingestDocument')
            ->once()
            ->withArgs(fn ($agentId, $title, $content, $meta) => $agentId === $agent->id
                && $title === 'Opening hours'
                && str_contains($content, 'Saturdays')
                && $meta['source_url'] === 'https://example.com/hours'
                && ! isset($meta['content_hash']))
            ->andReturn($replacement->id);

        $outcome = (new RefreshKnowledgeUrls)->refresh($doc, $store, $web);

        $this->assertSame('changed', $outcome);
        $this->assertNotNull($replacement->fresh()->refreshed_at);
    }

    public function test_fetch_failure_keeps_the_old_content_and_records_the_error(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $doc = $this->urlDocument($agent);

        $web = Mockery::mock(PublicWebPage::class);
        $web->shouldReceive('fetchText')->once()->andThrow(new \RuntimeException('That URL returned HTTP 404.'));
        $store = Mockery::mock(KnowledgeStore::class);
        $store->shouldNotReceive('ingestDocument');

        $outcome = (new RefreshKnowledgeUrls)->refresh($doc, $store, $web);

        $this->assertSame('failed', $outcome);
        $doc->refresh();
        $this->assertSame('That URL returned HTTP 404.', $doc->refresh_error);
        $this->assertSame('Old text about opening hours.', $doc->raw_content);
    }

    public function test_refresh_button_is_url_documents_only(): void
    {
        config(['runtime.embeddings.openai_api_key' => 'sk-test']);
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id])->save();
        $text = KbDocument::create(['agent_id' => $agent->id, 'title' => 'Pasted', 'source' => 'text', 'raw_content' => 'x', 'metadata' => [], 'chunk_count' => 1]);

        $this->actingAs($user)
            ->post(route('knowledge.refresh', $text->id))
            ->assertSessionHasErrors('url');
    }
}
