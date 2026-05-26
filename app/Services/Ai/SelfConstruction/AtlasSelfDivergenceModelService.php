<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionAtlasService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * Atlas Self-Divergence Model Service — Patamar 4 · P2 closure.
 *
 * Closes canon §2.2: "Atlas mantém um modelo de si que diverge do código
 * atual e propõe convergência continuamente". This service persists a
 * canonical **target state** (operator-curated or derived from canon docs)
 * and continuously compares against the **current state** (Scorecard + CFA
 * self-model) to emit a divergence delta:
 *
 *   target_state - current_state = divergence_set
 *
 * Each divergence is one of:
 *   - missing_subsystem      (target lists, current absent)
 *   - downgraded_subsystem   (current code/doc/pipeline < target)
 *   - drift_subsystem        (target marks `ready` for all, current has any non-ready)
 *
 * The service is read-only by canon. It does NOT auto-fire ASCB proposals
 * — that responsibility stays with Reconciliation Runtime when it observes
 * divergence > tolerance. Operator inspects divergence via CLI or HTTP
 * surface and decides whether to elevate to a proposal.
 *
 * Append-only JSONL receipts: every measurement is a record with
 * `divergence_hash`.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-self-divergence-model.md
 *
 * Schemas:
 *   - atlas.self_divergence.target_state.v1   (input file)
 *   - atlas.self_divergence.measurement.v1    (output envelope)
 *
 * Invariants:
 *   - Read-only — never mutates external state.
 *   - JSONL append-only receipts with sha256.
 *   - Kernel hash echoed for audit.
 *   - claim_policy provider-safe.
 */
class AtlasSelfDivergenceModelService
{
    public const TARGET_SCHEMA = 'atlas.self_divergence.target_state.v1';

    public const MEASUREMENT_SCHEMA = 'atlas.self_divergence.measurement.v1';

    public const DIVERGENCE_MISSING = 'missing_subsystem';

    public const DIVERGENCE_DOWNGRADED = 'downgraded_subsystem';

    public const DIVERGENCE_DRIFT = 'drift_subsystem';

    public const STATUS_ORDER = [
        'blocked' => 0,
        'building' => 1,
        'partial' => 2,
        'ready' => 3,
    ];

    private ?string $logPathOverride = null;

    private ?string $targetPathOverride = null;

    public function __construct(
        private readonly AtlasCognitionScoreCardService $scorecard,
        private readonly AtlasCognitiveFunctionAtlasService $cfa,
        private readonly AtlasConstitutionalKernelService $kernel,
    ) {}

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function setTargetPathForTesting(?string $path): void
    {
        $this->targetPathOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logPathOverride !== null) {
            return $this->logPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/self_construction')
            : sys_get_temp_dir().'/atlas/self_construction';

        return $base.DIRECTORY_SEPARATOR.'self_divergence_measurements.jsonl';
    }

