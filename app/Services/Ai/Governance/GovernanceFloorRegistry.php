<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance;

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopConstitutionResolveFlags;

final class GovernanceFloorRegistry
{
    public const SCHEMA_VERSION = 'atlas.governance.floor_registry.v1';

    private const SAFETY_LOOSENING_LABEL = 'safety_loosening';

    /**
     * @var array<string,array{value:mixed,type:string,loosening:string,area:int|string,description:string}>
     */
    private const DEFINITIONS = [
        'autonomy_ladder.trust_threshold' => [
            'value' => 0.95,
            'type' => 'float',
            'loosening' => 'decrease',
            'area' => 18,
            'description' => 'Trust ledger floor for L7 autonomy entry.',
        ],
        'autonomy_ladder.demote_consecutive_breaches' => [
            'value' => 2,
            'type' => 'int',
            'loosening' => 'increase',
            'area' => 18,
            'description' => 'Consecutive breached cycles before autonomy demotion.',
        ],
        'stewardship_autonomy_envelope.default_forbidden_actions' => [
            'value' => [
                'merge_to_main',
                'force_push',
                'delete_branch_unmerged',
                'rewrite_history',
                'touch_secrets',
                'forge_real_execution',
                'provider_topology_authority_fabrication',
            ],
            'type' => 'string_list',
            'loosening' => 'remove_list_item',
            'area' => 18,
            'description' => 'Safe-by-default autonomous envelope prohibitions.',
        ],
        'multi_agent_integration_judge.default_forbidden_actions' => [
            'value' => [
                'merge',
                'merge_to_base',
                'deploy',
                'push',
                'force_push',
                'rebase',
                'secrets_access',
                'main_mutation',
                'provider_bypass',
            ],
            'type' => 'string_list',
            'loosening' => 'remove_list_item',
            'area' => 18,
            'description' => 'Forbidden actions for the multi-agent integration judge.',
        ],
        'atlas_decide.live_feedback.degradation_threshold' => [
            'value' => 0.7,
            'type' => 'float',
            'loosening' => 'decrease',
            'area' => 10,
            'description' => 'Success-rate floor below which a live route is degrading.',
        ],
        'atlas_decide.live_feedback.broken_threshold' => [
            'value' => 0.4,
            'type' => 'float',
            'loosening' => 'decrease',
            'area' => 10,
            'description' => 'Success-rate floor below which a live route is broken.',
        ],
        'atlas_decide.cost_outcome.min_evidence' => [
            'value' => 3,
            'type' => 'int',
            'loosening' => 'decrease',
            'area' => 10,
            'description' => 'Certified evidence floor for cost-outcome routing.',
        ],
        'atlas_decide.cost_outcome.min_certification_rate' => [
            'value' => 0.8,
            'type' => 'float',
            'loosening' => 'decrease',
            'area' => 10,
            'description' => 'Certification-rate floor for route eligibility.',
        ],
        'atlas_decide.cost_outcome.min_score' => [
            'value' => 80.0,
            'type' => 'float',
            'loosening' => 'decrease',
            'area' => 10,
            'description' => 'Quality score floor for route eligibility.',
        ],
        'atlas_decide.cost_outcome.max_score_drop' => [
            'value' => 3.0,
            'type' => 'float',
            'loosening' => 'increase',
            'area' => 10,
            'description' => 'Maximum score drop allowed while preferring lower cost.',
        ],
        'atlas_decide.cost_outcome.require_measured_cost' => [
            'value' => true,
            'type' => 'bool',
            'loosening' => 'false',
            'area' => 10,
            'description' => 'Route eligibility requires measured cost samples.',
        ],
        'atlas_decide.cost_outcome.min_cost_samples' => [
            'value' => 1,
            'type' => 'int',
            'loosening' => 'decrease',
            'area' => 10,
            'description' => 'Measured-cost sample floor for route eligibility.',
        ],
        'atlas_decide.cost_outcome.confidence_medium_threshold' => [
            'value' => 3,
            'type' => 'int',
            'loosening' => 'decrease',
            'area' => 10,
            'description' => 'Evidence count floor for medium route confidence.',
        ],
        'atlas_decide.cost_outcome.confidence_high_threshold' => [
            'value' => 6,
            'type' => 'int',
            'loosening' => 'decrease',
            'area' => 10,
            'description' => 'Evidence count floor for high route confidence.',
        ],
    ];

