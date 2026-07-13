<?php

namespace App\Services;

use App\Enums\RecordType;
use App\Models\DnsEntry;
use App\Support\DnsEntryRules;
use Illuminate\Support\Facades\Validator;

class DnsEntryImporter
{
    public const COLUMNS = ['name', 'type', 'content', 'ttl', 'priority', 'proxied', 'comment'];

    public const MAX_ROWS = 1000;

    /** Errors are capped so a completely broken file yields a readable report. */
    private const MAX_REPORTED_FAILURES = 25;

    public function __construct(private SyncService $sync) {}

    /**
     * Import entries from raw CSV content. Valid rows are created and synced
     * to all compatible enabled providers; duplicates are skipped; invalid
     * rows are reported with their line number.
     *
     * @return array{imported: int, skipped: int, failed: list<array{line: int, message: string}>}
     */
    public function import(string $csv): array
    {
        $rows = $this->parse($csv);

        $imported = 0;
        $skipped = 0;
        $failed = [];

        foreach ($rows as [$line, $row]) {
            if ($row === null) {
                $failed[] = ['line' => $line, 'message' => 'Row does not match the expected columns.'];

                continue;
            }

            $validator = Validator::make(
                $row,
                DnsEntryRules::rules(RecordType::tryFrom((string) $row['type'])),
                DnsEntryRules::messages(),
            );

            if ($validator->fails()) {
                $failed[] = ['line' => $line, 'message' => implode(' ', $validator->errors()->all())];

                continue;
            }

            $data = $validator->validated();

            $exists = DnsEntry::query()
                ->where('name', $data['name'])
                ->where('type', $data['type'])
                ->where('content', $data['content'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $entry = DnsEntry::create($data);
            $this->sync->syncEntry($entry);
            $imported++;
        }

        if (count($failed) > self::MAX_REPORTED_FAILURES) {
            $extra = count($failed) - self::MAX_REPORTED_FAILURES;
            $failed = array_slice($failed, 0, self::MAX_REPORTED_FAILURES);
            $failed[] = ['line' => 0, 'message' => "…and {$extra} more invalid row(s)."];
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * The downloadable sample file.
     */
    public static function sampleCsv(): string
    {
        return implode("\n", [
            implode(',', self::COLUMNS),
            'app.example.com,A,192.168.1.10,,,true,Web frontend',
            'nas.example.com,A,192.168.1.20,3600,,false,',
            'router.example.com,AAAA,2001:db8::1,,,false,',
            'media.example.com,CNAME,nas.example.com,300,,false,Jellyfin',
            'example.com,MX,mail.example.com,,10,false,Primary mail',
            '_dmarc.example.com,TXT,"v=DMARC1; p=none",,,false,',
        ])."\n";
    }

    /**
     * Parse CSV content into [line number, row-or-null] pairs. A null row
     * signals a column-count mismatch. Throws on structural problems that
     * make the whole file unusable.
     *
     * @return list<array{0: int, 1: ?array<string, mixed>}>
     */
    private function parse(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));

        if ($lines === false || $lines === [''] || count($lines) < 2) {
            throw new \InvalidArgumentException('The file is empty or has no data rows.');
        }

        $header = array_map(fn ($col) => strtolower(trim($col)), str_getcsv(array_shift($lines)));

        $missing = array_diff(['name', 'type', 'content'], $header);

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Missing required column(s): '.implode(', ', $missing).'. Download the sample file for the expected format.',
            );
        }

        if (count($lines) > self::MAX_ROWS) {
            throw new \InvalidArgumentException('Too many rows — the limit is '.self::MAX_ROWS.' per import.');
        }

        $rows = [];

        foreach ($lines as $index => $rawLine) {
            $line = $index + 2; // 1-based, accounting for the header row

            if (trim($rawLine) === '') {
                continue;
            }

            $values = str_getcsv($rawLine);

            if (count($values) > count($header)) {
                $rows[] = [$line, null];

                continue;
            }

            $row = array_combine($header, array_pad($values, count($header), null));

            $rows[] = [$line, $this->normalize($row)];
        }

        return $rows;
    }

    /**
     * Coerce CSV strings into the shapes the validator expects. Unknown
     * columns are ignored.
     */
    private function normalize(array $row): array
    {
        $get = fn (string $key) => isset($row[$key]) && trim((string) $row[$key]) !== '' ? trim((string) $row[$key]) : null;

        return [
            'name' => $get('name'),
            'type' => strtoupper((string) $get('type')),
            'content' => $get('content'),
            'ttl' => $get('ttl'),
            'priority' => $get('priority'),
            'proxied' => filter_var($get('proxied') ?? 'false', FILTER_VALIDATE_BOOLEAN),
            'comment' => $get('comment'),
        ];
    }
}
