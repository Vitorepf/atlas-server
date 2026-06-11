<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Cognitive\Staleness\ContextPackStalenessClassifier;
use App\Services\Ai\Cognitive\Staleness\StalenessActionLadder;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

final class AtlasContextFreshnessQualityGateService
{
    public const SCHEMA_VERSION = 'atlas.aucri.context_freshness_quality_gate.v1';

    public const FRESHNESS_REPORT_SCHEMA = 'atlas.aucri.freshness_quality_report.v1';

    public const QUALITY_GATE_SCHEMA = 'atlas.aucri.context_quality_gate.v1';

    public const CONTRADICTION_REPORT_SCHEMA = 'atlas.aucri.contradiction_report.v1';

    public const STALENESS_ASSESSMENT_SCHEMA = 'atlas.aucri.context_staleness_assessment.v1';

    public function __construct(
        private readonly AtlasContextRankingSystemService $rankingSystem,
        private readonly ContextPackStalenessClassifier $stalenessClassifier = new ContextPackStalenessClassifier(),
        private readonly StalenessActionLadder $stalenessActionLadder = new StalenessActionLadder(),
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $risk = (string) ($input['risk_level'] ?? $input['risk'] ?? 'low');
        $ranking = $this->rankingSystem->rank($input + [
            'risk_level' => $risk,
        ]);
        $selectedRefs = (array) data_get($ranking, 'rerank_result.selected_refs', []);
        $coverage = (array) data_get($ranking, 'rerank_result.metrics.required_source_coverage', []);
        $contradictions = $this->contradictionReport((array) ($input['contradictions'] ?? []));
        $freshnessReport = $this->freshnessReport($selectedRefs, $risk);
        $qualityGate = $this->qualityGate($ranking, $selectedRefs, $coverage, $freshnessReport, $contradictions, $risk);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $qualityGate['status'],
            'generated_at' => Carbon::now()->toIso8601String(),
            'freshness_report' => $freshnessReport,
            'context_quality_gate' => $qualityGate,
            'contradiction_report' => $contradictions,
            'ranking_ref' => [
                'schema_version' => (string) ($ranking['schema_version'] ?? AtlasContextRankingSystemService::SCHEMA_VERSION),
                'status' => (string) ($ranking['status'] ?? 'unknown'),
                'rerank_result_hash' => (string) ($ranking['rerank_result_hash'] ?? ''),
                'selected_count' => count($selectedRefs),
                'required_source_coverage' => $coverage,
            ],
            'policy' => [
                'fail_closed_high_risk' => true,
                'unknown_freshness_is_not_current' => true,
                'provider_unsafe_selected_context_allowed' => false,
                'contradiction_bypass_allowed' => false,
                'raw_text_exposed' => false,
                'writes' => false,
                'providers_invoked' => false,
            ],
            'claims' => [
                'context_execution_allowed' => $qualityGate['action'] === 'allow_context',
                'requires_retrieval_refresh' => in_array($qualityGate['action'], ['refresh_retrieval', 'operator_review'], true),
                'providers_invoked' => false,
                'writes' => false,
                'raw_text_exposed' => false,
            ],
        ];

