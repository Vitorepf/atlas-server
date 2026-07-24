<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasAutonomousProductDeliveryRuntimeService;
use App\Services\Ai\Product\AtlasProductFalsificationProofRuntimeService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasProductProofChallengeCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:product-proof:challenge
        {request? : Human request to challenge}
        {--workspace= : Workspace slug}
        {--operator-approved : Attach operator/senior review approval for sensitive work}
        {--context=* : Context refs to pass into APDR/AEDPDS}
        {--doc=* : Canonical docs to pass into APDR/AEDPDS}
        {--evidence-ref=* : Evidence refs to pass into APDR/AEDPDS gate}
        {--ux=* : UX expectation or prototype refs to pass into APDR/AEDPDS}
        {--with-demo-evidence : Include sufficient demo evidence for local proof}
        {--json : Print JSON}
        {--strict : Exit non-zero unless proof status is ready}';

    protected $description = 'Runs APFPR proof challenge against a provider-free delivery plan.';

    public function handle(
        AtlasAutonomousProductDeliveryRuntimeService $delivery,
        AtlasProductFalsificationProofRuntimeService $proof,
    ): int {
        $plan = $delivery->plan([
            'human_request' => (string) ($this->argument('request') ?: 'Atlas product delivery request'),
            'workspace' => $this->option('workspace'),
            'operator_approved' => (bool) $this->option('operator-approved'),
            'context_refs' => $this->strings($this->option('context')),
            'canonical_docs' => $this->strings($this->option('doc')),
            'evidence_refs' => $this->strings($this->option('evidence-ref')),
            'ux_expectations' => $this->strings($this->option('ux')),
        ]);

        $report = $proof->challenge([
            'product_truth' => $plan['product_truth'] ?? [],
            'delivery_contract' => $plan,
            'evidence' => $this->evidence((bool) $this->option('with-demo-evidence')),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));
        } else {
            $this->components->twoColumnDetail('Atlas Proof Challenge', (string) $report['schema_version']);
            $this->components->twoColumnDetail('status', (string) $report['status']);
            $this->components->twoColumnDetail('falsification_score', (string) $report['falsification_score']);
            $this->components->twoColumnDetail('proof_hash', (string) $report['proof_hash']);
        }

        return (bool) $this->option('strict') && ($report['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @return array<string,list<string>>
     */
    private function evidence(bool $demo): array
    {
        if (! $demo) {
            return [];
        }

        return [
            'tests' => ['focused_tests passed', 'contract tests passed', 'security regression tests passed'],
            'security' => ['abuse cases reviewed'],
            'acceptance_mapping' => ['tests mapped to acceptance'],
            'outcome' => ['outcome memory candidate recorded'],
        ];
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
