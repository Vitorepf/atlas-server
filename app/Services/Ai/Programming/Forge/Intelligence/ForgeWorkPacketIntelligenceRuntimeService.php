<?php

namespace App\Services\Ai\Programming\Forge\Intelligence;

use App\Models\AiForgeIntake;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;

final class ForgeWorkPacketIntelligenceRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.forge.work_packet_intelligence.v1';

    /**
     * @return array<string,mixed>
     */
    public function analyze(AiForgeWorkPacket $packet, ?AiForgeIntake $intake = null): array
    {
        $intake ??= $packet->intake;
        $expectedFiles = $this->strings($packet->expected_files ?? []);
        $suggestedTests = $this->strings($packet->suggested_tests ?? []);
        $contextRefs = $this->strings($intake?->context_refs ?? []);
        $evidenceRefs = $this->strings($intake?->evidence_refs ?? []);
        $objective = strtolower((string) $packet->objective.' '.(string) $packet->scope);

        $likelyFiles = $expectedFiles;
        if ($likelyFiles === []) {
            $likelyFiles = $this->inferLikelyFiles($objective);
        }

        $ownerDocs = $this->ownerDocs($likelyFiles, $contextRefs);
        $riskSignals = array_values(array_filter([
            $packet->risk_band !== null ? 'risk_band:'.$packet->risk_band : null,
            $packet->risks !== null && $this->strings($packet->risks) !== [] ? 'declared_risks' : null,
            str_contains($objective, 'migration') || str_contains($objective, 'migrar') ? 'migration' : null,
            str_contains($objective, 'provider') ? 'provider_runtime' : null,
            str_contains($objective, 'billing') || str_contains($objective, 'pagamento') ? 'business_critical' : null,
        ]));

        $confidence = 'hypothesis';
        if ($expectedFiles !== [] && $ownerDocs !== []) {
            $confidence = 'strong_inference';
        }
        if ($expectedFiles !== [] && $suggestedTests !== [] && $contextRefs !== []) {
            $confidence = 'confirmed_fact';
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'packet_id' => (string) $packet->packet_id,
            'intake_uuid' => $intake?->uuid,
            'confidence' => $confidence,
            'likely_files' => $likelyFiles,
            'owner_docs' => $ownerDocs,
            'suggested_tests' => $suggestedTests,
            'context_refs' => $contextRefs,
            'evidence_refs' => $evidenceRefs,
            'risk_signals' => $riskSignals,
            'dependency_count' => count($this->strings($packet->dependencies ?? [])),
            'acceptance_count' => count($this->strings($packet->acceptance_criteria ?? [])),
            'required_evidence_kinds' => $this->strings($packet->required_evidence ?? []),
        ];
        $payload['intelligence_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<int|string,mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn ($value): string => is_string($value) ? trim($value) : '',
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @return list<string>
     */
    private function inferLikelyFiles(string $objective): array
    {
        $files = [];
        if (str_contains($objective, 'forge')) {
            $files[] = 'app/Services/Ai/Programming/Forge';
            $files[] = 'tests/Feature/Ai/Programming/Forge';
        }
        if (str_contains($objective, 'provider')) {
            $files[] = 'app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php';
        }
        if (str_contains($objective, 'test') || str_contains($objective, 'teste')) {
            $files[] = 'tests/Feature/Ai/Programming';
        }
        if (str_contains($objective, 'doc') || str_contains($objective, 'document')) {
            $files[] = 'docs/engineering-knowledge-base';
        }

        return array_values(array_unique($files));
    }

    /**
     * @param  list<string>  $likelyFiles
     * @param  list<string>  $contextRefs
     * @return list<string>
     */
    private function ownerDocs(array $likelyFiles, array $contextRefs): array
    {
        $docs = array_values(array_filter($contextRefs, static fn (string $ref): bool => str_contains($ref, 'docs/')));
        foreach ($likelyFiles as $file) {
            if (str_starts_with($file, 'app/Services/Ai/Programming/Forge') || str_contains($file, 'Forge')) {
                $docs[] = 'docs/engineering-knowledge-base/atlas-programming-forge-flow.md';
            }
            if (str_starts_with($file, 'app/Services/Ai/Programming')) {
                $docs[] = 'docs/engineering-knowledge-base/domains/programming.md';
            }
        }

        return array_values(array_unique($docs));
    }
}
