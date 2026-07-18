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
    public const FIELD_AEMOR_2 = 'AEMOR';
    public const FIELD_AUTONOMY_2 = 'AUTONOMY';
    public const FIELD_COGNITION_2 = 'COGNITION';
    public const FIELD_COMPOUND = 'COMPOUND';
    public const FIELD_CONSUMERS = 'CONSUMERS';
    public const FIELD_CONTEXT = 'CONTEXT';
    public const FIELD_DECIDE = 'DECIDE';
    public const FIELD_EVIDENCE_2 = 'EVIDENCE';
    public const FIELD_GOVERNANCE_2 = 'GOVERNANCE';
    public const FIELD_IMMUNE = 'IMMUNE';
    public const FIELD_MEMORY = 'MEMORY';
    public const FIELD_PATAMAR4_2 = 'PATAMAR4';
    public const FIELD_REALITY_2 = 'REALITY';
    public const FIELD_TEOS_2 = 'TEOS';
    public const FIELD_COMPOUNDING_2 = 'Compounding';
    public const FIELD_OTHER = 'OTHER';
    public const FIELD_CONTEXT_CACHE_2 = 'CONTEXT-CACHE';
    public const FIELD_CONTEXT_INTELLIGENCE_2 = 'CONTEXT-INTELLIGENCE';
    public const FIELD_CONTEXT_QUALITY_2 = 'CONTEXT-QUALITY';
    public const FIELD_LONG_HORIZON_2 = 'LONG-HORIZON';
    public const FIELD_OPEN_BRAIN_2 = 'OPEN-BRAIN';
    public const FIELD_PERSISTENT_CONTEXT_2 = 'PERSISTENT-CONTEXT';
    public const FIELD_VERIFIED_CONTEXT_2 = 'VERIFIED-CONTEXT';
    public const FIELD_ACOS_CONSUMERS_AND_LEGACY_PROJECTIONS = 'ACOS Consumers and Legacy Projections';
    public const FIELD_AUTONOMOUS_RECONCILIATION = 'Autonomous Reconciliation';
    public const FIELD_COGNITIVE_FUNCTION_ATLAS = 'Cognitive Function Atlas';
    public const FIELD_CONSTITUTIONAL_GOVERNANCE = 'Constitutional Governance';
    public const FIELD_CONTEXT_CACHE_COMPILER_RUNTIME = 'Context Cache Compiler Runtime';
    public const FIELD_CONTEXT_INTELLIGENCE_ENGINE = 'Context Intelligence Engine';
    public const FIELD_CONTEXT_QUALITY_CERTIFICATION_GATE = 'Context Quality Certification Gate';
    public const FIELD_EVIDENCE_LEDGER_MEMORY_SIDE = 'Evidence Ledger Memory Side';
    public const FIELD_EXECUTION_MEMORY_OUTCOME_RUNTIME = 'Execution Memory Outcome Runtime';
    public const FIELD_MEMORY_CORE_2 = 'Memory Core';

    /** @var list<string> */
    public const CONSUMER_GROUPS = [
        self::FIELD_SELF_IMPROVEMENT,
        self::FIELD_SELF_CONSTRUCTION,
        self::FIELD_CARTOGRAPHY,
        self::FIELD_PROGRAMMING,
        self::FIELD_RESEARCH_DOMAIN,
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
            foreach ([self::FIELD_CODE_STATUS, self::FIELD_DOC_STATUS, self::FIELD_PIPELINE_STATUS] as $dim) {
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
            self::FIELD_COGNITIVE_IMMUNE => self::FIELD_IMMUNE,
            self::FIELD_MEMORY_CORE => self::FIELD_MEMORY,
            self::FIELD_AUCRI => self::FIELD_CONTEXT,
            self::FIELD_SELF_IMPROVEMENT, self::FIELD_SELF_CONSTRUCTION, self::FIELD_CARTOGRAPHY, self::FIELD_PROGRAMMING, self::FIELD_RESEARCH_DOMAIN => self::FIELD_CONSUMERS,
            self::FIELD_GOVERNANCE => self::FIELD_GOVERNANCE_2,
            self::FIELD_ATLAS_DECIDE => self::FIELD_DECIDE,
            self::FIELD_COMPOUNDING => self::FIELD_COMPOUND,
            self::FIELD_REALITY, self::FIELD_CROSS_DOMAIN => self::FIELD_REALITY_2,
            self::FIELD_TEOS => self::FIELD_TEOS_2,
            self::FIELD_COGNITION => self::FIELD_COGNITION_2,
            self::FIELD_AUTONOMY => self::FIELD_AUTONOMY_2,
            self::FIELD_PATAMAR4, self::FIELD_PATAMAR_4, self::FIELD_INTEGRATION => self::FIELD_PATAMAR4_2,
            self::FIELD_CONTEXT_CACHE => self::FIELD_CONTEXT_CACHE_2,
            self::FIELD_CONTEXT_INTELLIGENCE => self::FIELD_CONTEXT_INTELLIGENCE_2,
            self::FIELD_PERSISTENT_CONTEXT => self::FIELD_PERSISTENT_CONTEXT_2,
            self::FIELD_AEMOR => self::FIELD_AEMOR_2,
            self::FIELD_LONG_HORIZON => self::FIELD_LONG_HORIZON_2,
            self::FIELD_VERIFIED_CONTEXT => self::FIELD_VERIFIED_CONTEXT_2,
            self::FIELD_CONTEXT_QUALITY => self::FIELD_CONTEXT_QUALITY_2,
            self::FIELD_OPEN_BRAIN => self::FIELD_OPEN_BRAIN_2,
            self::FIELD_EVIDENCE => self::FIELD_EVIDENCE_2,
            default => self::FIELD_OTHER,
        };
    }

    private function moduleName(string $key): string
    {
        return match ($key) {
            self::FIELD_IMMUNE => 'Cognitive Immune G0-G8',
            self::FIELD_MEMORY => self::FIELD_MEMORY_CORE_2,
            self::FIELD_CONTEXT => 'Context Runtime (AUCRI policies)',
            self::FIELD_CONSUMERS => self::FIELD_ACOS_CONSUMERS_AND_LEGACY_PROJECTIONS,
            self::FIELD_GOVERNANCE_2 => self::FIELD_CONSTITUTIONAL_GOVERNANCE,
            self::FIELD_DECIDE => 'Atlas Decide + Swarm',
            self::FIELD_COMPOUND => self::FIELD_COMPOUNDING_2,
            self::FIELD_REALITY_2 => 'Reality Graph + Cross-Domain',
            self::FIELD_TEOS_2 => 'TEOS Counterfactuals',
            self::FIELD_COGNITION_2 => self::FIELD_COGNITIVE_FUNCTION_ATLAS,
            self::FIELD_AUTONOMY_2 => self::FIELD_AUTONOMOUS_RECONCILIATION,
            self::FIELD_PATAMAR4_2 => 'Patamar 4 Integration',
            self::FIELD_CONTEXT_CACHE_2 => self::FIELD_CONTEXT_CACHE_COMPILER_RUNTIME,
            self::FIELD_CONTEXT_INTELLIGENCE_2 => self::FIELD_CONTEXT_INTELLIGENCE_ENGINE,
            self::FIELD_PERSISTENT_CONTEXT_2 => 'Persistent Context Runtime',
            self::FIELD_AEMOR_2 => self::FIELD_EXECUTION_MEMORY_OUTCOME_RUNTIME,
            self::FIELD_LONG_HORIZON_2 => 'TEOS-I1 Long-Horizon Intelligence',
            self::FIELD_VERIFIED_CONTEXT_2 => 'Verified Context Execution Loop',
            self::FIELD_CONTEXT_QUALITY_2 => self::FIELD_CONTEXT_QUALITY_CERTIFICATION_GATE,
            self::FIELD_OPEN_BRAIN_2 => 'Open Brain Gateway',
            self::FIELD_EVIDENCE_2 => self::FIELD_EVIDENCE_LEDGER_MEMORY_SIDE,
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
