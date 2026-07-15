<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Monday-morning summary of what the team's agent(s) did last week —
 * the product proving its value unprompted, plus the two work lists
 * (knowledge gaps, uncontacted leads) delivered to the inbox instead of
 * waiting for a dashboard visit.
 *
 * Mail-only by design: it's a summary, not an event — no bell entry.
 * Built and sent by teams:weekly-digest, which also suppresses it
 * entirely for quiet weeks. Stats arrive as a plain array so nothing
 * heavy is serialized onto the queue.
 */
class WeeklyDigestEmail extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $stats  see SendWeeklyDigests::stats()
     */
    public function __construct(public array $stats) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->stats;

        $mail = (new MailMessage)
            ->subject("Your week in review: {$s['conversations']} conversations, {$s['leads']} leads")
            ->greeting("Hi {$notifiable->name},")
            ->line(sprintf(
                'Last week your agent handled **%d conversations** (%d messages) and captured **%d leads** — %d qualified, %d won.',
                $s['conversations'],
                $s['messages'],
                $s['leads'],
                $s['qualified'],
                $s['won'],
            ));

        $quality = sprintf('%d conversations asked for a human (%s%%)', $s['escalated'], $s['escalation_rate']);
        if ($s['csat'] !== null) {
            $quality .= sprintf(' · %s%% visitor satisfaction', $s['csat']);
        }
        if ($s['canned_turns'] > 0) {
            $quality .= sprintf(' · %d answers served free', $s['canned_turns']);
        }
        $mail->line($quality.'.');

        if ($s['gaps'] !== []) {
            $mail->line('**Your knowledge base could not answer these** — add the missing content and the agent stops escalating them:');
            foreach ($s['gaps'] as $gap) {
                $mail->line(sprintf('- "%s" — asked %d time%s', $gap['question'], $gap['asked_count'], $gap['asked_count'] === 1 ? '' : 's'));
            }
        }

        if ($s['stale_leads'] > 0) {
            $mail->line(sprintf(
                '**%d lead%s still waiting on first contact** — hit "Mark contacted" once you reach out.',
                $s['stale_leads'],
                $s['stale_leads'] === 1 ? ' is' : 's are',
            ));
        }

        if (count($s['agents']) > 1) {
            $mail->line('Per agent:');
            foreach ($s['agents'] as $agent) {
                $mail->line(sprintf('- %s: %d conversations, %d leads', $agent['name'], $agent['conversations'], $agent['leads']));
            }
        }

        return $mail
            ->line(sprintf('Conversation credits: %d used last week, %d remaining.', $s['credits_used'], $s['credits_remaining']))
            ->action('Open your dashboard', url('/dashboard'));
    }
}
