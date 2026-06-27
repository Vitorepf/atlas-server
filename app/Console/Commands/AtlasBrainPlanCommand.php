<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainBriefHistogram;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCascadeRuleOutcomeAnalyzer;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintToPathTranslator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainNextPathSuggester;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathStarvationDetector;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionStream;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * BRAIN PLAN — single-purpose wrapper that pulls the L107 doctor surface and prints ONLY the
 * recommended_action block. Useful for "what should I do right now?" without scanning all findings.
 *
 * Pétreo (brain surface).
 */
final class AtlasBrainPlanCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:plan {--scope= : scope slug} {--reasons : include the input data the suggester used (starved paths + rollup)} {--json} {--raw}';

    /** @var string */
    protected $description = 'Single-purpose: prints the recommended next action from the doctor adviser.';

    public function handle(): int
    {
        $scopeOpt = trim((string) ($this->option('scope') ?? ''));
        $scope = (string) app(AtlasBrainScopeRegistry::class)->resolve($scopeOpt)['slug'];
        $args = $scopeOpt !== '' ? ['--scope' => $scopeOpt] : [];

        $buf = new BufferedOutput;
        Artisan::call('atlas:brain:health-doctor', $args + ['--json' => true, '--raw' => true], $buf);
        $doctor = json_decode(trim($buf->fetch()), true) ?: [];
        $rec = (array) ($doctor['recommended_action'] ?? []);

        // L130 suggestion — derived from starvation + path rollup.
        $stream = app(AtlasBrainReflectionStream::class);
        $tail50 = array_slice($stream->forScope($scope), -50);
        $brief = app(AtlasBrainBriefHistogram::class)->histogram($tail50);
        $tr = app(AtlasBrainHintToPathTranslator::class);
        $starv = app(AtlasBrainPathStarvationDetector::class)->detect($brief, $tr);
        $ledger = new AtlasBrainDoneSetLedger($scope, (string) config('atlas.brain.done_set_root'));
        $analyzer = app(AtlasBrainCascadeRuleOutcomeAnalyzer::class)->analyze($scope, $stream, $ledger);
        $rollup = [];
        foreach ($analyzer['by_hint'] as $r) {
            $p = $tr->pathFor((string) ($r['hint'] ?? ''));
            if ($p === null) {
                continue;
            }
            $rollup[$p] ??= ['path' => $p, 'served' => 0, 'total' => 0];
            $rollup[$p]['served'] += (int) ($r['served'] ?? 0);
            $rollup[$p]['total'] += (int) ($r['total'] ?? 0);
        }
        foreach ($rollup as &$rr) {
            $rr['served_rate_pct'] = $rr['total'] > 0 ? (int) round(($rr['served'] * 100) / $rr['total']) : 0;
        }
        unset($rr);
        $suggestion = app(AtlasBrainNextPathSuggester::class)->suggest($starv['starved'], array_values($rollup));

        $payload = [
            'scope' => $scope,
            'recommended' => $rec['recommended'] ?? null,
            'rationale' => (string) ($rec['rationale'] ?? ''),
            'suggested_next_path' => $suggestion,
        ];

        if ($this->option('reasons')) {
            $payload['suggester_inputs'] = [
                'starved_paths' => $starv['starved'],
                'path_rollup' => array_values($rollup),
            ];
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('raw')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($payload, $flags));

        return self::SUCCESS;
    }
}
