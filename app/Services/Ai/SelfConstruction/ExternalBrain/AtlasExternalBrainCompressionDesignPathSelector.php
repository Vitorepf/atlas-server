<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Deterministic design-path gate: maps a candidate's structural symptoms — duplication,
 * indirection, dead code, undocumented public surface, proof debt — to ONE repeatable
 * engineering action instead of letting the engine invent an ad hoc simplification task every
 * cycle. Symptom checks run in fixed priority order (first match wins), so a candidate with
 * multiple symptoms always gets the same, most-actionable path.
 *
 * Input contract:
 *   dead_code?:                     bool
 *   duplication_score?:             float  (0..1)
 *   indirection_score?:             float  (0..1)
 *   undocumented_public_surface?:   bool
 *   proof_debt_score?:              float  (0..1)
 *   required_proof?:                list<string>
 *   proof_available?:               list<string>
 *
 * PATH PRIORITY (first match wins):
 *   1. dead_code                                → delete
 *   2. duplication_score  >= threshold           → merge
 *   3. indirection_score  >= threshold           → collapse_indirection
 *   4. undocumented_public_surface               → extract_contract
 *   5. proof_debt_score   >= threshold           → add_proof
 *   6. none of the above                         → no_safe_path
 *
 * Every path except add_proof and no_safe_path requires its declared required_proof to be a
 * subset of proof_available; missing proof downgrades the path to no_safe_path with the exact
 * missing proof items named — a design path is never fabricated on top of unproven ground.
 * add_proof is exempt because it IS the action that gathers the missing proof.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainCompressionDesignPathSelector
{
    public const SCHEMA = 'atlas.external_brain.compression_design_path_selector.v1';

    public const PATH_DELETE               = 'delete';
    public const PATH_MERGE                = 'merge';
    public const PATH_COLLAPSE_INDIRECTION = 'collapse_indirection';
    public const PATH_EXTRACT_CONTRACT     = 'extract_contract';
    public const PATH_ADD_PROOF            = 'add_proof';
    public const PATH_NO_SAFE_PATH         = 'no_safe_path';

    public const DEFAULT_THRESHOLD = 0.6;

    /** @var list<string> */
    private const PROOF_EXEMPT_PATHS = [self::PATH_ADD_PROOF, self::PATH_NO_SAFE_PATH];

    /**
     * @param  array<string,mixed>  $facts
     * @return array{schema:string, design_path:string, missing_proof:list<string>}
     */
    public function select(array $facts): array
    {
        $threshold = (float) ($facts['threshold'] ?? self::DEFAULT_THRESHOLD);

        $deadCode                  = (bool) ($facts['dead_code'] ?? false);
        $duplicationScore          = (float) ($facts['duplication_score'] ?? 0.0);
        $indirectionScore          = (float) ($facts['indirection_score'] ?? 0.0);
        $undocumentedPublicSurface = (bool) ($facts['undocumented_public_surface'] ?? false);
        $proofDebtScore            = (float) ($facts['proof_debt_score'] ?? 0.0);

        $path = match (true) {
            $deadCode                             => self::PATH_DELETE,
            $duplicationScore >= $threshold        => self::PATH_MERGE,
            $indirectionScore >= $threshold        => self::PATH_COLLAPSE_INDIRECTION,
            $undocumentedPublicSurface             => self::PATH_EXTRACT_CONTRACT,
            $proofDebtScore >= $threshold          => self::PATH_ADD_PROOF,
            default                                => self::PATH_NO_SAFE_PATH,
        };

        $missingProof = [];
        if (! in_array($path, self::PROOF_EXEMPT_PATHS, true)) {
            $requiredProof  = array_values(array_filter(array_map('strval', (array) ($facts['required_proof'] ?? []))));
            $proofAvailable = array_values(array_filter(array_map('strval', (array) ($facts['proof_available'] ?? []))));
            $missingProof   = array_values(array_diff($requiredProof, $proofAvailable));

            if ($missingProof !== []) {
                $path = self::PATH_NO_SAFE_PATH;
            }
        }

        return [
            'schema'        => self::SCHEMA,
            'design_path'   => $path,
            'missing_proof' => $missingProof,
        ];
    }
}
