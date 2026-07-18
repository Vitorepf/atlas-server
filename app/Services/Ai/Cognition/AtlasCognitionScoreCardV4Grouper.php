<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Collapses 73 ACOS subsystems into ~15 deep modules for scorecard v4.
 */
final class AtlasCognitionScoreCardV4Grouper
{
    public const FIELD_GOVERNANCE = 'governance';
    public const FIELD_GROUP = 'group';
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
    public const FIELD_MEMORY_CORE = 'memory_core';
    public const FIELD_RESEARCH_DOMAIN = 'research_domain';
    public const FIELD_INTEGRATION = 'integration';
    public const FIELD_LONG_HORIZON = 'long_horizon';
    public const FIELD_OPEN_BRAIN = 'open_brain';
    public const FIELD_PERSISTENT_CONTEXT = 'persistent_context';
    public const FIELD_SERVICE_CLASS = 'service_class';
    public const FIELD_SUPPLEMENTAL = 'supplemental';
    public const FIELD_TEOS = 'teos';
    public const FIELD_VERIFIED_CONTEXT = 'verified_context';
    public const FIELD_CARTOGRAPHY = 'cartography';
    public const FIELD_CONSUMER = 'consumer';
    public const FIELD_PROGRAMMING = 'programming';
    public const FIELD_PATAMAR4 = 'patamar4';
    public const FIELD_PATAMAR_4 = 'patamar_4';
    public const FIELD_REALITY = 'reality';
    public const FIELD_SELF_CONSTRUCTION = 'self_construction';
    public const FIELD_SELF_IMPROVEMENT = 'self_improvement';
    public const FIELD_ACOS = 'acos';

    /** @var list<string> */
    public const CONSUMER_GROUPS = [
        'self_improvement',
        self::FIELD_SELF_CONSTRUCTION,
        self::FIELD_CARTOGRAPHY,
        self::FIELD_PROGRAMMING,
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
            $group = (AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_GROUP] ?? null) ?? self::STATUS_UNKNOWN);
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
            $serviceClass = AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_SERVICE_CLASS] ?? null) ?? '';
            if ($serviceClass !== '') {
                $buckets[$module][self::FIELD_SERVICE_CLASSES][] = $serviceClass;
            }
            if (($row[self::FIELD_SUPPLEMENTAL] ?? false) === true) {
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
                self::FIELD_BOUNDARY => $consumers ? self::FIELD_CONSUMER : self::FIELD_ACOS,
            ];
        }

        usort($modules, fn (array $a, array $b): int => strcmp(AiValueNormalizer::trimmedScalarStringOrNull($a[self::FIELD_ACRONYM] ?? null) ?? '', AiValueNormalizer::trimmedScalarStringOrNull($b[self::FIELD_ACRONYM] ?? null) ?? ''));

        return $modules;
    }

    private function moduleKey(string $group): string
    {
        return match ($group) {
            self::FIELD_COGNITIVE_IMMUNE => 'IMMUNE',
            self::FIELD_MEMORY_CORE => 'MEMORY',
            self::FIELD_AUCRI => 'CONTEXT',
            self::FIELD_SELF_IMPROVEMENT, self::FIELD_SELF_CONSTRUCTION, self::FIELD_CARTOGRAPHY, self::FIELD_PROGRAMMING, self::FIELD_RESEARCH_DOMAIN => 'CONSUMERS',
            self::FIELD_GOVERNANCE => 'GOVERNANCE',
            self::FIELD_ATLAS_DECIDE => 'DECIDE',
            self::FIELD_COMPOUNDING => 'COMPOUND',
            self::FIELD_REALITY, self::FIELD_CROSS_DOMAIN => 'REALITY',
            self::FIELD_TEOS => 'TEOS',
            self::FIELD_COGNITION => 'COGNITION',
            self::FIELD_AUTONOMY => 'AUTONOMY',
            self::FIELD_PATAMAR4, self::FIELD_PATAMAR_4, self::FIELD_INTEGRATION => 'PATAMAR4',
            self::FIELD_CONTEXT_CACHE => 'CONTEXT-CACHE',
            self::FIELD_CONTEXT_INTELLIGENCE => 'CONTEXT-INTELLIGENCE',
            self::FIELD_PERSISTENT_CONTEXT => 'PERSISTENT-CONTEXT',
            self::FIELD_AEMOR => 'AEMOR',
            self::FIELD_LONG_HORIZON => 'LONG-HORIZON',
            self::FIELD_VERIFIED_CONTEXT => 'VERIFIED-CONTEXT',
            self::FIELD_CONTEXT_QUALITY => 'CONTEXT-QUALITY',
            self::FIELD_OPEN_BRAIN => 'OPEN-BRAIN',
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
