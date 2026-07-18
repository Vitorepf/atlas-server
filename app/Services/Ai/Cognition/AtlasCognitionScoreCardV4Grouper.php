<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Collapses 73 ACOS subsystems into ~15 deep modules for scorecard v4.
 */
final class AtlasCognitionScoreCardV4Grouper
{
    public const SCHEMA_VERSION = 'atlas.cognition.scorecard.v4';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_BLOCKED = 'blocked';
    public const FIELD_ACRONYM = 'acronym';
    public const FIELD_NAME = 'name';
    public const FIELD_SUBSYSTEM_COUNT = 'subsystem_count';
    public const FIELD_CODE_STATUS = 'code_status';
    public const FIELD_DOC_STATUS = 'doc_status';
    public const FIELD_PIPELINE_STATUS = 'pipeline_status';
    public const FIELD_MEMBERS = 'members';
    public const FIELD_SERVICE_CLASSES = 'service_classes';
    public const FIELD_AEMOR = 'aemor';
    public const FIELD_ATLAS_DECIDE = 'atlas_decide';
    public const FIELD_AUCRI = 'aucri';
    public const FIELD_AUTONOMY = 'autonomy';
    public const FIELD_BOUNDARY = 'boundary';
    public const FIELD_COGNITION = 'cognition';
    public const FIELD_COGNITIVE_IMMUNE = 'cognitive_immune';
    public const FIELD_COMPOUNDING = 'compounding';
    public const FIELD_CONTEXT_CACHE = 'context_cache';
    public const FIELD_CONTEXT_INTELLIGENCE = 'context_intelligence';
    public const FIELD_CONTEXT_QUALITY = 'context_quality';
    public const FIELD_CROSS_DOMAIN = 'cross_domain';
    public const FIELD_SUPPLEMENTAL_COUNT = 'supplemental_count';
    public const FIELD_EVIDENCE = 'evidence';

    /** @var list<string> */
    public const CONSUMER_GROUPS = [
        'self_improvement',
        'self_construction',
        'cartography',
        'programming',
        'research_domain',
    ];

    /**
     * @param  list<array<string, mixed>>  $subsystems
     * @return list<array<string, mixed>>
     */
    public function group(array $subsystems): array
    {
        return $this->groupRows($subsystems, consumers: false);
    }

    /**
     * Consumers remain visible for integration readiness without being counted
     * as modules inside the ACOS cognitive boundary.
     *
     * @param  list<array<string, mixed>>  $subsystems
     * @return list<array<string, mixed>>
     */
    public function groupConsumers(array $subsystems): array
    {
        return $this->groupRows($subsystems, consumers: true);
    }

