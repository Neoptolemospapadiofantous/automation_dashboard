<?php

namespace App\Console\Commands;

use App\Billing\CreditMeter;
use App\Billing\Exceptions\OutOfCredits;
use App\Models\Agent;
use App\Models\AgentConfigVersion;
use App\Models\Team;
use App\Runtime\Contracts\Runtime;
use App\Runtime\Exceptions\RuntimeException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Interactive terminal surface onto the native agent runtime — a CLI sibling
 * of the web Chat page and the Slack bot. Resolves a team + agent, then drives
 * the same Runtime turn loop (launch / sendText) and bills credits with the
 * identical formula ChatController uses, so CLI sessions never drift the books.
 *
 * Use --message for a single non-interactive turn (scripting / smoke tests).
 */
class AgentsTerminal extends Command
{
    protected $signature = 'agents:terminal
        {--team= : Team id (prompts if omitted and you have more than one)}
        {--agent= : Agent id, slug, or name (defaults to the team current agent)}
        {--visitor=terminal-cli : Stable visitor id, so the session resumes across runs}
        {--message= : Send one message, print the reply, and exit (non-interactive)}
        {--no-bill : Do not debit credits (dev only)}';

    protected $description = 'Chat with a Flowstack agent from the terminal (CLI surface on the native runtime).';

