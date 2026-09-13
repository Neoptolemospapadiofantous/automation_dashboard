<?php

namespace App\Support;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;

/**
 * The CSV shape leads travel in, both directions. Export writes exactly
 * the columns import reads, so a file exported from one team imports
 * cleanly into another (or back into the same one after editing).
 *
 * Header aliases are lenient on import ("E-mail", "Phone number",
 * "Company name") because the file usually comes out of somebody else's
 * spreadsheet, not ours.
 */
final class LeadCsv
{
    /** Export columns, in order. */
    public const COLUMNS = [
        'id', 'name', 'email', 'phone', 'company', 'source', 'status', 'score',
        'assigned_to', 'tags', 'notes', 'created_at', 'last_contacted_at',
    ];

    /** Import: recognised header → field. Lower-cased, punctuation stripped. */
    private const ALIASES = [
        'name' => 'name', 'fullname' => 'name', 'contact' => 'name', 'lead' => 'name',
        'email' => 'email', 'emailaddress' => 'email', 'mail' => 'email',
        'phone' => 'phone', 'phonenumber' => 'phone', 'mobile' => 'phone', 'tel' => 'phone', 'telephone' => 'phone',
        'company' => 'company', 'companyname' => 'company', 'organisation' => 'company', 'organization' => 'company', 'business' => 'company',
        'source' => 'source',
        'status' => 'status', 'stage' => 'status',
        'score' => 'score',
        'tags' => 'tags', 'labels' => 'tags',
        'notes' => 'notes', 'note' => 'notes', 'comments' => 'notes',
    ];

    public const MAX_ROWS = 5000;

    /**
     * @return list<string|int|null>
     */
    public static function row(Lead $lead): array
    {
        $status = $lead->getAttribute('status');
        $status = $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
        $assignee = $lead->assignee;

        return [
            $lead->id,
            self::cell($lead->name),
            self::cell($lead->email),
            self::cell($lead->phone),
            self::cell($lead->company),
            self::cell($lead->source),
            $status,
            (int) $lead->score,
            self::cell($assignee instanceof User ? (string) $assignee->name : null),
            implode(', ', (array) ($lead->tags ?? [])),
            self::cell($lead->notes),
            $lead->created_at?->toIso8601String(),
            $lead->last_contacted_at?->toIso8601String(),
        ];
    }

    /**
     * Parse a CSV body into normalised lead rows. Returns the rows plus
     * per-line problems; a row with no usable name is a problem, not a
     * lead. Never throws on a malformed line — that line is reported.
     *
     * @return array{rows: list<array<string, mixed>>, errors: list<string>, truncated: bool}
     */
    public static function parse(string $body): array
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body; // UTF-8 BOM
        $lines = preg_split('/\r\n|\r|\n/', trim($body)) ?: [];
        if ($lines === [] || trim($lines[0]) === '') {
            return ['rows' => [], 'errors' => ['The file is empty.'], 'truncated' => false];
        }

        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(fn (string $h) => self::field($h), str_getcsv($lines[0], $delimiter, '"', '\\'));

        if (! in_array('name', $header, true) && ! in_array('email', $header, true)) {
            return ['rows' => [], 'errors' => ['No "name" or "email" column found in the header row.'], 'truncated' => false];
        }

        $rows = [];
        $errors = [];
        $truncated = false;
        foreach (array_slice($lines, 1) as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }
            $cells = str_getcsv($line, $delimiter, '"', '\\');
            $record = [];
            foreach ($header as $col => $field) {
                if ($field === null) {
                    continue;
                }
                $record[$field] = trim((string) ($cells[$col] ?? ''));
            }

            $name = $record['name'] ?? '';
            $email = mb_strtolower($record['email'] ?? '');
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'Line '.($i + 2).': "'.$email.'" is not an email address.';

                continue;
            }
            if ($name === '' && $email === '') {
                $errors[] = 'Line '.($i + 2).': no name or email.';

                continue;
            }

            $status = mb_strtolower($record['status'] ?? '');
            $score = $record['score'] ?? '';

            $rows[] = [
                'name' => $name !== '' ? mb_substr($name, 0, 255) : '(no name)',
                'email' => $email !== '' ? mb_substr($email, 0, 255) : null,
                'phone' => ($record['phone'] ?? '') !== '' ? mb_substr($record['phone'], 0, 50) : null,
                'company' => ($record['company'] ?? '') !== '' ? mb_substr($record['company'], 0, 255) : null,
                'source' => ($record['source'] ?? '') !== '' ? mb_substr(mb_strtolower($record['source']), 0, 50) : 'import',
                'status' => LeadStatus::tryFrom($status)->value ?? LeadStatus::New->value,
                'score' => is_numeric($score) ? max(0, min(100, (int) $score)) : 0,
                'tags' => Tags::normalize($record['tags'] ?? ''),
                'notes' => ($record['notes'] ?? '') !== '' ? mb_substr($record['notes'], 0, 10000) : null,
            ];
        }

        return ['rows' => $rows, 'errors' => $errors, 'truncated' => $truncated];
    }

    private static function field(string $header): ?string
    {
        $key = preg_replace('/[^a-z]/', '', mb_strtolower($header)) ?? '';

        return self::ALIASES[$key] ?? null;
    }

    private static function cell(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Neutralise spreadsheet formula injection: a cell starting with
        // = + - @ is executed by Excel/Sheets when the file is opened.
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
