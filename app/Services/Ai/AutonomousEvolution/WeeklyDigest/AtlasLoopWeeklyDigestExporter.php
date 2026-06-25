<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\WeeklyDigest;

/**
 * Renders an {@see AtlasLoopWeeklyDigestComposer} snapshot to deterministic Markdown.
 *
 *   - Sections per ledger source.
 *   - FACTS tables only (occurred_at, event_kind, payload_digest).
 *   - Top-of-file `snapshot_digest:` header echoes the composer snapshot hash so two exports of
 *     the same week are byte-identical.
 *   - Refuses to write when master_switch_off=true.
 *   - NEVER renders provider names / prompts / trace ids.
 *
 * Output path: `<root>/<iso_week>.md` (idempotent — overwrite is allowed because content is
 * deterministic for identical snapshot).
 */
final class AtlasLoopWeeklyDigestExporter
{
    public const SCHEMA = 'atlas.loop.weekly_digest_export.v1';

    public function __construct(private readonly string $rootDir)
    {
        if (! is_dir($this->rootDir)) {
            @mkdir($this->rootDir, 0o755, true);
        }
    }

    /**
     * @param  array<string,mixed>  $snapshot  output of AtlasLoopWeeklyDigestComposer::compose()
     * @return array<string,mixed>  {written: bool, path: ?string, markdown: ?string, reason?}
     */
    public function export(array $snapshot, string $isoWeek): array
    {
        if ((bool) ($snapshot['master_switch_off'] ?? false)) {
            return ['written' => false, 'path' => null, 'markdown' => null, 'reason' => 'master_switch_off'];
        }

        $markdown = $this->render($snapshot);
        $path = $this->rootDir.'/'.$this->safeIsoWeek($isoWeek).'.md';
        if (@file_put_contents($path, $markdown) === false) {
            return ['written' => false, 'path' => null, 'markdown' => $markdown, 'reason' => 'write_failed'];
        }

        return ['written' => true, 'path' => $path, 'markdown' => $markdown];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    public function render(array $snapshot): string
    {
        $digest = (string) ($snapshot['snapshot_hash'] ?? '');
        $from = (string) ($snapshot['window_from'] ?? '');
        $to = (string) ($snapshot['window_to'] ?? '');
        $rows = array_values((array) ($snapshot['rows'] ?? []));

        // Group by ledger_source, preserve deterministic order.
        $bySource = [];
        foreach ($rows as $row) {
            $bySource[(string) $row['ledger_source']][] = $row;
        }
        ksort($bySource, SORT_STRING);

        $lines = [];
        $lines[] = '# Atlas Loop Weekly Digest';
        $lines[] = '';
        $lines[] = 'snapshot_digest: '.$digest;
        $lines[] = 'window_from: '.$from;
        $lines[] = 'window_to: '.$to;
        $lines[] = '';

        if ($bySource === []) {
            $lines[] = '_no facts in window_';
            $lines[] = '';
        }

        foreach ($bySource as $source => $sourceRows) {
            $lines[] = '## '.$source;
            $lines[] = '';
            $lines[] = '| occurred_at | event_kind | payload_digest |';
            $lines[] = '| --- | --- | --- |';
            foreach ($sourceRows as $row) {
                $lines[] = sprintf(
                    '| %s | %s | %s |',
                    (string) $row['occurred_at'],
                    (string) $row['event_kind'],
                    (string) $row['payload_digest'],
                );
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function safeIsoWeek(string $isoWeek): string
    {
        // Allow only [A-Za-z0-9_-], replace others with '-'.
        return preg_replace('/[^A-Za-z0-9_-]/', '-', $isoWeek) ?? 'week';
    }
}