    public function handle(Runtime $runtime, CreditMeter $credits): int
    {
        $team = $this->resolveTeam();
        if (! $team instanceof Team) {
            return self::FAILURE;
        }

        $agent = $this->resolveAgent($team);
        if (! $agent instanceof Agent) {
            return self::FAILURE;
        }

        $visitor = (string) $this->option('visitor') ?: 'terminal-cli';
        $bill = ! $this->option('no-bill');

        $health = $runtime->health($agent);
        if (! $health['ok']) {
            $this->components->warn('Agent engine not ready: '.$health['reason']);
        }

        $this->components->info("Connected to «{$agent->name}» (team: {$team->name}, visitor: {$visitor})");
        if ($bill) {
            $this->line('  billing: on · per-message cost '.AgentConfigVersion::creditsPerMessage($agent->id).' credit(s)');
        } else {
            $this->components->warn('billing: OFF (--no-bill) — credits will not be debited');
        }

        // Resume an existing session, otherwise greet from a fresh one.
        if ($runtime->hasSession($agent, $visitor)) {
            $this->line('  (resuming previous session)');
            foreach ($runtime->transcript($agent, $visitor) as $entry) {
                $this->renderLine($agent, $entry['role'], $entry['text']);
            }
        } else {
            try {
                $this->printReplies($agent, $runtime->launch($agent, $visitor));
            } catch (RuntimeException $e) {
                $this->components->error('Agent unavailable: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        // Non-interactive single turn.
        if (($oneShot = $this->option('message')) !== null) {
            return $this->turn($runtime, $credits, $team, $agent, $visitor, (string) $oneShot, $bill);
        }

        $this->newLine();
        $this->line('  Type a message. Commands: /reset  /transcript  /exit');

        while (true) {
            $text = $this->ask('you');
            if ($text === null) {
                break; // EOF / Ctrl-D
            }
            $text = trim($text);
            if ($text === '') {
                continue;
            }

            if (in_array($text, ['/exit', '/quit'], true)) {
                break;
            }
            if ($text === '/reset') {
                $this->printReplies($agent, $runtime->launch($agent, $visitor));

                continue;
            }
            if ($text === '/transcript') {
                foreach ($runtime->transcript($agent, $visitor) as $entry) {
                    $this->renderLine($agent, $entry['role'], $entry['text']);
                }

                continue;
            }

            $this->turn($runtime, $credits, $team, $agent, $visitor, $text, $bill);
        }

        $this->components->info('Session left open — run again with the same --visitor to resume.');

        return self::SUCCESS;
    }

    /**
     * One full turn: pre-check credits, send to the runtime, print replies,
     * then bill exactly as ChatController does.
     */
    protected function turn(Runtime $runtime, CreditMeter $credits, Team $team, Agent $agent, string $visitor, string $text, bool $bill): int
    {
        if ($bill && ! $team->hasCredits()) {
            $this->components->error('Out of credits for this billing period. Use --no-bill for dev, or top up.');

            return self::FAILURE;
        }

        try {
            $traces = $runtime->sendText($agent, $visitor, $text);
        } catch (RuntimeException $e) {
            $this->components->error('Agent unavailable: '.$e->getMessage());

            return self::FAILURE;
        }

        $replies = $this->printReplies($agent, $traces);

        if ($bill) {
            $amount = (1 + count($replies)) * AgentConfigVersion::creditsPerMessage($agent->id);
            try {
                $credits->consume(
                    team: $team,
                    amount: $amount,
                    agentId: $agent->id,
                    meta: ['surface' => 'cli', 'visitor' => $visitor],
                );
            } catch (OutOfCredits) {
                $this->components->warn('Credit debit raced past zero — reply already delivered.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Print the agent text out of runtime traces and return just the strings
     * (the count drives billing, mirroring ChatController::respond).
     *
     * @param  list<array<string, mixed>>  $traces
     * @return list<string>
     */
    protected function printReplies(Agent $agent, array $traces): array
    {
        $messages = [];
        foreach ($traces as $trace) {
            $text = (string) ($trace['payload']['message'] ?? '');
            if ($text === '') {
                continue;
            }
            $messages[] = $text;
            $this->renderLine($agent, 'agent', $text);

            $citations = (array) ($trace['payload']['citations'] ?? []);
            foreach ($citations as $c) {
                $title = is_array($c) ? ($c['document_title'] ?? $c['title'] ?? null) : null;
                if ($title) {
                    $this->line('    <fg=gray>↳ source: '.$title.'</>');
                }
            }
        }

        return $messages;
    }

    protected function renderLine(Agent $agent, string $role, string $text): void
    {
        if ($role === 'user') {
            $this->line('<fg=cyan>you</> '.$text);
        } else {
            $this->line('<fg=green>'.$agent->name.'</> '.$text);
        }
    }

    protected function resolveTeam(): ?Team
    {
        if ($id = $this->option('team')) {
            $team = Team::find($id);
            if (! $team instanceof Team) {
                $this->components->error("No team with id {$id}.");

                return null;
            }

            return $team;
        }

        $teams = Team::query()->orderBy('id')->get();
        if ($teams->isEmpty()) {
            $this->components->error('No teams exist yet.');

            return null;
        }
        if ($teams->count() === 1) {
            return $teams->first();
        }

        $choice = $this->choice(
            'Which team?',
            $teams->mapWithKeys(fn (Team $t) => [$t->id => "{$t->name} (#{$t->id})"])->all(),
        );

        return $teams->firstWhere('id', (int) Str::before($choice, ' '))
            ?? $teams->first(fn (Team $t) => "{$t->name} (#{$t->id})" === $choice);
    }

    protected function resolveAgent(Team $team): ?Agent
    {
        if ($needle = $this->option('agent')) {
            $agent = $team->agents()
                ->where(fn ($q) => $q->where('id', $needle)->orWhere('slug', $needle)->orWhere('name', $needle))
                ->first();
            if (! $agent instanceof Agent) {
                $this->components->error("No agent matching «{$needle}» on team {$team->name}.");

                return null;
            }

            return $agent;
        }

        if ($team->currentAgent instanceof Agent) {
            return $team->currentAgent;
        }

        $agents = $team->agents()->orderBy('id')->get();
        if ($agents->isEmpty()) {
            $this->components->error("Team {$team->name} has no agents.");

            return null;
        }
        if ($agents->count() === 1) {
            return $agents->first();
        }

        $choice = $this->choice(
            'Which agent?',
            $agents->mapWithKeys(fn (Agent $a) => [$a->id => "{$a->name} (#{$a->id})"])->all(),
        );

        return $agents->firstWhere('id', (int) Str::before($choice, ' '))
            ?? $agents->first(fn (Agent $a) => "{$a->name} (#{$a->id})" === $choice);
    }
}
