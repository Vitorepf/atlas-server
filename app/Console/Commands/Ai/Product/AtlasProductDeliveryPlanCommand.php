<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasAutonomousProductDeliveryRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasProductDeliveryPlanCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:product-delivery:plan
        {request? : Human request to plan}
        {--workspace= : Workspace slug}
        {--route= : Optional route override}
        {--operator-approved : Attach operator/senior review approval for sensitive work}
        {--context=* : Context refs to pass into APDR/AEDPDS}
        {--doc=* : Canonical docs to pass into APDR/AEDPDS}
        {--evidence=* : Evidence refs to pass into APDR/AEDPDS}
        {--ux=* : UX expectation or prototype refs to pass into APDR/AEDPDS}
        {--json : Print JSON}
        {--strict : Exit non-zero unless ready_for_delivery and AEDPDS gate passed}';

    protected $description = 'Plans a provider-free AEDPDS/APDR delivery envelope from a human request.';

    public function handle(AtlasAutonomousProductDeliveryRuntimeService $service): int
    {
        $report = $service->plan([
            'human_request' => (string) ($this->argument('request') ?: 'Atlas product delivery request'),
            'workspace' => $this->option('workspace'),
            'route' => $this->option('route'),
            'operator_approved' => (bool) $this->option('operator-approved'),
            'context_refs' => $this->strings($this->option('context')),
            'canonical_docs' => $this->strings($this->option('doc')),
            'evidence_refs' => $this->strings($this->option('evidence')),
            'ux_expectations' => $this->strings($this->option('ux')),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));
        } else {
            $this->components->twoColumnDetail('Product Delivery', (string) $report['schema_version']);
            $this->components->twoColumnDetail('status', (string) $report['status']);
            $this->components->twoColumnDetail('route', (string) $report['route']);
            $this->components->twoColumnDetail('delivery_hash', (string) $report['delivery_hash']);
        }

        return (bool) $this->option('strict') && (
            ($report['status'] ?? null) !== 'ready_for_delivery'
            || data_get($report, 'aedpds.gate.status') !== 'passed'
        )
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_scalar($item) ? trim((string) $item) : null,
            $value,
        ), static fn (?string $item): bool => $item !== null && $item !== '')) : [];
    }
}
