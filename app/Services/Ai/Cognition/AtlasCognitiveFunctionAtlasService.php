<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
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
    public const SELF_MODEL_SCHEMA = 'atlas.cognitive_function_atlas.self_model.v1';

    public const GROUP_SUMMARY_SCHEMA = 'atlas.cognitive_function_atlas.group_summary.v1';

    public const OVERLOAD_DEFAULT_THRESHOLD = 8;

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
        $subs = (array) ($scorecard['subsystems'] ?? []);

        $groups = $this->extractGroups($subs);
        $shape = $this->buildShape($subs, $groups);
        $gaps = $this->buildGaps($shape);

        return [
            'schema_version' => self::SELF_MODEL_SCHEMA,
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
        return $this->extractGroups((array) ($this->scoreCard->build()['subsystems'] ?? []));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function subsystemsByGroup(string $group): array
    {
        if ($group === '') {
            throw new InvalidArgumentException('group must be non-empty.');
        }
        $subs = (array) ($this->scoreCard->build()['subsystems'] ?? []);
        $out = [];
        foreach ($subs as $s) {
            if (($s['group'] ?? null) === $group) {
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
        $subs = (array) ($this->scoreCard->build()['subsystems'] ?? []);
        $tally = [];
        foreach ($subs as $s) {
            $g = (string) ($s['group'] ?? 'unknown');
            $pipeline = (string) ($s['pipeline_status'] ?? 'unknown');
            if (! isset($tally[$g])) {
                $tally[$g] = 0;
            }
            if ($pipeline !== 'ready') {
                $tally[$g]++;
            }
        }
        $out = [];
        foreach ($tally as $g => $n) {
            $out[] = ['group' => $g, 'non_ready_pipeline' => $n];
        }
        usort($out, static fn ($a, $b): int => $b['non_ready_pipeline'] <=> $a['non_ready_pipeline']);

        return $out;
    }

    public function whichGroupOwns(string $acronym): ?string
    {
        if ($acronym === '') {
            return null;
        }
        foreach ((array) ($this->scoreCard->build()['subsystems'] ?? []) as $s) {
            if (($s['acronym'] ?? null) === $acronym) {
                return (string) ($s['group'] ?? '');
            }
        }

        return null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function cognitiveShape(): array
    {
        $subs = (array) ($this->scoreCard->build()['subsystems'] ?? []);
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

    // ---------- internals ----------

    /**
     * @param  list<array<string,mixed>>  $subs
     * @return list<string>
     */
    private function extractGroups(array $subs): array
    {
        $set = [];
        foreach ($subs as $s) {
            $g = (string) ($s['group'] ?? '');
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
                if (($s['group'] ?? null) !== $g) {
                    continue;
                }
                $total++;
                if (($s['code_status'] ?? '') === 'ready') {
                    $codeReady++;
                    $servicePresent++;
                }
                if (($s['doc_status'] ?? '') === 'ready') {
                    $docReady++;
                }
                $pipeline = (string) ($s['pipeline_status'] ?? '');
                if ($pipeline === 'ready') {
                    $pipelineReady++;
                } elseif ($pipeline === 'partial') {
                    $pipelinePartial++;
                } else {
                    $pipelineBuilding++;
                }
            }
            $shape[] = [
                'schema_version' => self::GROUP_SUMMARY_SCHEMA,
                'group' => $g,
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
            $nonReady = (int) ($row['pipeline_partial'] ?? 0) + (int) ($row['pipeline_building'] ?? 0);
            if ($nonReady > 0) {
                $out[] = ['group' => (string) $row['group'], 'non_ready_pipeline' => $nonReady];
            }
        }
        usort($out, static fn ($a, $b): int => $b['non_ready_pipeline'] <=> $a['non_ready_pipeline']);

        return $out;
    }
}
