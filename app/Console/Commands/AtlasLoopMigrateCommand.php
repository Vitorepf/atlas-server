<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationReceipt;
use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationRegistry;
use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationRunner;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use Illuminate\Console\Command;
use Throwable;

final class AtlasLoopMigrateCommand extends Command
{
    protected $signature = 'atlas:loop:migrate
        {action : inspect|run|history}
        {--artifact=}
        {--from=}
        {--to=}
        {--approval-token=}
        {--json}';

    protected $description = 'Inspect, run, or review Atlas Loop schema migrations.';

    public function handle(): int
    {
        if (! AtlasLoopMasterSwitch::enabled()) {
            return $this->emit(['payload' => [], 'schema' => 'atlas.loop.migrate.disabled.v1', 'status' => 'disabled'], self::SUCCESS);
        }

        $action = (string) $this->argument('action');
        $artifact = trim((string) ($this->option('artifact') ?: ''));
        if ($artifact === '') {
            return $this->emitError('artifact option is required.');
        }

        try {
            return match ($action) {
                'inspect' => $this->inspect($artifact),
                'run' => $this->runMigration($artifact),
                'history' => $this->history($artifact),
                default => $this->emitError("Unknown action \"{$action}\"."),
            };
        } catch (Throwable $e) {
            return $this->emit([
                'payload' => ['error' => $e::class, 'message' => $e->getMessage()],
                'schema' => 'atlas.loop.migrate.error.v1',
                'status' => 'error',
            ], self::FAILURE);
        }
    }

    private function inspect(string $artifact): int
    {
        $registry = $this->registry();
        $versions = $registry->knownVersions($artifact);
        $currentVersion = $versions[0] ?? null;
        $targetVersion = $versions === [] ? null : $versions[array_key_last($versions)];
        $chain = [];

        if (is_int($currentVersion) && is_int($targetVersion) && $targetVersion > $currentVersion) {
            foreach ($registry->resolveChain($artifact, $currentVersion, $targetVersion) as $step) {
                $chain[] = [
                    'artifact_kind' => $step->artifactKind,
                    'from_version' => $step->fromVersion,
                    'to_version' => $step->toVersion,
                    'reversible' => $step->reversible,
                ];
            }
        }

        return $this->emit([
            'payload' => [
                'approval_handshake' => is_int($currentVersion) && is_int($targetVersion)
                    ? $this->approvalHandshake($artifact, $currentVersion, $targetVersion)
                    : null,
                'chain' => $chain,
                'current_version' => $currentVersion,
                'target_version' => $targetVersion,
            ],
            'schema' => 'atlas.loop.migrate.inspect.v1',
            'status' => 'ok',
        ], self::SUCCESS);
    }

    private function runMigration(string $artifact): int
    {
        $from = $this->parseVersionOption('from');
        $to = $this->parseVersionOption('to');
        $approvalToken = $this->option('approval-token');
        $handshake = $this->approvalHandshake($artifact, $from, $to);

        if (! is_string($approvalToken) || trim($approvalToken) === '') {
            return $this->emit([
                'payload' => [
                    'approval_handshake' => $handshake,
                    'instructions' => 'Re-run with --approval-token='.$handshake,
                ],
                'schema' => 'atlas.loop.migrate.run.v1',
                'status' => 'error',
            ], self::FAILURE);
        }

        $result = $this->runner()->run(
            $artifact,
            $from,
            $to,
            $this->snapshotPathFor($artifact),
            $approvalToken,
        );

        return $this->emit([
            'payload' => [
                'approval_handshake' => $handshake,
                'result' => $result,
            ],
            'schema' => 'atlas.loop.migrate.run.v1',
            'status' => 'ok',
        ], self::SUCCESS);
    }

    private function history(string $artifact): int
    {
        $entries = [];
        $previousHash = null;
        foreach ($this->ledger()->list($artifact) as $receipt) {
            $expected = hash(
                'sha256',
                ($previousHash ?? 'genesis').'|'.CanonicalJson::encode($receipt->toArrayWithoutPostHash()),
            );
            $hashChainOk = $receipt->postHash === $expected;
            $entries[] = [
                'artifact_kind' => $receipt->artifactKind,
                'from_version' => $receipt->fromVersion,
                'to_version' => $receipt->toVersion,
                'pre_hash' => $receipt->preHash,
                'post_hash' => $receipt->postHash,
                'operator_token_fingerprint' => $receipt->operatorTokenFingerprint,
                'verifier_result' => $receipt->verifierResult,
                'started_at' => $receipt->startedAt,
                'finished_at' => $receipt->finishedAt,
                'evidence_receipt_id' => $receipt->evidenceReceiptId,
                'outcome' => $receipt->outcome,
                'hash_chain_ok' => $hashChainOk,
            ];
            $previousHash = $receipt->postHash;
        }

        return $this->emit([
            'payload' => [
                'receipts' => $entries,
            ],
            'schema' => 'atlas.loop.migrate.history.v1',
            'status' => 'ok',
        ], self::SUCCESS);
    }

    private function emitError(string $message): int
    {
        return $this->emit([
            'payload' => ['message' => $message],
            'schema' => 'atlas.loop.migrate.error.v1',
            'status' => 'error',
        ], self::FAILURE);
    }

    /**
     * @param  array{schema:string,status:string,payload:array<string,mixed>}  $envelope
     */
    private function emit(array $envelope, int $exitCode): int
    {
        $payload = [
            'payload' => $envelope['payload'],
            'schema' => $envelope['schema'],
            'status' => $envelope['status'],
        ];
        ksort($payload, SORT_STRING);

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $this->line($encoded === false ? '{}' : $encoded);

        return $exitCode;
    }

    private function approvalHandshake(string $artifact, int $fromVersion, int $toVersion): string
    {
        $seed = strtolower(trim($artifact)).'|'.$fromVersion.'|'.$toVersion;
        $hex = substr(hash('sha256', $seed), 0, 32);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private function parseVersionOption(string $name): int
    {
        $raw = strtolower(trim((string) ($this->option($name) ?: '')));
        if (preg_match('/^v?([1-9][0-9]*)$/', $raw, $m) !== 1) {
            throw new \InvalidArgumentException("Option --{$name} must be a positive version like v1.");
        }

        return (int) $m[1];
    }

    private function snapshotPathFor(string $artifact): string
    {
        return storage_path('app/atlas-loop-migrations/'.trim($artifact).'.json');
    }

    private function registry(): object
    {
        if (app()->bound(AtlasLoopSchemaMigrationRegistry::class)) {
            return app(AtlasLoopSchemaMigrationRegistry::class);
        }

        return new AtlasLoopSchemaMigrationRegistry;
    }

    private function runner(): object
    {
        if (app()->bound(AtlasLoopSchemaMigrationRunner::class)) {
            return app(AtlasLoopSchemaMigrationRunner::class);
        }

        return new AtlasLoopSchemaMigrationRunner($this->registry());
    }

    private function ledger(): object
    {
        if (app()->bound(AtlasLoopSchemaMigrationReceiptLedger::class)) {
            return app(AtlasLoopSchemaMigrationReceiptLedger::class);
        }

        return new AtlasLoopSchemaMigrationReceiptLedger(
            storage_path('app/atlas-loop-schema-migration-receipts.jsonl'),
        );
    }
}
