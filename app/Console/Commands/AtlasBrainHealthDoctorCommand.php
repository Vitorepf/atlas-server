<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionStream;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedQualityGate;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Illuminate\Console\Command;

/**
 * BRAIN HEALTH DOCTOR — first-aid checks for common stuck states. Read-only. Emits a list of
 * `{severity, code, advice}` findings. severity ∈ {critical, warn, info}. Empty findings list = healthy.
 *
 * The findings are derived from the same observable surface as brain:state, but framed as ACTIONABLE
 * diagnoses instead of raw counts. Useful when babysitting: 'critical: gate has 2 holes — originate a
 * fix' vs reading the state JSON and figuring it out yourself.
 *
 * Read-only by construction; no mutation, no origination, no comprehension build.
 */
final class AtlasBrainHealthDoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:health-doctor {--scope= : scope slug (default: configured default_scope)} {--json}';

    /** @var string */
    protected $description = 'First-aid checks over brain state — emits actionable findings (gate holes, dormant flags, dead memory, etc).';

    public function handle(): int
    {
        $scopeOpt = trim((string) ($this->option('scope') ?? ''));
        $scope = (string) app(AtlasBrainScopeRegistry::class)->resolve($scopeOpt)['slug'];

        $findings = [];

        // CRITICAL: gate regression.
        $inspectorHoles = count(app(AtlasBrainGateAdversarialAuditor::class)->audit(new AtlasTaskPacketQualityInspector)['holes']);
        $seedHoles = count(app(AtlasBrainSeedGateAdversarialAuditor::class)->audit(app(AtlasBrainSeedQualityGate::class))['holes']);
        if ($inspectorHoles > 0 || $seedHoles > 0) {
            $findings[] = ['severity' => 'critical', 'code' => 'gate_regression', 'advice' => "inspector holes={$inspectorHoles} / seed_gate holes={$seedHoles} — originate a fix for the failing audit attack(s) BEFORE any other leap"];
        }

        // CRITICAL: master switch off (brain is fully inert).
        if (! AtlasBrainMasterSwitch::enabled()) {
            $findings[] = ['severity' => 'warn', 'code' => 'master_switch_off', 'advice' => 'ATLAS_BRAIN_MASTER_ENABLED=false — brain:next/seed are no-ops; flip the env to arm'];
        }

        // WARN: rich payload flag dormant.
        $digestEnabled = (bool) config('atlas.brain.scope_signal_digest_enabled', false);
        if (! $digestEnabled) {
            $findings[] = ['severity' => 'info', 'code' => 'scope_signal_digest_dormant', 'advice' => 'atlas.brain.scope_signal_digest_enabled=false — the L1-L37 payload is not surfacing; arm to give the brain its full perception layer'];
        }

        // WARN: reflection enabled but stream empty (means recording is wired but stream is fresh).
        $reflectionEnabled = (bool) config('atlas.brain.reflection_enabled', false);
        if ($reflectionEnabled) {
            $reflectionCount = count(app(AtlasBrainReflectionStream::class)->forScope($scope));
            if ($reflectionCount === 0) {
                $findings[] = ['severity' => 'info', 'code' => 'reflection_empty', 'advice' => "reflection_enabled=true but scope '{$scope}' has 0 stored reflections — run brain:next at least once to populate the time series"];
            }
        }

        $this->line((string) json_encode([
            'scope' => $scope,
            'findings' => $findings,
            'status' => $findings === [] ? 'healthy' : 'has_findings',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
