<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AuditTrail\AtlasLoopAuditTrailComposer;
use App\Services\Ai\AutonomousEvolution\AuditTrail\AtlasLoopAuditTrailExporter;
use App\Services\Ai\AutonomousEvolution\AuditTrail\AtlasLoopAuditTrailIntegrityVerifier;
use App\Services\Ai\AutonomousEvolution\AuditTrail\AtlasLoopAuditTrailReplayer;
use App\Services\Ai\AutonomousEvolution\AuditTrail\AuditEvent;
use App\Services\Ai\AutonomousEvolution\AuditTrail\TimelineWindow;
use Illuminate\Console\Command;

/**
 * Operator-facing CLI for the audit trail surface:
 *   atlas:loop:audit timeline --since= --until=
 *   atlas:loop:audit replay   --from=  --to=     [--filter=]
 *   atlas:loop:audit export   --since= --until= --out=path.jsonl
 *   atlas:loop:audit verify   --since= --until=
 *
 * The CLI resolves Composer/Replayer/Exporter/IntegrityVerifier from the container — it does NOT
 * instantiate them inline.
 */
final class AtlasLoopAuditCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:audit
        {action : timeline|replay|export|verify}
        {--since=}
        {--until=}
        {--from=}
        {--to=}
        {--filter=}
        {--out=}
        {--source-jsonl=}';

    /** @var string */
    protected $description = 'Audit trail CLI: timeline | replay | export | verify.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $this->maybeRegisterJsonlSource();

        return match ($action) {
            'timeline' => $this->doTimeline(),
            'replay' => $this->doReplay(),
            'export' => $this->doExport(),
            'verify' => $this->doVerify(),
            default => $this->emit(['error' => 'unknown_action:'.$action], self::FAILURE),
        };
    }

    private function doTimeline(): int
    {
        $window = $this->window((string) $this->option('since'), (string) $this->option('until'));
        $composer = app(AtlasLoopAuditTrailComposer::class);
        $events = [];
        foreach ($composer->compose($window) as $e) {
            if ($e instanceof AuditEvent) {
                $events[] = $this->encodeEvent($e);
            }
        }

        return $this->emit(['action' => 'timeline', 'events' => $events]);
    }

    private function doReplay(): int
    {
        $replayer = app(AtlasLoopAuditTrailReplayer::class);
        $filterRaw = (string) $this->option('filter');
        $filter = $filterRaw === '' ? null : json_decode($filterRaw, true);
        $report = $replayer->replay(
            (string) $this->option('from'),
            (string) $this->option('to'),
            is_array($filter) ? $filter : null,
        );

        return $this->emit(['action' => 'replay', 'report' => $report->toArray()]);
    }

    private function doExport(): int
    {
        $window = $this->window((string) $this->option('since'), (string) $this->option('until'));
        $out = (string) $this->option('out');
        if ($out === '') {
            return $this->emit(['error' => 'out_required'], self::FAILURE);
        }
        $composer = app(AtlasLoopAuditTrailComposer::class);
        $exporter = app(AtlasLoopAuditTrailExporter::class);
        $manifest = $exporter->export($composer->compose($window), $out, $window);

        return $this->emit(['action' => 'export', 'out' => $out, 'count' => $manifest->event_count]);
    }

    private function doVerify(): int
    {
        $window = $this->window((string) $this->option('since'), (string) $this->option('until'));
        $composer = app(AtlasLoopAuditTrailComposer::class);
        $verifier = app(AtlasLoopAuditTrailIntegrityVerifier::class);
        $events = [];
        foreach ($composer->compose($window) as $e) {
            if ($e instanceof AuditEvent) {
                $events[] = $this->encodeEvent($e);
            }
        }
        $report = $verifier->verify($events);
        $status = $report->isIntact() ? 'intact' : 'anomalies_detected';
        $payload = [
            'action' => 'verify',
            'status' => $status,
            'anomaly_count' => count($report->anomalies),
            'anomalies' => $report->anomalies,
        ];

        return $this->emit($payload, $report->isIntact() ? self::SUCCESS : self::FAILURE);
    }

    private function window(string $from, string $to): TimelineWindow
    {
        $defaultFrom = '1970-01-01T00:00:00Z';
        $defaultTo = '2999-12-31T23:59:59Z';

        return new TimelineWindow(
            $from !== '' ? $from : $defaultFrom,
            $to !== '' ? $to : $defaultTo,
        );
    }

    /**
     * Register a fixture JSONL source on the composer. Only used by feature tests — production callers
     * register sources via the service provider.
     */
    private function maybeRegisterJsonlSource(): void
    {
        $path = (string) $this->option('source-jsonl');
        if ($path === '' || ! is_file($path)) {
            return;
        }
        $composer = app(AtlasLoopAuditTrailComposer::class);
        $rows = $this->loadJsonlRows($path);
        $composer->register('jsonl_fixture', static function (TimelineWindow $window) use ($rows): \Generator {
            foreach ($rows as $row) {
                yield $row;
            }
        });
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadJsonlRows(string $path): array
    {
        $rows = [];
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\n");
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        } finally {
            fclose($fh);
        }

        return $rows;
    }

    /**
     * @return list<AuditEvent>
     */
    private function loadJsonlEvents(string $path): array
    {
        $events = [];
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\n");
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (! is_array($decoded)) {
                    continue;
                }
                $events[] = new AuditEvent(
                    (string) ($decoded['event_id'] ?? ''),
                    (string) ($decoded['ts_utc'] ?? ''),
                    (string) ($decoded['source_ledger'] ?? ''),
                    (string) ($decoded['kind'] ?? ''),
                    array_values(array_map('strval', (array) ($decoded['refs'] ?? []))),
                    is_array($decoded['facts'] ?? null) ? $decoded['facts'] : [],
                );
            }
        } finally {
            fclose($fh);
        }

        return $events;
    }

    /**
     * @return array<string,mixed>
     */
    private function encodeEvent(AuditEvent $e): array
    {
        $facts = $e->facts;
        $this->ksortRecursive($facts);

        return [
            'event_id' => ctype_digit($e->event_id) ? (int) $e->event_id : $e->event_id,
            'ts_utc' => $e->ts_utc,
            'source_ledger' => $e->source_ledger,
            'kind' => $e->kind,
            'refs' => array_map(
                static fn (string $r) => ctype_digit($r) ? (int) $r : $r,
                $e->refs,
            ),
            'facts' => $facts,
            'content_hash' => hash('sha256', (string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }

    /**
     * @param  array<string,mixed>  $arr
     */
    private function ksortRecursive(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$v) {
            if (is_array($v)) {
                $this->ksortRecursive($v);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit = self::SUCCESS): int
    {
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $exit;
    }
}
