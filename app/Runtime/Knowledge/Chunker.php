<?php

namespace App\Runtime\Knowledge;

/**
 * Splits a document into retrieval-sized chunks.
 *
 * Strategy: greedy paragraph packing with a character budget approximating
 * the configured token sizes (~4 chars/token for English). Paragraphs that
 * individually exceed the budget are hard-split on sentence boundaries,
 * falling back to raw character slicing for pathological inputs (e.g. a
 * single unbroken line). Adjacent chunks share an overlap tail so a fact
 * straddling a boundary is retrievable from either side.
 */
class Chunker
{
    /**
     * @return list<string>
     */
    public function chunk(string $text): array
    {
        $budget = max(200, ((int) config('runtime.rag.chunk_size_tokens')) * 4);

        $text = trim(preg_replace("/\r\n?/", "\n", $text) ?? '');
        if ($text === '') {
            return [];
        }

        // Markdown headings are hard retrieval boundaries: a section is the
        // natural per-topic unit, and packing unrelated sections into one
        // chunk dilutes its embedding until nothing clears the answer
        // confidence threshold (a whole six-topic FAQ as a single chunk
        // scored ~0.38 against a question it answered verbatim). Documents
        // without headings keep the plain paragraph-packing behavior.
        $sections = preg_split('/\n(?=#{1,6}[ \t])/', $text) ?: [$text];
        if (count($sections) > 1) {
            $chunks = [];
            foreach ($sections as $section) {
                foreach ($this->chunkSection(trim($section), $budget) as $chunk) {
                    $chunks[] = $chunk;
                }
            }

            return array_values(array_filter($chunks, fn (string $c): bool => $c !== ''));
        }

        return $this->chunkSection($text, $budget);
    }

    /**
     * Paragraph-pack one heading-free block of text into budget-sized
     * chunks (the original whole-document strategy).
     *
     * @return list<string>
     */
    protected function chunkSection(string $text, int $budget): array
    {
        $overlap = max(0, ((int) config('runtime.rag.chunk_overlap_tokens')) * 4);

        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= $budget) {
            return [$text];
        }

        // Split into paragraphs, hard-splitting any single oversized one.
        $units = [];
        foreach (preg_split("/\n{2,}/", $text) ?: [] as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            if (mb_strlen($para) <= $budget) {
                $units[] = $para;

                continue;
            }
            foreach ($this->hardSplit($para, $budget) as $piece) {
                $units[] = $piece;
            }
        }

        // Greedy packing with overlap carried between chunks.
        $chunks = [];
        $current = '';
        foreach ($units as $unit) {
            $candidate = $current === '' ? $unit : $current."\n\n".$unit;
            if (mb_strlen($candidate) <= $budget) {
                $current = $candidate;

                continue;
            }
            if ($current !== '') {
                $chunks[] = $current;
                $tail = $overlap > 0 ? mb_substr($current, -$overlap) : '';
                $current = $tail === '' ? $unit : $tail."\n\n".$unit;
                // The carried tail can push the new buffer over budget for
                // large units — trim from the front to stay within bounds.
                if (mb_strlen($current) > $budget) {
                    $current = mb_substr($current, -$budget);
                }
            } else {
                $current = $unit;
            }
        }
        if (trim($current) !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Sentence-boundary split for an oversized paragraph; raw slicing as
     * the last resort.
     *
     * @return list<string>
     */
    protected function hardSplit(string $para, int $budget): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $para) ?: [$para];

        $pieces = [];
        $buffer = '';
        foreach ($sentences as $sentence) {
            // A single sentence longer than the budget: raw slice.
            while (mb_strlen($sentence) > $budget) {
                if ($buffer !== '') {
                    $pieces[] = $buffer;
                    $buffer = '';
                }
                $pieces[] = mb_substr($sentence, 0, $budget);
                $sentence = mb_substr($sentence, $budget);
            }
            $candidate = $buffer === '' ? $sentence : $buffer.' '.$sentence;
            if (mb_strlen($candidate) <= $budget) {
                $buffer = $candidate;
            } else {
                $pieces[] = $buffer;
                $buffer = $sentence;
            }
        }
        if (trim($buffer) !== '') {
            $pieces[] = $buffer;
        }

        return $pieces;
    }
}
