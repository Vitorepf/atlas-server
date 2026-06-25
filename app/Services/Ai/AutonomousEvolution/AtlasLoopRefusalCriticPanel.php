<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * 3-voter adversarial panel that re-examines a candidate task BEFORE the unified refusal verdict.
 * Each voter is a small deterministic critic with a distinct lens — the panel never collapses lenses
 * into a scalar (R1/R2 anti-Goodhart); votes are listed verbatim.
 *
 * VOTERS:
 *   ProxyLensCritic        — behaviour-preserving refactor signature:
 *                              revert_recheck_green && red_test_count===0 && no acceptance contract delta
 *                            ⇒ pattern_id = 'behaviour-preserving-refactor'
 *   MetricProxyLensCritic  — cyclomatic / coverage / LOC-style proxy target
 *                            ⇒ pattern_id = 'cyclomatic-proxy'
 *   FarmLensCritic         — characterization-test-farm / self-edit-in-own-judge signature
 *                            ⇒ pattern_id = 'characterization-test-farm' (or 'self-edit-in-own-judge')
 *
 * VERDICT semantics:
 *   - refused === true if MAJORITY refuse OR ANY voter emits 'block' severity.
 *   - Each vote is recorded — none is silently dropped.
 */
final class AtlasLoopRefusalCriticPanel
{
    public const VOTER_PROXY = 'App\\Services\\Ai\\AutonomousEvolution\\Critics\\ProxyLensCritic';

    public const VOTER_METRIC = 'App\\Services\\Ai\\AutonomousEvolution\\Critics\\MetricProxyLensCritic';

    public const VOTER_FARM = 'App\\Services\\Ai\\AutonomousEvolution\\Critics\\FarmLensCritic';

    public const SEVERITY_ALLOW = 'allow';

    public const SEVERITY_REFUSE = 'refuse';

    public const SEVERITY_BLOCK = 'block';

    /**
     * @param  array<string,mixed>  $taskContext  candidate task FACTS (revert_recheck_green, red_test_count,
     *         acceptance_contract_delta, metric_kind, metric_delta, mutation_kills, wired_proof,
     *         production_caller, characterization_diff, target_path, forbidden_core, ...)
     * @return array{votes:list<array{voter_fqn:string, refuse:bool, severity:string, pattern_id:string, fact:array<string,mixed>}>, refused:bool}
     */
    public function deliberate(array $taskContext): array
    {
        $votes = [
            $this->proxyLensVote($taskContext),
            $this->metricProxyLensVote($taskContext),
            $this->farmLensVote($taskContext),
        ];

        $refuseCount = 0;
        $anyBlock = false;
        foreach ($votes as $v) {
            if ($v['refuse']) {
                $refuseCount++;
            }
            if (($v['severity'] ?? '') === self::SEVERITY_BLOCK) {
                $anyBlock = true;
            }
        }
        $majorityRefuse = $refuseCount >= 2;

        return ['votes' => $votes, 'refused' => $anyBlock || $majorityRefuse];
    }

    /**
     * @param  array<string,mixed>  $ctx
     * @return array{voter_fqn:string, refuse:bool, severity:string, pattern_id:string, fact:array<string,mixed>}
     */
    private function proxyLensVote(array $ctx): array
    {
        $revertGreen = (bool) ($ctx['revert_recheck_green'] ?? false);
        $redTestCount = (int) ($ctx['red_test_count'] ?? 0);
        $contractDelta = (bool) ($ctx['acceptance_contract_delta'] ?? false);
        $refuse = $revertGreen && $redTestCount === 0 && ! $contractDelta;

        return [
            'voter_fqn' => self::VOTER_PROXY,
            'refuse' => $refuse,
            'severity' => $refuse ? self::SEVERITY_REFUSE : self::SEVERITY_ALLOW,
            'pattern_id' => 'behaviour-preserving-refactor',
            'fact' => [
                'revert_recheck_green' => $revertGreen,
                'red_test_count' => $redTestCount,
                'acceptance_contract_delta' => $contractDelta,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $ctx
     * @return array{voter_fqn:string, refuse:bool, severity:string, pattern_id:string, fact:array<string,mixed>}
     */
    private function metricProxyLensVote(array $ctx): array
    {
        $metricKind = (string) ($ctx['metric_kind'] ?? '');
        $metricDelta = $ctx['metric_delta'] ?? null;
        $mutationKills = (int) ($ctx['mutation_kills'] ?? 0);
        $isProxyMetric = in_array($metricKind, ['cyclomatic', 'coverage', 'loc'], true);
        $isShrink = is_numeric($metricDelta) && (float) $metricDelta < 0.0;
        $refuse = $isProxyMetric && $isShrink && $mutationKills === 0;

        // Severity escalation: a CYCLOMATIC shrink with zero kills is block-severity — it is the canonical
        // proxy farm called out in loop-material-fuel-gap.
        $severity = $refuse ? ($metricKind === 'cyclomatic' ? self::SEVERITY_BLOCK : self::SEVERITY_REFUSE) : self::SEVERITY_ALLOW;

        return [
            'voter_fqn' => self::VOTER_METRIC,
            'refuse' => $refuse,
            'severity' => $severity,
            'pattern_id' => 'cyclomatic-proxy',
            'fact' => [
                'metric_kind' => $metricKind,
                'metric_delta' => $metricDelta,
                'mutation_kills' => $mutationKills,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $ctx
     * @return array{voter_fqn:string, refuse:bool, severity:string, pattern_id:string, fact:array<string,mixed>}
     */
    private function farmLensVote(array $ctx): array
    {
        $wiredProof = (bool) ($ctx['wired_proof'] ?? false);
        $productionCaller = (bool) ($ctx['production_caller'] ?? false);
        $charDiff = (bool) ($ctx['characterization_diff'] ?? false);
        $targetPath = (string) ($ctx['target_path'] ?? '');
        $forbiddenCore = (bool) ($ctx['forbidden_core'] ?? false);

        // FARM: wired without production_caller, OR characterization-only diff.
        $isFarm = ($wiredProof && ! $productionCaller) || $charDiff;
        // SELF-SERVE: edit target lives in the loop's own judge (forbidden core).
        $isSelfServe = $forbiddenCore && $targetPath !== '';
        $refuse = $isFarm || $isSelfServe;
        $patternId = $isSelfServe ? 'self-edit-in-own-judge' : 'characterization-test-farm';
        $severity = $refuse ? ($isSelfServe ? self::SEVERITY_BLOCK : self::SEVERITY_REFUSE) : self::SEVERITY_ALLOW;

        return [
            'voter_fqn' => self::VOTER_FARM,
            'refuse' => $refuse,
            'severity' => $severity,
            'pattern_id' => $patternId,
            'fact' => [
                'wired_proof' => $wiredProof,
                'production_caller' => $productionCaller,
                'characterization_diff' => $charDiff,
                'target_path' => $targetPath,
                'forbidden_core' => $forbiddenCore,
            ],
        ];
    }
}
