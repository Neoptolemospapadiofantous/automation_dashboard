<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Runtime\Collab\AgentConversation;
use App\Runtime\Collab\RoomLedger;
use Illuminate\Console\Command;

/**
 * Multi-agent collaboration from the terminal: open a room of agents and run a
 * round-table where each agent responds to the previous speaker. Agents can
 * span teams. Every turn is billed to the speaking agent's team and recorded
 * to the RoomLedger so the exchange is auditable and resumable.
 */
class AgentsCollab extends Command
{
    protected $signature = 'agents:collab
        {--agents= : Comma-separated agent ids/slugs/names (2+ recommended)}
        {--topic= : Opening task/question the room works on}
        {--rounds=1 : How many times each agent speaks}
        {--room= : Room id for the ledger (defaults to a timestamped one)}
        {--reset : Clear the room ledger and agent sessions before starting}
        {--no-bill : Do not debit credits (dev only)}';

    protected $description = 'Run a round-table of multiple agents collaborating on a topic.';

    public function handle(AgentConversation $convo, RoomLedger $ledger): int
    {
        $agents = $this->resolveAgents();
        if ($agents === null) {
            return self::FAILURE;
        }

        $topic = (string) ($this->option('topic') ?: $this->ask('Topic / task for the room'));
        if (trim($topic) === '') {
            $this->components->error('A topic is required.');

            return self::FAILURE;
        }

        $room = (string) ($this->option('room') ?: 'session-'.now()->format('Ymd-His'));
        $rounds = max(1, (int) $this->option('rounds'));
        $bill = ! $this->option('no-bill');

        if ($this->option('reset')) {
            $ledger->reset($room);
        }

        $this->components->info('Room «'.$room.'» · '.count($agents).' agents · '.$rounds.' round(s)'.($bill ? '' : ' · billing OFF'));
        $this->line('  '.collect($agents)->map(fn (Agent $a) => $a->name.' (#'.$a->id.')')->implode(', '));
        $this->newLine();
        $this->line('<fg=yellow>topic</> '.$topic);
        $this->newLine();

        $transcript = $convo->roundtable($agents, $topic, $rounds, $room, $bill);

        foreach ($transcript as $turn) {
            if ($turn['text'] === '') {
                $this->line('<fg=red>'.$turn['agent'].'</> (no reply)');

                continue;
            }
            $this->line('<fg=green>'.$turn['agent'].'</> '.$turn['text']);
            $this->newLine();
        }

        $this->components->info('Ledger: '.$ledger->path($room));

        return self::SUCCESS;
    }

    /**
     * @return list<Agent>|null null = resolution failed (message already shown)
     */
    protected function resolveAgents(): ?array
    {
        $raw = (string) $this->option('agents');
        $tokens = array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($t) => $t !== ''));

        if ($tokens === []) {
            $this->components->error('Pass --agents=<id,id,...> (ids, slugs, or names).');

            return null;
        }

        $agents = [];
        foreach ($tokens as $needle) {
            $agent = Agent::query()
                ->where('id', $needle)
                ->orWhere('slug', $needle)
                ->orWhere('name', $needle)
                ->first();
            if (! $agent instanceof Agent) {
                $this->components->error('No agent matching «'.$needle.'».');

                return null;
            }
            $agents[] = $agent;
        }

        if (count($agents) < 2) {
            $this->components->warn('Only one agent resolved — a round-table wants 2+. Continuing anyway.');
        }

        return $agents;
    }
}
