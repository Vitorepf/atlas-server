<?php

namespace App\Services\Ai\Cyber;

use App\Models\AiAppSecReview;
use App\Models\AiBugBountyIntake;
use App\Models\AiCyberEngagement;
use App\Models\AiCyberEvidenceChainEntry;
use App\Models\AiCyberScopeRules;
use App\Models\AiDefensiveSecurityReview;
use App\Models\AiGrcMapping;
use App\Models\AiRemediationPlan;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CyberControlPlaneProjection
{
    public const SCHEMA = 'atlas.ai.cyber.control_plane.v1';

    /**
     * @return array<string,mixed>
     */
    public function snapshot(int $limitRecent = 20): array
    {
        if (! Schema::hasTable('ai_cyber_engagements')) {
            return [
                'schema' => self::SCHEMA,
                'status' => 'missing',
                'detail' => 'ai_cyber_* tables not present',
                'generated_at' => now()->toJSON(),
            ];
        }

        try {
            return [
                'schema' => self::SCHEMA,
                'status' => 'ready',
                'totals' => $this->totals(),
                'engagements' => $this->section(AiCyberEngagement::class, 'status', $limitRecent, [
                    'id', 'uuid', 'engagement_id', 'engagement_kind', 'status',
                    'authorization_present', 'requester', 'created_at',
                ]),
                'scope_rules' => $this->section(AiCyberScopeRules::class, 'status', $limitRecent, [
                    'id', 'uuid', 'scope_id', 'engagement_id', 'status', 'created_at',
                ]),
                'appsec_reviews' => $this->section(AiAppSecReview::class, 'status', $limitRecent, [
                    'id', 'uuid', 'review_id', 'target_kind', 'risk_score', 'status', 'created_at',
                ]),
                'grc_mappings' => $this->section(AiGrcMapping::class, 'compliance_status', $limitRecent, [
                    'id', 'uuid', 'framework', 'control_id', 'compliance_status', 'created_at',
                ]),
                'remediation_plans' => $this->section(AiRemediationPlan::class, 'status', $limitRecent, [
                    'id', 'uuid', 'plan_id', 'severity', 'status', 'created_at',
                ]),
                'defensive_reviews' => $this->section(AiDefensiveSecurityReview::class, 'status', $limitRecent, [
                    'id', 'uuid', 'review_id', 'review_kind', 'status', 'created_at',
                ]),
                'bug_bounty_intakes' => $this->section(AiBugBountyIntake::class, 'status', $limitRecent, [
                    'id', 'uuid', 'intake_id', 'program', 'status',
                    'authorization_present', 'scope_parsed', 'roe_documented',
                    'legal_gate_passed', 'privacy_gate_passed', 'created_at',
                ]),
                'evidence_chain' => $this->section(AiCyberEvidenceChainEntry::class, 'entry_kind', $limitRecent, [
                    'id', 'uuid', 'engagement_id', 'entry_kind', 'actor', 'entry_hash', 'created_at',
                ]),
                'generated_at' => now()->toJSON(),
            ];
        } catch (Throwable $e) {
            return [
                'schema' => self::SCHEMA,
                'status' => 'degraded',
                'detail' => 'cyber snapshot failed: '.$e->getMessage(),
                'generated_at' => now()->toJSON(),
            ];
        }
    }

    /**
     * @return array<string,int>
     */
    private function totals(): array
    {
        return [
            'engagements' => $this->safeCount(AiCyberEngagement::class),
            'scope_rules' => $this->safeCount(AiCyberScopeRules::class),
            'appsec_reviews' => $this->safeCount(AiAppSecReview::class),
            'grc_mappings' => $this->safeCount(AiGrcMapping::class),
            'remediation_plans' => $this->safeCount(AiRemediationPlan::class),
            'defensive_reviews' => $this->safeCount(AiDefensiveSecurityReview::class),
            'bug_bounty_intakes' => $this->safeCount(AiBugBountyIntake::class),
            'evidence_chain_entries' => $this->safeCount(AiCyberEvidenceChainEntry::class),
        ];
    }

    /**
     * @param  array<int,string>  $recentColumns
     * @return array<string,mixed>
     */
    private function section(string $modelClass, string $statusColumn, int $limit, array $recentColumns): array
    {
        if (! class_exists($modelClass)) {
            return ['count' => 0, 'by_status' => [], 'recent' => []];
        }
        try {
            $byStatus = $modelClass::query()
                ->selectRaw("{$statusColumn} as bucket, COUNT(*) as total")
                ->groupBy($statusColumn)
                ->pluck('total', 'bucket')
                ->all();

            $recent = $modelClass::query()
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->map(static function ($row) use ($recentColumns): array {
                    $out = [];
                    foreach ($recentColumns as $column) {
                        $out[$column] = $row->{$column} ?? null;
                    }

                    return $out;
                })->all();

            return [
                'count' => (int) $modelClass::query()->count(),
                'by_status' => array_map(static fn ($v): int => (int) $v, $byStatus),
                'recent' => $recent,
            ];
        } catch (Throwable) {
            return ['count' => 0, 'by_status' => [], 'recent' => []];
        }
    }

    private function safeCount(string $modelClass): int
    {
        if (! class_exists($modelClass)) {
            return 0;
        }
        try {
            return (int) $modelClass::query()->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
