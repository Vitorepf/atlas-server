<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use Illuminate\Console\Command;

/**
 * Operator surface over the constitutional invariant registry.
 *
 *   list  — enumerate invariants (id + fingerprint + pétreo flag).
 *   drift — compare current invariant IDs against a --since baseline; exits non-zero when IDs diverge.
 *   audit — validate a JSON edit descriptor against kernel rules; exits non-zero when refused.
 *
 * Every invocation appends one receipt to the ledger (--ledger-path overrides default for tests).
 * Read-only: no invariant mutations, no provider calls, no token spend.
 */
final class AtlasLoopConstitutionCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:constitution
        {action : list|audit|drift}
        {--json}
        {--since= : JSON array of {id,fingerprint} objects representing the baseline for drift}
        {--input= : path to a JSON edit descriptor for the audit action}
        {--ledger-path= : override ledger file path (for testing)}';

    /** @var string */
    protected $description = 'Constitutional invariant registry: list | audit | drift (read-only, auditable).';

    public function handle(AtlasConstitutionalKernelService $kernel): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ['list', 'audit', 'drift'], true)) {
            return $this->emit(['error' => 'unknown_action:'.$action], self::FAILURE);
        }

        [$exit, $payload] = match ($action) {
            'list'  => $this->doList($kernel),
            'drift' => $this->doDrift($kernel),
            'audit' => $this->doAudit($kernel),
        };

        $this->appendLedger($action, $payload);

        return $this->emit($payload, $exit);
    }

    /**
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function doList(AtlasConstitutionalKernelService $kernel): array
    {
        $invariants = $kernel->listInvariants();
        $rows = [];
        foreach ($invariants as $inv) {
            $id        = (string) ($inv['id'] ?? '');
            $statement = (string) ($inv['statement'] ?? '');
            $class     = (string) ($inv['class'] ?? '');
            $rows[] = [
                'id'          => $id,
                'fingerprint' => hash('sha256', $id.'|'.$statement),
                'petreo'      => $class === AtlasConstitutionalKernelService::CLASS_PETREO,
            ];
        }

        return [self::SUCCESS, ['action' => 'list', 'invariants' => $rows, 'count' => count($rows)]];
    }

    /**
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function doDrift(AtlasConstitutionalKernelService $kernel): array
    {
        $since = (string) $this->option('since');

        $current = [];
        foreach ($kernel->listInvariants() as $inv) {
            $id = (string) ($inv['id'] ?? '');
            if ($id !== '') {
                $current[$id] = true;
            }
        }

        if ($since === '') {
            return [self::SUCCESS, ['action' => 'drift', 'ok' => true, 'missing_ids' => [], 'added_ids' => [], 'baseline_count' => count($current), 'current_count' => count($current)]];
        }

        $baseline = json_decode($since, true);
        if (! is_array($baseline)) {
            return [self::FAILURE, ['action' => 'drift', 'error' => 'invalid_since_json']];
        }

        $baselineIds = [];
        foreach ($baseline as $entry) {
            $id = (string) ($entry['id'] ?? '');
            if ($id !== '') {
                $baselineIds[$id] = true;
            }
        }

        $missing = array_values(array_diff(array_keys($baselineIds), array_keys($current)));
        $added   = array_values(array_diff(array_keys($current), array_keys($baselineIds)));
        $ok      = ($missing === [] && $added === []);

        return [$ok ? self::SUCCESS : self::FAILURE, [
            'action'         => 'drift',
            'ok'             => $ok,
            'missing_ids'    => $missing,
            'added_ids'      => $added,
            'baseline_count' => count($baselineIds),
            'current_count'  => count($current),
        ]];
    }

    /**
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function doAudit(AtlasConstitutionalKernelService $kernel): array
    {
        $input = (string) $this->option('input');
        $raw   = $input !== '' && is_file($input) ? (string) file_get_contents($input) : '';

        if ($raw === '') {
            return [self::FAILURE, ['action' => 'audit', 'error' => 'no_input', 'detail' => '--input=<path> required for audit']];
        }

        $descriptor = json_decode($raw, true);
        if (! is_array($descriptor)) {
            return [self::FAILURE, ['action' => 'audit', 'error' => 'invalid_input_json']];
        }

        $verdict     = $kernel->validateChange($descriptor);
        $payloadHash = hash('sha256', $raw);
        $allowed     = (bool) ($verdict['allowed'] ?? false);

        return [$allowed ? self::SUCCESS : self::FAILURE, [
            'action'       => 'audit',
            'allowed'      => $allowed,
            'verdict'      => $verdict,
            'receipt_sha'  => $payloadHash,
        ]];
    }

    private function appendLedger(string $action, array $payload): void
    {
        $path = (string) $this->option('ledger-path');
        if ($path === '') {
            $path = storage_path('app/atlas/loop/constitution/receipts.jsonl');
        }
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $entry = [
            'action'      => $action,
            'recorded_at' => date('c'),
            'receipt_sha' => hash('sha256', (string) json_encode($payload)),
        ];
        @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
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
