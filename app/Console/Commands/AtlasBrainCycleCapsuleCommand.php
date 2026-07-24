<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCycleCapsuleLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainInternalizationPipeline;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * CYCLE CAPSULE — the live operator/soak surface that WIRES the AtlasBrainCycleCapsule + Internalization
 * Pipeline organs into a runnable flow (they have no in-process consumer because the cycle path —
 * brain:next/seed — is pétreo and cannot be edited). Three verbs:
 *
 *   record      — append one external cycle as a replayable capsule (the muscle/operator calls this after a
 *                 cycle completes; --cycle is the cycle JSON, or - to read STDIN).
 *   internalize — replay the scope's capsules through the Internalization Pipeline → internal capability
 *                 candidates (skill|policy|wiring|metric|reflection). NEVER auto-promotes (requires_gate).
 *   list        — the recorded capsules for the scope.
 *
 * Read-only except `record` (append-only). This is how a 15-day external soak compounds into internal
 * capability candidates the operator can later gate.
 */
final class AtlasBrainCycleCapsuleCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:brain:cycle-capsule
        {action : record|internalize|list}
        {--scope=autonomous : scope slug}
        {--cycle= : cycle JSON for record (or - to read STDIN)}
        {--json}';

    /** @var string */
    protected $description = 'Record external cycles as replayable capsules and internalize them into gated capability candidates.';

    public function handle(): int
    {
        $scope = (string) (app(AtlasBrainScopeRegistry::class)->resolve((string) $this->option('scope'))['slug']);
        $ledger = new AtlasBrainCycleCapsuleLedger($scope, (string) config('atlas.brain.cycle_capsule_root'));
        $action = (string) $this->argument('action');

        $payload = match ($action) {
            'record' => $this->record($ledger, $scope),
            'internalize' => $this->internalize($ledger, $scope),
            'list' => ['scope' => $scope, 'count' => $ledger->count(), 'capsules' => $ledger->all()],
            default => ['status' => 'error', 'reason' => "unknown action '{$action}' (record|internalize|list)"],
        };

        $this->line($this->encode($payload));

        return ($payload['status'] ?? 'ok') === 'error' ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function record(AtlasBrainCycleCapsuleLedger $ledger, string $scope): array
    {
        $raw = (string) $this->option('cycle');
        if ($raw === '-') {
            $raw = (string) file_get_contents('php://stdin');
        }
        $cycle = json_decode($raw, true);
        if (! is_array($cycle)) {
            return ['status' => 'error', 'reason' => 'invalid --cycle JSON'];
        }
        $cycle['scope'] = $cycle['scope'] ?? $scope;
        $capsule = $ledger->record($cycle);
        if ($capsule === null) {
            return ['status' => 'error', 'reason' => 'unattributable cycle (no task_packet_id) — nothing recorded'];
        }

        return ['status' => 'ok', 'recorded' => true, 'scope' => $scope, 'capsule' => $capsule];
    }

    /** @return array<string,mixed> */
    private function internalize(AtlasBrainCycleCapsuleLedger $ledger, string $scope): array
    {
        $capsules = $ledger->all();
        $candidates = AtlasBrainInternalizationPipeline::candidatesFrom($capsules);

        return [
            'status' => 'ok',
            'scope' => $scope,
            'capsules_consumed' => count($capsules),
            'candidate_count' => count($candidates),
            'auto_promoted' => count(array_filter($candidates, static fn (array $c): bool => (bool) ($c['promoted'] ?? false))),
            'candidates' => $candidates,
        ];
    }
}
