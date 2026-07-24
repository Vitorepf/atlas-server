<?php

namespace App\Console\Commands;

use App\Services\Engineering\AtlasDocumentationRealitySystemService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasDocumentationRealityCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:documentation-reality
        {action=score : score|sources|blocks|evaluations|acceptance}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when blockers exist}';

    protected $description = 'Read-only ADRS source registry, block readiness and documentation reality score.';

    public function handle(AtlasDocumentationRealitySystemService $service): int
    {
        $payload = $service->report();
        $action = (string) $this->argument('action');

        if (! in_array($action, ['score', 'sources', 'blocks', 'evaluations', 'acceptance'], true)) {
            $this->error('Unknown action. Expected score, sources, blocks, evaluations or acceptance.');

            return self::FAILURE;
        }

        $output = match ($action) {
            'sources' => [
                'schema_version' => $payload['schema_version'],
                'status' => $payload['status'],
                'source_registry' => $payload['source_registry'],
                'blockers' => $payload['blockers'],
                'writes' => false,
                'generated_at' => $payload['generated_at'],
            ],
            'blocks' => [
                'schema_version' => $payload['schema_version'],
                'status' => $payload['status'],
                'summary' => $payload['summary'],
                'planes' => $payload['planes'],
                'blocks' => $payload['blocks'],
                'blockers' => $payload['blockers'],
                'writes' => false,
                'generated_at' => $payload['generated_at'],
            ],
            'evaluations' => [
                'schema_version' => $payload['schema_version'],
                'status' => $payload['status'],
                'summary' => $payload['summary'],
                'evaluations' => $payload['evaluations'],
                'readiness_matrix' => $payload['readiness_matrix'],
                'blockers' => $payload['blockers'],
                'writes' => false,
                'generated_at' => $payload['generated_at'],
            ],
            'acceptance' => [
                'schema_version' => $payload['schema_version'],
                'status' => $payload['status'],
                'summary' => $payload['summary'],
                'block_acceptance_matrix' => $payload['block_acceptance_matrix'],
                'blockers' => $payload['blockers'],
                'writes' => false,
                'generated_at' => $payload['generated_at'],
            ],
            default => $payload,
        };

        if ((bool) $this->option('json')) {
            $this->line($this->encode($output));

            return $this->exitCode($payload);
        }

        $this->components->twoColumnDetail('Atlas Documentation Reality', (string) $payload['status']);
        $this->components->twoColumnDetail('Implementation', (string) $payload['implementation_status']);
        $this->components->twoColumnDetail('Sources', data_get($payload, 'summary.source_present_count').'/'.data_get($payload, 'summary.source_count'));
        $this->components->twoColumnDetail('Blocks', data_get($payload, 'summary.block_count').'/'.data_get($payload, 'summary.expected_block_count'));
        $this->components->twoColumnDetail('Blocks with upgrade', (string) data_get($payload, 'summary.block_with_upgrade_count'));
        $this->components->twoColumnDetail('L4 integrated blocks', (string) data_get($payload, 'summary.integrated_runtime_block_count'));
        $this->components->twoColumnDetail('L3 read-only blocks', (string) data_get($payload, 'summary.read_only_foundation_block_count'));
        $this->components->twoColumnDetail('Blockers', (string) data_get($payload, 'summary.blocker_count'));

        if ($payload['blockers'] !== []) {
            $this->newLine();
            $this->warn('ADRS blockers found.');
            $this->table(
                ['reason', 'severity', 'target'],
                collect($payload['blockers'])->take(10)->map(static fn (array $blocker): array => [
                    $blocker['reason'] ?? '',
                    $blocker['severity'] ?? '',
                    $blocker['path'] ?? $blocker['block'] ?? '',
                ])->all(),
            );
        }

        return $this->exitCode($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCode(array $payload): int
    {
        return (bool) $this->option('strict') && $payload['status'] !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
