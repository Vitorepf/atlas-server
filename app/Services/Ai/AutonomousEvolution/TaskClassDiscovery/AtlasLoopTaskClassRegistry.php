<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\TaskClassDiscovery;

use RuntimeException;

/**
 * Canonical, APPEND-ONLY store of approved task-classes. NEVER mutates entries in place;
 * supersede() creates a new entry with version+1.
 *
 * approve(proposal_id, operator_token) resolves proposal data via the injected proposalResolver.
 * The resolver is duck-typed to keep the registry testable without coupling to a specific store.
 *
 * Anti-Goodhart: match() is a deterministic FACT — it returns the class_id when EVERY shape rule
 * matches the supplied packet shape payload, OR returns null. There is no similarity score.
 */
final class AtlasLoopTaskClassRegistry
{
    /** @var list<AtlasLoopTaskClassRegistryEntry> */
    private array $entries = [];

    /** @var (callable(string): ?array{class_id:string, shape_rules:array, expected_acceptance_criteria_template:list, default_required_evidence_ids:list, default_allowed_files_globs:list, source_cluster_fingerprint:string})|null */
    private $proposalResolver;

    /**
     * @param  (callable(string): ?array<string,mixed>)|null  $proposalResolver  resolves proposal_id ⇒ proposal data
     */
    public function __construct(?callable $proposalResolver = null)
    {
        $this->proposalResolver = $proposalResolver;
    }

    public function setProposalResolver(callable $resolver): void
    {
        $this->proposalResolver = $resolver;
    }

    public function approve(string $proposalId, string $operatorToken): AtlasLoopTaskClassRegistryEntry
    {
        if ($operatorToken === '') {
            throw new RuntimeException('operator_token_required');
        }
        $proposal = $this->resolveProposal($proposalId);
        $entry = $this->buildEntry($proposal, $operatorToken, version: 1);
        $this->entries[] = $entry;

        return $entry;
    }

    public function supersede(string $classId, string $newProposalId, string $operatorToken): AtlasLoopTaskClassRegistryEntry
    {
        if ($operatorToken === '') {
            throw new RuntimeException('operator_token_required');
        }
        $latest = $this->latestForClass($classId);
        if ($latest === null) {
            throw new RuntimeException('class_id_not_registered:'.$classId);
        }
        $proposal = $this->resolveProposal($newProposalId);
        // Force the class_id of the new entry to the existing class_id (chain preserved).
        $proposal['class_id'] = $classId;
        $entry = $this->buildEntry($proposal, $operatorToken, version: $latest->version + 1);
        $this->entries[] = $entry;

        return $entry;
    }

    /**
     * @param  array<string,mixed>  $packetShape
     */
    public function match(array $packetShape): ?string
    {
        // Iterate LATEST version per class_id; first exact match wins (deterministic order by class_id).
        $latestByClass = [];
        foreach ($this->entries as $entry) {
            $existing = $latestByClass[$entry->classId] ?? null;
            if ($existing === null || $entry->version > $existing->version) {
                $latestByClass[$entry->classId] = $entry;
            }
        }
        ksort($latestByClass);
        foreach ($latestByClass as $entry) {
            if ($this->shapeRulesMatch($entry->shapeRules, $packetShape)) {
                return $entry->classId;
            }
        }

        return null;
    }

    /**
     * @return list<AtlasLoopTaskClassRegistryEntry>
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function latestForClass(string $classId): ?AtlasLoopTaskClassRegistryEntry
    {
        $best = null;
        foreach ($this->entries as $entry) {
            if ($entry->classId !== $classId) {
                continue;
            }
            if ($best === null || $entry->version > $best->version) {
                $best = $entry;
            }
        }

        return $best;
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveProposal(string $proposalId): array
    {
        $resolver = $this->proposalResolver;
        if (! is_callable($resolver)) {
            throw new RuntimeException('no_proposal_resolver_bound');
        }
        $proposal = $resolver($proposalId);
        if (! is_array($proposal)) {
            throw new RuntimeException('proposal_not_found:'.$proposalId);
        }

        return $proposal;
    }

    /**
     * @param  array<string,mixed>  $proposal
     */
    private function buildEntry(array $proposal, string $operatorToken, int $version): AtlasLoopTaskClassRegistryEntry
    {
        return new AtlasLoopTaskClassRegistryEntry(
            classId: (string) ($proposal['class_id'] ?? ''),
            shapeRules: is_array($proposal['shape_rules'] ?? null) ? $proposal['shape_rules'] : [],
            expectedAcceptanceCriteriaTemplate: array_values(array_map('strval', (array) ($proposal['expected_acceptance_criteria_template'] ?? []))),
            defaultRequiredEvidenceIds: array_values(array_map('strval', (array) ($proposal['default_required_evidence_ids'] ?? []))),
            defaultAllowedFilesGlobs: array_values(array_map('strval', (array) ($proposal['default_allowed_files_globs'] ?? []))),
            approvedByOperatorToken: $operatorToken,
            approvedAtUnix: time(),
            sourceClusterFingerprint: (string) ($proposal['source_cluster_fingerprint'] ?? ''),
            version: $version,
        );
    }

    /**
     * @param  array<string,mixed>  $rules
     * @param  array<string,mixed>  $payload
     */
    private function shapeRulesMatch(array $rules, array $payload): bool
    {
        foreach ($rules as $key => $expected) {
            if (! array_key_exists((string) $key, $payload)) {
                return false;
            }
            $actual = $payload[(string) $key];
            if (is_array($expected)) {
                if (! is_array($actual) || $expected !== $actual) {
                    return false;
                }

                continue;
            }
            if ($actual !== $expected) {
                return false;
            }
        }

        return true;
    }
}
