<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopAutopoieticScopeGovernancePipeline;
use Illuminate\Console\Command;

/**
 * Lets the loop PREVIEW whether a self-proposed new scope would clear its own constitutional governance gate,
 * by running the dormant {@see AtlasLoopAutopoieticScopeGovernancePipeline::evaluate()} on an assembled proposal
 * and emitting its admission verdict (admitted, blocking_reasons, requires_operator_receipt).
 *
 * ADVISORY only: it previews the verdict and NEVER originates a scope, registers/bootstraps anything, or mutates
 * the queue or git. The proposal is assembled from the options; an optional bound proposal seed
 * (`atlas.loop.scope_governance_preview.proposal`) supplies the remaining descriptor fields as a test seam.
 */
final class AtlasLoopScopeGovernancePreviewCommand extends Command
{
    protected $signature = 'atlas:loop:scope-governance-preview {--scope-id=} {--label=} {--json}';

    protected $description = 'Read-only preview of the autopoietic scope-governance admission verdict for a proposed scope.';

    public function handle(): int
    {
        $proposal = $this->proposalSeed();

        $scopeId = trim((string) $this->option('scope-id'));
        if ($scopeId !== '') {
            $proposal['scope_id'] = $scopeId;
        }
        $label = trim((string) $this->option('label'));
        if ($label !== '') {
            $proposal['operator_intent'] = [$label];
        }

        $verdict = app(AtlasLoopAutopoieticScopeGovernancePipeline::class)->evaluate($proposal);

        $facts = [
            'admitted' => $verdict['admitted'],
            'blocking_reasons' => $verdict['blocking_reasons'],
            'requires_operator_receipt' => $verdict['requires_operator_receipt'],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            foreach ($facts as $k => $v) {
                $this->line($k.': '.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Optional injected descriptor fields (namespace, root, operator_intent, operator_receipt, …) the bare
     * options can't carry — a test seam and an operator override point. Empty by default.
     *
     * @return array<string,mixed>
     */
    private function proposalSeed(): array
    {
        $key = 'atlas.loop.scope_governance_preview.proposal';
        $seed = app()->bound($key) ? app($key) : [];

        return is_array($seed) ? $seed : [];
    }
}
