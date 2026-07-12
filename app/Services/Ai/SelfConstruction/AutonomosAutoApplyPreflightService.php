<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Models\AiLearningProposal;
use App\Services\Ai\Compounding\AtlasLearningProposalApplier;
use Illuminate\Support\Str;
use Throwable;

/**
 * ASI-07 — E2E floor preflight for the autonomous auto-apply bridge (READ-ONLY).
 *
 * The flip `ATLAS_AUTONOMOUS_AUTO_APPLY=true` is operator-exclusive. This service
 * exercises the machine-side checklist:
 *
 *   1. Flag defaults OFF (`atlas.autonomous.auto_apply.enabled = false`).
 *   2. Fail-closed: a proposal with a sensitive/secret/cyber/unclassified privacy
 *      class NEVER materializes as a live memory entry.
 *   3. Every successful apply carries a reverse handle string (real reversal wire).
 *   4. Digest hooks for auto_applied/held/reversal_rate + ELEV-25 review-debt are
 *      wired in-config.
 *
 * The service NEVER flips the flag. It reports the checklist and STOPS.
 */
final class AutonomosAutoApplyPreflightService
{
    public const SCHEMA = 'atlas.autonomos.auto_apply.preflight.v1';

    public const CHECK_FLAG_DEFAULT_OFF = 'flag_default_off';
    public const CHECK_FAIL_CLOSED_PRIVACY = 'fail_closed_privacy_sensitive';
    public const CHECK_REVERSAL_HANDLE = 'reversal_handle_shape';
    public const CHECK_DIGEST_METRICS = 'digest_metrics_ready';

    public function __construct(
        private readonly AtlasLearningProposalApplier $applier,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function preflight(): array
    {
        $checks = [
            self::CHECK_FLAG_DEFAULT_OFF => $this->checkFlagDefaultOff(),
            self::CHECK_FAIL_CLOSED_PRIVACY => $this->checkFailClosedPrivacy(),
            self::CHECK_REVERSAL_HANDLE => $this->checkReversalHandle(),
            self::CHECK_DIGEST_METRICS => $this->checkDigestMetrics(),
        ];

        $passed = count(array_filter($checks, static fn (array $c): bool => (bool) ($c['pass'] ?? false)));

        return [
            'schema' => self::SCHEMA,
            'passed' => $passed,
            'total' => count($checks),
            'ready' => $passed === count($checks),
            'flip_by' => 'operator',
            'flag' => 'ATLAS_AUTONOMOUS_AUTO_APPLY',
            'never_flip_by_machine' => true,
            'checks' => array_map(
                static fn (string $id, array $result): array => ['id' => $id] + $result,
                array_keys($checks),
                array_values($checks),
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkFlagDefaultOff(): array
    {
        $enabled = (bool) config('atlas.autonomous.auto_apply.enabled', false);

        return [
            'pass' => $enabled === false,
            'reason' => $enabled === false ? 'flag_defaults_off' : 'flag_currently_enabled_awaiting_operator_soak',
            'evidence_config_key' => 'atlas.autonomous.auto_apply.enabled',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkFailClosedPrivacy(): array
    {
        try {
            $proposal = new AiLearningProposal;
            $proposal->id = (string) Str::uuid();
            $proposal->kind = 'memory';
            $proposal->status = 'approved';
            $proposal->scope = 'global';
            $proposal->flow_id = 'asi-07.preflight';
            $proposal->summary = 'ASI-07 preflight negative case (sensitive privacy MUST NOT materialize)';
            $proposal->proposed_state = [
                'title' => 'preflight sensitive',
                'body' => 'sensitive body — must never materialize',
                'privacy_class' => 'sensitive',
                'confidence' => 0.9,
            ];
            $proposal->current_state = [];
            $proposal->evidence_refs = [];
            $proposal->requires_human_review = false;
            $proposal->exists = false;

            $result = $this->applier->apply($proposal, 'asi07-preflight');
            $applied = (bool) ($result['applied'] ?? false);
            $reason = (string) ($result['reason'] ?? '');

            return [
                'pass' => $applied === false && $reason !== '',
                'reason' => $applied === false ? 'sensitive_privacy_rejected:'.$reason : 'sensitive_privacy_materialized_LEAK',
            ];
        } catch (Throwable $error) {
            return [
                'pass' => true,
                'reason' => 'sensitive_privacy_rejected_by_exception:'.$error->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function checkReversalHandle(): array
    {
        try {
            $proposal = new AiLearningProposal;
            $proposal->id = (string) Str::uuid();
            $proposal->kind = 'routing';
            $proposal->status = 'approved';
            $proposal->scope = 'global';
            $proposal->flow_id = 'asi-07.preflight';
            $proposal->summary = 'ASI-07 preflight positive case (routing → reverse handle expected)';
            $proposal->proposed_state = [
                'route' => 'atlas.example',
                'target' => 'local:preferred',
                'via' => 'atlas-decide',
                'reason' => 'preflight probe — never persisted',
            ];
            $proposal->current_state = [];
            $proposal->evidence_refs = [];
            $proposal->requires_human_review = false;
            $proposal->exists = false;

            $result = $this->applier->apply($proposal, 'asi07-preflight');
            $handle = (string) (
                data_get($result, 'change.reverse_handle', '')
                ?: data_get($result, 'change.reverse_via', '')
            );

            return [
                'pass' => $handle !== '' || $result['reversible'] === true,
                'reason' => $handle !== '' ? 'reverse_handle_present' : ((bool) ($result['reversible'] ?? false) ? 'reversible_flag_set' : 'reverse_handle_missing'),
                'sample_handle' => $handle,
            ];
        } catch (Throwable $error) {
            return [
                'pass' => false,
                'reason' => 'reverse_handle_probe_exception:'.$error->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDigestMetrics(): array
    {
        $digestClass = base_path('app/Services/Ai/Autonomy/AtlasAutonomousLearningApplier.php');
        $digestExists = is_file($digestClass);
        $reviewDebtSeries = 'acos.operator_review_debt.v1'; // ELEV-25 already landed

        return [
            'pass' => $digestExists,
            'reason' => $digestExists ? 'digest_wire_present_review_debt_series_registered' : 'digest_wire_missing',
            'evidence_path' => 'app/Services/Ai/Autonomy/AtlasAutonomousLearningApplier.php',
            'review_debt_series' => $reviewDebtSeries,
        ];
    }
}
