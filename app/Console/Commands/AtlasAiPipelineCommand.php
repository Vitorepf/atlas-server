<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Pipeline\KernelPipelineAuditService;
use App\Services\Ai\Kernel\Pipeline\PipelineInput;
use App\Services\Ai\Kernel\Pipeline\ScaffoldAtlasKernelPipeline;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAiPipelineCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:pipeline
        {text? : Input text to plan through the kernel pipeline contract}
        {--surface=atlas_cli : Surface id for audit metadata}
        {--tenant=atlas-single-tenant : Tenant id for audit metadata}
        {--operator=cli : Operator id for audit metadata}
        {--hint=* : Optional key=value hints}
        {--execute : Return contract stage results instead of plan only}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect the Atlas AI kernel pipeline contract without executing providers or runtime.';

    public function handle(ScaffoldAtlasKernelPipeline $pipeline, KernelPipelineAuditService $audit): int
    {
        $input = PipelineInput::fromArray([
            'text' => (string) ($this->argument('text') ?: 'atlas kernel pipeline inspection'),
            'surface_id' => (string) $this->option('surface'),
            'tenant_id' => (string) $this->option('tenant'),
            'operator_id' => (string) $this->option('operator'),
            'hints' => $this->hints(),
            'dry_run' => true,
        ]);

        if ((bool) $this->option('execute')) {
            $result = $pipeline->execute($input);
            $ledgerEvent = $audit->recordScaffoldExecution($result);

            $payload = [
                'schema_version' => 1,
                'status' => 'executed_scaffold',
                'pipeline' => $result->toArray(),
                'ledger_event' => $audit->eventPayload($ledgerEvent),
            ];
        } else {
            $payload = [
                'schema_version' => 1,
                'status' => 'planned_scaffold',
                'pipeline' => $pipeline->plan($input),
                'compliance' => $pipeline->complianceReport(),
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $pipelinePayload = (array) ($payload['pipeline'] ?? []);
        $compliance = (array) ($payload['compliance'] ?? data_get($pipelinePayload, 'compliance_report', []));

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Kernel Pipeline</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Pipeline', (string) ($pipelinePayload['pipeline_id'] ?? '-'));
        $this->components->twoColumnDetail('Dry run', data_getYesNo::format($pipelinePayload, 'dry_run', true));
        $this->components->twoColumnDetail('Provider execution', data_get($pipelinePayload, 'provider_execution_attempted', false) ? 'attempted' : 'disabled');
        $this->components->twoColumnDetail('Compliance', ($compliance['ok'] ?? false) ? 'ok' : 'failed');
        $this->components->twoColumnDetail('Ledger event', (string) data_get($payload, 'ledger_event.event_id', 'not recorded'));

        $stages = (array) (data_get($pipelinePayload, 'stages') ?: data_get($pipelinePayload, 'stage_results') ?: []);
        if ($stages !== []) {
            $this->table(
                ['order', 'stage', 'reads', 'writes'],
                collect($stages)->map(fn (array $stage, int $index): array => [
                    $stage['order'] ?? data_get($stage, 'metadata.order', $index + 1),
                    $stage['stage'] ?? '-',
                    implode(', ', (array) ($stage['reads'] ?? [])),
                    implode(', ', (array) ($stage['writes'] ?? [])),
                ])->all(),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,string>
     */
    private function hints(): array
    {
        $hints = [];

        foreach ((array) $this->option('hint') as $hint) {
            if (! is_string($hint) || ! str_contains($hint, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $hint, 2);
            $key = trim($key);
            if ($key !== '') {
                $hints[$key] = trim($value);
            }
        }

        return $hints;
    }
}
