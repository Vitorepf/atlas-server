<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\CausalGraph;

/**
 * Pre-materialization blast-radius predictor for hypothetical API edits.
 *
 * It composes the causal-edge classifier over caller-provided proposed changes and consumer evidence. The
 * output is advisory facts only: no files are touched, no edit is materialized, and no risk score is invented.
 */
final class AtlasLoopPreHocBlastRadiusPredictor
{
    public const SCHEMA_VERSION = 'atlas.loop.prehoc_blast_radius_predictor.v1';

    public function __construct(private readonly ?AtlasLoopCausalEdgeClassifier $classifier = null) {}

    /**
     * @param  array<string,mixed>  $proposedApiChange
     * @param  list<string|array<string,mixed>>  $consumerFqcns
     * @return array{schema:string, would_break:list<array{consumer_fqcn:string, break_kind:string}>, safe:bool, reason:string}
     */
    public function predict(string $fqcn, array $proposedApiChange, array $consumerFqcns): array
    {
        $classified = ($this->classifier ?? new AtlasLoopCausalEdgeClassifier)
            ->classify($fqcn, $consumerFqcns, $proposedApiChange);

        $wouldBreak = [];
        foreach ((array) ($classified['edges'] ?? []) as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $kind = (string) ($edge['break_kind'] ?? '');
            if (! in_array($kind, ['removed_member', 'signature_changed'], true)) {
                continue;
            }

            $consumer = trim((string) ($edge['consumer_fqcn'] ?? ''));
            if ($consumer === '') {
                continue;
            }

            $wouldBreak[] = [
                'consumer_fqcn' => $consumer,
                'break_kind' => $kind,
            ];
        }

        $safe = $wouldBreak === [];

        return [
            'schema' => self::SCHEMA_VERSION,
            'would_break' => $wouldBreak,
            'safe' => $safe,
            'reason' => $safe ? 'no_breaking_consumers_predicted' : 'breaking_consumers_predicted',
        ];
    }
}
