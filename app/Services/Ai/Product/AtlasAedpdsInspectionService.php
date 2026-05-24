<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;

class AtlasAedpdsInspectionService
{
    public const INSPECT_SCHEMA_VERSION = 'atlas.aedpds.inspect.v1';

    public const CERTIFICATION_SCHEMA_VERSION = 'atlas.aedpds.certification.v1';

    /**
     * @return array<string,mixed>
     */
    public function inspect(): array
    {
        $items = [
            'doctrine_doc' => $this->item('docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md', ['AEDPDS', 'Atlas Execution Doctrine & Product Delivery System'], 'documented_only'),
            'runtime_matrix_doc' => $this->item('docs/engineering-knowledge-base/atlas-execution-doctrine-runtime-matrix.md', ['AEDPDS', 'Runtime Matrix'], 'documented_only'),
            'selector_service' => $this->item('app/Services/Ai/Product/AtlasExecutionDoctrineRuntimeService.php', [AtlasExecutionDoctrineRuntimeService::SCHEMA_VERSION, 'selected_primary_drivers'], 'implemented_runtime'),
            'gate_service' => $this->item('app/Services/Ai/Product/AtlasExecutionDoctrineGateService.php', [AtlasExecutionDoctrineGateService::SCHEMA_VERSION, 'missing_acceptance_criteria'], 'implemented_runtime'),
            'apdr_service' => $this->item('app/Services/Ai/Product/AtlasAutonomousProductDeliveryRuntimeService.php', ['aedpds', 'AtlasExecutionDoctrineRuntimeService'], 'implemented_runtime'),
            'receipt_model' => $this->item('app/Models/AtlasProductDeliveryRuntimeReceipt.php', ['atlas_product_delivery_runtime_receipts'], 'implemented_runtime'),
            'receipt_migration' => $this->item('database/migrations/2026_05_22_172000_create_atlas_product_delivery_runtime_receipts.php', ['atlas_product_delivery_runtime_receipts', 'receipt_hash'], 'implemented_runtime'),
            'outcome_memory' => $this->item('app/Services/Ai/Product/AtlasProductDeliveryOutcomeMemoryService.php', ['outcome_memory', 'should_promote_to_aemor'], 'implemented_runtime'),
            'dev_task_packet' => $this->item('app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence/DevTaskPacketRuntimeService.php', ['AtlasExecutionDoctrineRuntimeService', 'aedpds_context'], 'implemented_runtime'),
            'dev_context_gate' => $this->item('app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence/DevContextGateService.php', ['aedpds_context:', 'owner_docs_or_context_refs'], 'implemented_runtime'),
            'dev_run_certification' => $this->item('app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence/DevRunCertificationService.php', ['aedpds_gate_status', 'aedpds_selected_drivers'], 'implemented_runtime'),
            'forge_intake' => $this->item('app/Services/Ai/Programming/AtlasCodeForgeWorkIntakeService.php', ['atlas.forge.aedpds_projection.v1', 'blocked_aedpds_'], 'implemented_runtime'),
            'forge_outcome_memory' => $this->item('app/Services/Ai/Programming/Forge/Intelligence/ForgeOutcomeMemoryService.php', ['AiForgeOutcomeMemory', 'persist'], 'implemented_partial'),
            'commands' => $this->multiItem([
                'app/Console/Commands/Ai/Product/AtlasAedpdsInspectCommand.php' => ['atlas:aedpds:inspect'],
                'app/Console/Commands/Ai/Product/AtlasAedpdsSelectCommand.php' => ['atlas:aedpds:select'],
                'app/Console/Commands/Ai/Product/AtlasAedpdsGateCommand.php' => ['atlas:aedpds:gate'],
                'app/Console/Commands/Ai/Product/AtlasAedpdsCertifyCommand.php' => ['atlas:aedpds:certify'],
            ], 'implemented_runtime'),
            'tests' => $this->item('tests/Feature/Ai/Product/AtlasAedpdsRuntimeTest.php', ['test_ui_bug_selects_ux_atdd_tdd_and_risk', 'test_certify_returns_ready'], 'implemented_runtime'),
        ];

        $payload = [
            'schema_version' => self::INSPECT_SCHEMA_VERSION,
            'status' => collect($items)->contains(fn (array $item): bool => $item['status'] === 'missing') ? 'partial' : 'ready',
            'items' => $items,
            'classifications' => collect($items)->groupBy('classification')->map->count()->all(),
            'claim_policy' => [
                'docs_alone_are_not_runtime' => true,
                'provider_invoked' => false,
                'writes' => false,
            ],
        ];
        $payload['inspection_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $inspect = $this->inspect();
        $sample = app(AtlasExecutionDoctrineRuntimeService::class)->select([
            'task' => 'Implement API endpoint with auth and database migration',
            'surface' => 'atlas_dev',
            'code_changes_requested' => true,
            'senior_review_present' => true,
        ]);
        $gate = app(AtlasExecutionDoctrineGateService::class)->evaluate([
            'doctrine' => $sample,
            'acceptance_criteria' => ['happy path and auth failure are specified'],
            'context_refs' => ['docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md'],
            'tests' => ['php artisan test --filter=Aedpds'],
            'contracts' => ['api contract'],
            'docs' => ['docs/engineering-knowledge-base/atlas-execution-doctrine-product-delivery-system.md'],
            'review' => ['senior review'],
            'evidence' => ['test output'],
        ]);

        $checks = [
            'doctrine_doc_present' => $this->passed('doctrine_doc', $inspect),
            'driver_registry_present' => count(AtlasExecutionDoctrineRuntimeService::DRIVERS) >= 20,
            'selector_service_present' => $this->passed('selector_service', $inspect),
            'gate_service_present' => $this->passed('gate_service', $inspect),
            'dev_integration_present' => $this->passed('dev_task_packet', $inspect) && $this->passed('dev_run_certification', $inspect),
            'forge_integration_present' => $this->passed('forge_intake', $inspect),
            'test_mapping_present' => in_array('tdd', $sample['selected_primary_drivers'], true) && $sample['required_tests'] !== [],
            'risk_security_mapping_present' => in_array('security_driven', $sample['selected_primary_drivers'], true),
            'ux_mapping_present' => in_array('ux_driven', app(AtlasExecutionDoctrineRuntimeService::class)->select(['task' => 'bug visual na tela mobile', 'code_changes_requested' => true])['selected_primary_drivers'], true),
            'api_contract_mapping_present' => in_array('api_first', $sample['selected_primary_drivers'], true) && $sample['required_contracts'] !== [],
            'db_mapping_present' => in_array('database_driven', $sample['selected_primary_drivers'], true),
            'evidence_receipt_present' => $this->passed('receipt_model', $inspect) && $this->passed('receipt_migration', $inspect),
            'outcome_memory_present' => $this->passed('outcome_memory', $inspect),
            'command_surface_present' => $this->passed('commands', $inspect),
            'no_documentation_only_claims' => $this->passed('selector_service', $inspect) && $this->passed('gate_service', $inspect),
            'sample_gate_passes' => ($gate['status'] ?? null) === 'passed' || ($gate['status'] ?? null) === 'warning',
        ];

        $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));
        $payload = [
            'schema_version' => self::CERTIFICATION_SCHEMA_VERSION,
            'status' => $failed === [] ? 'ready' : ($this->passed('selector_service', $inspect) && $this->passed('gate_service', $inspect) ? 'partial' : 'blocked'),
            'checks' => array_map(
                static fn (string $id, bool $passed): array => ['id' => $id, 'status' => $passed ? 'passed' : 'failed'],
                array_keys($checks),
                array_values($checks),
            ),
            'remaining_blockers' => $failed,
            'sample' => [
                'selected_drivers' => $sample['selected_primary_drivers'],
                'gate_status' => $gate['status'],
            ],
            'claim_policy' => [
                'ready_requires_runtime_not_docs_only' => true,
                'provider_invoked' => false,
                'writes' => false,
            ],
        ];
        $payload['certification_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  list<string>  $needles
     * @return array<string,mixed>
     */
    private function item(string $path, array $needles, string $implementedClassification): array
    {
        $fullPath = base_path($path);
        $source = is_file($fullPath) ? File::get($fullPath) : '';
        $missing = array_values(array_filter($needles, static fn (string $needle): bool => ! str_contains($source, $needle)));
        $exists = $source !== '';

        return [
            'path' => $path,
            'status' => $exists && $missing === [] ? 'present' : ($exists ? 'partial' : 'missing'),
            'classification' => $exists && $missing === [] ? $implementedClassification : ($exists ? 'implemented_partial' : 'missing'),
            'missing_needles' => $missing,
        ];
    }

    /**
     * @param  array<string,list<string>>  $files
     * @return array<string,mixed>
     */
    private function multiItem(array $files, string $implementedClassification): array
    {
        $missing = [];
        foreach ($files as $path => $needles) {
            $item = $this->item($path, $needles, $implementedClassification);
            if ($item['status'] !== 'present') {
                $missing[$path] = $item['missing_needles'];
            }
        }

        return [
            'path' => implode(',', array_keys($files)),
            'status' => $missing === [] ? 'present' : 'partial',
            'classification' => $missing === [] ? $implementedClassification : 'implemented_partial',
            'missing_needles' => $missing,
        ];
    }

    /**
     * @param  array<string,mixed>  $inspect
     */
    private function passed(string $id, array $inspect): bool
    {
        return data_get($inspect, "items.{$id}.status") === 'present';
    }
}
