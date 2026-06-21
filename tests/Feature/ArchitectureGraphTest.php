<?php

namespace Tests\Feature;

use App\Support\ArchitectureGraph;
use Tests\TestCase;

/**
 * Guards the auto-discovered architecture graph (the local-only /architecture
 * page) against drift: every app/ class becomes a node, and no node should be
 * left orphaned. Mirrors the arch:graph-check gate command.
 */
class ArchitectureGraphTest extends TestCase
{
    public function test_node_count_matches_classes_on_disk_plus_clients(): void
    {
        $graph = (new ArchitectureGraph)->build();

        $classFiles = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $f) {
            if ($f instanceof \SplFileInfo && $f->getExtension() === 'php') {
                $classFiles++;
            }
        }

        // -1 skipped base Controller, +4 synthetic client nodes.
        $this->assertCount(($classFiles - 1) + 4, $graph['nodes']);
    }

    public function test_no_node_is_orphaned_except_known_floaters(): void
    {
        $allowed = [
            'App\\Models\\Membership',
            'App\\Models\\TeamInvitation',
            'App\\Http\\Middleware\\ComingSoon',
            'App\\Http\\Middleware\\SecurityHeaders',
        ];

        $graph = (new ArchitectureGraph)->build();

        $degree = [];
        foreach ($graph['nodes'] as $n) {
            $degree[$n['id']] = 0;
        }
        foreach ($graph['links'] as $l) {
            $degree[$l['source']]++;
            $degree[$l['target']]++;
        }

        $orphans = array_values(array_filter(
            array_keys($degree),
            fn ($id) => $degree[$id] === 0 && ! in_array($id, $allowed, true),
        ));

        $this->assertSame([], $orphans, 'Unexpected orphan node(s) in architecture graph');
    }

    public function test_every_controller_is_wired_to_a_client(): void
    {
        $graph = (new ArchitectureGraph)->build();

        $wiredFromClient = [];
        foreach ($graph['links'] as $l) {
            if (str_starts_with($l['source'], 'client::')) {
                $wiredFromClient[$l['target']] = true;
            }
        }

        $unwired = [];
        foreach ($graph['nodes'] as $n) {
            if ($n['group'] === 'controller' && ! isset($wiredFromClient[$n['id']])) {
                $unwired[] = $n['id'];
            }
        }

        $this->assertSame([], $unwired, 'Controller(s) not wired to any client entry point');
    }
}
