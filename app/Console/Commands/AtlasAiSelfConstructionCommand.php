<?php

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Console\Command;

class AtlasAiSelfConstructionCommand extends Command
{
    protected $signature = 'atlas:ai:self-construction
        {--workspace= : Workspace root for reporting}
        {--meta-sdd : Generate a read-only Meta-SDD candidate packet}
        {--receipt-preview : Generate a read-only Decision Receipt preview}
        {--traceability : Audit Self-Construction documentation traceability}
        {--promotion-gate : Evaluate read-only phase promotion readiness}
        {--target= : Target capability for Meta-SDD candidate generation}
        {--json : Print machine-readable JSON}';

    protected $description = 'Show Atlas Self-Construction OS readiness and next safe governed construction blocks.';

    public function handle(AtlasSelfConstructionReadinessService $readiness): int
    {
        $options = [
            'workspace' => $this->option('workspace'),
            'target' => $this->option('target'),
        ];

        $payload = match (true) {
            (bool) $this->option('promotion-gate') => $readiness->promotionGate($options),
            (bool) $this->option('traceability') => $readiness->traceabilityAudit($options),
            (bool) $this->option('receipt-preview') => $readiness->receiptPreview($options),
            (bool) $this->option('meta-sdd') => $readiness->metaSddPacket($options),
            default => $readiness->snapshot($options),
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Self-Construction OS</>', (string) $payload['status']);

        if ((bool) $this->option('promotion-gate')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Current phase', (string) data_get($payload, 'current_phase'));
            $this->components->twoColumnDetail('Recommended next phase', (string) data_get($payload, 'recommended_next_phase'));
            $this->components->twoColumnDetail('Blocking failures', (string) count((array) data_get($payload, 'blocking_failures', [])));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('traceability')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Required docs', (string) data_get($payload, 'summary.required_doc_count'));
            $this->components->twoColumnDetail('Violations', (string) data_get($payload, 'summary.violation_count'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('receipt-preview')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt', (string) data_get($payload, 'receipt_preview.id'));
            $this->components->twoColumnDetail('Target capability', (string) data_get($payload, 'receipt_preview.target_capability'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('meta-sdd')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Target capability', (string) data_get($payload, 'meta_spec.target_capability'));
            $this->components->twoColumnDetail('Target maturity', (string) data_get($payload, 'meta_spec.target_maturity'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
        $this->components->twoColumnDetail('Runtime phase', (string) data_get($payload, 'summary.runtime_phase'));
        $this->components->twoColumnDetail('Docs missing', (string) data_get($payload, 'summary.missing_doc_count'));
        $this->components->twoColumnDetail('Self-programming allowed', data_get($payload, 'safety_contract.self_programming_allowed') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Next target', (string) data_get($payload, 'maturity.next_target'));

        $blocks = (array) data_get($payload, 'next_safe_blocks', []);
        if ($blocks !== []) {
            $this->newLine();
            $this->line('Next safe blocks:');
            foreach ($blocks as $block) {
                $this->line('  - '.data_get($block, 'order').'. '.data_get($block, 'block').' ['.data_get($block, 'risk').']');
            }
        }

        return self::SUCCESS;
    }
}
