<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedQualityGate;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * BRAIN AUDIT — one-shot consolidated read of the brain's observable surface for CI / dashboard /
 * end-of-cycle log scrape. Wraps `atlas:brain:state` + `atlas:brain:health-doctor` + the raw adversarial
 * auditor reports into a single payload. Read-only; no mutation; no comprehension build.
 *
 * Pétreo: brain entry point; réu never edits its own consolidated audit surface.
 */
final class AtlasBrainAuditCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:audit {--scope= : scope slug (default: configured default_scope)} {--json} {--raw : single-line JSON}';

    /** @var string */
    protected $description = 'One-shot consolidated brain observability: state + health-doctor findings + raw adversarial audits.';

    public function handle(): int
    {
        $scope = trim((string) ($this->option('scope') ?? ''));
        $scopeArg = $scope !== '' ? ['--scope' => $scope] : [];

        // Use dedicated BufferedOutput per sub-call so the audit command's own JSON emit isn't polluted
        // by the sub-commands' streams (Artisan::output() is shared and concatenates).
        $stateBuf = new BufferedOutput;
        Artisan::call('atlas:brain:state', $scopeArg + ['--json' => true], $stateBuf);
        $state = json_decode(trim($stateBuf->fetch()), true) ?: [];

        $doctorBuf = new BufferedOutput;
        Artisan::call('atlas:brain:health-doctor', $scopeArg + ['--json' => true], $doctorBuf);
        $doctor = json_decode(trim($doctorBuf->fetch()), true) ?: [];

        $inspector = app(AtlasBrainGateAdversarialAuditor::class)->audit(new AtlasTaskPacketQualityInspector);
        $seedGate = app(AtlasBrainSeedGateAdversarialAuditor::class)->audit(app(AtlasBrainSeedQualityGate::class));

        $totalHoles = count($inspector['holes']) + count($seedGate['holes']);
        $payload = [
            'gate_health_status' => $totalHoles === 0 ? 'airtight' : 'regression',
            'gate_health_total_holes' => $totalHoles,
            'state' => $state,
            'doctor' => $doctor,
            'adversarial' => [
                'inspector' => $inspector,
                'seed_gate' => $seedGate,
            ],
        ];

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('raw')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($payload, $flags));

        return $totalHoles === 0 ? self::SUCCESS : self::FAILURE;
    }
}
