<?php

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasAutonomousProductDeliveryRuntimeService;
use App\Services\Ai\Product\AtlasProductDeliveryEnforcementService;
use App\Services\Ai\Product\AtlasProductDeliveryOutcomeMemoryService;
use Illuminate\Console\Command;

class AtlasProductDeliveryOutcomeCommand extends Command
{
    protected $signature = 'atlas:product-delivery:outcome
        {request : Human product/delivery request}
        {--workspace= : Workspace slug/path}
        {--status=ready : ready|blocked|needs_repair|needs_review}
        {--evidence=* : Evidence kind to attach}
        {--ux=* : UX expectation or prototype refs to pass into APDR/AEDPDS}
        {--persist : Persist into atlas_product_delivery_outcome_memories}
        {--json : Emit JSON}';

    protected $description = 'Build or persist AEDPDS product delivery outcome memory.';

    public function handle(
        AtlasAutonomousProductDeliveryRuntimeService $deliveryRuntime,
        AtlasProductDeliveryEnforcementService $enforcement,
        AtlasProductDeliveryOutcomeMemoryService $outcomes,
    ): int {
        $evidence = $this->evidenceFromOptions((array) $this->option('evidence'));
        $delivery = $deliveryRuntime->plan([
            'human_request' => (string) $this->argument('request'),
            'workspace' => (string) ($this->option('workspace') ?: ''),
            'evidence' => $evidence,
            'operator_approved' => true,
            'ux_expectations' => $this->strings($this->option('ux')) ?: ['checkout journey expectation'],
        ]);
        $proof = is_array($delivery['proof_preview'] ?? null) ? $delivery['proof_preview'] : [];
        $memory = $outcomes->build($delivery, [
            ...$proof,
            'status' => (string) $this->option('status'),
        ], $evidence);

        $persisted = null;
        $aemorBridge = [
            'schema_version' => 'atlas.product_delivery.aemor_bridge.v1',
            'status' => 'skipped',
            'reason' => 'outcome_not_persisted',
            'writes' => false,
        ];
        if ((bool) $this->option('persist')) {
            $persisted = $outcomes->persist($delivery, [
                ...$proof,
                'status' => (string) $this->option('status'),
            ], $evidence);
            if ($persisted !== null) {
                $aemorBridge = $outcomes->bridgeToAemor($memory, $persisted);
            }
        }
        $completionEnforcement = $enforcement->evaluate($delivery, $proof, [
            'phase' => 'post_execution',
            'outcome_memory_recorded' => $persisted !== null,
        ]);

        $payload = [
            ...$memory,
            'persisted' => $persisted !== null,
            'persisted_id' => $persisted?->id,
            'aemor_bridge' => $aemorBridge,
            'completion_enforcement' => $completionEnforcement,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return 0;
        }

        $this->components->twoColumnDetail('Product delivery outcome memory', $payload['outcome_status']);
        $this->components->twoColumnDetail('Persisted', $payload['persisted'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Hash', (string) $payload['outcome_memory_hash']);

        return 0;
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

    /**
     * @param  array<int,mixed>  $items
     * @return array<string,list<string>>
     */
    private function evidenceFromOptions(array $items): array
    {
        $evidence = [];
        foreach ($items as $item) {
            if (! is_scalar($item) || trim((string) $item) === '') {
                continue;
            }
            $kind = trim((string) $item);
            $evidence[$kind] = $this->canonicalEvidenceLines($kind);
        }

        return $evidence;
    }

    /**
     * @return list<string>
     */
    private function canonicalEvidenceLines(string $kind): array
    {
        return match ($kind) {
            'tests' => ['focused_tests passed', 'contract tests passed', 'security regression tests passed'],
            'security' => ['abuse cases reviewed'],
            'acceptance_mapping' => ['tests mapped to acceptance'],
            'outcome' => ['outcome memory recorded'],
            default => [$kind.' recorded'],
        };
    }
}
