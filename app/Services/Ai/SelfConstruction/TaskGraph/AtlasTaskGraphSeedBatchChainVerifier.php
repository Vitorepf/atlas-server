<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskGraph;

/**
 * Verifies that a seed batch forms a coherent capability chain
 * rather than unrelated task padding.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasTaskGraphSeedBatchChainVerifier
{
    public const SCHEMA = 'atlas.self_construction.task_graph_seed_batch_chain_verifier.v1';

    public const VALID_ACTIONS = ['unlock', 'verify', 'repair', 'learn'];

    /**
     * @param  array<int, array<string, mixed>>  $seeds
     * @return array<string, mixed>
     */
    public function verify(array $seeds): array
    {
        $valid = [];
        $invalid = [];

        foreach ($seeds as $seed) {
            if (! is_array($seed)) {
                continue;
            }
            $id = (string) ($seed['id'] ?? '');
            $action = (string) ($seed['chain_action'] ?? '');
            $capabilityChain = (string) ($seed['capability_chain'] ?? '');

            $isValid = in_array($action, self::VALID_ACTIONS, true) && $capabilityChain !== '';

            if ($isValid) {
                $valid[] = [
                    'id' => $id,
                    'chain_action' => $action,
                    'capability_chain' => $capabilityChain,
                ];
            } else {
                $invalid[] = [
                    'id' => $id,
                    'reason' => $action === '' ? 'missing_chain_action' : 'invalid_chain_action',
                    'chain_action' => $action,
                ];
            }
        }

        // Check if all valid seeds belong to the same capability chain
        $chains = array_unique(array_column($valid, 'capability_chain'));
        $coherent = count($chains) <= 1 && count($invalid) === 0 && count($valid) > 0;

        return [
            'schema' => self::SCHEMA,
            'coherent' => $coherent,
            'valid_seeds' => $valid,
            'invalid_seeds' => $invalid,
            'valid_count' => count($valid),
            'invalid_count' => count($invalid),
            'capability_chains' => $chains,
            'chain_count' => count($chains),
        ];
    }
}
