<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Support\AiValueNormalizer;
use InvalidArgumentException;

/**
 * Atlas CognitiveFunctionAtlas — Patamar 4 · 4.2 (self-model read-only).
 *
 * NÃO é registry. NÃO é fonte de verdade. É lente / read-model puro sobre
 * AtlasCognitionScoreCardService::build(), projetando o estado dos 36
 * subsistemas ACOS em queries que consumidores autônomos precisam:
 *
 *   - selfModel()              — shape global do Atlas (group × maturity)
 *   - subsystemsByGroup($g)
 *   - gapsByGroup()
 *   - whichGroupOwns($acronym)
 *   - cognitiveShape()
 *   - isGroupOverloaded($g)
 *
 * Authority doc:
 *   docs/engineering-knowledge-base/atlas-cognitive-function-atlas.md
 *
 * Invariantes:
 *   - Nunca lista subsistemas hardcoded — tudo deriva de ScoreCardService.
 *   - Stateless (sem persistência).
 *   - Determinístico para mesmo estado do ScoreCard.
 */
class AtlasCognitiveFunctionAtlasService
{
    public const FIELD_GROUP = 'group';

    public const FIELD_SUBSYSTEMS = 'subsystems';

    public const FIELD_NON_READY_PIPELINE = 'non_ready_pipeline';

    public const FIELD_DECLARED_READY = 'declared_ready';

    public const FIELD_EVIDENCE_FILES_SEEN = 'evidence_files_seen';

    public const FIELD_STATUS = 'status';

    public const FIELD_SCHEMA_VERSION = 'schema_version';

    public const FIELD_FUNCTIONS = 'functions';

    public const FIELD_READINESS = 'readiness';

    public const FIELD_PIPELINE = 'pipeline';


    public const SELF_MODEL_SCHEMA = 'atlas.cognitive_function_atlas.self_model.v1';

    public const GROUP_SUMMARY_SCHEMA = 'atlas.cognitive_function_atlas.group_summary.v1';

    public const OVERLOAD_DEFAULT_THRESHOLD = 8;

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_UNKNOWN = 'unknown';

