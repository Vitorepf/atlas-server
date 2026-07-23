<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Proof gate for public-surface reduction: Atlas needs fewer entrypoints (CLI commands, API
 * routes, domain methods) without losing capability reachability or breaking a live consumer.
 * Each capability is approved for surface reduction only when it still has an owner, evidence,
 * and at least one intended (kept) entrypoint after the reduction. An entrypoint marked for
 * removal that still has an active consumer is never silently dropped — it holds the whole
 * capability with the exact consumer names named, so nothing breaks a caller no one told.
 *
 * Input contract:
 *   capabilities: list<array{
 *     id?:           string,
 *     owner?:        string,
 *     evidence?:     string,
 *     entrypoints?:  list<array{
 *       name?:              string,
 *       kept?:              bool,   (default false — removed unless explicitly kept)
 *       active_consumers?:  list<string>,
 *     }>,
 *   }>
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainCapabilitySurfaceMinimizer
{
    public const SCHEMA = 'atlas.external_brain.capability_surface_minimizer.v1';

    public const DECISION_APPROVE = 'approve';
    public const DECISION_HOLD    = 'hold';

    /**
     * @param  array{capabilities?: list<array<string,mixed>>}  $facts
     * @return array{schema:string, overall_decision:string, capabilities:list<array<string,mixed>>}
     */
    public function evaluate(array $facts): array
    {
        $capabilities = is_array($facts['capabilities'] ?? null) ? $facts['capabilities'] : [];

        $results = [];
        $overallApproved = true;

        foreach ($capabilities as $capability) {
            if (! is_array($capability)) {
                continue;
            }

            $id = trim((string) ($capability['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $owner    = trim((string) ($capability['owner'] ?? ''));
            $evidence = trim((string) ($capability['evidence'] ?? ''));
            $entrypoints = is_array($capability['entrypoints'] ?? null) ? $capability['entrypoints'] : [];

            $reasons = [];
            $blockedConsumers = [];
            $keptCount = 0;

            foreach ($entrypoints as $entrypoint) {
                if (! is_array($entrypoint)) {
                    continue;
                }
                $kept = (bool) ($entrypoint['kept'] ?? false);
                $activeConsumers = array_values(array_filter(array_map('strval', (array) ($entrypoint['active_consumers'] ?? []))));

                if ($kept) {
                    $keptCount++;

                    continue;
                }

                if ($activeConsumers !== []) {
                    $entrypointName = trim((string) ($entrypoint['name'] ?? ''));
                    $reasons[] = "removed_entrypoint_has_active_consumers:{$entrypointName}";
                    foreach ($activeConsumers as $consumer) {
                        $blockedConsumers[] = $consumer;
                    }
                }
            }

            if ($owner === '') {
                $reasons[] = 'missing_owner';
            }
            if ($evidence === '') {
                $reasons[] = 'missing_evidence';
            }
            if ($keptCount === 0) {
                $reasons[] = 'no_intended_entrypoint_remaining';
            }

            $decision = $reasons === [] ? self::DECISION_APPROVE : self::DECISION_HOLD;
            if ($decision === self::DECISION_HOLD) {
                $overallApproved = false;
            }

            $results[] = [
                'id'                 => $id,
                'decision'           => $decision,
                'reasons'            => $reasons,
                'blocked_consumers'  => array_values(array_unique($blockedConsumers)),
            ];
        }

        return [
            'schema'            => self::SCHEMA,
            'overall_decision'  => $overallApproved ? self::DECISION_APPROVE : self::DECISION_HOLD,
            'capabilities'      => $results,
        ];
    }
}
