<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev;

use App\Models\AtlasDevRunIndex;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\RunIndex\AtlasDevRunIndexRepository;

/**
 * Read-only control snapshot over a Dev run's persisted artifacts.
 * Composes {request, context_summary, model_tier, risk, plan, proof, gaps, next_action}
 * by reading the run's artifact directory — zero writes, pure read model.
 */
final class DevRunControlSnapshotService
{
    public function __construct(
        private readonly ReceiptStorage $storage,
        private readonly AtlasDevRunIndexRepository $index,
        private readonly int $recentRunLimit = 10,
    ) {}

    public function snapshot(string $runId): array
    {
        $runDir = $this->storage->baseDir().'/'.$runId;
        if (! is_dir($runDir)) {
            return $this->recentRunsResponse($runId);
        }

        $artifacts = [];
        $artifacts['operation_envelope'] = $this->readJson($runDir.'/'.ArtifactNames::OPERATION_ENVELOPE);
        $artifacts['compact_sdd'] = $this->readJson($runDir.'/'.ArtifactNames::COMPACT_SDD);
        $artifacts['mini_programming_spec'] = $this->readJson($runDir.'/'.ArtifactNames::MINI_PROGRAMMING_SPEC);
        $artifacts['verification_receipt'] = $this->readJson($runDir.'/'.ArtifactNames::VERIFICATION_RECEIPT);
        $artifacts['quality_gate'] = $this->readJson($runDir.'/'.ArtifactNames::SCOPE_GUARD_RECEIPT);
        $artifacts['patch_apply'] = $this->readJson($runDir.'/'.ArtifactNames::PATCH_APPLY_RESULT);
        $artifacts['review_receipt'] = $this->readJson($runDir.'/'.ArtifactNames::PATCH_INTELLIGENCE_RECEIPT);

        $gaps = [];
        foreach (['operation_envelope', 'compact_sdd', 'mini_programming_spec'] as $required) {
            if ($artifacts[$required] === []) {
                $gaps[] = $required;
            }
        }

        $envelope = $artifacts['operation_envelope'];
        $spec = $artifacts['mini_programming_spec'];
        $verification = $artifacts['verification_receipt'];
        $qualityGate = $artifacts['quality_gate'];

        $request = (string) ($envelope['request'] ?? $envelope['objective'] ?? '');
        $contextSummary = $this->contextSummary($envelope);
        $modelTier = (string) ($envelope['provider'] ?? $envelope['model'] ?? 'unknown');
        $risk = $this->extractRisk($artifacts['compact_sdd'], $envelope);
        $plan = $this->extractPlan($spec);
        $proof = $this->extractProof($verification, $qualityGate);

        $missingVerification = $verification === [] && $gaps === [];
        $blocked = is_array($qualityGate) && (($qualityGate['passed'] ?? null) === false || ($qualityGate['verdict'] ?? '') === 'blocked');

        $nextAction = match (true) {
            $gaps !== [] => 'awaiting_plan',
            $blocked => 'repair',
            $missingVerification => 'run_verification',
            default => 'done',
        };

        return [
            'run_id' => $runId,
            'request' => $request,
            'context_summary' => $contextSummary,
            'model_tier' => $modelTier,
            'risk' => $risk,
            'plan' => $plan,
            'proof' => $proof,
            'gaps' => $gaps,
            'next_action' => $nextAction,
            'read_model' => true,
        ];
    }

    private function contextSummary(array $envelope): array
    {
        $workspace = (string) ($envelope['workspace'] ?? '');
        $files = (array) ($envelope['allowed_files'] ?? $envelope['files'] ?? []);
        $scopeType = (string) ($envelope['scope_type'] ?? '');

        return [
            'workspace' => $workspace,
            'file_count' => count($files),
            'scope_type' => $scopeType,
        ];
    }

    private function extractRisk(array $sdd, array $envelope): array
    {
        return [
            'level' => (string) ($sdd['risk_level'] ?? $envelope['risk_level'] ?? 'unknown'),
            'confidence' => (float) ($sdd['confidence'] ?? 0.0),
            'budget_hint' => (string) (($sdd['budget'] ?? [])['strategy'] ?? ''),
        ];
    }

    private function extractPlan(array $spec): array
    {
        if ($spec === []) {
            return [];
        }

        return [
            'design_path' => (string) ($spec['design_path'] ?? ''),
            'workcell_count' => count((array) ($spec['workcells'] ?? $spec['steps'] ?? [])),
            'instruction_char_count' => (int) ($spec['instruction_char_count'] ?? 0),
        ];
    }

    private function extractProof(array $verification, array $qualityGate): array
    {
        $verdict = 'unknown';
        if ($qualityGate !== [] && ($qualityGate['passed'] ?? null) === false) {
            $verdict = 'blocked';
        } elseif ($verification !== []) {
            $verdict = (string) ($verification['verdict'] ?? 'passed');
        }

        return ['gate_verdict' => $verdict, 'verification_present' => $verification !== []];
    }

    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function recentRunsResponse(string $unknownRunId): array
    {
        $recentIds = [];
        try {
            $rows = AtlasDevRunIndex::query()
                ->orderByDesc('created_at')
                ->orderByDesc('run_id')
                ->limit($this->recentRunLimit)
                ->get();
            $recentIds = array_values(array_map(
                static fn (AtlasDevRunIndex $row): string => $row->run_id,
                $rows->all(),
            ));
        } catch (\Throwable) {
            // DB unavailable — return empty recent list.
        }

        return [
            'run_id' => $unknownRunId,
            'error' => 'run_not_found',
            'recent_run_ids' => $recentIds,
            'read_model' => true,
        ];
    }
}
