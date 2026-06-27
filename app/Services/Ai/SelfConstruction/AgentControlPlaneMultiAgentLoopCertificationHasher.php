<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

/**
 * JSON / SERIALIZATION + CANONICAL HASH helpers extracted from the
 * god-class {@see AgentControlPlaneMultiAgentLoopCertificationService}.
 *
 * Owns every pure JSON-and-hash helper (loadJson, encodeJson,
 * normalizeForHash, stableHash). The runtime service delegates each
 * method to this collaborator through thin byte-identical delegators.
 */
final class AgentControlPlaneMultiAgentLoopCertificationHasher
{
public function loadJson($disk, string $path): ?array
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::loadJson($disk, $path);
    }


public function encodeJson(array $payload): string
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::encodeJson($payload);
    }


public function normalizeForHash(array $payload): array
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::normalizeForHash($payload);
    }


public function stableHash(array $payload): string
    {
        return MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::stableHash($payload);
    }

}
