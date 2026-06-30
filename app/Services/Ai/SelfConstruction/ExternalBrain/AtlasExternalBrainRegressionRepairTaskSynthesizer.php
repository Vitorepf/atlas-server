<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure synthesizer. Converts diagnostic reports from brain audit, task health,
 * and gate regression outputs into concrete repair task specs with allowed_files,
 * runnable acceptance criteria, and an unblock rationale.
 *
 * Rejection hierarchy (first match wins):
 *   vague_diagnostic              — missing concrete_failing_gate AND/OR missing
 *                                   target path AND/OR missing runnable proof
 *   forbidden_self_target         — target is flagged forbidden and no unblock_plan
 *   manual_operator_steady_state  — requires manual operator action in steady state
 *                                   (unless is_bootstrap_only = true)
 *
 * Promotion requires:
 *   1. gate_name (concrete failing gate)
 *   2. target_path OR component (concrete target)
 *   3. runnable_proof_command (runnable command)
 *
 * AC4: output always includes repair_specs, rejected_diagnostics, allowed_files,
 *      acceptance_criteria, and unblock_reason.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainRegressionRepairTaskSynthesizer
{
    public const SCHEMA = 'atlas.external_brain.regression_repair_task_synthesizer.v1';

    public const REJECTION_VAGUE              = 'vague_diagnostic';
    public const REJECTION_FORBIDDEN_TARGET   = 'forbidden_self_target_without_unblock_plan';
    public const REJECTION_MANUAL_OPERATOR    = 'manual_operator_required_steady_state';

    /**
     * @param  array{diagnostics?: list<array<string,mixed>>}  $input
     * @return array{schema:string, repair_specs:list<array<string,mixed>>, rejected_diagnostics:list<array<string,mixed>>, allowed_files:list<string>, acceptance_criteria:list<string>, unblock_reason:string|null}
     */
    public function synthesize(array $input): array
    {
        $diagnostics = (array) ($input['diagnostics'] ?? []);

        $repairSpecs         = [];
        $rejectedDiagnostics = [];

        foreach ($diagnostics as $idx => $diag) {
            $diagId = (string) ($diag['diagnostic_id'] ?? "diag_{$idx}");

            $rejectionReason = $this->rejectionReason($diag);
            if ($rejectionReason !== null) {
                $rejectedDiagnostics[] = [
                    'diagnostic_id'    => $diagId,
                    'rejection_reason' => $rejectionReason,
                    'diagnostic'       => $diag,
                ];
                continue;
            }

            $repairSpecs[] = $this->buildRepairSpec($diagId, $diag);
        }

        $allAllowedFiles       = [];
        $allAcceptanceCriteria = [];

        foreach ($repairSpecs as $spec) {
            foreach ($spec['allowed_files'] as $f) {
                if (! in_array($f, $allAllowedFiles, true)) {
                    $allAllowedFiles[] = $f;
                }
            }
            foreach ($spec['acceptance_criteria'] as $ac) {
                if (! in_array($ac, $allAcceptanceCriteria, true)) {
                    $allAcceptanceCriteria[] = $ac;
                }
            }
        }

        $unblockReason = $repairSpecs !== [] ? $repairSpecs[0]['unblock_reason'] : null;

        return [
            'schema'               => self::SCHEMA,
            'repair_specs'         => $repairSpecs,
            'rejected_diagnostics' => $rejectedDiagnostics,
            'allowed_files'        => $allAllowedFiles,
            'acceptance_criteria'  => $allAcceptanceCriteria,
            'unblock_reason'       => $unblockReason,
        ];
    }

    private function rejectionReason(array $diag): ?string
    {
        // 1. Vague: missing any of the three required signals
        $gateName     = trim((string) ($diag['gate_name'] ?? ''));
        $targetPath   = trim((string) ($diag['target_path'] ?? ''));
        $component    = trim((string) ($diag['component']   ?? ''));
        $effectiveTarget = $targetPath !== '' ? $targetPath : $component;
        $proofCommand = trim((string) ($diag['runnable_proof_command'] ?? ''));

        if ($gateName === '' || $effectiveTarget === '' || $proofCommand === '') {
            return self::REJECTION_VAGUE;
        }

        // 2. Forbidden self-target without unblock plan
        $forbiddenTarget = (bool) ($diag['forbidden_self_target'] ?? false);
        $unblockPlan     = trim((string) ($diag['unblock_plan'] ?? ''));
        if ($forbiddenTarget && $unblockPlan === '') {
            return self::REJECTION_FORBIDDEN_TARGET;
        }

        // 3. Manual operator required in steady state
        $requiresManual  = (bool) ($diag['requires_manual_operator_action'] ?? false);
        $isBootstrapOnly = (bool) ($diag['is_bootstrap_only'] ?? false);
        if ($requiresManual && ! $isBootstrapOnly) {
            return self::REJECTION_MANUAL_OPERATOR;
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function buildRepairSpec(string $diagId, array $diag): array
    {
        $gateName     = trim((string) $diag['gate_name']);
        $rawTarget    = trim((string) ($diag['target_path'] ?? ''));
        $targetPath   = $rawTarget !== '' ? $rawTarget : trim((string) ($diag['component'] ?? ''));
        $proofCommand = trim((string) $diag['runnable_proof_command']);
        $failingReason = trim((string) ($diag['failing_reason'] ?? "gate {$gateName} failed on {$targetPath}"));

        $allowedFiles = $this->deriveAllowedFiles($targetPath);

        $acceptance = [
            "Runnable: {$proofCommand} must exit 0 after repair.",
            "Gate {$gateName} must pass for target {$targetPath}.",
        ];

        $unblockPlan = trim((string) ($diag['unblock_plan'] ?? ''));

        return [
            'diagnostic_id'       => $diagId,
            'gate_name'           => $gateName,
            'target_path'         => $targetPath,
            'allowed_files'       => $allowedFiles,
            'acceptance_criteria' => $acceptance,
            'unblock_reason'      => $unblockPlan !== ''
                ? $unblockPlan
                : "Fix {$gateName} regression on {$targetPath}: {$failingReason}",
        ];
    }

    /** @return list<string> */
    private function deriveAllowedFiles(string $targetPath): array
    {
        if ($targetPath === '') {
            return [];
        }

        // If target already looks like a file path, use it directly
        if (str_contains($targetPath, '.php') || str_contains($targetPath, '/')) {
            return [$targetPath];
        }

        // Component name → guess conventional paths
        $base = preg_replace('/[^A-Za-z0-9]/', '', $targetPath) ?? $targetPath;
        return ["app/Services/{$base}.php"];
    }
}
