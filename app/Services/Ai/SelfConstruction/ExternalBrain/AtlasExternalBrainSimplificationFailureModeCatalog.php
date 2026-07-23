<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Decision catalog for prior compression failures: the engine must learn from a failed
 * simplification instead of retrying the same broken shape. Each KNOWN failure signature maps
 * to the exact prework that closes it — contract extraction, replay, rollback, consumer
 * discovery, test coverage. An unrecognized signature never defaults to "retry" — it returns
 * investigate_first with the evidence still needed to classify it.
 *
 * Input shape:
 *   { failure_signature: string }
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainSimplificationFailureModeCatalog
{
    public const SCHEMA = 'atlas.self_construction.external_brain.simplification_failure_mode_catalog.v1';

    public const DECISION_KNOWN_FAILURE = 'known_failure';

    public const DECISION_INVESTIGATE_FIRST = 'investigate_first';

    /** @var array<string, list<string>> */
    private const FAILURE_MODE_PREWORK = [
        'missing_public_contract' => ['contract_extraction'],
        'replay_divergence' => ['replay'],
        'no_rollback_path' => ['rollback'],
        'unknown_consumer' => ['consumer_discovery'],
        'missing_test_coverage' => ['test_coverage_proof'],
    ];

    /**
     * @param  array{failure_signature?: string}  $facts
     * @return array<string,mixed>
     */
    public function catalog(array $facts): array
    {
        $signature = trim((string) ($facts['failure_signature'] ?? ''));

        if (isset(self::FAILURE_MODE_PREWORK[$signature])) {
            return [
                'schema' => self::SCHEMA,
                'failure_signature' => $signature,
                'decision' => self::DECISION_KNOWN_FAILURE,
                'required_prework' => self::FAILURE_MODE_PREWORK[$signature],
                'evidence_needed' => [],
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'failure_signature' => $signature,
            'decision' => self::DECISION_INVESTIGATE_FIRST,
            'required_prework' => [],
            'evidence_needed' => ['failure_signature_classification', 'root_cause_analysis'],
        ];
    }
}
