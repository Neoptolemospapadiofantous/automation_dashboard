<?php

namespace Tests\Feature;

use App\Enums\AssignmentStrategy;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\LeadAssignedNotification;
use App\Services\LeadDelegator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LeadAssignedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_via_includes_database_and_mail(): void
    {
        $user = User::factory()->create();
        $lead = Lead::factory()->create(['name' => 'Test Person']);

        $notification = new LeadAssignedNotification($lead);

        $this->assertSame(['database', 'mail'], $notification->via($user));
    }

    public function test_mail_renders_with_lead_context(): void
    {
        $user = User::factory()->create(['name' => 'Rep Alice']);
        $lead = Lead::factory()->create([
            'name' => 'Bob Buyer',
            'company' => 'Buyer Co',
            'email' => 'bob@buyer.co',
            'phone' => '+1-555-0100',
            'score' => 85,
        ]);

        /** @var MailMessage $mail */
        $mail = (new LeadAssignedNotification($lead))->toMail($user);

        $this->assertSame('New lead assigned: Bob Buyer', $mail->subject);
        $this->assertStringContainsString('Rep Alice', (string) $mail->greeting);
        $this->assertSame('Open in dashboard', $mail->actionText);

        $body = implode(' ', array_map(fn ($l) => (string) $l, $mail->introLines));
        $this->assertStringContainsString('Score: 85/100', $body);
        $this->assertStringContainsString('Buyer Co', $body);
        $this->assertStringContainsString('bob@buyer.co', $body);
        $this->assertStringContainsString('+1-555-0100', $body);
    }

    public function test_delegator_dispatches_notification_via_both_channels(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $rep = User::factory()->create();
        $owner->currentTeam->users()->attach($rep, ['role' => 'editor']);

        $lead = Lead::factory()->for($owner->currentTeam)->create();

        app(LeadDelegator::class)->assign(
            lead: $lead,
            strategy: AssignmentStrategy::Manual,
            byUser: $owner,
            toUserId: $rep->id,
        );

        Notification::assertSentTo($rep, LeadAssignedNotification::class);
    }
}
