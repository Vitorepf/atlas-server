<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Console\Command;

/**
 * BRAIN QUEUED-TARGETS — read-only. Lists the target files inside a scope that ALREADY have a LIVE task packet
 * in the serving queue, so the external brain (the pasted decide-loop session) can ORIGINATE genuinely-new work
 * instead of re-proposing what another brain/session already enqueued ("onipresente no escopo": consider CODE
 * *and* TASKS). Complements the automated path's queueAwareDemote (AtlasLoopOriginationPipeline) for the
 * session-as-brain mode, where origination runs in the model, not in produce().
 *
 * LIVE = the non-terminal registry statuses; list(['status'=>X]) filters on the registry entry status (the
 * record's own task_packet.status is frozen at build-time 'planned' and is NOT trusted here). Read-only, no
 * enqueue, no provider call. Fail-OPEN to an empty list so a queue hiccup never blocks the brain.
 */
final class AtlasBrainQueuedTargetsCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:queued-targets {--scope=autonomous : the scope slug to filter targets to} {--json}';

    /** @var string */
    protected $description = 'List target files in a scope that already have a LIVE task packet (so the brain never re-proposes queued work).';

    private const LIVE_STATUSES = ['queued', 'claimable', 'claimed', 'lease_expired', 'released', 'blocked'];

    public function handle(): int
    {
        $scopeDef = app(AtlasBrainScopeRegistry::class)->resolve((string) $this->option('scope'));
        $slug = (string) $scopeDef['slug'];
        $roots = array_values((array) ($scopeDef['roots'] ?? []));

        $targetPackets = $this->liveTargetPackets();
        $targets = self::scopedTargets(array_keys($targetPackets), $roots);
        $collisions = self::collisionsIn($targetPackets, $roots);

        $payload = [
            'scope' => $slug,
            'live_statuses' => self::LIVE_STATUSES,
            'count' => count($targets),
            'targets' => $targets,
            'collision_count' => count($collisions),
            'collisions' => $collisions,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("brain queued-targets — scope '{$slug}': {$payload['count']} target(s) already have a LIVE task (do NOT re-propose):");
        foreach ($targets as $t) {
            $this->line('  - '.$t);
        }
        if ($targets === []) {
            $this->line('  (none — every scope target is free to originate)');
        }
        if ($collisions !== []) {
            $this->warn(count($collisions).' COLLISION(S) — a target has >1 LIVE packet (they will conflict at merge):');
            foreach ($collisions as $target => $ids) {
                $this->line('  ! '.$target.' <- '.implode(', ', $ids));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Map of normalized target path => list of LIVE task_packet_ids that target it. Fail-OPEN: [] on any
     * queue-read error. One packet's allowed_files (target + its test) each map to that packet's id, so a
     * target carried by >1 DISTINCT packet is a collision (two live packets editing the same file).
     *
     * @return array<string, list<string>>
     */
    private function liveTargetPackets(): array
    {
        $map = [];
        try {
            foreach (self::LIVE_STATUSES as $status) {
                foreach (AtlasTaskServingStack::queueRepo()->list(['status' => $status]) as $record) {
                    $id = (string) ($record['task_packet_id'] ?? '');
                    $scope = (array) (data_get($record, 'task_packet.normalized_scope') ?? []);
                    $paths = (array) ($scope['allowed_files'] ?? []);
                    if ($paths === []) {
                        $paths = (array) ($scope['scope_in'] ?? []);
                    }
                    foreach ($paths as $path) {
                        $key = self::normKey((string) $path);
                        if ($key !== '') {
                            $map[$key][] = $id;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $map;
    }

    /**
     * Pure collision detector: in-scope targets carried by MORE THAN ONE distinct live packet (they will
     * conflict at merge — the multi-session hazard the operator hit). Public+static for direct unit testing.
     *
     * @param  array<string, list<string>>  $targetPackets  normalized target => live packet ids
     * @param  list<string>  $roots
     * @return array<string, list<string>> in-scope colliding target => its distinct packet ids
     */
    public static function collisionsIn(array $targetPackets, array $roots): array
    {
        $collisions = [];
        foreach (self::scopedTargets(array_keys($targetPackets), $roots) as $target) {
            $ids = array_values(array_unique($targetPackets[$target] ?? []));
            if (count($ids) > 1) {
                $collisions[$target] = $ids;
            }
        }

        return $collisions;
    }

    /**
     * Pure scope filter: normalize every live target + root, keep targets under ANY scope root (or ALL when the
     * scope declares no roots), unique + sorted. Public+static so the scope-keying is directly unit-testable.
     *
     * @param  list<string>  $liveTargets
     * @param  list<string>  $roots
     * @return list<string>
     */
    public static function scopedTargets(array $liveTargets, array $roots): array
    {
        $rootKeys = [];
        foreach ($roots as $root) {
            $key = self::normKey((string) $root);
            if ($key !== '') {
                $rootKeys[] = $key;
            }
        }

        $kept = [];
        foreach ($liveTargets as $target) {
            $target = self::normKey((string) $target);
            if ($target === '') {
                continue;
            }
            if ($rootKeys === [] || self::underAnyRoot($target, $rootKeys)) {
                $kept[$target] = true;
            }
        }
        $kept = array_keys($kept);
        sort($kept);

        return $kept;
    }

    /** @param  list<string>  $roots */
    private static function underAnyRoot(string $target, array $roots): bool
    {
        foreach ($roots as $root) {
            if (str_starts_with($target, $root)) {
                return true;
            }
        }

        return false;
    }

    /** Canonical path key (mirrors AtlasLoopOriginationPipeline::normKey: leading slash / backslash / ./ / whitespace). */
    private static function normKey(string $s): string
    {
        $s = ltrim(str_replace('\\', '/', trim($s)), '/');
        if (str_starts_with($s, './')) {
            $s = substr($s, 2);
        }

        return ltrim($s, '/');
    }
}
