<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Team;
use App\Runtime\Contracts\KnowledgeStore;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * Load a project's docs into a per-project agent's knowledge base, so the
 * terminal agent can answer about that codebase from memory. Creates (or
 * reuses) one agent named after the project and ingests its markdown
 * (README / AGENTS.md / CLAUDE.md / docs/**) via the KnowledgeStore.
 */
class AgentsIngestProject extends Command
{
    protected $signature = 'agents:ingest-project
        {path : Project directory (absolute, or relative to your home)}
        {--agent= : Ingest into an EXISTING agent (id, slug, or name) instead of a per-project one}
        {--team=2 : Team that owns the project agent (ignored when --agent is set)}
        {--name= : Agent name (defaults to the project folder name)}
        {--reset : Delete the agent\'s existing documents first}';

    protected $description = 'Ingest a directory of markdown docs into an agent\'s knowledge base (per-project, or an existing agent via --agent).';

    /** Skip heavy / irrelevant trees. */
    private const SKIP_DIRS = ['node_modules', 'vendor', '.git', 'dist', 'build', 'coverage', '.next'];

    private const MAX_FILES = 60;

    private const MAX_BYTES = 200_000;

    public function handle(KnowledgeStore $kb): int
    {
        $path = $this->resolvePath((string) $this->argument('path'));
        if ($path === null) {
            return self::FAILURE;
        }

        // Target an existing agent by id/slug/name (e.g. the landing agent),
        // or fall back to the per-project find-or-create on a team.
        if ($needle = (string) $this->option('agent')) {
            $agent = $this->findExistingAgent($needle);
            if (! $agent instanceof Agent) {
                $this->components->error('No agent matching «'.$needle.'».');

                return self::FAILURE;
            }
            $team = $agent->team;
            $name = $agent->name;
        } else {
            $team = Team::find($this->option('team'));
            if (! $team instanceof Team) {
                $this->components->error('No team with id '.$this->option('team').'.');

                return self::FAILURE;
            }

            $name = (string) ($this->option('name') ?: basename($path));
            $agent = $this->findOrCreateAgent($team, $name);
        }

        if ($this->option('reset')) {
            foreach ($kb->listDocuments($agent->id) as $doc) {
                $kb->deleteDocument($agent->id, $doc['id']);
            }
            $this->line('  reset: cleared existing documents');
        }

        $files = $this->collectDocs($path);
        if ($files === []) {
            $this->components->warn('No markdown docs found under '.$path);

            return self::SUCCESS;
        }

        $teamLabel = $team instanceof Team ? $team->name : 'no team';
        $this->components->info("Ingesting «{$name}» → agent #{$agent->id} (team {$teamLabel})");

        $rows = [];
        $ok = 0;
        foreach ($files as $relative => $content) {
            try {
                $kb->ingestDocument($agent->id, $relative, $content, ['project' => $name, 'path' => $relative]);
                $rows[] = [$relative, number_format(strlen($content)).' B', 'ok'];
                $ok++;
            } catch (\Throwable $e) {
                $rows[] = [$relative, number_format(strlen($content)).' B', 'FAILED: '.substr($e->getMessage(), 0, 50)];
            }
        }

        $this->table(['Document', 'Size', 'Status'], $rows);
        $this->components->info("Ingested {$ok}/".count($files).' docs. Chat: php artisan agents:terminal --agent='.$agent->id.' --no-bill');

        return $ok > 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Resolve an existing agent globally by id, slug, or name (slugs are unique). */
    private function findExistingAgent(string $needle): ?Agent
    {
        return Agent::query()
            ->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }

    private function findOrCreateAgent(Team $team, string $name): Agent
    {
        $agent = $team->agents()->where('name', $name)->first();
        if ($agent instanceof Agent) {
            return $agent;
        }

        $agent = $team->agents()->create([
            'name' => $name,
            'status' => Agent::STATUS_ACTIVE,
            'mode' => Agent::MODE_MANAGED,
            'runtime_mode' => Agent::RUNTIME_NATIVE,
        ]);
        $this->line('  created agent #'.$agent->id.' «'.$name.'»');

        return $agent;
    }

    private function resolvePath(string $raw): ?string
    {
        $candidates = [$raw, getcwd().'/'.$raw, (string) getenv('HOME').'/'.$raw];
        foreach ($candidates as $c) {
            $real = realpath($c);
            if ($real !== false && is_dir($real)) {
                return $real;
            }
        }
        $this->components->error('No such directory: '.$raw);

        return null;
    }

    /**
     * @return array<string, string> relative path => file contents
     */
    private function collectDocs(string $path): array
    {
        $finder = (new Finder)
            ->files()
            ->in($path)
            ->name('*.md')
            ->depth('< 4')
            ->size('< '.self::MAX_BYTES)
            ->exclude(self::SKIP_DIRS)
            ->sortByName();

        $docs = [];
        foreach ($finder as $file) {
            $content = trim($file->getContents());
            if ($content === '') {
                continue;
            }
            $docs[$file->getRelativePathname()] = $content;
            if (count($docs) >= self::MAX_FILES) {
                break;
            }
        }

        return $docs;
    }
}
