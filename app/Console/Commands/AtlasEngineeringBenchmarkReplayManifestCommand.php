<?php

namespace App\Console\Commands;

use App\Models\AtlasEngineeringBenchmarkRun;
use App\Services\Engineering\EngineeringBenchmarkService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasEngineeringBenchmarkReplayManifestCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:engineering:benchmark:replay-manifest
        {run : Benchmark run id}
        {--json : Print machine-readable JSON}';

    protected $description = 'Read a persisted benchmark replay manifest after integrity verification.';

    public function handle(EngineeringBenchmarkService $benchmarks): int
    {
        $runId = trim((string) $this->argument('run'));
        $run = AtlasEngineeringBenchmarkRun::query()->find($runId);
        if (! $run) {
            $this->error("Benchmark run nao encontrado: {$runId}");

            return self::FAILURE;
        }

        $payload = $benchmarks->replayManifestPayload($run);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return ($payload['status'] ?? null) === 'available' ? self::SUCCESS : self::FAILURE;
        }

        $this->render($payload);

        return ($payload['status'] ?? null) === 'available' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): void
    {
        $summary = (array) ($payload['summary'] ?? []);
        $artifact = (array) ($payload['artifact'] ?? []);
        $integrity = (array) ($artifact['integrity'] ?? []);
        $finalPacket = (array) ($payload['final_packet'] ?? []);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Replay manifest</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Final packet', (string) ($finalPacket['status'] ?? '-'));
        $this->components->twoColumnDetail('Kind', (string) ($summary['kind'] ?? '-'));
        $this->components->twoColumnDetail('Packets', (string) ($summary['packet_count'] ?? 0));
        $this->components->twoColumnDetail('Manifest hash', (string) ($summary['manifest_hash'] ?? '-'));
        $this->components->twoColumnDetail('Artifact', (string) ($artifact['status'] ?? '-'));
        $this->components->twoColumnDetail('Integrity', (bool) ($integrity['hash_matches'] ?? false) ? 'passed' : 'failed');
        $this->components->twoColumnDetail('Replay command', (string) ($finalPacket['replay_command'] ?? '-'));

        if (($payload['status'] ?? null) !== 'available') {
            $this->warn('Replay manifest indisponivel: '.(string) ($payload['reason'] ?? 'unknown'));
        }
    }
}