    /**
     * @param  list<array<string, mixed>>  $subsystems
     * @return list<array<string, mixed>>
     */
    private function groupRows(array $subsystems, bool $consumers): array
    {
        $buckets = [];
        foreach ($subsystems as $row) {
            $group = (AiValueNormalizer::trimmedStringOrNull($row['group'] ?? null) ?? self::STATUS_UNKNOWN);
            $isConsumer = in_array($group, self::CONSUMER_GROUPS, true);
            if ($isConsumer !== $consumers) {
                continue;
            }

            $module = $this->moduleKey($group);
            $buckets[$module][self::FIELD_ACRONYM] ??= $module;
            $buckets[$module][self::FIELD_NAME] ??= $this->moduleName($module);
            $buckets[$module][self::FIELD_SUBSYSTEM_COUNT] = ($buckets[$module][self::FIELD_SUBSYSTEM_COUNT] ?? 0) + 1;
            foreach (['code_status', 'doc_status', 'pipeline_status'] as $dim) {
                $buckets[$module][$dim][] = (AiValueNormalizer::trimmedStringOrNull($row[$dim] ?? null) ?? self::STATUS_BLOCKED);
            }
            $buckets[$module][self::FIELD_MEMBERS][] = (AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_ACRONYM] ?? null) ?? '');
            $serviceClass = AiValueNormalizer::trimmedStringOrNull($row['service_class'] ?? null) ?? '';
            if ($serviceClass !== '') {
                $buckets[$module][self::FIELD_SERVICE_CLASSES][] = $serviceClass;
            }
            if (($row['supplemental'] ?? false) === true) {
                $buckets[$module][self::FIELD_SUPPLEMENTAL_COUNT] = ($buckets[$module][self::FIELD_SUPPLEMENTAL_COUNT] ?? 0) + 1;
            }
        }

        $modules = [];
        foreach ($buckets as $key => $bucket) {
            $modules[] = [
                self::FIELD_ACRONYM => $key,
                self::FIELD_NAME => $bucket[self::FIELD_NAME],
                self::FIELD_SUBSYSTEM_COUNT => $bucket[self::FIELD_SUBSYSTEM_COUNT],
                self::FIELD_CODE_STATUS => $this->rollup($bucket[self::FIELD_CODE_STATUS] ?? []),
                self::FIELD_DOC_STATUS => $this->rollup($bucket[self::FIELD_DOC_STATUS] ?? []),
                self::FIELD_PIPELINE_STATUS => $this->rollup($bucket[self::FIELD_PIPELINE_STATUS] ?? []),
                self::FIELD_MEMBERS => $bucket[self::FIELD_MEMBERS] ?? [],
                self::FIELD_SERVICE_CLASSES => array_values(array_unique($bucket[self::FIELD_SERVICE_CLASSES] ?? [])),
                self::FIELD_SUPPLEMENTAL_COUNT => (int) (AiValueNormalizer::finiteFloatOrNull($bucket[self::FIELD_SUPPLEMENTAL_COUNT] ?? null) ?? 0),
                self::FIELD_BOUNDARY => $consumers ? 'consumer' : 'acos',
            ];
        }

        usort($modules, fn (array $a, array $b): int => strcmp(AiValueNormalizer::trimmedScalarStringOrNull($a[self::FIELD_ACRONYM] ?? null) ?? '', AiValueNormalizer::trimmedScalarStringOrNull($b[self::FIELD_ACRONYM] ?? null) ?? ''));

        return $modules;
    }

    private function moduleKey(string $group): string
    {
        return match ($group) {
            self::FIELD_COGNITIVE_IMMUNE => 'IMMUNE',
            'memory_core' => 'MEMORY',
            self::FIELD_AUCRI => 'CONTEXT',
            'self_improvement', 'self_construction', 'cartography', 'programming', 'research_domain' => 'CONSUMERS',
            'governance' => 'GOVERNANCE',
            self::FIELD_ATLAS_DECIDE => 'DECIDE',
            self::FIELD_COMPOUNDING => 'COMPOUND',
            'reality', self::FIELD_CROSS_DOMAIN => 'REALITY',
            'teos' => 'TEOS',
            self::FIELD_COGNITION => 'COGNITION',
            self::FIELD_AUTONOMY => 'AUTONOMY',
            'patamar4', 'patamar_4', 'integration' => 'PATAMAR4',
            self::FIELD_CONTEXT_CACHE => 'CONTEXT-CACHE',
            self::FIELD_CONTEXT_INTELLIGENCE => 'CONTEXT-INTELLIGENCE',
            'persistent_context' => 'PERSISTENT-CONTEXT',
            self::FIELD_AEMOR => 'AEMOR',
            'long_horizon' => 'LONG-HORIZON',
            'verified_context' => 'VERIFIED-CONTEXT',
            self::FIELD_CONTEXT_QUALITY => 'CONTEXT-QUALITY',
            'open_brain' => 'OPEN-BRAIN',
            self::FIELD_EVIDENCE => 'EVIDENCE',
            default => 'OTHER',
        };
    }

    private function moduleName(string $key): string
    {
        return match ($key) {
            'IMMUNE' => 'Cognitive Immune G0-G8',
            'MEMORY' => 'Memory Core',
            'CONTEXT' => 'Context Runtime (AUCRI policies)',
            'CONSUMERS' => 'ACOS Consumers and Legacy Projections',
            'GOVERNANCE' => 'Constitutional Governance',
            'DECIDE' => 'Atlas Decide + Swarm',
            'COMPOUND' => 'Compounding',
            'REALITY' => 'Reality Graph + Cross-Domain',
            'TEOS' => 'TEOS Counterfactuals',
            'COGNITION' => 'Cognitive Function Atlas',
            'AUTONOMY' => 'Autonomous Reconciliation',
            'PATAMAR4' => 'Patamar 4 Integration',
            'CONTEXT-CACHE' => 'Context Cache Compiler Runtime',
            'CONTEXT-INTELLIGENCE' => 'Context Intelligence Engine',
            'PERSISTENT-CONTEXT' => 'Persistent Context Runtime',
            'AEMOR' => 'Execution Memory Outcome Runtime',
            'LONG-HORIZON' => 'TEOS-I1 Long-Horizon Intelligence',
            'VERIFIED-CONTEXT' => 'Verified Context Execution Loop',
            'CONTEXT-QUALITY' => 'Context Quality Certification Gate',
            'OPEN-BRAIN' => 'Open Brain Gateway',
            'EVIDENCE' => 'Evidence Ledger Memory Side',
            default => 'Other ACOS',
        };
    }

    /**
     * @param  list<string>  $statuses
     */
    private function rollup(array $statuses): string
    {
        if ($statuses === []) {
            return AtlasCognitionScoreCardService::STATUS_BLOCKED;
        }
        if (count(array_filter($statuses, fn (string $s): bool => $s !== AtlasCognitionScoreCardService::STATUS_READY)) === 0) {
            return AtlasCognitionScoreCardService::STATUS_READY;
        }
        if (in_array(AtlasCognitionScoreCardService::STATUS_BLOCKED, $statuses, true)) {
            return AtlasCognitionScoreCardService::STATUS_PARTIAL;
        }

        return AtlasCognitionScoreCardService::STATUS_PARTIAL;
    }
}
