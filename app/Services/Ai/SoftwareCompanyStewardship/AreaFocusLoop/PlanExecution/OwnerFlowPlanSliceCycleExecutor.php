<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use Symfony\Component\Process\Process;

/**
 * Pilar 1 · Plan Execution · REAL slice executor.
 *
 * Bridges a decomposed-plan slice to the loop's REAL cycle by injecting the slice as the
 * selected finding into {@see AutonomousEvolutionSessionService}. This runs the slice through
 * the IDENTICAL proven owner-flow machinery every 24h-loop cycle uses — AP-726 preflight /
 * handoff, AP-756 sandbox materialization, the AP-747→AP-750 owner runtime chain, merge
 * governance (AP-769/AP-774) and the loop receipt — instead of calling the owner-flow runner
 * raw (which has no handoff and so always blocked on ap726_handoff_hash_required). The session
 * cycle IS the cycle: it is returned so PlanCompletionTrackerService can DERIVE
 * delivery/provider-proof/acceptance from the canonical receipt.
 *
 * Honesty: this executor never sets merge/provider flags itself. If the owner flow blocks
 * (no provider capacity, sandbox failure, AWIS gate, ...) the cycle carries no merge and the
 * tracker correctly records the slice as not delivered. Real delivery requires a real owner-flow
 * cycle with allowed changed files + provider proof — exactly as in every other loop cycle. The
 * slice is operator-authorized work (the operator launched this plan run), so the injected
 * finding is marked auto-executable — the same authorization model as the session's own cycles.
 */
final class OwnerFlowPlanSliceCycleExecutor implements PlanSliceCycleExecutor
{
    public function __construct(
        private readonly AutonomousEvolutionSessionService $session,
        private readonly bool $execute = true,
    ) {}

    public function isSimulated(): bool
    {
        return false;
    }

