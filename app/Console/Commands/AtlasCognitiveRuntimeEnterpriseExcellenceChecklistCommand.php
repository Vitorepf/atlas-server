<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveRuntimeEnterpriseExcellenceChecklistService as Checklist;
use Illuminate\Console\Command;
use Throwable;

/**
 * Exercises the Cognitive Runtime Enterprise Excellence Checklist runtime:
 * scores the ten cognitive-runtime areas on their three documented levels
 * (baseline / atlas_plus / proof) and applies the Superation Rule — Atlas reaches
 * enterprise only when all ten areas are proven AND none of evidence / review /
 * privacy / replay is skipped.
 *
 * @see docs/engineering-knowledge-base/cognitive-runtime/enterprise-excellence-checklist.md
 */
class AtlasCognitiveRuntimeEnterpriseExcellenceChecklistCommand extends Command
{
    protected $signature = 'atlas:aaeos:cognitive-runtime-enterprise-excellence-checklist {--json}';

    protected $description = 'Score the 10 cognitive-runtime areas (baseline/atlas_plus/proof) and apply the Superation Rule for Atlas-enterprise readiness.';

    public function handle(Checklist $checklist): int
    {
        try {
            // Safe defaults: mirror the doc's own Executive Score, where the only
            // fully proven area today is the read model layer and the rest are
            // partial/planned. This produces a real, non-trivial verdict.
            $areas = [];
            foreach (Checklist::AREAS as $name) {
                $areas[$name] = [
                    Checklist::LEVEL_BASELINE => true,
                    Checklist::LEVEL_ATLAS_PLUS => true,
                    Checklist::LEVEL_PROOF => false,
                ];
            }

            $skippedPillars = [];
            foreach (Checklist::NON_SKIPPABLE_PILLARS as $pillar) {
                $skippedPillars[$pillar] = false;
            }

            $result = $checklist->evaluateSuperation($areas, $skippedPillars);

            $payload = $result + ['generated_at' => now()->toJSON()];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('areas ready', $result['ready_count'].'/'.$result['areas_total']);
            $this->components->twoColumnDetail('all areas ready', $result['all_areas_ready'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('pillars honoured', $result['pillars_honoured'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('verdict', $result['verdict']);
            $this->components->twoColumnDetail('atlas enterprise', $result['is_atlas_enterprise'] ? 'yes' : 'no');

            if ($result['areas_not_ready'] !== []) {
                $this->warn('Not yet enterprise-ready: '.implode(', ', $result['areas_not_ready']));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $envelope = [
                'schema' => Checklist::SCHEMA_SUPERATION,
                'ok' => false,
                'error' => $e->getMessage(),
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }
    }
}
