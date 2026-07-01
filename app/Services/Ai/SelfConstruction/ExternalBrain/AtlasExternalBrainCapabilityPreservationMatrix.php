<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Fail-closed capability-preservation matrix: compression can never be judged by lines removed
 * alone — every capability the system had BEFORE a compression wave must be traceable to a real
 * owner, a test, and runtime evidence AFTER the wave, or the wave is held. Lines-removed is not a
 * proxy for "nothing important was lost."
 *
 * Input shape:
 *   { capabilities: list<{
 *       capability_id?:    string,
 *       before_owner?:     string,
 *       after_owner?:      string,
 *       tests?:            list<string>,
 *       runtime_evidence?: string,
 *   }> }
 *
 * PRESERVATION STATUS per capability:
 *   lost      — after_owner is blank (the capability has no owner post-compression at all).
 *   unproved  — after_owner is present but tests and/or runtime_evidence are missing (an owner
 *               claims it but nothing proves it survived).
 *   preserved — after_owner, at least one test, and runtime_evidence are all present.
 *
 * Overall verdict: approved ONLY when every capability is preserved; otherwise hold, naming the
 * exact missing preservation facts per capability — never a single aggregate score.
 *
 * Pure: no I/O, no provider calls, no queue mutation.
 */
final class AtlasExternalBrainCapabilityPreservationMatrix
{
    public const SCHEMA = 'atlas.external_brain.capability_preservation_matrix.v1';

    public const VERDICT_APPROVED = 'approved';

    public const VERDICT_HOLD = 'hold';

    public const STATUS_PRESERVED = 'preserved';

    public const STATUS_UNPROVED = 'unproved';

    public const STATUS_LOST = 'lost';

    /**
     * @param  array{capabilities?: list<array<string,mixed>>}  $input
     * @return array{schema:string, verdict:string, matrix:list<array<string,mixed>>, missing_preservation_facts:list<string>}
     */
    public function evaluate(array $input): array
    {
        $capabilities = is_array($input['capabilities'] ?? null) ? $input['capabilities'] : [];

        $matrix = [];
        $missingFacts = [];

        foreach ($capabilities as $capability) {
            if (! is_array($capability) || ! isset($capability['capability_id'])) {
                continue;
            }

            $capabilityId = (string) $capability['capability_id'];
            $beforeOwner = trim((string) ($capability['before_owner'] ?? ''));
            $afterOwner = trim((string) ($capability['after_owner'] ?? ''));
            $tests = array_values(array_filter(array_map('strval', (array) ($capability['tests'] ?? []))));
            $runtimeEvidence = trim((string) ($capability['runtime_evidence'] ?? ''));

            $missingParts = [];

            if ($afterOwner === '') {
                $missingParts[] = 'missing_after_owner';
                $status = self::STATUS_LOST;
            } else {
                if ($tests === []) {
                    $missingParts[] = 'missing_tests';
                }
                if ($runtimeEvidence === '') {
                    $missingParts[] = 'missing_runtime_evidence';
                }
                $status = $missingParts === [] ? self::STATUS_PRESERVED : self::STATUS_UNPROVED;
            }

            $matrix[] = [
                'capability_id' => $capabilityId,
                'before_owner' => $beforeOwner,
                'after_owner' => $afterOwner,
                'tests' => $tests,
                'runtime_evidence' => $runtimeEvidence,
                'preservation_status' => $status,
                'missing_preservation_facts' => $missingParts,
            ];

            foreach ($missingParts as $part) {
                $missingFacts[] = $capabilityId.':'.$part;
            }
        }

        $verdict = $missingFacts === [] ? self::VERDICT_APPROVED : self::VERDICT_HOLD;

        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'matrix' => $matrix,
            'missing_preservation_facts' => $missingFacts,
        ];
    }
}
