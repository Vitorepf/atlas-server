<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance;

use App\Services\Ai\Policy\PolicyCanon;

/**
 * L6-14 capstone gate: one narrow change class may earn lower review friction
 * from re-checkable evidence, while a regression immediately takes it away.
 *
 * This service is a certification/reporting layer over the existing
 * AtlasChangeClassTrustLadder + AtlasAutonomyAdmissionService path. It never
 * changes merge gates, never calls a provider, and never releases classes whose
 * names match the trust-ladder sensitive/never-merge blocklist.
 */
final class ChangeClassTrustReleaseGateService
{
    public const SCHEMA_VERSION = 'atlas.governance.change_class_trust_release_gate.v1';

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $cfg = (array) config('atlas.ai.trust_ladder.release_gate', []);
        $enabled = (bool) ($options['enabled'] ?? $cfg['enabled'] ?? true);
        $fixture = trim((string) ($options['fixture'] ?? 'live')) ?: 'live';
        $class = strtolower(trim((string) ($options['change_class'] ?? $cfg['target_class'] ?? 'documentation_only')));
        $minCleanStreak = max(1, (int) ($options['min_clean_streak'] ?? $cfg['min_clean_streak'] ?? 3));
        $logPath = trim((string) ($options['log_path'] ?? ''));
        $seedRefs = $this->seedRefs((array) ($options['seed_refs'] ?? []));

        if (! $enabled) {
            return $this->payload('disabled', false, $fixture, $class, [], [], ['change_class_trust_release_gate_disabled']);
        }
        if (! in_array($fixture, ['live', 'mature', 'regressed', 'blocked-sensitive'], true)) {
            return $this->payload('blocked', false, $fixture, $class, [], [], ['unsupported_fixture']);
        }

        if ($fixture === 'blocked-sensitive') {
            $class = strtolower(trim((string) ($options['blocked_class'] ?? $cfg['blocked_class_probe'] ?? 'constitutional_kernel')));
        }

        $ladder = new AtlasChangeClassTrustLadder();
        if ($logPath !== '') {
            $ladder->setLogPathForTesting($logPath);
        } elseif ($fixture !== 'live') {
            $ladder->setLogPathForTesting($this->tempLogPath('l6-14-main'));
        }

        $before = $this->assessment($ladder, $class);

        if ($fixture !== 'live') {
            $this->recordCleanSeries($ladder, $class, $minCleanStreak, 'fixture-'.$fixture);
        }
        foreach ($seedRefs as $ref) {
            $ladder->recordEvidence($class, AtlasChangeClassTrustLadder::EVIDENCE_FROZEN_JUDGE_PASS, $ref);
        }
        if ($fixture === 'regressed') {
            $ladder->recordEvidence($class, AtlasChangeClassTrustLadder::EVIDENCE_REVERT, 'fixture-regression');
        }

        $after = $this->assessment($ladder, $class);
        $regressionProbe = $this->regressionProbe($class, $minCleanStreak);
        $blockedProbe = $this->blockedClassProbe((string) ($cfg['blocked_class_probe'] ?? 'constitutional_kernel'), $minCleanStreak);

        $blockers = $this->blockers($after, $regressionProbe, $blockedProbe, $minCleanStreak);
        $certified = $blockers === [];

