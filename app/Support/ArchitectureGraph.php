<?php

namespace App\Support;

/**
 * Builds the full-application dependency graph by scanning every PHP class under
 * app/. Nodes = classes (plus synthetic client entry points); edges = real
 * `use App\...` imports and same-namespace sibling references. Shared by the
 * local-only architecture page (ArchitectureGraphController) and the
 * graph-integrity gate check (arch:graph-check) so both see the identical graph.
 */
class ArchitectureGraph
{
    /**
     * Controllers that serve a non-browser client; everything else in the
     * controller layer is auto-wired to the dashboard browser.
     *
     * @var array<string, string>
     */
    public const NON_BROWSER_CONTROLLERS = [
        'App\\Http\\Controllers\\EmbedController' => 'client::widget',
        'App\\Http\\Controllers\\StripeWebhookController' => 'client::stripe',
        'App\\Http\\Controllers\\SubscribeController' => 'client::stripe',
    ];

    /**
     * @return array{nodes: list<array{id: string, label: string, group: string}>, links: list<array{source: string, target: string}>}
     */
    public function build(): array
    {
        // Path-prefix → colour group. This is *only* for colouring; discovery is
        // automatic (every class under app/ is scanned, below). Longest matching
        // prefix wins; anything unmapped falls back to its top-level dir name, so
        // a brand-new app/ folder shows up without touching this file.
        $groupMap = [
            'Http/Controllers' => 'controller',
            'Http/Middleware' => 'http',
            'Models' => 'model',
            'Runtime/LLM' => 'llm',
            'Runtime/Tools' => 'tools',
            'Runtime/Knowledge' => 'knowledge',
            'Runtime/Models' => 'data',
            'Runtime' => 'runtime',
            'Services' => 'service',
            'Billing' => 'billing',
            'Events' => 'events',
            'Listeners' => 'events',
            'Lifecycle' => 'domain',
            'Enums' => 'enum',
            'Policies' => 'policy',
            'Authorization' => 'policy',
            'Notifications' => 'notification',
            'Actions' => 'action',
            'Console' => 'console',
            'Providers' => 'provider',
            'Support' => 'support',
        ];
        // Longest prefix first so 'Runtime/LLM' beats 'Runtime'.
        uksort($groupMap, static fn ($a, $b) => strlen($b) <=> strlen($a));

        // Abstract bases / framework glue that would only add noise as nodes.
        $skip = ['App\Http\Controllers\Controller'];

        $byFqcn = [];   // FQCN => node
        $byShort = [];  // short name => list of FQCN (for sibling resolution)
        $contents = []; // FQCN => file body

        // Recursively scan EVERY PHP class under app/ — nothing curated.
        $root = app_path();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $fqcn = 'App\\'.str_replace('/', '\\', substr($rel, 0, -4)); // drop .php
            if (in_array($fqcn, $skip, true)) {
                continue;
            }

            $dir = str_contains($rel, '/') ? substr($rel, 0, (int) strrpos($rel, '/')) : '';
            $group = explode('/', $rel)[0]; // fallback: top-level dir name
            foreach ($groupMap as $prefix => $g) {
                if ($dir === $prefix || str_starts_with($dir.'/', $prefix.'/')) {
                    $group = $g;
                    break;
                }
            }

            $short = $file->getBasename('.php');
            $byFqcn[$fqcn] = ['id' => $fqcn, 'label' => $short, 'group' => $group];
            $byShort[$short][] = $fqcn;
            $contents[$fqcn] = (string) file_get_contents($file->getPathname());
        }

        $links = [];
        $seen = [];
        $add = function (string $from, string $to) use (&$links, &$seen): void {
            if ($from === $to) {
                return;
            }
            $key = $from.'>'.$to;
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $links[] = ['source' => $from, 'target' => $to];
            }
        };

        foreach ($byFqcn as $fqcn => $node) {
            $body = $contents[$fqcn];
            $ns = substr($fqcn, 0, (int) strrpos($fqcn, '\\'));

            // (a) explicit `use App\...;` imports that resolve to a known node.
            if (preg_match_all('/^use\s+(App\\\\[A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?;/m', $body, $m)) {
                foreach ($m[1] as $imported) {
                    if (isset($byFqcn[$imported])) {
                        $add($fqcn, $imported);
                    }
                }
            }

            // (b) same-namespace siblings referenced by bare class name (no
            // `use` needed within a namespace) — e.g. LlmRouter → AnthropicClient.
            foreach ($byShort as $short => $fqcns) {
                foreach ($fqcns as $candidate) {
                    if ($candidate === $fqcn) {
                        continue;
                    }
                    $candidateNs = substr($candidate, 0, (int) strrpos($candidate, '\\'));
                    if ($candidateNs === $ns && preg_match('/\b'.preg_quote($short, '/').'\b/', $body)) {
                        $add($fqcn, $candidate);
                    }
                }
            }
        }

        $nodes = array_values($byFqcn);

        // Synthetic client/entry nodes — not classes, but where traffic enters.
        $clients = [
            'client::browser' => 'Dashboard browser (Inertia/Vue)',
            'client::widget' => 'Embedded chat widget',
            'client::stripe' => 'Stripe webhooks',
            'client::cron' => 'Scheduler / cron',
        ];
        foreach ($clients as $id => $label) {
            $nodes[] = ['id' => $id, 'label' => $label, 'group' => 'client'];
        }

        // A few controllers serve non-browser clients (the widget, Stripe). Wire
        // those explicitly; everything else in the controller layer is browser-
        // facing and gets auto-wired below, so no controller can silently fall
        // off the graph when one is added.
        foreach (self::NON_BROWSER_CONTROLLERS as $fqcn => $client) {
            if (isset($byFqcn[$fqcn])) {
                $add($client, $fqcn);
            }
        }
        // Auto-wire every remaining controller to the dashboard browser.
        foreach ($byFqcn as $fqcn => $node) {
            if ($node['group'] === 'controller' && ! isset(self::NON_BROWSER_CONTROLLERS[$fqcn])) {
                $add('client::browser', $fqcn);
            }
        }
        if (isset($byFqcn['App\\Billing\\EvaluateCreditAlerts'])) {
            $add('client::cron', 'App\\Billing\\EvaluateCreditAlerts');
        }
        // The scheduler invokes artisan commands — wire cron to each.
        foreach ($byFqcn as $fqcn => $node) {
            if ($node['group'] === 'console') {
                $add('client::cron', $fqcn);
            }
        }

        return ['nodes' => $nodes, 'links' => $links];
    }
}
