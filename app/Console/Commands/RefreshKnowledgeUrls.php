<?php

namespace App\Console\Commands;

use App\Runtime\Contracts\KnowledgeStore;
use App\Runtime\Models\KbDocument;
use App\Support\PublicWebPage;
use Illuminate\Console\Command;

/**
 * Re-fetch every URL-sourced knowledge document and re-ingest the ones
 * whose text changed, so a customer whose site moved stops serving the
 * old answer. Unchanged pages cost one HTTP fetch and no embedding call.
 *
 * Per document: refresh_checked_at is stamped on every look, refreshed_at
 * only when the content changed and was re-ingested, refresh_error when
 * the fetch failed (the old content stays in service — a 404 today must
 * not wipe knowledge that was right yesterday).
 *
 * Weekly on the schedule; --document=ID runs one on demand (the Refresh
 * button on the Knowledge page).
 */
class RefreshKnowledgeUrls extends Command
{
    protected $signature = 'knowledge:refresh-urls {--document= : Refresh one document id only}';

    protected $description = 'Re-read URL knowledge documents and re-ingest the ones whose page changed';

    public function handle(KnowledgeStore $knowledge, PublicWebPage $web): int
    {
        $query = KbDocument::query()->where('source', 'url')->whereNotNull('source_url');
        if ($this->option('document') !== null) {
            $query->whereKey((int) $this->option('document'));
        }

        $checked = 0;
        $changed = 0;
        $failed = 0;

        foreach ($query->orderBy('id')->cursor() as $document) {
            $checked++;
            $outcome = $this->refresh($document, $knowledge, $web);
            if ($outcome === 'changed') {
                $changed++;
            } elseif ($outcome === 'failed') {
                $failed++;
            }
        }

        $this->info("Checked {$checked} URL document(s): {$changed} changed and re-ingested, {$failed} failed to fetch.");

        return self::SUCCESS;
    }

    /**
     * @return 'changed'|'unchanged'|'failed'
     */
    public function refresh(KbDocument $document, KnowledgeStore $knowledge, PublicWebPage $web): string
    {
        $url = (string) $document->source_url;

        try {
            $content = $web->fetchText($url);
        } catch (\Throwable $e) {
            $document->forceFill([
                'refresh_checked_at' => now(),
                'refresh_error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            return 'failed';
        }

        if (hash('sha256', $content) === hash('sha256', (string) $document->raw_content)) {
            $document->forceFill(['refresh_checked_at' => now(), 'refresh_error' => null])->save();

            return 'unchanged';
        }

        // ingestDocument() replaces the row that carries this source_url,
        // so the new document inherits the URL and gets fresh chunks; stamp
        // the refresh times on the replacement.
        $metadata = (array) ($document->metadata ?? []);
        $metadata['source'] = 'url';
        $metadata['source_url'] = $url;
        unset($metadata['content_hash']);

        try {
            $newId = $knowledge->ingestDocument((int) $document->agent_id, (string) $document->title, $content, $metadata);
        } catch (\Throwable $e) {
            report($e);
            $document->forceFill([
                'refresh_checked_at' => now(),
                'refresh_error' => 'Re-index failed: '.mb_substr($e->getMessage(), 0, 400),
            ])->save();

            return 'failed';
        }

        KbDocument::query()->whereKey($newId)->update([
            'refresh_checked_at' => now(),
            'refreshed_at' => now(),
            'refresh_error' => null,
        ]);

        return 'changed';
    }
}
