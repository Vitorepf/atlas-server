<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\WireFormat;

/**
 * Resolves schema version disagreements between primitives. Picks the highest mutual version
 * advertised by both sides, after asserting every offered version exists in the registry.
 *
 * Fail-closed: phantom versions (advertised but not declared) ⇒ chosenVersion=null with
 * reason=phantom_version and offendingSide=source|target.
 */
final class AtlasLoopInterPrimitiveMessageVersionNegotiator
{
    /** @var callable(string): list<int> */
    private $versionsResolver;

    /**
     * @param  callable(string): list<int>  $versionsResolver  optional override (defaults to the registry).
     */
    public function __construct(
        private readonly AtlasLoopInterPrimitiveMessageSchemaRegistry $registry,
        ?callable $versionsResolver = null,
    ) {
        $this->versionsResolver = $versionsResolver
            ?? fn (string $family): array => array_map(
                static fn (array $row): int => (int) $row['version'],
                $this->registry->versionsOf($family),
            );
    }

    /**
     * @param  list<int>  $sourceVersions
     * @param  list<int>  $targetVersions
     */
    public function negotiate(string $family, array $sourceVersions, array $targetVersions): AtlasLoopInterPrimitiveMessageNegotiationOutcome
    {
        $sourceVersions = array_values(array_map('intval', $sourceVersions));
        $targetVersions = array_values(array_map('intval', $targetVersions));

        $known = ($this->versionsResolver)($family);
        if ($known === []) {
            return new AtlasLoopInterPrimitiveMessageNegotiationOutcome(
                $family, $sourceVersions, $targetVersions, null,
                AtlasLoopInterPrimitiveMessageNegotiationOutcome::REASON_UNKNOWN_FAMILY,
                'family',
            );
        }

        $sourcePhantom = array_values(array_diff($sourceVersions, $known));
        if ($sourcePhantom !== []) {
            return new AtlasLoopInterPrimitiveMessageNegotiationOutcome(
                $family, $sourceVersions, $targetVersions, null,
                AtlasLoopInterPrimitiveMessageNegotiationOutcome::REASON_PHANTOM_VERSION,
                'source',
            );
        }
        $targetPhantom = array_values(array_diff($targetVersions, $known));
        if ($targetPhantom !== []) {
            return new AtlasLoopInterPrimitiveMessageNegotiationOutcome(
                $family, $sourceVersions, $targetVersions, null,
                AtlasLoopInterPrimitiveMessageNegotiationOutcome::REASON_PHANTOM_VERSION,
                'target',
            );
        }

        $common = array_values(array_intersect($sourceVersions, $targetVersions));
        if ($common === []) {
            return new AtlasLoopInterPrimitiveMessageNegotiationOutcome(
                $family, $sourceVersions, $targetVersions, null,
                AtlasLoopInterPrimitiveMessageNegotiationOutcome::REASON_NO_COMMON_VERSION,
            );
        }

        $chosen = max($common);

        return new AtlasLoopInterPrimitiveMessageNegotiationOutcome(
            $family, $sourceVersions, $targetVersions, $chosen,
            AtlasLoopInterPrimitiveMessageNegotiationOutcome::REASON_HIGHEST_COMMON,
        );
    }
}
