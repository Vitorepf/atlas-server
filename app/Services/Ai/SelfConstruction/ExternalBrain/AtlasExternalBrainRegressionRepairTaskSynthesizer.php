<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure synthesizer. Converts diagnostic reports from brain audit, task health,
 * and gate regression outputs into concrete repair task specs with allowed_files,
 * runnable acceptance criteria, and an unblock rationale.
 *
 * Rejection hierarchy (first match wins):
 *   vague_diagnostic                   — missing gate / target / runnable proof
 *   test_only_target_without_impl_file — target resolves to a test file with no impl
 *   forbidden_self_target              — target is flagged forbidden and no unblock_plan
 *   manual_operator_steady_state       — requires manual operator action in steady state
 *                                        (unless is_bootstrap_only = true)
 *
 * Grouping: promoted diagnostics that share the same resolved impl target_path are
 * merged into one bounded macro repair spec with deduped allowed_files (impl + test)
 * and gate-specific runnable acceptance criteria.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainRegressionRepairTaskSynthesizer
{
    public const SCHEMA = 'atlas.external_brain.regression_repair_task_synthesizer.v1';

    public const REJECTION_VAGUE            = 'vague_diagnostic';
    public const REJECTION_TEST_ONLY        = 'test_only_target_without_impl_file';
    public const REJECTION_FORBIDDEN_TARGET = 'forbidden_self_target_without_unblock_plan';
    public const REJECTION_MANUAL_OPERATOR  = 'manual_operator_required_steady_state';

    /**
     * @param  array{diagnostics?: list<array<string,mixed>>}  $input
     * @return array{schema:string, repair_specs:list<array<string,mixed>>, rejected_diagnostics:list<array<string,mixed>>, allowed_files:list<string>, acceptance_criteria:list<string>, unblock_reason:string|null}
     */
    public function synthesize(array $input): array
    {
        $diagnostics = (array) ($input['diagnostics'] ?? []);

        $promoted            = [];
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

            $promoted[] = [$diagId, $diag];
        }

        // Group by resolved impl target — related diagnostics share a target
        $groups = [];
        foreach ($promoted as [$diagId, $diag]) {
            $implTarget          = $this->resolveTarget($diag);
            $groups[$implTarget][] = [$diagId, $diag];
        }

        $repairSpecs = [];
        foreach ($groups as $implTarget => $groupDiags) {
            $repairSpecs[] = $this->buildGroupRepairSpec($implTarget, $groupDiags);
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
        $gateName        = trim((string) ($diag['gate_name']              ?? ''));
        $targetPath      = trim((string) ($diag['target_path']            ?? ''));
        $component       = trim((string) ($diag['component']              ?? ''));
        $effectiveTarget = $targetPath !== '' ? $targetPath : $component;
        $proofCommand    = trim((string) ($diag['runnable_proof_command'] ?? ''));

        // 1. Vague — missing required signals
        if ($gateName === '' || $effectiveTarget === '' || $proofCommand === '') {
            return self::REJECTION_VAGUE;
        }

        // 2. Test-only target (no impl file)
        if ($this->isTestPath($effectiveTarget)) {
            return self::REJECTION_TEST_ONLY;
        }

        // 3. Forbidden self-target without unblock plan
        $forbiddenTarget = (bool) ($diag['forbidden_self_target'] ?? false);
        $unblockPlan     = trim((string) ($diag['unblock_plan']    ?? ''));
        if ($forbiddenTarget && $unblockPlan === '') {
            return self::REJECTION_FORBIDDEN_TARGET;
        }

        // 4. Manual operator required in steady state
        $requiresManual  = (bool) ($diag['requires_manual_operator_action'] ?? false);
        $isBootstrapOnly = (bool) ($diag['is_bootstrap_only']               ?? false);
        if ($requiresManual && ! $isBootstrapOnly) {
            return self::REJECTION_MANUAL_OPERATOR;
        }

        return null;
    }

    /**
     * @param list<array{string, array<string,mixed>}> $groupDiags
     * @return array<string,mixed>
     */
    private function buildGroupRepairSpec(string $implTarget, array $groupDiags): array
    {
        $allowedFiles  = $this->deriveAllowedFiles($implTarget);
        $acceptance    = [];
        $gateNames     = [];
        $diagIds       = [];
        $unblockReason = null;

        foreach ($groupDiags as [$diagId, $diag]) {
            $diagIds[]  = $diagId;
            $gateName   = trim((string) $diag['gate_name']);
            $gateNames[] = $gateName;
            $proofCmd   = trim((string) $diag['runnable_proof_command']);
            $failReason = trim((string) ($diag['failing_reason'] ?? "gate {$gateName} failed on {$implTarget}"));
            $plan       = trim((string) ($diag['unblock_plan']   ?? ''));

            $c1 = "Runnable: {$proofCmd} must exit 0 after repair.";
            $c2 = "Gate {$gateName} must pass for target {$implTarget}.";

            if (! in_array($c1, $acceptance, true)) {
                $acceptance[] = $c1;
            }
            if (! in_array($c2, $acceptance, true)) {
                $acceptance[] = $c2;
            }

            if ($unblockReason === null) {
                $unblockReason = $plan !== '' ? $plan : "Fix {$gateName} regression on {$implTarget}: {$failReason}";
            }
        }

        $uniqueGates = array_unique($gateNames);
        $macroGate   = count($uniqueGates) === 1
            ? $uniqueGates[0]
            : 'macro_repair['.implode(',', $uniqueGates).']';

        return [
            'diagnostic_id'       => count($diagIds) === 1 ? $diagIds[0] : implode(',', $diagIds),
            'gate_name'           => $macroGate,
            'target_path'         => $implTarget,
            'allowed_files'       => $allowedFiles,
            'acceptance_criteria' => $acceptance,
            'unblock_reason'      => $unblockReason,
        ];
    }

    private function resolveTarget(array $diag): string
    {
        $targetPath = trim((string) ($diag['target_path'] ?? ''));
        return $targetPath !== '' ? $targetPath : trim((string) ($diag['component'] ?? ''));
    }

    /** @return list<string> impl file + derived test file */
    private function deriveAllowedFiles(string $targetPath): array
    {
        if ($targetPath === '') {
            return [];
        }

        // Component name (no slashes or .php) → derive conventional paths
        if (! str_contains($targetPath, '.php') && ! str_contains($targetPath, '/')) {
            $base = preg_replace('/[^A-Za-z0-9]/', '', $targetPath) ?? $targetPath;
            return ["app/Services/{$base}.php", "tests/Unit/Services/{$base}Test.php"];
        }

        $testPath = $this->deriveTestPath($targetPath);

        return $testPath !== $targetPath
            ? [$targetPath, $testPath]
            : [$targetPath];
    }

    private function deriveTestPath(string $implPath): string
    {
        $test = preg_replace('/^app\//', 'tests/Unit/', $implPath) ?? $implPath;
        $test = preg_replace('/\.php$/', 'Test.php', $test) ?? $test;
        return $test;
    }

    private function isTestPath(string $path): bool
    {
        return str_starts_with($path, 'tests/')
            || str_ends_with($path, 'Test.php')
            || str_ends_with($path, 'Spec.php');
    }
}
