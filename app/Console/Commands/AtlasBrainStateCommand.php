<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionStream;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedQualityGate;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Illuminate\Console\Command;

/**
 * READ-ONLY brain state inspector. Emits a small snapshot the operator (or a future cron/dashboard) can
 * pull without triggering origination — the heavy comprehension-model build only happens inside
 * `atlas:brain:next`. Cheap, idempotent, fail-open: errors degrade to zero counts rather than crash.
 *
 * Output shape:
 *   {brain_enabled, default_scope, scope: {slug, meta_harness},
 *    done_set: {recent_count, recent_served, recent_refused},
 *    reflection: {recent_count}}
 *
 * Purpose: with L1-L22 the brain accumulates a lot of state (done-set, reflection time series, gate
 * audit history). Today the only window into it is running brain:next, which mutates state. This
 * command gives a non-mutating health snapshot — useful when babysitting, scripting a dashboard, or
 * just confirming the master switch state without burning a comprehension build.
 */
final class AtlasBrainStateCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:state {--scope= : scope slug (default: configured default_scope)} {--json}';

    /** @var string */
    protected $description = 'READ-ONLY brain state snapshot: master switch, scope, done-set tail, reflection tail (no origination).';

    public function handle(): int
    {
        $scopeOpt = trim((string) ($this->option('scope') ?? ''));
        $scopeDef = app(AtlasBrainScopeRegistry::class)->resolve($scopeOpt);
        $scope = (string) $scopeDef['slug'];

        $ledger = new AtlasBrainDoneSetLedger($scope, (string) config('atlas.brain.done_set_root'));
        $recent = $ledger->recentCycles(50);
        $served = 0;
        $refused = 0;
        foreach ($recent as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status === 'served' || $status === 'seeded') {
                $served++;
            } elseif (in_array($status, ['refused', 'abstain', 'already_done', 'prepare_blocked', 'forbidden_target'], true)) {
                $refused++;
            }
        }

        $reflection = app(AtlasBrainReflectionStream::class);
        $reflectionCount = count($reflection->forScope($scope));
        // Parse the newest leverage_brief reflection (L14 time series) into a {hint, rationale} snapshot —
        // shows the last recommendation without running brain:next.
        $lastBrief = null;
        $newest = $reflection->recallTexts($scope, ['signals' => ['action_hint' => '']], 1);
        if ($newest !== []) {
            $text = (string) ($newest[0]['reflection'] ?? '');
            if (str_starts_with($text, 'leverage_brief: ')) {
                $rest = substr($text, strlen('leverage_brief: '));
                $cut = strpos($rest, ' — ');
                $lastBrief = [
                    'hint' => trim($cut === false ? $rest : substr($rest, 0, $cut)),
                    'rationale' => $cut === false ? '' : trim(substr($rest, $cut + strlen(' — '))),
                ];
            }
        }

        $payload = [
            'brain_enabled' => AtlasBrainMasterSwitch::enabled(),
            'default_scope' => (string) config('atlas.brain.default_scope', 'loop'),
            'scope' => [
                'slug' => $scope,
                'meta_harness' => (bool) $scopeDef['meta_harness'],
            ],
            'done_set' => [
                'recent_count' => count($recent),
                'recent_served' => $served,
                'recent_refused' => $refused,
            ],
            'reflection' => [
                'recent_count' => $reflectionCount,
            ],
            'last_brief' => $lastBrief,
            // GATE HEALTH — runtime adversarial audit hole counts (same as scope_signals.gate_health in
            // brain:next, computed here without origination). zero=airtight; non-zero=regression to fix.
            'gate_health' => [
                'inspector_holes' => count(app(AtlasBrainGateAdversarialAuditor::class)->audit(new AtlasTaskPacketQualityInspector)['holes']),
                'seed_gate_holes' => count(app(AtlasBrainSeedGateAdversarialAuditor::class)->audit(app(AtlasBrainSeedQualityGate::class))['holes']),
            ],
        ];

        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
