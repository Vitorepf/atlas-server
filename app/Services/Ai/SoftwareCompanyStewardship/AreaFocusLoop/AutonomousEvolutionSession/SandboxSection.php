<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSession;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxPreflightService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeRouterService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * AP-726/AP-756 sandbox preflight + branch base-ref section, extracted VERBATIM
 * from AutonomousEvolutionSessionService by the GOD-DEBULK split. Builds the
 * one-time preflight/handoff packet so a single handoff_hash threads through the
 * owner-flow chain, validates the sandbox base ref (input/repo, injection-safe),
 * and materializes the branch sandbox through the parent's materializer. Pure
 * projection + one delegated materialize call; no provider, no merge. The
 * materializer back-reference is reached via {@see AutonomousEvolutionSessionService};
 * taxonomy classes are referenced qualified.
 */
final class SandboxSection
{
    public function __construct(
        private readonly AutonomousEvolutionSessionService $parent,
    ) {}

    /**
     * Build the AP-726 preflight/handoff ONCE so the same handoff_hash threads
     * through AP-756 (sandbox materialization), AP-747 (release) and AP-757
     * (sandbox binding inside AP-749). Both the sandbox materializer and the
     * owner-flow executor must see the same handoff.
     *
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    public function buildPreflight(string $areaId, array $finding, array $allowedFiles, string $owner, string $cycleId, string $baseRef = 'main'): array
    {
        $route = $owner === 'forge' ? AreaFocusDevForgeRouterService::ROUTE_FORGE : AreaFocusDevForgeRouterService::ROUTE_ATLAS_DEV;
        $hash = substr(MissionCanonicalHash::sha256([$cycleId, $finding['finding_hash'] ?? '', $allowedFiles]), 0, 12);
        $branchName = 'atlas/area-focus/'.$areaId.'/'.$route.'/'.$hash;
        $workOrderId = 'ap786_wo_'.$hash;
        $workOrderHash = 'sha256:'.MissionCanonicalHash::sha256([$workOrderId, $finding]);
        $decisionId = 'ap786_decision_'.$hash;
        $decisionHash = 'sha256:'.MissionCanonicalHash::sha256([$decisionId, 'session_operator_authorized']);
        $handoffHash = 'sha256:'.MissionCanonicalHash::sha256([$cycleId, $branchName, $workOrderHash, $decisionHash]);

        return [
            'schema_version' => AreaFocusBranchSandboxPreflightService::REPORT_SCHEMA,
            'ap_contract' => 'AP-726',
            'status' => AreaFocusBranchSandboxPreflightService::STATUS_READY,
            'area_id' => $areaId,
            'branch_plan' => [
                'branch_name' => $branchName,
                'base_ref_plan' => $baseRef !== '' ? $baseRef : 'main',
                'allowed_files' => $allowedFiles,
            ],
            'handoff_packet' => [
                'schema_version' => AreaFocusBranchSandboxPreflightService::HANDOFF_SCHEMA,
                'area_id' => $areaId,
                'route' => $route,
                'target_owner' => $owner,
                'work_order_id' => $workOrderId,
                'work_order_hash' => $workOrderHash,
                'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
                'decision_id' => $decisionId,
                'decision_hash' => $decisionHash,
                'title' => (string) ($finding['title'] ?? 'Autonomous evolution work'),
                'risk_level' => (string) ($finding['severity'] ?? 'medium'),
                'allowed_files' => $allowedFiles,
                'allowed_paths' => $allowedFiles,
                'handoff_hash' => $handoffHash,
            ],
            'preflight_hash' => 'sha256:'.MissionCanonicalHash::sha256([$cycleId, $handoffHash]),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function sandboxBaseRefFromInput(array $input): string
    {
        $ref = trim((string) ($input['sandbox_base_ref'] ?? ''));
        if ($ref === '') {
            return '';
        }
        if (str_starts_with($ref, '-') || str_contains($ref, '..') || preg_match('/\s/', $ref) === 1) {
            return '';
        }
        if (! preg_match('/\A[A-Za-z0-9._\/-]+\z/', $ref)) {
            return '';
        }

        return $ref;
    }

    public function sandboxBaseRefFromRepo(string $repoRoot): string
    {
        $repoRoot = trim($repoRoot);
        if ($repoRoot === '' || ! is_dir($repoRoot)) {
            return '';
        }

        try {
            $process = new Process(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $repoRoot);
            $process->setTimeout(10);
            $process->run();
            if (! $process->isSuccessful()) {
                return '';
            }

            $ref = $this->safeSandboxBaseRef($process->getOutput());
            if (! str_starts_with($ref, 'atlas/loop-runner/')) {
                return '';
            }

            return $ref;
        } catch (Throwable) {
            return '';
        }
    }

    private function safeSandboxBaseRef(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            return '';
        }
        if (str_starts_with($ref, '-') || str_contains($ref, '..') || preg_match('/\s/', $ref) === 1) {
            return '';
        }
        if (! preg_match('/\A[A-Za-z0-9._\/-]+\z/', $ref)) {
            return '';
        }

        return $ref;
    }

    /**
     * @param  array<string,mixed>  $preflight
     * @return array<string,mixed>
     */
    public function materializeSandbox(array $preflight, string $areaId, string $repoRoot, string $baseRef = 'main'): array
    {
        $handoffHash = (string) data_get($preflight, 'handoff_packet.handoff_hash', '');

        return $this->parent->materializer->materialize([
            'area_id' => $areaId,
            'repo_root' => $repoRoot,
            'base_ref' => $baseRef !== '' ? $baseRef : 'main',
            'preflight_report' => $preflight,
            'sandbox_receipt' => [
                'decision' => 'materialize_sandbox',
                'operator_actor' => 'ap786_autonomous_session',
                'target_handoff_hash' => $handoffHash,
                'rationale' => 'Operator authorized AP-786 autonomous evolution session for this area/focus.',
            ],
            'materialize_sandbox' => true,
            'record_sandbox' => true,
        ]);
    }
}
