<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasProductDeliveryControlPlaneService;
use Illuminate\Console\Command;

class AtlasProductDeliveryControlPlaneCommand extends Command
{
    protected $signature = 'atlas:product-delivery:control-plane
        {request? : Human request to inspect}
        {--workspace=atlas-server : Workspace slug/path}
        {--route= : Optional route override}
        {--provider-patch : Treat as provider/subagent patch candidate}
        {--operator-approved : Simulate explicit operator approval}
        {--evidence=* : Evidence kind supplied to APFPR}
        {--context=* : Context refs to pass into APDR/AEDPDS}
        {--doc=* : Canonical docs to pass into APDR/AEDPDS}
        {--ux=* : UX expectation or prototype refs to pass into APDR/AEDPDS}
        {--json : Emit JSON}
        {--strict : Exit non-zero unless status === healthy}';

    protected $description = 'Aggregates AEDPDS delivery, risk, replay, doctrine fitness, receipts, and certification without providers or writes.';

    public function handle(AtlasProductDeliveryControlPlaneService $service): int
    {
        $payload = $service->snapshot([
            'human_request' => (string) ($this->argument('request') ?: 'Atlas product delivery control plane snapshot'),
            'workspace' => (string) $this->option('workspace'),
            'route' => $this->option('route'),
            'provider_patch' => (bool) $this->option('provider-patch'),
            'operator_approved' => (bool) $this->option('operator-approved'),
            'evidence' => (array) $this->option('evidence'),
            'context_refs' => $this->strings($this->option('context')),
            'canonical_docs' => $this->strings($this->option('doc')),
            'evidence_refs' => $this->strings($this->option('evidence')),
            'ux_expectations' => $this->strings($this->option('ux')),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Product Delivery Control Plane', (string) $payload['schema_version']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('route', (string) data_get($payload, 'delivery.route'));
            $this->components->twoColumnDetail('risk', (string) data_get($payload, 'risk_governor.risk_band'));
            $this->components->twoColumnDetail('hash', (string) $payload['control_plane_hash']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'healthy'
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_scalar($item) ? trim((string) $item) : null,
            $value,
        ), static fn (?string $item): bool => $item !== null && $item !== ''));
    }
}