    public function targetStatePath(): string
    {
        if ($this->targetPathOverride !== null) {
            return $this->targetPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/self_construction')
            : sys_get_temp_dir().'/atlas/self_construction';

        return $base.DIRECTORY_SEPARATOR.'target_state.json';
    }

    /**
     * Measure divergence between target_state file and current scorecard.
     *
     * If target_state.json is missing, the canonical default is "every row
     * in scorecard should be ready/ready/ready" — i.e., the target is
     * "ACOS fully ready in all 3 dimensions" and divergence = anything
     * below that.
     *
     * @return array<string,mixed>
     */
    public function measure(): array
    {
        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $target = $this->loadTargetState();
        $report = $this->scorecard->build();
        $currentRows = (array) ($report['subsystems'] ?? []);

        $byAcronym = [];
        foreach ($currentRows as $row) {
            $byAcronym[(string) ($row['acronym'] ?? '')] = $row;
        }

        $divergences = [];

        // Walk target rows.
        foreach ($target['subsystems'] as $tRow) {
            $acronym = (string) ($tRow['acronym'] ?? '');
            if ($acronym === '') {
                continue;
            }
            $current = $byAcronym[$acronym] ?? null;
            if ($current === null) {
                $divergences[] = [
                    'kind' => self::DIVERGENCE_MISSING,
                    'acronym' => $acronym,
                    'target_status' => ['code' => $tRow['code'] ?? 'ready', 'doc' => $tRow['doc'] ?? 'ready', 'pipeline' => $tRow['pipeline'] ?? 'ready'],
                    'current_status' => null,
                ];

                continue;
            }
            $cur = [
                'code' => (string) ($current['code_status'] ?? ''),
                'doc' => (string) ($current['doc_status'] ?? ''),
                'pipeline' => (string) ($current['pipeline_status'] ?? ''),
            ];
            $tgt = [
                'code' => (string) ($tRow['code'] ?? 'ready'),
                'doc' => (string) ($tRow['doc'] ?? 'ready'),
                'pipeline' => (string) ($tRow['pipeline'] ?? 'ready'),
            ];
            $delta = [];
            foreach (['code', 'doc', 'pipeline'] as $dim) {
                $tRank = self::STATUS_ORDER[$tgt[$dim]] ?? 3;
                $cRank = self::STATUS_ORDER[$cur[$dim]] ?? 0;
                if ($cRank < $tRank) {
                    $delta[$dim] = ['target' => $tgt[$dim], 'current' => $cur[$dim], 'gap' => $tRank - $cRank];
                }
            }
            if ($delta !== []) {
                $divergences[] = [
                    'kind' => self::DIVERGENCE_DOWNGRADED,
                    'acronym' => $acronym,
                    'target_status' => $tgt,
                    'current_status' => $cur,
                    'delta' => $delta,
                ];
            }
        }

        // Walk current rows that are not target-listed — drift if any non-ready.
        foreach ($currentRows as $row) {
            $acronym = (string) ($row['acronym'] ?? '');
            $listedInTarget = false;
            foreach ($target['subsystems'] as $tRow) {
                if (($tRow['acronym'] ?? '') === $acronym) {
                    $listedInTarget = true;
                    break;
                }
            }
            if ($listedInTarget) {
                continue;
            }
            $statuses = [
                (string) ($row['code_status'] ?? ''),
                (string) ($row['doc_status'] ?? ''),
                (string) ($row['pipeline_status'] ?? ''),
            ];
            if (in_array('blocked', $statuses, true) || in_array('building', $statuses, true) || in_array('partial', $statuses, true)) {
                $divergences[] = [
                    'kind' => self::DIVERGENCE_DRIFT,
                    'acronym' => $acronym,
                    'current_status' => [
                        'code' => $row['code_status'] ?? null,
                        'doc' => $row['doc_status'] ?? null,
                        'pipeline' => $row['pipeline_status'] ?? null,
                    ],
                    'note' => 'subsystem not in declared target_state and has non-ready dimension',
                ];
            }
        }

        $envelope = [
            'schema_version' => self::MEASUREMENT_SCHEMA,
            'measured_at' => $generatedAt,
            'target_state_present' => $target['source'] === 'file',
            'target_subsystem_count' => count($target['subsystems']),
            'current_subsystem_count' => count($currentRows),
            'divergence_count' => count($divergences),
            'divergences_by_kind' => [
                self::DIVERGENCE_MISSING => count(array_filter($divergences, fn ($d) => $d['kind'] === self::DIVERGENCE_MISSING)),
                self::DIVERGENCE_DOWNGRADED => count(array_filter($divergences, fn ($d) => $d['kind'] === self::DIVERGENCE_DOWNGRADED)),
                self::DIVERGENCE_DRIFT => count(array_filter($divergences, fn ($d) => $d['kind'] === self::DIVERGENCE_DRIFT)),
            ],
            'divergences' => $divergences,
            'kernel_hash' => $this->kernel->kernelHash(),
            'scorecard_hash' => $report['scorecard_hash'] ?? null,
            'claim_policy' => $this->claimPolicy(),
        ];
        $envelope['divergence_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::MEASUREMENT_SCHEMA,
            'measured_at' => $generatedAt,
            'divergence_count' => count($divergences),
            'scorecard_hash' => $envelope['scorecard_hash'],
        ], JSON_THROW_ON_ERROR));

        $this->appendJsonl($this->logPath(), $envelope);

        return $envelope;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listMeasurements(int $tail = 50): array
    {
        $all = $this->readJsonl($this->logPath());
        if ($tail <= 0) {
            return $all;
        }

        return array_slice($all, -$tail);
    }

    /**
     * @return array<string,bool>
     */
    public function claimPolicy(): array
    {
        return [
            'benchmark_claim_allowed' => false,
            'rivals_claim_allowed' => false,
            'superiority_claim_allowed' => false,
            'external_rivals_certification_touched' => false,
            'cognitive_immune_law_enforced' => true,
            'provider_safe_only_enforced' => true,
            'local_first_only' => true,
        ];
    }

    // ---------- internals ----------

    /**
     * @return array{source:string, subsystems:list<array<string,mixed>>}
     */
    private function loadTargetState(): array
    {
        $path = $this->targetStatePath();
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['subsystems']) && is_array($decoded['subsystems'])) {
                    return ['source' => 'file', 'subsystems' => $decoded['subsystems']];
                }
            }
        }
        // Default target: every current scorecard row should be ready/ready/ready.
        $defaults = [];
        foreach ((array) ($this->scorecard->build()['subsystems'] ?? []) as $row) {
            $defaults[] = [
                'acronym' => $row['acronym'] ?? null,
                'code' => 'ready',
                'doc' => 'ready',
                'pipeline' => 'ready',
            ];
        }

        return ['source' => 'default_all_ready', 'subsystems' => $defaults];
    }

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