    public function __construct(
        private readonly AtlasCognitionScoreCardService $scoreCard,
        private readonly AtlasConstitutionalKernelService $kernel,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function selfModel(): array
    {
        $scorecard = $this->scoreCard->build();
        $subs = AiValueNormalizer::arrayOrEmpty($scorecard[self::FIELD_SUBSYSTEMS] ?? null);

        $groups = $this->extractGroups($subs);
        $shape = $this->buildShape($subs, $groups);
        $gaps = $this->buildGaps($shape);

        return [
            self::FIELD_SCHEMA_VERSION => self::SELF_MODEL_SCHEMA,
            'generated_at' => $scorecard['generated_at'] ?? gmdate('c'),
            'subsystem_count' => count($subs),
            'group_count' => count($groups),
            'groups' => $groups,
            'shape' => $shape,
            'gaps' => $gaps,
            'overall_score' => $scorecard['score'] ?? null,
            'kernel_hash' => $this->kernel->kernelHash(),
        ];
    }

    /**
     * @return list<string>
     */
    public function groupTaxonomy(): array
    {
        return $this->extractGroups(AiValueNormalizer::arrayOrEmpty($this->scoreCard->build()[self::FIELD_SUBSYSTEMS] ?? null));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function subsystemsByGroup(string $group): array
    {
        if ($group === '') {
            throw new InvalidArgumentException('group must be non-empty.');
        }
        $subs = AiValueNormalizer::arrayOrEmpty($this->scoreCard->build()[self::FIELD_SUBSYSTEMS] ?? null);
        $out = [];
        foreach ($subs as $s) {
            if (($s[self::FIELD_GROUP] ?? null) === $group) {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * @return list<array{group:string,non_ready_pipeline:int}>
     */
    public function gapsByGroup(): array
    {
        $subs = AiValueNormalizer::arrayOrEmpty($this->scoreCard->build()[self::FIELD_SUBSYSTEMS] ?? null);
        $tally = [];
        foreach ($subs as $s) {
            $g = (AiValueNormalizer::trimmedStringOrNull($s[self::FIELD_GROUP] ?? null) ?? self::STATUS_UNKNOWN);
            $pipeline = (AiValueNormalizer::trimmedStringOrNull($s['pipeline_status'] ?? null) ?? self::STATUS_UNKNOWN);
            if (! isset($tally[$g])) {
                $tally[$g] = 0;
            }
            if ($pipeline !== self::STATUS_READY) {
                $tally[$g]++;
            }
        }
        $out = [];
        foreach ($tally as $g => $n) {
            $out[] = [self::FIELD_GROUP => $g, self::FIELD_NON_READY_PIPELINE => $n];
        }
        usort($out, static fn ($a, $b): int => $b[self::FIELD_NON_READY_PIPELINE] <=> $a[self::FIELD_NON_READY_PIPELINE]);

        return $out;
    }

    public function whichGroupOwns(string $acronym): ?string
    {
        if ($acronym === '') {
            return null;
        }
        foreach (AiValueNormalizer::arrayOrEmpty($this->scoreCard->build()[self::FIELD_SUBSYSTEMS] ?? null) as $s) {
            if (($s['acronym'] ?? null) === $acronym) {
                return (AiValueNormalizer::trimmedStringOrNull($s[self::FIELD_GROUP] ?? null) ?? '');
            }
        }

        return null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function cognitiveShape(): array
    {
        $subs = AiValueNormalizer::arrayOrEmpty($this->scoreCard->build()[self::FIELD_SUBSYSTEMS] ?? null);
        $groups = $this->extractGroups($subs);

        return $this->buildShape($subs, $groups);
    }

    public function isGroupOverloaded(string $group, int $threshold = self::OVERLOAD_DEFAULT_THRESHOLD): bool
    {
        if ($threshold < 1) {
            throw new InvalidArgumentException('threshold must be >= 1.');
        }
        $count = count($this->subsystemsByGroup($group));

        return $count >= $threshold;
    }

    /**
     * Probe runtime evidence (append-only JSONL existence + size) per subsystem
     * group to surface groups where declared `pipeline_status=ready` may not
     * be backed by real activity yet. Returns advisory rows only — does NOT
     * mutate scorecard. Operator decides if this signals a real gap.
     *
     * @return list<array{group:string, declared_ready:int, evidence_files_seen:int, evidence_files_empty:int}>
     */
    public function runtimeEvidenceByGroup(?string $storageBase = null): array
    {
        $base = $storageBase ?? (function_exists('storage_path') ? storage_path('atlas') : sys_get_temp_dir().'/atlas');
        // Heuristic mapping of group → expected JSONL roots under storage/atlas/.
        $groupRoots = [
            'cognitive_immune' => ['aemor', 'cognitive_immune'],
            'memory_core' => ['memory'],
            'aucri' => ['akif'],
            'self_improvement' => ['self_improvement'],
            'atlas_decide' => ['atlas_decide', 'swarm'],
            'self_construction' => ['self_construction'],
            'reality' => ['aurg'],
            'cross_domain' => ['cross_domain'],
            'teos' => ['teos_i3', 'teos_i4'],
            'governance' => ['governance'],
            'autonomy' => ['reconciliation'],
            'cognition' => [],
            'compounding' => ['compounding'],
        ];

        $subs = AiValueNormalizer::arrayOrEmpty($this->scoreCard->build()[self::FIELD_SUBSYSTEMS] ?? null);
        $byGroup = [];
        foreach ($subs as $s) {
            $g = (AiValueNormalizer::trimmedStringOrNull($s[self::FIELD_GROUP] ?? null) ?? '');
            if ($g === '') {
                continue;
            }
            $byGroup[$g] = $byGroup[$g] ?? [self::FIELD_DECLARED_READY => 0, self::FIELD_EVIDENCE_FILES_SEEN => 0, 'evidence_files_empty' => 0];
            if (($s['pipeline_status'] ?? '') === self::STATUS_READY) {
                $byGroup[$g][self::FIELD_DECLARED_READY]++;
            }
        }

        foreach ($byGroup as $g => &$row) {
            $roots = $groupRoots[$g] ?? [];
            foreach ($roots as $r) {
                $dir = $base.DIRECTORY_SEPARATOR.$r;
                if (! is_dir($dir)) {
                    continue;
                }
                foreach (glob($dir.'/*.jsonl') ?: [] as $f) {
                    $row[self::FIELD_EVIDENCE_FILES_SEEN]++;
                    if (filesize($f) === 0) {
                        $row['evidence_files_empty']++;
                    }
                }
            }
        }
        unset($row);

        $out = [];
        foreach ($byGroup as $g => $row) {
            $out[] = [
                self::FIELD_GROUP => $g,
                self::FIELD_DECLARED_READY => $row[self::FIELD_DECLARED_READY],
                self::FIELD_EVIDENCE_FILES_SEEN => $row[self::FIELD_EVIDENCE_FILES_SEEN],
                'evidence_files_empty' => $row['evidence_files_empty'],
            ];
        }
        // Sort: groups with most empty/missing evidence first (advisory gaps).
        usort($out, static function ($a, $b): int {
            $a_lack = $a[self::FIELD_DECLARED_READY] - $a[self::FIELD_EVIDENCE_FILES_SEEN];
            $b_lack = $b[self::FIELD_DECLARED_READY] - $b[self::FIELD_EVIDENCE_FILES_SEEN];

            return $b_lack <=> $a_lack;
        });

        return $out;
    }

    // ---------- internals ----------

    /**
     * @param  list<array<string,mixed>>  $subs
     * @return list<string>
     */
    private function extractGroups(array $subs): array
    {
        $set = [];
        foreach ($subs as $s) {
            $g = (AiValueNormalizer::trimmedStringOrNull($s[self::FIELD_GROUP] ?? null) ?? '');
            if ($g !== '') {
                $set[$g] = true;
            }
        }
        $out = array_keys($set);
        sort($out);

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $subs
     * @param  list<string>  $groups
     * @return list<array<string,mixed>>
     */
    private function buildShape(array $subs, array $groups): array
    {
        $shape = [];
        foreach ($groups as $g) {
            $total = 0;
            $codeReady = 0;
            $docReady = 0;
            $pipelineReady = 0;
            $pipelinePartial = 0;
            $pipelineBuilding = 0;
            $servicePresent = 0;
            foreach ($subs as $s) {
                if (($s[self::FIELD_GROUP] ?? null) !== $g) {
                    continue;
                }
                $total++;
                if (($s['code_status'] ?? '') === self::STATUS_READY) {
                    $codeReady++;
                    $servicePresent++;
                }
                if (($s['doc_status'] ?? '') === self::STATUS_READY) {
                    $docReady++;
                }
                $pipeline = (AiValueNormalizer::trimmedStringOrNull($s['pipeline_status'] ?? null) ?? '');
                if ($pipeline === self::STATUS_READY) {
                    $pipelineReady++;
                } elseif ($pipeline === self::STATUS_PARTIAL) {
                    $pipelinePartial++;
                } else {
                    $pipelineBuilding++;
                }
            }
            $shape[] = [
                self::FIELD_SCHEMA_VERSION => self::GROUP_SUMMARY_SCHEMA,
                self::FIELD_GROUP => $g,
                'total' => $total,
                'code_ready' => $codeReady,
                'doc_ready' => $docReady,
                'pipeline_ready' => $pipelineReady,
                'pipeline_partial' => $pipelinePartial,
                'pipeline_building' => $pipelineBuilding,
                'service_present' => $servicePresent,
            ];
        }

        return $shape;
    }

    /**
     * @param  list<array<string,mixed>>  $shape
     * @return list<array{group:string,non_ready_pipeline:int}>
     */
    private function buildGaps(array $shape): array
    {
        $out = [];
        foreach ($shape as $row) {
            $nonReady = (int) (AiValueNormalizer::finiteFloatOrNull($row['pipeline_partial'] ?? null) ?? 0) + (int) (AiValueNormalizer::finiteFloatOrNull($row['pipeline_building'] ?? null) ?? 0);
            if ($nonReady > 0) {
                $out[] = [self::FIELD_GROUP => AiValueNormalizer::trimmedScalarStringOrNull($row[self::FIELD_GROUP] ?? null) ?? '', self::FIELD_NON_READY_PIPELINE => $nonReady];
            }
        }
        usort($out, static fn ($a, $b): int => $b[self::FIELD_NON_READY_PIPELINE] <=> $a[self::FIELD_NON_READY_PIPELINE]);

        return $out;
    }
}
