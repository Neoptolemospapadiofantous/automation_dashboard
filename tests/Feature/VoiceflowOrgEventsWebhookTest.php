<?php

namespace Tests\Feature;

use App\Models\VoiceflowProjectPoolEntry;
use App\Models\VoiceflowWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceflowOrgEventsWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.voiceflow.org_webhook_secret', 'org-shared-sek');
    }

    public function test_rejects_missing_secret(): void
    {
        $this->postJson(route('voiceflow.webhook.org'), [
            'type' => 'organization.project.deleted',
            'data' => ['projectID' => 'proj-x'],
        ])->assertStatus(401);
    }

    public function test_rejects_bad_secret(): void
    {
        $this->withHeader('X-Voiceflow-Org-Secret', 'wrong')
            ->postJson(route('voiceflow.webhook.org'), [
                'type' => 'organization.project.deleted',
                'data' => ['projectID' => 'proj-x'],
            ])->assertStatus(401);
    }

    public function test_persists_event_and_marks_processed(): void
    {
        $this->withHeader('X-Voiceflow-Org-Secret', 'org-shared-sek')
            ->postJson(route('voiceflow.webhook.org'), [
                'type' => 'organization.project.environment.published',
                'data' => ['projectID' => 'proj-1', 'publishedProjectEnvironmentID' => 'env-pub'],
                'time' => 1717000000000,
            ])->assertOk();

        $row = VoiceflowWebhookEvent::query()->latest('id')->first();
        $this->assertSame('organization.project.environment.published', $row->event_type);
        $this->assertNotNull($row->processed_at);
        $this->assertTrue($row->signature_valid);
    }

    public function test_project_deleted_retires_pool_entry(): void
    {
        // Pool has unique(voiceflow_project_id) — one entry per project.
        VoiceflowProjectPoolEntry::factory()->create([
            'voiceflow_project_id' => 'proj-doomed',
            'status' => 'available',
        ]);
        VoiceflowProjectPoolEntry::factory()->create([
            'voiceflow_project_id' => 'proj-other',
            'status' => 'available',
        ]);

        $this->withHeader('X-Voiceflow-Org-Secret', 'org-shared-sek')
            ->postJson(route('voiceflow.webhook.org'), [
                'type' => 'organization.project.deleted',
                'data' => ['projectID' => 'proj-doomed'],
            ])->assertOk();

        $this->assertSame('retired', VoiceflowProjectPoolEntry::query()
            ->where('voiceflow_project_id', 'proj-doomed')
            ->value('status'));

        // Untouched.
        $this->assertSame('available', VoiceflowProjectPoolEntry::query()
            ->where('voiceflow_project_id', 'proj-other')
            ->value('status'));
    }

    public function test_idempotent_on_duplicate_delivery(): void
    {
        $payload = [
            'type' => 'organization.project.deleted',
            'data' => ['projectID' => 'proj-dupe'],
            'time' => 1717000000000,
        ];

        $this->withHeader('X-Voiceflow-Org-Secret', 'org-shared-sek')
            ->postJson(route('voiceflow.webhook.org'), $payload)->assertOk();
        $this->withHeader('X-Voiceflow-Org-Secret', 'org-shared-sek')
            ->postJson(route('voiceflow.webhook.org'), $payload)->assertOk();

        $this->assertSame(1, VoiceflowWebhookEvent::query()
            ->where('event_type', 'organization.project.deleted')
            ->count());
    }
}
