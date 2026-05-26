<?php

declare(strict_types=1);

namespace App\Services\Ai\Reconciliation;

use App\Services\Ai\Cognition\AtlasCognitiveFunctionAtlasService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * Atlas Autonomous Reconciliation Runtime — Patamar 4 · 4.3.
 *
 * Motor da batida do loop fechado de Patamar 4. NÃO contém lógica de
 * gap-detection, propose, validate, admit ou tick — delega para:
 *   - AtlasCognitiveFunctionAtlasService (self-model + gaps)
 *   - AtlasAutonomyAdmissionService (admit + Constitutional Kernel)
 *   - AtlasUnifiedRealityGraphTemporalService (AURG-4D tick imutável)
 *
 * Authority doc:
 *   docs/engineering-knowledge-base/atlas-autonomous-reconciliation-runtime.md
 *
 * Invariantes:
 *   - Cada tick atravessa Constitutional Kernel via Admission;
 *   - Cada tick gera AURG-4D tick (hash chain imutável);
 *   - Append-only JSONL local-first;
 *   - noop_no_gap quando todos os pipelines estão ready (não inventa ação).
 */
final class AtlasAutonomousReconciliationRuntimeService
{
    public const TICK_SCHEMA = 'atlas.autonomous_reconciliation.tick.v1';

    public const STEP_SCHEMA = 'atlas.autonomous_reconciliation.step.v1';

    public const OUTCOME_AUTO_APPLIED = 'auto_applied';

    public const OUTCOME_PENDING_APPROVAL = 'pending_approval';

    public const OUTCOME_BLOCKED_BY_KERNEL = 'blocked_by_kernel';

    public const OUTCOME_NOOP_NO_GAP = 'noop_no_gap';

    private ?string $ticksLogOverride = null;

    public function __construct(
        private readonly AtlasCognitiveFunctionAtlasService $cfa,
        private readonly AtlasAutonomyAdmissionService $admission,
        private readonly AtlasUnifiedRealityGraphTemporalService $aurg,
    ) {}

    public function setTicksLogPathForTesting(?string $path): void
    {
        $this->ticksLogOverride = $path;
    }