    public function __construct(private readonly ?GovernanceAmendmentLedger $ledger = null) {}

    public function ledger(): GovernanceAmendmentLedger
    {
        return $this->ledger ?? new GovernanceAmendmentLedger;
    }

    /**
     * @return array<string,mixed>
     */
    public function amend(array $diff, array $receipt): array
    {
        $receiptErrors = $this->receiptErrors($receipt);
        if ($receiptErrors !== []) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'rejected',
                'error' => 'governance_amendment_receipt_required',
                'receipt_errors' => $receiptErrors,
            ];
        }

        $normalized = $this->normalizeDiff($diff);
        if ($normalized === []) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'rejected',
                'error' => 'governance_amendment_diff_required',
            ];
        }

        $violations = $this->monotonicityViolations($normalized);
        $labels = $this->labels($receipt['labels'] ?? []);
        if ($violations !== [] && ! in_array(self::SAFETY_LOOSENING_LABEL, $labels, true)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'rejected',
                'error' => 'monotonicity_violation',
                'violations' => $violations,
            ];
        }

        $entry = $this->ledger()->append([
            'registry_schema_version' => self::SCHEMA_VERSION,
            'proposal_id' => (string) $receipt['proposal_id'],
            'receipt' => $receipt,
            'diff' => $normalized,
            'monotonicity' => [
                'status' => $violations === [] ? 'preserved' : 'safety_loosening_labelled',
                'violations' => $violations,
                'checker' => AtlasLoopConstitutionResolveFlags::class,
            ],
        ]);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'accepted',
            'entry' => $entry,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function floors(): array
    {
        $effective = $this->effectiveValues();

        return array_map(
            static fn (string $key): array => [
                'key' => $key,
                'value' => $effective[$key],
                'area' => self::DEFINITIONS[$key]['area'],
                'type' => self::DEFINITIONS[$key]['type'],
                'loosening' => self::DEFINITIONS[$key]['loosening'],
                'description' => self::DEFINITIONS[$key]['description'],
            ],
            array_keys(self::DEFINITIONS),
        );
    }

    public function get(string $key): mixed
    {
        $values = $this->effectiveValues();
        if (! array_key_exists($key, $values)) {
            throw new \InvalidArgumentException('Unknown governance floor: '.$key);
        }

        return $values[$key];
    }

    public function autonomyLadderTrustThreshold(): float
    {
        return (float) $this->get('autonomy_ladder.trust_threshold');
    }

    public function autonomyLadderDemoteConsecutiveBreaches(): int
    {
        return (int) $this->get('autonomy_ladder.demote_consecutive_breaches');
    }

    /**
     * @return list<string>
     */
    public function stewardshipAutonomyEnvelopeDefaultForbiddenActions(): array
    {
        return array_values((array) $this->get('stewardship_autonomy_envelope.default_forbidden_actions'));
    }

    /**
     * @return list<string>
     */
    public function multiAgentIntegrationJudgeDefaultForbiddenActions(): array
    {
        return array_values((array) $this->get('multi_agent_integration_judge.default_forbidden_actions'));
    }

    public function atlasDecideLiveFeedbackDegradationThreshold(): float
    {
        return (float) $this->get('atlas_decide.live_feedback.degradation_threshold');
    }

    public function atlasDecideLiveFeedbackBrokenThreshold(): float
    {
        return (float) $this->get('atlas_decide.live_feedback.broken_threshold');
    }

    /**
     * @return array<string,mixed>
     */
    public function atlasDecideCostOutcomeConfig(bool $enabled): array
    {
        return [
            'enabled' => $enabled,
            'min_evidence' => max(1, (int) $this->get('atlas_decide.cost_outcome.min_evidence')),
            'min_certification_rate' => max(0.0, min(1.0, (float) $this->get('atlas_decide.cost_outcome.min_certification_rate'))),
            'min_score' => max(0.0, min(100.0, (float) $this->get('atlas_decide.cost_outcome.min_score'))),
            'max_score_drop' => max(0.0, (float) $this->get('atlas_decide.cost_outcome.max_score_drop')),
            'require_measured_cost' => (bool) $this->get('atlas_decide.cost_outcome.require_measured_cost'),
            'min_cost_samples' => max(1, (int) $this->get('atlas_decide.cost_outcome.min_cost_samples')),
        ];
    }

    public function atlasDecideCostOutcomeConfidenceMediumThreshold(): int
    {
        return (int) $this->get('atlas_decide.cost_outcome.confidence_medium_threshold');
    }

    public function atlasDecideCostOutcomeConfidenceHighThreshold(): int
    {
        return (int) $this->get('atlas_decide.cost_outcome.confidence_high_threshold');
    }

    /**
     * @return array<string,mixed>
     */
    private function effectiveValues(): array
    {
        $values = array_map(static fn (array $definition): mixed => $definition['value'], self::DEFINITIONS);
        foreach ($this->ledger()->history() as $entry) {
            foreach ((array) ($entry['diff'] ?? []) as $key => $value) {
                if (array_key_exists($key, self::DEFINITIONS)) {
                    $values[$key] = $this->coerce($key, $value);
                }
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function receiptErrors(array $receipt): array
    {
        $errors = [];
        foreach (['proposal_id', 'actor', 'at'] as $field) {
            if (! is_string($receipt[$field] ?? null) || trim((string) $receipt[$field]) === '') {
                $errors[] = $field.'_required';
            }
        }

        $rollback = $receipt['rollback_predeclared'] ?? null;
        if (! is_array($rollback)
            || ($rollback['id'] ?? null) !== 'ROL-01'
            || ! is_string($rollback['command'] ?? null)
            || trim((string) $rollback['command']) === '') {
            $errors[] = 'rol_01_rollback_predeclared_required';
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $diff
     * @return array<string,mixed>
     */
    private function normalizeDiff(array $diff): array
    {
        $normalized = [];
        foreach ($diff as $key => $value) {
            if (! is_string($key) || ! array_key_exists($key, self::DEFINITIONS)) {
                continue;
            }
            $normalized[$key] = $this->coerce($key, $value);
        }

        return $normalized;
    }

    private function coerce(string $key, mixed $value): mixed
    {
        $type = self::DEFINITIONS[$key]['type'];

        return match ($type) {
            'int' => max(0, (int) $value),
            'float' => (float) $value,
            'bool' => (bool) $value,
            'string_list' => $this->stringList($value),
            default => $value,
        };
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return list<string>
     */
    private function monotonicityViolations(array $candidate): array
    {
        $current = $this->effectiveValues();
        $violations = [];
        $resolverLive = [];
        $resolverCandidate = [];

        foreach ($candidate as $key => $newValue) {
            $oldValue = $current[$key] ?? self::DEFINITIONS[$key]['value'];
            $loosening = self::DEFINITIONS[$key]['loosening'];
            $violation = match ($loosening) {
                'decrease' => is_numeric($oldValue) && is_numeric($newValue) && (float) $newValue < (float) $oldValue,
                'increase' => is_numeric($oldValue) && is_numeric($newValue) && (float) $newValue > (float) $oldValue,
                'false' => $oldValue === true && $newValue === false,
                'remove_list_item' => array_values(array_diff($this->stringList($oldValue), $this->stringList($newValue))) !== [],
                default => false,
            };
            if ($violation) {
                $violations[] = $key.': safety floor loosened';
            }

            if ($loosening === 'increase' && is_numeric($oldValue) && is_numeric($newValue)) {
                $shapeKey = 'atlas.governance.'.$key.'.threshold';
                $resolverLive[$shapeKey] = $oldValue;
                $resolverCandidate[$shapeKey] = $newValue;
            }
        }

        $constitutional = (new AtlasLoopConstitutionResolveFlags)->monotonicityCheck($resolverLive, $resolverCandidate);
        foreach ((array) ($constitutional['violations'] ?? []) as $violation) {
            if (is_string($violation) && ! in_array($violation, $violations, true)) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return array_values(array_unique(array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), (array) $value),
            static fn (string $item): bool => $item !== '',
        ))));
    }

    /**
     * @return list<string>
     */
    private function labels(mixed $value): array
    {
        return array_values(array_unique(array_map(
            static fn (mixed $label): string => strtolower(trim((string) $label)),
            (array) $value,
        )));
    }
}
