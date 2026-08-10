<?php

namespace App\Console\Commands;

use App\Support\ArchitectureGraph;
use Illuminate\Console\Command;

/**
 * Integrity gate for the auto-discovered architecture graph (the local-only
 * /architecture page). Rebuilds the graph from the live codebase and fails if it
 * has drifted out of shape — a node count that no longer matches the class files
 * on disk, or a node that has no edges at all. Catches the failure mode where a
 * new layer/class silently falls off the map. Known-floating nodes (used only
 * from config/migrations/bootstrap, not app PHP) are allowlisted.
 */
class ArchGraphCheck extends Command
{
    protected $signature = 'arch:graph-check';

    protected $description = 'Verify the architecture graph stays complete (node count matches app/ classes; no orphan nodes).';

    /**
     * Nodes legitimately unconnected at the code level: referenced only from
     * config (Jetstream models), or wired outside app/ in bootstrap/app.php
     * (global middleware). Not graph bugs — exempt from the orphan check.
     *
     * @var list<string>
     */
    private const ALLOWED_ORPHANS = [
        'App\\Models\\Membership',
        'App\\Models\\TeamInvitation',
        'App\\Http\\Middleware\\SecurityHeaders',
    ];

    public function handle(): int
    {
        $graph = (new ArchitectureGraph)->build();

        $errors = [];

        // 1. Node count must match the .php classes actually on disk (minus the
        //    one skipped base controller) plus the 4 synthetic client nodes.
        $classFiles = 0;
        $root = app_path();
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $f) {
            if ($f instanceof \SplFileInfo && $f->getExtension() === 'php') {
                $classFiles++;
            }
        }
        $expected = ($classFiles - 1) + 4; // -1 skipped base Controller, +4 clients
        $actual = count($graph['nodes']);
        if ($actual !== $expected) {
            $errors[] = "node count {$actual} != expected {$expected} (".($classFiles - 1).' classes + 4 clients) — discovery drifted';
        }

        // 2. No node may be completely unconnected (except known floaters).
        $degree = [];
        foreach ($graph['nodes'] as $n) {
            $degree[$n['id']] = 0;
        }
        foreach ($graph['links'] as $l) {
            $degree[$l['source']] = ($degree[$l['source']] ?? 0) + 1;
            $degree[$l['target']] = ($degree[$l['target']] ?? 0) + 1;
        }
        foreach ($degree as $id => $deg) {
            if ($deg === 0 && ! in_array($id, self::ALLOWED_ORPHANS, true)) {
                $errors[] = "orphan node (no edges): {$id}";
            }
        }

        if ($errors !== []) {
            foreach ($errors as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        $this->info("arch graph OK — {$actual} nodes, ".count($graph['links']).' edges, no orphans');

        return self::SUCCESS;
    }
}
