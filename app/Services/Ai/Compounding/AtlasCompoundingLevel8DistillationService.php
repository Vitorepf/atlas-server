<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Services\Ai\Cognition\AtlasCognitiveFunctionAtlasService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Reconciliation\AtlasAutonomousReconciliationRuntimeService;
use App\Services\Ai\Teos\AtlasTeosI4CounterfactualTreeService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * Compounding Level 8/9 Distillation Service.
 *
 * Reads append-only runtime evidence (Reconciliation ticks, TEOS-I4 trees,
 * CFA self-models) and produces a distilled compounding envelope that
 * marks which subsystems have demonstrated reflexive activity — the
 * indicator of L8 (system observes itself) → L9 (system improves itself
 * from observation).
 *
 * Read-only over canon sources. Composer, no source of truth.
 *
 * Schemas:
 *   - atlas.compounding.level8_distillation.v1
 *
 * Invariants:
 *   - never claims winner/superiority;
 *   - L8 = observation evidence count above threshold;
 *   - L9 = L8 AND reconciliation produced ASCB proposals (self-improvement loop);
 *   - append-only optional ledger.
 */
final class AtlasCompoundingLevel8DistillationService
{
    public const SCHEMA_VERSION = 'atlas.compounding.level8_distillation.v1';

    public const LEVEL_L7 = 'L7';

    public const LEVEL_L8 = 'L8';

    public const LEVEL_L9 = 'L9';

    public const L8_OBSERVATION_THRESHOLD = 3;

    public const L9_PROPOSAL_THRESHOLD = 1;

    private ?string $ledgerPathOverride = null;

    public function __construct(
        private readonly AtlasCognitiveFunctionAtlasService $cfa,
        private readonly AtlasAutonomousReconciliationRuntimeService $reconciliation,
        private readonly AtlasTeosI4CounterfactualTreeService $teosI4,
        private readonly AtlasConstitutionalKernelService $kernel,
    ) {}

    public function setLedgerPathForTesting(?string $path): void
    {
        $this->ledgerPathOverride = $path;
    }

    public function ledgerPath(): string
    {
        if ($this->ledgerPathOverride !== null) {
            return $this->ledgerPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/compounding')
            : sys_get_temp_dir().'/atlas/compounding';

        return $base.DIRECTORY_SEPARATOR.'level8_distillations.jsonl';
    }

    /**
     * Distill the current compounding level.
     *
     * @return array<string,mixed>
     */
    public function distill(bool $persist = true): array
    {
        $reconciliationTicks = $this->reconciliation->listTicks();
        $teosTrees = $this->teosI4->listTrees();
        $observationCount = count($reconciliationTicks) + count($teosTrees);

        $proposalCount = 0;
        foreach ($reconciliationTicks as $t) {
            if (! empty($t['step']['ascb_proposal_id'])) {
                $proposalCount++;
            }
        }

        $level = self::LEVEL_L7;
        if ($observationCount >= self::L8_OBSERVATION_THRESHOLD) {
            $level = self::LEVEL_L8;
        }
        if ($level === self::LEVEL_L8 && $proposalCount >= self::L9_PROPOSAL_THRESHOLD) {
            $level = self::LEVEL_L9;
        }

        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'distilled_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'observation_count' => $observationCount,
            'proposal_count' => $proposalCount,
            'reconciliation_tick_count' => count($reconciliationTicks),
            'teos_i4_tree_count' => count($teosTrees),
            'subsystem_count' => $this->cfa->selfModel()['subsystem_count'] ?? 0,
            'level' => $level,
            'kernel_hash' => $this->kernel->kernelHash(),
            'claim_policy' => [
                'benchmark_claim_allowed' => false,
                'rivals_claim_allowed' => false,
                'superiority_claim_allowed' => false,
            ],
        ];
        $envelope['envelope_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SCHEMA_VERSION,
            'level' => $level,
            'observation_count' => $observationCount,
            'proposal_count' => $proposalCount,
            'kernel_hash' => $envelope['kernel_hash'],
        ], JSON_THROW_ON_ERROR));

        if ($persist) {
            $this->appendJsonl($this->ledgerPath(), $envelope);
        }

        return $envelope;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listDistillations(): array
    {
        return $this->readJsonl($this->ledgerPath());
    }

    // ---------- internals ----------

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function appendJsonl(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (function_exists('app')) {
                File::ensureDirectoryExists($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }
}
