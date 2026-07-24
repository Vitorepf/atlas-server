<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Hermes\Mesh\HermesDoctorPreflight;
use App\Services\Ai\Hermes\Mesh\HermesSessionEvidenceImporter;
use Illuminate\Console\Command;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only operational surface for the Hermes Executive Runtime.
 *
 * `preflight` runs the read-only `hermes --version|doctor|status` and turns them
 * into sealed health evidence before a critical mesh dispatch — always safe,
 * degrades to healthy=false when the binary is offline. `sessions` is the
 * sovereign sessions->evidence path: it is FAIL-CLOSED (refuses to even call the
 * binary unless providers.hermes_cli.session_evidence_policy === 'atlas_adapter')
 * and only ever emits hash-only EVIDENCE CANDIDATES — never promoted memory.
 * Auto-discovered from app/Console/Commands.
 *
 * @see docs/engineering-knowledge-base/atlas-hermes-executive-mesh.md
 */
class AtlasHermesOpsCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:hermes:ops
        {action=preflight : preflight|sessions}
        {--json : Emit JSON}';

    protected $description = 'Read-only Hermes ops: preflight (version/doctor/status -> sealed health evidence) and sessions (sessions list -> hash-only evidence candidates; fail-closed unless session_evidence_policy=atlas_adapter).';

    public function handle(HermesDoctorPreflight $preflight, HermesSessionEvidenceImporter $importer): int
    {
        return $this->action() === 'sessions'
            ? $this->handleSessions($importer)
            : $this->handlePreflight($preflight);
    }

    private function handlePreflight(HermesDoctorPreflight $preflight): int
    {
        $evidence = $preflight->assess(
            $this->capture(['--version']),
            $this->capture(['doctor']),
            $this->capture(['status']),
        );

        $this->emit(['action' => 'preflight', 'evidence' => $evidence]);

        return self::SUCCESS;
    }

    private function handleSessions(HermesSessionEvidenceImporter $importer): int
    {
        $enabled = config('atlas.ai.providers.hermes_cli.session_evidence_policy') === 'atlas_adapter';
        $policy = ['enabled' => $enabled];

        // Fail-closed: do not even invoke the binary when the policy is off.
        $listing = $enabled ? $this->captureSessions() : [];

        $result = $importer->import($listing, $policy);

        $this->emit(['action' => 'sessions', 'import' => $result]);

        return self::SUCCESS;
    }

    /**
     * Read-only, bounded, degrade-safe capture of a hermes subcommand's stdout.
     *
     * @param  array<int,string>  $args
     */
    private function capture(array $args): string
    {
        $binary = (string) config('atlas.ai.providers.hermes_cli.binary', 'hermes');

        try {
            $process = new Process(array_merge([$binary], $args), null, $this->env(), null, 15.0);
            $process->run();

            return (string) $process->getOutput();
        } catch (ProcessException) {
            return '';
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function captureSessions(): array
    {
        $raw = $this->capture(['sessions', 'list', '--json']);
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            // Accept both a bare list and a {"sessions":[...]} wrapper.
            if (isset($decoded['sessions']) && is_array($decoded['sessions'])) {
                return array_values(array_filter($decoded['sessions'], 'is_array'));
            }

            return array_values(array_filter($decoded, 'is_array'));
        }

        return [];
    }

    /**
     * @return array<string,string>
     */
    private function env(): array
    {
        $env = [];
        $home = config('atlas.ai.providers.hermes_cli.hermes_home');
        if (is_string($home) && trim($home) !== '') {
            $env['HERMES_HOME'] = trim($home);
        }

        return $env;
    }

    private function action(): string
    {
        $action = strtolower(trim((string) $this->argument('action')));

        return in_array($action, ['preflight', 'sessions'], true) ? $action : 'preflight';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return;
        }

        foreach ($payload as $key => $value) {
            if (is_scalar($value)) {
                $this->components->twoColumnDetail((string) $key, (string) $value);
            }
        }
        $this->line(json_encode($payload['evidence'] ?? $payload['import'] ?? [], JSON_PRETTY_PRINT) ?: '{}');
    }
}
