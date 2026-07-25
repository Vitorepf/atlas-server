<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticWorkcell\Support;

use App\Services\Ai\AgenticWorkcell\AtlasAgenticWorkcellRuntimeService;
use App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorService;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Str;

/**
 * Pure topology / domain / risk / claim-policy helpers for AAWR workcell design.
 *
 * Extracted from AtlasAgenticWorkcellRuntimeService private pure residual:
 * claim policy envelope, objective normalize, domain classify/normalize, flow pick,
 * complexity/risk scores, topology choice + scorecard, status band, execution-order
 * topology map, risk band, payload sanitize, and circuit-breaker receipt shape.
 *
 * No I/O, no DI, no provider calls, no clock, no filesystem, no DB.
 */
final class AgenticWorkcellTopologyPolicySupport
{
    /** @var list<string> */
    public const TOPOLOGIES = [
        'solo_agent',
        'lead_workers',
        'parallel_scouts',
        'debate_council',
        'tournament',
        'red_blue_team',
        'mapreduce_research',
        'forge_milestone_crew',
        'critic_chain',
        'tool_builder_loop',
    ];

    private function __construct()
    {
    }

    /**
     * @return array<string,mixed>
     */
    public static function claimPolicy(): array
    {
        return [
            'planning_only' => true,
            'provider_invoked' => false,
            'agents_spawned' => false,
            'external_execution_performed' => false,
            'benchmark_not_run' => true,
            'requires_areg_budget' => true,
            'requires_independent_verification' => true,
            'does_not_bypass_dev_or_forge' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function objective(array $input): string
    {
        return AiValueNormalizer::trimmedScalarStringOrNull($input['objective'] ?? $input['prompt'] ?? $input['input_text'] ?? null) ?? 'AAWR workcell objective';
    }

    public static function normalizeDomain(string $domain): string
    {
        return match ($domain) {
            'dev', 'code', 'coding', 'software' => 'programming',
            'strategic' => 'strategy',
            default => $domain,
        };
    }

    public static function classifyDomain(string $objective): string
    {
        $lower = Str::lower($objective);

        return match (true) {
            str_contains($lower, 'bug') || str_contains($lower, 'codigo') || str_contains($lower, 'código') || str_contains($lower, 'forge') || str_contains($lower, 'runtime') => 'programming',
            str_contains($lower, 'pesquisa') || str_contains($lower, 'mercado') || str_contains($lower, 'paper') => 'research',
            str_contains($lower, 'finance') || str_contains($lower, 'invest') || str_contains($lower, 'carteira') => 'finance',
            str_contains($lower, 'marketing') || str_contains($lower, 'campanha') || str_contains($lower, 'copy') => 'marketing',
            str_contains($lower, 'estrateg') || str_contains($lower, 'decis') => 'strategy',
            default => 'conversation',
        };
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function flowForDomain(string $domain, array $input): string
    {
        $task = AiValueNormalizer::trimmedScalarStringOrNull($input['task'] ?? null);
        if ($domain === 'programming') {
            return match ($task) {
                'forge' => 'atlas_forge',
                'debug' => 'atlas_debug',
                'review' => 'atlas_review',
                default => 'atlas_dev',
            };
        }

        return match ($domain) {
            'research' => 'atlas_research',
            'finance' => 'atlas_finance',
            'marketing' => 'atlas_marketing',
            'strategy' => 'atlas_strategy',
            default => 'atlas_conversation',
        };
    }

    public static function complexityScore(string $objective, string $domain): int
    {
        $score = str_word_count($objective) > 80 ? 7 : (str_word_count($objective) > 25 ? 5 : 3);
        foreach (['enterprise', 'completo', 'robusto', 'multi', 'obra', 'meses', 'autonom', 'certificacao', 'research', 'mercado'] as $signal) {
            if (str_contains(Str::lower($objective.' '.$domain), $signal)) {
                $score++;
            }
        }

        return max(1, min(10, $score));
    }

    /**
     * @param  list<string>|array<int,mixed>  $evidenceRefs
     * @param  array<string,mixed>  $input
     */
    public static function riskScore(string $objective, string $domain, array $evidenceRefs, array $input): int
    {
        $score = match ($domain) {
            'finance', 'cyber', 'security' => 7,
            'programming' => 5,
            'strategy' => 6,
            default => 3,
        };
        if ($evidenceRefs === [] && in_array($domain, ['programming', 'research', 'finance', 'strategy'], true)) {
            $score++;
        }
        if ((bool) data_get($input, 'external_execution_requested', false)) {
            $score = 10;
        }

        return max(1, min(10, $score));
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $aregDecision
     */
    public static function chooseTopology(
        string $objective,
        string $domain,
        string $flowId,
        int $complexity,
        int $risk,
        array $input,
        array $aregDecision,
    ): string {
        $forced = AiValueNormalizer::trimmedScalarStringOrNull($input['topology'] ?? null);
        if ($forced !== null && in_array($forced, self::TOPOLOGIES, true)) {
            return $forced;
        }
        $lower = Str::lower($objective.' '.$flowId.' '.$domain);
        if (($aregDecision['path'] ?? null) === AtlasRuntimeEfficiencyGovernorService::PATH_BLOCKED || $risk >= 10) {
            return 'critic_chain';
        }
        if (str_contains($lower, 'ferramenta') || str_contains($lower, 'capability') || str_contains($lower, 'tool')) {
            return 'tool_builder_loop';
        }
        if ($flowId === 'atlas_forge' || str_contains($lower, 'obra') || str_contains($lower, 'meses')) {
            return 'forge_milestone_crew';
        }
        if ($domain === 'research' || str_contains($lower, 'pesquisa profunda')) {
            return 'mapreduce_research';
        }
        if (in_array($domain, ['strategy', 'finance'], true) || $risk >= 8) {
            return 'red_blue_team';
        }
        if ($complexity >= 8) {
            return 'lead_workers';
        }
        if ($complexity >= 6) {
            return 'parallel_scouts';
        }

        return 'solo_agent';
    }

    /**
     * @param  list<string>|array<int,mixed>  $evidenceRefs
     * @param  array<string,mixed>  $input
     */
    public static function status(string $topology, int $risk, array $evidenceRefs, array $input): string
    {
        if ((bool) data_get($input, 'external_execution_requested', false) && $risk >= 10) {
            return AtlasAgenticWorkcellRuntimeService::STATUS_BLOCKED;
        }
        if ($risk >= 7 && $evidenceRefs === []) {
            return AtlasAgenticWorkcellRuntimeService::STATUS_WATCH;
        }
        if ($topology === 'critic_chain' && $risk >= 10) {
            return AtlasAgenticWorkcellRuntimeService::STATUS_BLOCKED;
        }

        return AtlasAgenticWorkcellRuntimeService::STATUS_READY;
    }

    public static function executionOrderTopologyMap(string $topology): string
    {
        return match ($topology) {
            'single' => 'solo_agent',
            'candidate_set' => 'tournament',
            'workcell' => 'lead_workers',
            'DAG' => 'critic_chain',
            'portfolio' => 'parallel_scouts',
            default => 'unsupported',
        };
    }

    public static function riskBand(int $risk): string
    {
        return match (true) {
            $risk <= 1 => 'R0', $risk <= 3 => 'R1', $risk <= 5 => 'R2',
            $risk <= 7 => 'R3', $risk <= 9 => 'R4', default => 'R5',
        };
    }

    /**
     * @return array<string,mixed>
     */
    public static function scoreTopology(
        string $topology,
        string $selectedTopology,
        string $domain,
        string $flowId,
        int $complexity,
        int $risk,
    ): array {
        $quality = match ($topology) {
            'solo_agent' => 0.62,
            'lead_workers' => 0.78,
            'parallel_scouts' => 0.76,
            'debate_council' => 0.80,
            'tournament' => 0.81,
            'red_blue_team' => 0.86,
            'mapreduce_research' => 0.88,
            'forge_milestone_crew' => 0.90,
            'critic_chain' => 0.74,
            'tool_builder_loop' => 0.84,
            default => 0.70,
        };
        $cost = match ($topology) {
            'solo_agent' => 0.06,
            'critic_chain' => 0.12,
            'parallel_scouts' => 0.20,
            'lead_workers' => 0.24,
            'debate_council', 'tournament', 'red_blue_team' => 0.30,
            'mapreduce_research', 'tool_builder_loop' => 0.34,
            'forge_milestone_crew' => 0.42,
            default => 0.20,
        };
        $domainBonus = match (true) {
            $domain === 'research' && $topology === 'mapreduce_research' => 0.10,
            $flowId === 'atlas_forge' && $topology === 'forge_milestone_crew' => 0.12,
            in_array($domain, ['finance', 'strategy'], true) && $topology === 'red_blue_team' => 0.10,
            default => 0.0,
        };
        $riskPenalty = $risk >= 8 && $topology === 'solo_agent' ? 0.25 : 0.0;
        $complexityPenalty = $complexity >= 8 && in_array($topology, ['solo_agent', 'critic_chain'], true) ? 0.18 : 0.0;
        $utility = $quality + $domainBonus - $cost - $riskPenalty - $complexityPenalty;

        return [
            'topology' => $topology,
            'selected' => $topology === $selectedTopology,
            'predicted_quality' => round($quality + $domainBonus, 2),
            'coordination_cost' => round($cost, 2),
            'utility_score' => round(max(0, min(1, $utility)), 3),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function sanitizePayload(array $payload): array
    {
        unset($payload['raw_prompt'], $payload['provider_raw_output'], $payload['secret'], $payload['credential']);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public static function circuitBreakerReceipt(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $receipt = [
            'failure_fingerprint' => trim((string) ($value['failure_fingerprint'] ?? '')),
            'evidence_delta_rounds' => max(0, (int) ($value['evidence_delta_rounds'] ?? 0)),
            'approach_id' => trim((string) ($value['approach_id'] ?? $value['candidate_id'] ?? '')),
            'terminal_reason' => trim((string) ($value['terminal_reason'] ?? $value['reason'] ?? '')),
            'status' => trim((string) ($value['status'] ?? '')),
            'decision_hash' => trim((string) ($value['decision_hash'] ?? '')),
        ];

        return array_filter($receipt, static fn (mixed $item): bool => $item !== '' && $item !== null);
    }
}