        // Default-OFF companion: advisory index-age/changed-files staleness
        // assessment. When disabled, the payload (and therefore the canonical
        // hash) is byte-identical to the legacy shape. When enabled, the
        // assessment is appended BEFORE hashing and is non-load-bearing: it
        // never changes `status`/`action`/coverage above.
        if ($this->stalenessAssessmentEnabled()) {
            $payload['staleness_assessment'] = $this->stalenessAssessment(
                (array) ($input['staleness_signals'] ?? []),
                $risk,
            );
        }

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['freshness_quality_gate_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * Advisory staleness assessment over caller-supplied index/drift signals.
     *
     * Delegates verbatim to the two consolidated kernels: the classifier
     * derives the severity from index-age/changed-files drift and the ladder
     * maps that severity (plus the existing high-risk flag) onto the
     * remediation rung. Advisory only — see `enforced => false`.
     *
     * @param  array<string,mixed>  $signals
     * @return array<string,mixed>
     */
    private function stalenessAssessment(array $signals, string $risk): array
    {
        $classification = $this->stalenessClassifier->classify($signals);
        $ladder = $this->stalenessActionLadder->action(
            (string) $classification['severity'],
            $this->isHighRisk($risk),
        );

        return [
            'schema_version' => self::STALENESS_ASSESSMENT_SCHEMA,
            'enforced' => false,
            'risk_level' => $risk,
            'classification' => $classification,
            'action_ladder' => $ladder,
        ];
    }

    private function stalenessAssessmentEnabled(): bool
    {
        return (bool) config('atlas_ai.context_staleness.enabled', false);
    }

    /**
     * @param  array<int,array<string,mixed>>  $selectedRefs
     * @return array<string,mixed>
     */
    private function freshnessReport(array $selectedRefs, string $risk): array
    {
        $items = [];
        foreach ($selectedRefs as $ref) {
            $freshness = (float) data_get($ref, 'score_components.freshness', 0.0);
            $authority = (float) data_get($ref, 'score_components.authority', 0.0);
            $score = (float) ($ref['score'] ?? 0.0);
            $items[] = [
                'candidate_id' => (string) ($ref['candidate_id'] ?? ''),
                'candidate_hash' => (string) ($ref['candidate_hash'] ?? ''),
                'source_ref_hash' => (string) ($ref['source_ref_hash'] ?? ''),
                'source_type' => (string) ($ref['source_type'] ?? 'unknown'),
                'freshness_score' => round($freshness, 4),
                'authority_score' => round($authority, 4),
                'context_score' => round($score, 4),
                'provider_safe' => (bool) ($ref['provider_safe'] ?? false),
                'status' => $this->itemStatus($freshness, $authority, $score),
            ];
        }

        $stale = array_values(array_filter($items, static fn (array $item): bool => $item['freshness_score'] < 0.70));
        $weakAuthority = array_values(array_filter($items, static fn (array $item): bool => $item['authority_score'] < 0.60));
        $weakScore = array_values(array_filter($items, static fn (array $item): bool => $item['context_score'] < 0.45));
        $providerUnsafe = array_values(array_filter($items, static fn (array $item): bool => ! (bool) $item['provider_safe']));

        return [
            'schema_version' => self::FRESHNESS_REPORT_SCHEMA,
            'status' => match (true) {
                $selectedRefs === [] => 'blocked',
                $providerUnsafe !== [] => 'blocked',
                $stale !== [] && $this->isHighRisk($risk) => 'blocked',
                $stale !== [] || $weakAuthority !== [] || $weakScore !== [] => 'warn',
                default => 'pass',
            },
            'selected_count' => count($selectedRefs),
            'stale_count' => count($stale),
            'weak_authority_count' => count($weakAuthority),
            'weak_score_count' => count($weakScore),
            'provider_unsafe_count' => count($providerUnsafe),
            'min_freshness_score' => $items === [] ? null : min(array_column($items, 'freshness_score')),
            'min_authority_score' => $items === [] ? null : min(array_column($items, 'authority_score')),
            'min_context_score' => $items === [] ? null : min(array_column($items, 'context_score')),
            'items' => $items,
        ];
    }

    /**
     * @param  array<int,mixed>  $contradictions
     * @return array<string,mixed>
     */
    private function contradictionReport(array $contradictions): array
    {
        $signals = array_values(array_filter(array_map(static function (mixed $signal): ?array {
            if (is_string($signal) && trim($signal) !== '') {
                return [
                    'signal_hash' => MissionCanonicalHash::sha256(trim($signal)),
                    'severity' => 'high',
                    'reason' => 'operator_or_upstream_contradiction_signal',
                ];
            }

            if (! is_array($signal)) {
                return null;
            }

            $reason = trim((string) ($signal['reason'] ?? $signal['description'] ?? 'contradiction_signal'));
            if ($reason === '') {
                return null;
            }

            return [
                'signal_hash' => MissionCanonicalHash::sha256($signal),
                'severity' => in_array(($signal['severity'] ?? null), ['low', 'medium', 'high', 'critical'], true)
                    ? (string) $signal['severity']
                    : 'high',
                'reason' => $reason,
            ];
        }, $contradictions)));

        return [
            'schema_version' => self::CONTRADICTION_REPORT_SCHEMA,
            'status' => $signals === [] ? 'pass' : 'blocked',
            'signal_count' => count($signals),
            'signals' => $signals,
        ];
    }

    /**
     * @param  array<string,mixed>  $ranking
     * @param  array<int,array<string,mixed>>  $selectedRefs
     * @param  array<string,bool>  $coverage
     * @param  array<string,mixed>  $freshnessReport
     * @param  array<string,mixed>  $contradictions
     * @return array<string,mixed>
     */
    private function qualityGate(
        array $ranking,
        array $selectedRefs,
        array $coverage,
        array $freshnessReport,
        array $contradictions,
        string $risk,
    ): array {
        $blockingReasons = [];
        $warnings = [];

        if ((string) ($ranking['status'] ?? 'unknown') === 'blocked') {
            $blockingReasons[] = 'ranking_blocked';
        }

        if ($selectedRefs === []) {
            $blockingReasons[] = 'no_selected_context';
        }

        if (in_array(false, $coverage, true)) {
            $blockingReasons[] = 'missing_required_source_coverage';
        }

        if ((int) ($freshnessReport['provider_unsafe_count'] ?? 0) > 0) {
            $blockingReasons[] = 'provider_unsafe_context_selected';
        }

        if ((string) ($contradictions['status'] ?? 'pass') === 'blocked') {
            $blockingReasons[] = 'contradiction_detected';
        }

        if ((string) ($freshnessReport['status'] ?? 'pass') === 'blocked') {
            $blockingReasons[] = 'freshness_blocked';
        } elseif ((string) ($freshnessReport['status'] ?? 'pass') === 'warn') {
            $warnings[] = 'freshness_or_quality_warning';
        }

        if ((string) ($ranking['status'] ?? 'unknown') === 'degraded') {
            $warnings[] = 'ranking_degraded';
        }

        if ($this->isHighRisk($risk) && $warnings !== []) {
            $blockingReasons[] = 'high_risk_escalated_warning';
        }

        $status = match (true) {
            $blockingReasons !== [] => 'blocked',
            $warnings !== [] => 'degraded',
            default => 'passed',
        };

        return [
            'schema_version' => self::QUALITY_GATE_SCHEMA,
            'status' => $status,
            'risk_level' => $risk,
            'action' => match ($status) {
                'passed' => 'allow_context',
                'degraded' => 'refresh_retrieval',
                default => 'block_execution',
            },
            'blocking_reasons' => AtlasContextStringListNormalizer::uniqueTrimmedStrings($blockingReasons),
            'warnings' => AtlasContextStringListNormalizer::uniqueTrimmedStrings($warnings),
            'required_source_coverage' => $coverage,
            'fail_closed' => $this->isHighRisk($risk) || $blockingReasons !== [],
            'remediation' => match ($status) {
                'passed' => 'none',
                'degraded' => 'rerun_retrieval_or_continue_only_in_read_only_low_risk_mode',
                default => 'rerun_ahri_acrs_with_fresh_provider_safe_sources_or_request_operator_review',
            },
        ];
    }

    private function itemStatus(float $freshness, float $authority, float $score): string
    {
        return match (true) {
            $freshness < 0.70 => 'stale_or_unknown',
            $authority < 0.60 => 'weak_authority',
            $score < 0.45 => 'weak_context_score',
            default => 'usable',
        };
    }

    private function isHighRisk(string $risk): bool
    {
        return in_array($risk, ['high', 'irreversible', 'critical'], true);
    }
}
