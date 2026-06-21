<?php

namespace App\Http\Controllers;

use App\Support\ArchitectureGraph;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Local-only operator page: renders the full-application architecture as an
 * interactive 3D force-directed graph (nodes = components, links = call/render/
 * dispatch edges, colored by layer). The flat Mermaid source still lives in
 * docs/architecture/full-application-graph.md and is parsed out for an optional
 * 2D view. Registered only in the local environment (see routes/web.php), so it
 * never reaches production or the route-smoke test.
 */
class ArchitectureGraphController extends Controller
{
    public function __invoke(): Response
    {
        $file = base_path('docs/architecture/full-application-graph.md');
        $markdown = is_file($file) ? (string) file_get_contents($file) : '';

        return Inertia::render('Hermes/Architecture', [
            'graph' => (new ArchitectureGraph)->build(),
            'diagrams' => $this->extractDiagrams($markdown),
        ]);
    }

    /**
     * Pull each fenced ```mermaid block out of the doc, tagging it with the
     * nearest preceding markdown heading as its caption.
     *
     * @return list<array{caption: string, code: string}>
     */
    private function extractDiagrams(string $markdown): array
    {
        $lines = preg_split('/\r?\n/', $markdown) ?: [];

        $diagrams = [];
        $heading = 'Diagram';
        $inFence = false;
        $buffer = [];

        foreach ($lines as $line) {
            if (! $inFence && preg_match('/^#{1,6}\s+(.*)$/', $line, $m)) {
                $heading = trim($m[1]);

                continue;
            }

            if (! $inFence && preg_match('/^```mermaid\s*$/', trim($line))) {
                $inFence = true;
                $buffer = [];

                continue;
            }

            if ($inFence && preg_match('/^```\s*$/', trim($line))) {
                $inFence = false;
                $diagrams[] = [
                    'caption' => $heading,
                    'code' => implode("\n", $buffer),
                ];

                continue;
            }

            if ($inFence) {
                $buffer[] = $line;
            }
        }

        return $diagrams;
    }
}
