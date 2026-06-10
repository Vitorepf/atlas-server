<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasOpenBrainWriteBackService;
use Illuminate\Console\Command;

/**
 * AOBG N1.F2 — `atlas:aobg:record-outcome`: the CLI mirror of the record_outcome
 * write-back tool, for testing + audit. An external session (or the operator) records
 * what a run DID → a provider-safe brain mission/evidence node via the gated recorder.
 * NEVER a merge, idempotent, fail-open. Input is treated as untrusted.
 *
 *     atlas:aobg:record-outcome --id=task-42 --request="add the X cache" \
 *         --file=app/Foo.php --file=tests/FooTest.php --delivered --status=passed --json
 *
 * Read/write to the brain only (no provider spend). Always exits 0 — a rejection or a
 * store outage is a normal, audited answer, not a command failure.
 */
class AtlasAobgRecordOutcomeCommand extends Command
{
    public const SCHEMA = 'atlas.aobg.record_outcome_command.v1';

    protected $signature = 'atlas:aobg:record-outcome
        {--id= : External mission/task ref (the brain node identity) — required}
        {--request= : What the session was asked to do (label, redacted) — required}
        {--file=* : A file path the session touched (repeatable)}
        {--branch= : Branch ref (never a merge)}
        {--provider= : Provider/agent label}
        {--receipt= : Optional receipt id/hash}
        {--status= : Result status (e.g. passed/failed/delivered/blocked)}
        {--delivered : Mark the outcome as delivered}
        {--memory-ref=* : Cited existing memory node/source id (repeatable)}
        {--privacy-class= : Self-declared privacy class — must be normal or omitted}
        {--workspace= : Workspace path or id (defaults to the primary atlas-server)}
        {--json : Output the result envelope as JSON}';

    protected $description = 'AOBG N1.F2: record what an external session did back into the brain (provider-safe mission/evidence node, never a merge, fail-open).';

    public function handle(AtlasOpenBrainWriteBackService $service): int
    {
        $files = array_values(array_filter((array) $this->option('file'), 'is_string'));
        $memoryRefs = array_values(array_filter((array) $this->option('memory-ref'), 'is_string'));

        $input = array_filter([
            'id' => $this->stringOpt('id'),
            'request' => $this->stringOpt('request'),
            'files' => $files,
            'branch' => $this->stringOpt('branch'),
            'provider' => $this->stringOpt('provider'),
            'receipt' => $this->stringOpt('receipt'),
            'memory_refs' => $memoryRefs,
            'privacy_class' => $this->stringOpt('privacy-class'),
            'workspace' => $this->stringOpt('workspace'),
            'delivered' => (bool) $this->option('delivered'),
            'result' => array_filter([
                'status' => $this->stringOpt('status'),
            ], static fn ($v): bool => $v !== null),
        ], static fn ($v): bool => $v !== null && $v !== []);

        $result = $service->recordOutcome($input);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if (($result['ok'] ?? false) === true) {
            $this->info(sprintf(
                'recorded  mission=%s  evidence=%s  edges=%d  merged=no',
                (string) ($result['mission_node'] ?? ''),
                (string) ($result['evidence_node'] ?? ''),
                (int) ($result['edges'] ?? 0),
            ));
        } else {
            $this->warn(sprintf(
                'not recorded  status=%s  reason=%s',
                (string) ($result['status'] ?? 'n/a'),
                (string) ($result['reason'] ?? 'n/a'),
            ));
        }

        return self::SUCCESS;
    }

    private function stringOpt(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
