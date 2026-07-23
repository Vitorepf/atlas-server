<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Ports;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * TEST-ONLY deterministic Frontier generator.
 *
 * Self-labels 'fixture:deterministic' (NEVER 'real:'), so the orchestrator can
 * refuse fixture-labelled proposals in production. Exposes a resolved
 * provider/model pair so a judge can prove the I3 resolved model-difference
 * check against a DIFFERENT pair. This is NEVER a real proposal source.
 *
 * It stamps result-level provenance per proposal_id (generator_label,
 * generator_input_hash, proposal_hash) using the canonical identity formula —
 * provenance is NEVER stamped on the proposal object itself (which must carry
 * only the 13 schema keys).
 */
final class DeterministicFixtureFrontierGeneratorService implements FrontierGeneratorPort
{
    public const LABEL = 'fixture:deterministic';

    public const FIXTURE_PROVIDER = 'codex_cli';

    public const FIXTURE_MODEL = 'fixture-generator-model';

    /**
     * @param  list<array<string,mixed>>  $cannedProposals  13-key proposal projections
     */
    public function __construct(
        private readonly array $cannedProposals = [],
        private readonly string $resolvedProvider = self::FIXTURE_PROVIDER,
        private readonly string $resolvedModel = self::FIXTURE_MODEL,
    ) {}

    public function generate(array $dossier, int $count, array $context = []): array
    {
        $proposals = array_slice($this->cannedProposals, 0, max(0, $count));
        $generatorInputHash = MissionCanonicalHash::sha256($dossier);

        $provenance = [];
        foreach ($proposals as $proposal) {
            $proposalId = (string) ($proposal['proposal_id'] ?? '');
            $provenance[$proposalId] = [
                'generator_label' => self::LABEL,
                'generator_input_hash' => $generatorInputHash,
                'proposal_hash' => FrontierProposalIdentity::of($proposal),
            ];
        }

        return [
            'status' => $proposals === [] ? 'skipped' : 'generated',
            'proposals' => array_values($proposals),
            'provenance' => $provenance,
            'generator_label' => self::LABEL,
            'generator_provider_resolved' => $this->resolvedProvider,
            'generator_model_resolved' => $this->resolvedModel,
            'generator_blocked_reasons' => [],
            'claim_policy' => [
                'provider_invoked' => false,
            ],
        ];
    }
}