        return $this->payload(
            status: $certified ? 'change_class_trust_release_ready' : 'change_class_trust_release_blocked',
            certified: $certified,
            fixture: $fixture,
            changeClass: $class,
            assessments: [
                'before' => $before,
                'after' => $after,
                'regression_probe' => $regressionProbe,
                'blocked_class_probe' => $blockedProbe,
            ],
            config: [
                'min_clean_streak' => $minCleanStreak,
                'log_path' => $ladder->logPath(),
                'seed_ref_count' => count($seedRefs),
                'thresholds' => (array) config('atlas.ai.trust_ladder.thresholds', []),
                'eligible_classes' => (array) config('atlas.ai.trust_ladder.eligible_classes', []),
                'blocked_class_patterns' => (array) config('atlas.ai.trust_ladder.blocked_class_patterns', []),
            ],
            blockers: $blockers,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function assessment(AtlasChangeClassTrustLadder $ladder, string $class): array
    {
        $admission = new AtlasAutonomyAdmissionService(new AtlasConstitutionalKernelService());
        $admission->setTicketsLogPathForTesting($this->tempLogPath('l6-14-admission'));
        $admission->setChangeClassLadder($ladder);

        $snapshot = $ladder->snapshot($class);
        $env = $admission->admit([
            'actor' => 'atlas-l6-14-trust-release-gate',
            'change_kind' => $class,
            'change_class' => $class,
            'requested_autonomy' => PolicyCanon::AUTONOMY_AUTONOMOUS,
            'risk_level' => PolicyCanon::RISK_LOW,
            'proposed_effect' => 'L6-14 class-scoped low-risk release check',
            'scope' => ['privacy_class' => 'public'],
        ]);

        return [
            'snapshot' => $snapshot,
            'admission' => [
                'decision' => (string) ($env['decision'] ?? ''),
                'effective_autonomy' => (string) ($env['effective_autonomy'] ?? ''),
                'max_autonomy_for_risk' => (string) ($env['max_autonomy_for_risk'] ?? ''),
                'requires_human_approval' => (bool) ($env['requires_human_approval'] ?? true),
                'change_class_earned_autonomy' => (string) ($env['change_class_earned_autonomy'] ?? ''),
                'gaps' => (array) ($env['gaps'] ?? []),
            ],
            'release_ready' => (string) ($env['decision'] ?? '') === AtlasAutonomyAdmissionService::DECISION_ALLOW_AUTONOMOUS
                && (bool) ($env['requires_human_approval'] ?? true) === false
                && (string) ($env['effective_autonomy'] ?? '') === PolicyCanon::AUTONOMY_AUTONOMOUS,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function regressionProbe(string $class, int $minCleanStreak): array
    {
        $ladder = new AtlasChangeClassTrustLadder();
        $ladder->setLogPathForTesting($this->tempLogPath('l6-14-regression'));
        $this->recordCleanSeries($ladder, $class, $minCleanStreak, 'regression-clean');
        $earned = $this->assessment($ladder, $class);
        $ladder->recordEvidence($class, AtlasChangeClassTrustLadder::EVIDENCE_REVERT, 'regression-probe');
        $revoked = $this->assessment($ladder, $class);

        return [
            'earned' => $earned,
            'after_revert' => $revoked,
            'reverted_to_max_friction' => (bool) ($earned['release_ready'] ?? false) === true
                && (bool) ($revoked['release_ready'] ?? false) === false
                && (int) data_get($revoked, 'snapshot.clean_streak', 0) === 0
                && (string) data_get($revoked, 'snapshot.earned_autonomy', '') === PolicyCanon::AUTONOMY_SUGGEST,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedClassProbe(string $class, int $minCleanStreak): array
    {
        $class = strtolower(trim($class));
        $ladder = new AtlasChangeClassTrustLadder();
        $ladder->setLogPathForTesting($this->tempLogPath('l6-14-blocked'));
        $this->recordCleanSeries($ladder, $class, $minCleanStreak, 'blocked-clean');
        $assessment = $this->assessment($ladder, $class);

        return [
            'change_class' => $class,
            'assessment' => $assessment,
            'blocked_despite_clean_refs' => (bool) ($assessment['release_ready'] ?? false) === false
                && (array) data_get($assessment, 'snapshot.release_policy.blockers', []) !== [],
        ];
    }

    private function recordCleanSeries(AtlasChangeClassTrustLadder $ladder, string $class, int $count, string $prefix): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $ladder->recordEvidence($class, AtlasChangeClassTrustLadder::EVIDENCE_FROZEN_JUDGE_PASS, $prefix.'-'.$i);
        }
    }

    /**
     * @param  array<int|string,mixed>  $refs
     * @return list<string>
     */
    private function seedRefs(array $refs): array
    {
        $out = [];
        foreach ($refs as $ref) {
            if (! is_string($ref) && ! is_numeric($ref)) {
                continue;
            }
            $ref = trim((string) $ref);
            if ($ref !== '') {
                $out[] = $ref;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string,mixed>  $after
     * @param  array<string,mixed>  $regressionProbe
     * @param  array<string,mixed>  $blockedProbe
     * @return list<string>
     */
    private function blockers(array $after, array $regressionProbe, array $blockedProbe, int $minCleanStreak): array
    {
        $blockers = [];
        foreach ((array) data_get($after, 'snapshot.release_policy.blockers', []) as $blocker) {
            $blockers[] = (string) $blocker;
        }
        if ((int) data_get($after, 'snapshot.clean_streak', 0) < $minCleanStreak) {
            $blockers[] = 'clean_streak_below_floor';
        }
        if ((string) data_get($after, 'snapshot.earned_autonomy', '') !== PolicyCanon::AUTONOMY_AUTONOMOUS) {
            $blockers[] = 'autonomous_threshold_not_earned';
        }
        if ((bool) ($after['release_ready'] ?? false) !== true) {
            $blockers[] = 'admission_still_requires_review';
        }
        if ((bool) ($regressionProbe['reverted_to_max_friction'] ?? false) !== true) {
            $blockers[] = 'regression_did_not_revoke_trust';
        }
        if ((bool) ($blockedProbe['blocked_despite_clean_refs'] ?? false) !== true) {
            $blockers[] = 'sensitive_class_release_not_blocked';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<string,mixed>  $assessments
     * @param  array<string,mixed>  $config
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function payload(
        string $status,
        bool $certified,
        string $fixture,
        string $changeClass,
        array $assessments,
        array $config,
        array $blockers,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'fixture' => $fixture,
            'change_class' => $changeClass,
            'certified' => $certified,
            'completion_claim_allowed' => $certified,
            'assessments' => $assessments,
            'blockers' => $blockers,
            'config' => $config,
            'claim_policy' => [
                'provider_calls_made' => false,
                'workspace_mutated' => false,
                'never_merge_changed' => false,
                'merge_gate_changed' => false,
                'sensitive_kernel_release_allowed' => false,
                'regression_revoke_required' => true,
            ],
        ];
    }

    private function tempLogPath(string $tag): string
    {
        return sys_get_temp_dir().'/atlas-'.$tag.'-'.bin2hex(random_bytes(4)).'.jsonl';
    }
}
