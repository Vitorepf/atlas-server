<?php

namespace App\Services\Ai\AutonomousEngineering\WorldModel;

use App\Services\Ai\AutonomousEngineering\AutonomousEngineeringHash;

/**
 * Structured query for {@see WorldModelGraphRanker}. Holds textual seeds and
 * graph anchors (file paths, flows, capabilities, risks) plus task posture.
 *
 * Field shape stays small and deterministic so callers can replay rankings
 * and so the resulting JSON receipt is bit-stable.
 */
final class WorldModelRankingQuery
{
    public const SCHEMA = 'atlas.ai.codebase_world_model.ranking_query.v1';

    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    /**
     * @param  array<int,string>  $textualSeeds
     * @param  array<int,string>  $targetFiles
     * @param  array<int,string>  $targetFlows
     * @param  array<int,string>  $targetCapabilities
     * @param  array<int,string>  $targetRisks
     */
    public function __construct(
        public readonly array $textualSeeds = [],
        public readonly array $targetFiles = [],
        public readonly array $targetFlows = [],
        public readonly array $targetCapabilities = [],
        public readonly array $targetRisks = [],
        public readonly string $taskRiskLevel = 'low',
        public readonly bool $boostDocs = false,
        public readonly bool $boostTests = false,
        public readonly ?string $worldModelId = null,
        public readonly int $maxResults = 20,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $risk = strtolower((string) ($payload['task_risk_level'] ?? 'low'));
        if (! in_array($risk, self::RISK_LEVELS, true)) {
            $risk = 'low';
        }

        return new self(
            textualSeeds: self::cleanList($payload['textual_seeds'] ?? []),
            targetFiles: self::cleanList($payload['target_files'] ?? []),
            targetFlows: self::cleanList($payload['target_flows'] ?? []),
            targetCapabilities: self::cleanList($payload['target_capabilities'] ?? []),
            targetRisks: self::cleanList($payload['target_risks'] ?? []),
            taskRiskLevel: $risk,
            boostDocs: (bool) ($payload['boost_docs'] ?? false),
            boostTests: (bool) ($payload['boost_tests'] ?? false),
            worldModelId: isset($payload['world_model_id']) && is_string($payload['world_model_id'])
                ? $payload['world_model_id']
                : null,
            maxResults: max(1, min(200, (int) ($payload['max_results'] ?? 20))),
        );
    }

    public function isRiskElevated(): bool
    {
        return in_array($this->taskRiskLevel, ['high', 'critical'], true);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'world_model_id' => $this->worldModelId,
            'textual_seeds' => $this->textualSeeds,
            'target_files' => $this->targetFiles,
            'target_flows' => $this->targetFlows,
            'target_capabilities' => $this->targetCapabilities,
            'target_risks' => $this->targetRisks,
            'task_risk_level' => $this->taskRiskLevel,
            'boost_docs' => $this->boostDocs,
            'boost_tests' => $this->boostTests,
            'max_results' => $this->maxResults,
        ];
    }

    /**
     * Deterministic signature: same logical query → same hash.
     */
    public function signature(): string
    {
        return AutonomousEngineeringHash::make([
            'schema' => self::SCHEMA,
            'textual_seeds' => $this->sortedUnique($this->textualSeeds),
            'target_files' => $this->sortedUnique($this->targetFiles),
            'target_flows' => $this->sortedUnique($this->targetFlows),
            'target_capabilities' => $this->sortedUnique($this->targetCapabilities),
            'target_risks' => $this->sortedUnique($this->targetRisks),
            'task_risk_level' => $this->taskRiskLevel,
            'boost_docs' => $this->boostDocs,
            'boost_tests' => $this->boostTests,
            'max_results' => $this->maxResults,
        ]);
    }

    /**
     * @param  array<int,string>  $values
     * @return array<int,string>
     */
    private function sortedUnique(array $values): array
    {
        $sorted = array_values(array_unique($values));
        sort($sorted);

        return $sorted;
    }

    /**
     * @return array<int,string>
     */
    private static function cleanList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $clean = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $trim = trim($item);
            if ($trim === '') {
                continue;
            }
            $clean[] = strtolower($trim);
        }

        return array_values(array_unique($clean));
    }
}
