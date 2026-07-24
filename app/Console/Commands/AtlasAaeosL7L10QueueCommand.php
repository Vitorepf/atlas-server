<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\L7L10QueueConsumer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Support\YesNo;

/**
 * Governed L7-L10 queue consumer CLI (operator mandate, 2026-06-01).
 *
 * Validates the L7-L10 convergence trail (S83-S165) inside the BROAD backlog doc
 * WITHOUT the auto-runner ever swallowing the whole broad doc. The 24h runner's
 * auto-index consumes the canonical atomic docs plus the dedicated L7-L10 child
 * backlog; this command is the explicit audit/materialization seam for the
 * S83-S165 trail. `--emit-child` materializes a loop-ready child plan-doc
 * (S83-S165 only) when an operator wants to regenerate it.
 */
final class AtlasAaeosL7L10QueueCommand extends Command
{
    protected $signature = 'atlas:software-company-stewardship:l7-l10-queue
        {--doc=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md : Source backlog doc carrying the L7-L10 trail}
        {--repo-root= : Repo root; defaults to base_path()}
        {--emit-child= : Write the loop-ready S83-S165 child plan-doc to this path (operator-chosen; not committed by this command)}
        {--child-id=atlas-aaeos-l7-l10-loop-ready-backlog : Frontmatter id for the emitted child doc}
        {--json : Emit JSON only}';

    protected $description = 'Validate + optionally materialize the governed L7-L10 (S83-S165) queue from the broad backlog doc, without auto-index capture.';

    public function handle(L7L10QueueConsumer $consumer): int
    {
        $repoRoot = rtrim((string) ($this->option('repo-root') ?: base_path()), '/');
        $docPath = (string) $this->option('doc');
        $absolute = str_starts_with($docPath, '/') ? $docPath : $repoRoot.'/'.$docPath;

        if (! is_file($absolute)) {
            return $this->emitBlocked('source_doc_not_found', $docPath);
        }

        $markdown = (string) File::get($absolute);
        $result = $consumer->consume($markdown);

        $emitChild = trim((string) ($this->option('emit-child') ?: ''));
        $childResult = null;
        if ($emitChild !== '') {
            // Only materialize a child when the queue is valid — never emit a
            // broken/incomplete loop-ready doc.
            if (($result['status'] ?? '') !== 'valid') {
                $childResult = ['written' => false, 'reason' => 'queue_invalid_child_not_emitted'];
            } else {
                $childPath = str_starts_with($emitChild, '/') ? $emitChild : $repoRoot.'/'.$emitChild;
                File::ensureDirectoryExists(dirname($childPath));
                $child = $consumer->extractChildDoc($markdown, (string) $this->option('child-id'), 'AAEOS L7-L10 loop-ready backlog (S83-S165)');
                File::put($childPath, $child);
                $childResult = ['written' => true, 'path' => $emitChild, 'bytes' => strlen($child)];
            }
        }

        $payload = [
            'schema_version' => L7L10QueueConsumer::SCHEMA_VERSION,
            'source_doc' => $docPath,
            'status' => $result['status'],
            'levels' => $result['levels'],
            'total' => $result['total'],
            'bad' => $result['bad'],
            'expected' => ['L7' => 18, 'L8' => 25, 'L9' => 20, 'L10' => 20, 'total' => 83],
            'matches_expected' => $result['levels'] === ['L7' => 18, 'L8' => 25, 'L9' => 20, 'L10' => 20] && $result['total'] === 83 && $result['bad'] === [],
            'child_emit' => $childResult,
            'note' => 'Governed L7-L10 mechanism; the 24h auto-index consumes the dedicated child backlog, never the broad source doc.',
        ];

        $this->emit($payload);

        return ($payload['status'] === 'valid') ? self::SUCCESS : self::FAILURE;
    }

    private function emitBlocked(string $reason, string $detail): int
    {
        $this->emit(['schema_version' => L7L10QueueConsumer::SCHEMA_VERSION, 'status' => 'blocked', 'reason' => $reason, 'detail' => $detail]);

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }
        $this->components->twoColumnDetail('L7-L10 queue', (string) ($payload['status'] ?? 'unknown'));
        foreach ((array) ($payload['levels'] ?? []) as $level => $count) {
            $this->components->twoColumnDetail('  '.$level, (string) $count);
        }
        $this->components->twoColumnDetail('Total', (string) ($payload['total'] ?? 0));
        $this->components->twoColumnDetail('Matches expected', YesNo::format((bool) ($payload['matches_expected'] ?? false)));
        foreach (array_slice((array) ($payload['bad'] ?? []), 0, 10) as $bad) {
            $this->warn('  bad: '.(string) $bad);
        }
    }
}