    /**
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function executeSlice(array $slice, array $context): array
    {
        $sliceId = (string) ($slice['slice_id'] ?? '');
        $findingId = (string) ($slice['finding_id'] ?? $sliceId);
        $owner = (string) ($slice['owner'] ?? 'atlas_dev');
        $areaId = (string) ($context['area_id'] ?? 'agentic_engineering_os');

        $acceptance = is_array($slice['acceptance_criteria'] ?? null) ? array_values(array_filter($slice['acceptance_criteria'], 'is_string')) : [];
        $objective = trim((string) ($slice['objective'] ?? ''));
        $delivery = trim((string) ($slice['delivery'] ?? ''));

        // Make the build-plan slice executable WITHOUT fabricating: the AP-786 robust-flow
        // gate needs allowed_files + an SDD/TDD/BDD packet. Derive them from the slice's OWN
        // declared content — explicit repo paths the slice names in its objective/delivery/
        // acceptance, and a spec packet from objective + acceptance. A slice that names no
        // concrete file stays empty and blocks HONESTLY at the gate (never a guessed path).
        $namedSlice = is_array($slice['allowed_files'] ?? null) ? array_values(array_filter($slice['allowed_files'], 'is_string')) : [];
        $nestedFinding = is_array($slice['finding'] ?? null) ? $slice['finding'] : [];
        $namedNested = is_array($nestedFinding['affected_files'] ?? null) ? array_values(array_filter($nestedFinding['affected_files'], 'is_string')) : [];
        $extracted = $this->extractRepoPaths($objective.' '.$delivery.' '.implode(' ', $acceptance));
        $allowedFiles = array_values(array_unique(array_merge($namedSlice, $namedNested, $extracted)));
        $testFiles = array_values(array_filter(
            $allowedFiles,
            static fn (string $path): bool => str_starts_with($path, 'tests/') && str_ends_with($path, '.php'),
        ));

        $seed = is_array($nestedFinding['spec_seed'] ?? null) ? $nestedFinding['spec_seed'] : [];
        // Derived-from-slice values WIN when the nested seed is empty (the decomposer leaves
        // spec_seed.tests_required = [], which must NOT override our path-derived
        // contract). tests_required is a file list, not the free-text acceptance criteria;
        // allowedFiles() treats it as scope, so putting acceptance prose here inflates the
        // preflight file/layer budget and blocks otherwise valid class+test slices.
        $specSeed = $seed + [
            'candidate_id' => $findingId,
            'objective' => $objective !== '' ? $objective : $delivery,
            'acceptance' => $acceptance,
            'tests_required' => $testFiles,
        ];
        if (trim((string) ($specSeed['objective'] ?? '')) === '') {
            $specSeed['objective'] = $objective !== '' ? $objective : $delivery;
        }
        if (empty($specSeed['acceptance'])) {
            $specSeed['acceptance'] = $acceptance;
        }
        if (empty($specSeed['tests_required'])) {
            $specSeed['tests_required'] = $testFiles;
        }

        $finding = [
            'finding_id' => $findingId,
            'id' => $findingId,
            // Deterministic finding_hash so preflight/handoff + dedup are stable per slice.
            'finding_hash' => substr(MissionCanonicalHash::sha256([$findingId, $sliceId, $allowedFiles]), 0, 16),
            'title' => (string) ($slice['title'] ?? ($delivery !== '' ? $delivery : 'slice '.$sliceId)),
            'kind' => (string) ($slice['kind'] ?? 'plan_slice'),
            'severity' => (string) ($slice['severity'] ?? 'medium'),
            'owner_candidate' => $owner,
            'acceptance_criteria' => $acceptance,
            'affected_files' => $allowedFiles,
            'evidence_refs' => is_array($slice['evidence_refs'] ?? null) ? array_values($slice['evidence_refs']) : [],
            'spec_seed' => $specSeed,
            'origin_type' => (string) ($slice['origin_type'] ?? $nestedFinding['origin_type'] ?? 'build_plan_decomposition'),
            'active_slice_id' => $sliceId,
            'active_slice_kind' => 'plan_backlog_slice',
            'why_it_matters' => $objective !== '' ? $objective : (string) ($slice['why_it_matters'] ?? ''),
            'proposed_next_action' => $delivery !== '' ? $delivery : (string) ($slice['proposed_next_action'] ?? ''),
            // Operator-authorized plan execution: the slice is allowed to run autonomously,
            // same authorization the session grants its own selected findings.
            'auto_execution_allowed' => true,
            'operator_review_required' => false,
            'autonomous_execution_reason' => 'operator_authorized_plan_execution',
        ];

        $repoRoot = $this->repoRoot($context);
        if ($this->requiresCleanBaseBeforeProvider($context)) {
            $baseStatus = $this->baseWorktreeStatus($repoRoot);
            if (($baseStatus['clean'] ?? false) !== true) {
                return $this->blockedBeforeProviderCycle(
                    $finding,
                    $sliceId,
                    $context,
                    (string) ($baseStatus['blocker'] ?? 'base_worktree_dirty'),
                    $baseStatus,
                );
            }
        }

        $sessionInput = [
            'area_id' => $areaId,
            'focus' => (string) ($context['focus'] ?? 'dev_forge'),
            'scope_profile' => (string) ($context['scope_profile'] ?? 'balanced') ?: 'balanced',
            'execute' => $this->execute,
            'cycles' => 1,
            'injected_finding' => $finding,
            // A plan-execution run that is authorized to --execute is authorized to MERGE its
            // slices — otherwise the session routes the completed work to the operator inbox
            // (acceptance_basis=operator_acceptance_pending) and the slice never delivers
            // autonomously. Mirror the soak's --auto-merge --allow-code-auto-merge so a passing
            // worker result becomes a real ff-only merge. Context may override (e.g. dry plans).
            'auto_merge' => (bool) ($context['auto_merge'] ?? $this->execute),
            'allow_code_auto_merge' => (bool) ($context['allow_code_auto_merge'] ?? $this->execute),
        ];
        if (array_key_exists('repo_root', $context)) {
            $sessionInput['repo_root'] = (string) $context['repo_root'];
        }
        // Default to the operator's configured atlas_dev engine (e.g. minimax_m27_cli) so a
        // plan-execution run uses the real provider, not the session's legacy cursor_cli
        // default. An explicit context provider/model still wins (passed through below).
        if (! array_key_exists('provider', $context) && function_exists('config')) {
            $configured = (string) config('atlas_dev.provider.default_provider', '');
            if ($configured !== '') {
                $sessionInput['provider'] = $configured;
                $sessionInput['model'] = (string) config('atlas.ai.providers.'.$configured.'.model', '');
            }
        }
        // Pass through any caller-supplied real forge authority. Never fabricated; absent
        // them an owner=forge slice blocks honestly inside the owner flow.
        foreach (['forge_obra', 'forge_live_topology', 'forge_live_decision', 'forge_awis_ready',
            'forge_provider_authorization', 'forge_budget_approved', 'provider', 'model'] as $passthrough) {
            if (array_key_exists($passthrough, $context)) {
                $sessionInput[$passthrough] = $context[$passthrough];
            }
        }

        $result = $this->session->run($sessionInput);
        $cycles = is_array($result['cycles'] ?? null) ? $result['cycles'] : [];
        $cycle = is_array($cycles[0] ?? null) ? $cycles[0] : [];

        // Tag the cycle with the slice so the tracker's finding_id->slice_id join is
        // unambiguous; never mutate merge/provider signals (the tracker derives those).
        if (! isset($cycle['selected_finding']) || ! is_array($cycle['selected_finding'])) {
            $cycle['selected_finding'] = ['finding_id' => $findingId, 'title' => $finding['title']];
        }
        $cycle['plan_slice_id'] = $sliceId;
        $cycle['simulated'] = false;

        return $cycle;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function repoRoot(array $context): string
    {
        $repoRoot = trim((string) ($context['repo_root'] ?? ''));
        if ($repoRoot !== '') {
            return $repoRoot;
        }

        if (function_exists('base_path')) {
            return (string) base_path();
        }

        return getcwd() ?: '';
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function requiresCleanBaseBeforeProvider(array $context): bool
    {
        if (! $this->execute) {
            return false;
        }

        return (bool) ($context['auto_merge'] ?? true);
    }

    /**
     * @return array{clean:bool,blocker?:string,repo_root:string,dirty_paths?:list<string>,exit_code?:int|null}
     */
    private function baseWorktreeStatus(string $repoRoot): array
    {
        if ($repoRoot === '') {
            return ['clean' => false, 'blocker' => 'repo_root_required_before_provider', 'repo_root' => $repoRoot];
        }

        $process = new Process(['git', 'status', '--porcelain'], $repoRoot);
        $process->setTimeout(15);
        $process->run();
        if (! $process->isSuccessful()) {
            return [
                'clean' => false,
                'blocker' => 'base_worktree_status_unreadable_before_provider',
                'repo_root' => $repoRoot,
                'exit_code' => $process->getExitCode(),
            ];
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($process->getOutput()))), static fn (string $line): bool => $line !== ''));
        if ($lines !== []) {
            return [
                'clean' => false,
                'blocker' => 'base_worktree_dirty',
                'repo_root' => $repoRoot,
                'dirty_paths' => $lines,
                'exit_code' => $process->getExitCode(),
            ];
        }

        return ['clean' => true, 'repo_root' => $repoRoot, 'exit_code' => $process->getExitCode()];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $baseStatus
     * @return array<string,mixed>
     */
    private function blockedBeforeProviderCycle(array $finding, string $sliceId, array $context, string $blocker, array $baseStatus): array
    {
        $findingId = (string) ($finding['finding_id'] ?? $sliceId);
        $cycleId = 'plan_slice_pre_provider_block_'.substr(MissionCanonicalHash::sha256([
            $findingId,
            $sliceId,
            (int) ($context['cycle_index'] ?? 0),
            $blocker,
        ]), 0, 16);

        return [
            'cycle_id' => $cycleId,
            'cycle_index' => (int) ($context['cycle_index'] ?? 0),
            'selected_finding' => [
                'finding_id' => $findingId,
                'title' => (string) ($finding['title'] ?? $findingId),
            ],
            'plan_slice_id' => $sliceId,
            'simulated' => false,
            'final_status' => 'blocked',
            'blockers' => [$blocker],
            'pre_provider_gate' => [
                'status' => 'blocked',
                'reason' => $blocker,
                'repo_root' => (string) ($baseStatus['repo_root'] ?? ''),
                'dirty_paths' => array_values(array_slice((array) ($baseStatus['dirty_paths'] ?? []), 0, 20)),
            ],
            'provider_called' => false,
            'provider_invoked' => false,
            'branch_created' => false,
            'worktree_created' => false,
            'merge_performed' => false,
            'merge_skipped' => true,
            'continue_loop' => false,
        ];
    }

    /**
     * Extract repo-relative file paths a slice EXPLICITLY names in its prose (objective /
     * delivery / acceptance). Mirrors the session's allowedFiles() path regex so the loop
     * scopes edits to files the plan actually references — never a guessed/fabricated path.
     *
     * @return list<string>
     */
    private function extractRepoPaths(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $paths = [];
        if (preg_match_all('#(?:app|tests|config|routes|database|resources|docs)/[A-Za-z0-9_./\\\\-]+?\.(?:php|md|ts|tsx|json|yml|yaml)#', $text, $matches) === 1 || ($matches[0] ?? []) !== []) {
            foreach ($matches[0] as $match) {
                $clean = trim($match, " \t\n\r\0\x0B,.:");
                if ($clean !== '') {
                    $paths[] = $clean;
                }
            }
        }

        return array_values(array_unique($paths));
    }
}