    public function ticksLogPath(): string
    {
        if ($this->ticksLogOverride !== null) {
            return $this->ticksLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/reconciliation')
            : sys_get_temp_dir().'/atlas/reconciliation';

        return $base.DIRECTORY_SEPARATOR.'ticks.jsonl';
    }

    /**
     * Execute one reconciliation cycle. Idempotent in spirit — caller decides cadence.
     *
     * @param  array<string,mixed>|null  $context
     * @return array<string,mixed>
     */
    public function tick(?array $context = null): array
    {
        $context = $context ?? [];

        $selfModel = $this->cfa->selfModel();
        $selfModelHash = 'sha256:'.hash('sha256', json_encode([
            'groups' => $selfModel['groups'] ?? [],
            'shape' => $selfModel['shape'] ?? [],
            'kernel_hash' => $selfModel['kernel_hash'] ?? '',
        ], JSON_THROW_ON_ERROR));

        $gaps = $this->cfa->gapsByGroup();
        $top = $gaps[0] ?? null;

        $at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        if ($top === null || (int) $top['non_ready_pipeline'] === 0) {
            $tick = $this->buildTick(
                at: $at,
                selfModelHash: $selfModelHash,
                selectedGroup: null,
                gapSize: 0,
                step: null,
                outcome: self::OUTCOME_NOOP_NO_GAP,
            );
            $this->appendJsonl($this->ticksLogPath(), $tick);

            return $tick;
        }

        $group = (string) $top['group'];
        $gapSize = (int) $top['non_ready_pipeline'];
        $privacy = (string) ($context['privacy_class'] ?? 'normal');

        // Build canonical change proposal.
        $change = [
            'change_kind' => 'reconciliation_step',
            'proposed_effect' => "stabilize pipeline status of group '{$group}' towards ready",
            'scope' => [
                'group' => $group,
                'privacy_class' => $privacy,
            ],
            'actor' => 'autonomous_reconciliation_runtime',
            'requested_autonomy' => (string) ($context['requested_autonomy'] ?? 'execute_with_approval'),
        ];

        // Admit (which chains through Constitutional Kernel).
        $admission = $this->admission->admit($change);

        $outcome = match ($admission['decision']) {
            AtlasAutonomyAdmissionService::DECISION_ALLOW_AUTONOMOUS => self::OUTCOME_AUTO_APPLIED,
            AtlasAutonomyAdmissionService::DECISION_ALLOW_WITH_APPROVAL => self::OUTCOME_PENDING_APPROVAL,
            AtlasAutonomyAdmissionService::DECISION_DENY => self::OUTCOME_BLOCKED_BY_KERNEL,
            default => self::OUTCOME_PENDING_APPROVAL,
        };

        // Emit AURG-4D tick (rationale_event — we're not capturing a graph snapshot here).
        $aurgTick = $this->aurg->recordTick([
            'kind' => 'rationale_event',
            'actor' => 'self_construction',
            'rationale' => "reconciliation_step on group='{$group}' outcome={$outcome}",
        ]);

        $step = [
            'schema_version' => self::STEP_SCHEMA,
            'step_kind' => 'stabilize_pipeline',
            'scope' => $change['scope'],
            'admission_envelope' => $admission,
            'aurg_tick_id' => (string) ($aurgTick['tick_id'] ?? ''),
            'aurg_tick_hash' => (string) ($aurgTick['tick_hash'] ?? ''),
        ];

        $tick = $this->buildTick(
            at: $at,
            selfModelHash: $selfModelHash,
            selectedGroup: $group,
            gapSize: $gapSize,
            step: $step,
            outcome: $outcome,
        );
        $this->appendJsonl($this->ticksLogPath(), $tick);

        return $tick;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listTicks(): array
    {
        return $this->readJsonl($this->ticksLogPath());
    }

    public function lastTick(): ?array
    {
        $ticks = $this->listTicks();

        return $ticks === [] ? null : $ticks[count($ticks) - 1];
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $tally = [
            self::OUTCOME_AUTO_APPLIED => 0,
            self::OUTCOME_PENDING_APPROVAL => 0,
            self::OUTCOME_BLOCKED_BY_KERNEL => 0,
            self::OUTCOME_NOOP_NO_GAP => 0,
        ];
        $ticks = $this->listTicks();
        foreach ($ticks as $t) {
            $o = (string) ($t['outcome'] ?? '');
            if (isset($tally[$o])) {
                $tally[$o]++;
            }
        }

        return [
            'schema_version' => 'atlas.autonomous_reconciliation.summary.v1',
            'tick_count' => count($ticks),
            'outcomes' => $tally,
            'last_tick_at' => $ticks === [] ? null : (string) ($ticks[count($ticks) - 1]['at'] ?? ''),
        ];
    }

    // ---------- internals ----------

    /**
     * @param  array<string,mixed>|null  $step
     * @return array<string,mixed>
     */
    private function buildTick(
        string $at,
        string $selfModelHash,
        ?string $selectedGroup,
        int $gapSize,
        ?array $step,
        string $outcome,
    ): array {
        $tickId = 'rcn_'.substr(hash('sha256', $at.'|'.$selfModelHash.'|'.($selectedGroup ?? '').'|'.$outcome), 0, 12);
        $tick = [
            'schema_version' => self::TICK_SCHEMA,
            'tick_id' => $tickId,
            'at' => $at,
            'self_model_hash' => $selfModelHash,
            'selected_group' => $selectedGroup,
            'selected_gap_size' => $gapSize,
            'step' => $step,
            'outcome' => $outcome,
        ];
        $tick['tick_hash'] = 'sha256:'.hash('sha256', json_encode([
            'tick_id' => $tickId,
            'self_model_hash' => $selfModelHash,
            'selected_group' => $selectedGroup,
            'outcome' => $outcome,
        ], JSON_THROW_ON_ERROR));

        return $tick;
    }

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
