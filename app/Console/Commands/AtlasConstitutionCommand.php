<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasConstitutionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Constitution decider CLI.
 *
 *   php artisan atlas:aaeos:constitution
 *     [--intent=identify_gaps]                 // a "May Do" / "Must Not Do" verb
 *     [--structural-target=memory_os]          // structural subsystem id (or empty)
 *     [--contract-stage=read_only_runtime]     // stage about to start
 *     [--contract-complete=contract_doc,schema]// completed contract stages (csv)
 *     [--change-kinds=autonomy_level]          // Human-Gate change kinds (csv)
 *     [--json]
 *
 * Read-only, deterministic. Emits the allow / allow_with_human_review / block
 * verdict for one proposed self-construction operation, plus the receipt.
 *
 * @see docs/engineering-knowledge-base/self-construction/constitution.md
 */
class AtlasConstitutionCommand extends Command
{
    protected $signature = 'atlas:aaeos:constitution
        {--intent= : self-construction intent (one of MAY_DO / MUST_NOT_DO)}
        {--structural-target= : structural subsystem id (memory_os|evidence_ledger|...) or empty}
        {--contract-stage= : contract stage about to start (contract_doc|schema|invariants|examples|gates|read_only_runtime)}
        {--contract-complete= : comma-separated contract stages already complete}
        {--change-kinds= : comma-separated Human-Gate change kinds this op touches}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · constitution decider for one proposed operation (allow|allow_with_human_review|block).';

    public function handle(AtlasConstitutionService $service): int
    {
        try {
            $operation = [
                'intent' => $this->option('intent') ?? 'implement_small_reversible_block',
                'boundary' => $this->safeBoundary(),
                'structural_target' => $this->option('structural-target') ?? '',
                'contract_stage' => $this->option('contract-stage') ?? '',
                'contract_complete' => $this->csv($this->option('contract-complete')),
                'change_kinds' => $this->csv($this->option('change-kinds')),
            ];

            $verdict = $service->evaluate($operation);

            $this->line((string) json_encode(
                ['ok' => true, 'verdict' => $verdict],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'constitution_evaluation_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /**
     * Safe default Construction Boundary so the bare command exercises a
     * fully-declared, compliant operation (the 12 required fields present).
     *
     * @return array<string,mixed>
     */
    private function safeBoundary(): array
    {
        return [
            'target_layer' => '0.8-self-construction',
            'target_capability' => 'self_construction_constitution',
            'owner' => 'atlas-ai',
            'risk' => 'high',
            'current_maturity' => 'L1',
            'desired_maturity' => 'L2',
            'allowed_actions' => ['update_canonical_docs'],
            'forbidden_actions' => ['mutate_core_policy_without_ap'],
            'gates' => ['php artisan atlas:engineering:knowledge docs-health --json'],
            'rollback' => 'revert_doc_change',
            'evidence' => ['docs/engineering-knowledge-base/self-construction/constitution.md'],
            'residual_risk' => 'low',
        ];
    }

    /**
     * @return list<string>
     */
    private function csv(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn ($v) => $v !== '',
        ));
    }
}
