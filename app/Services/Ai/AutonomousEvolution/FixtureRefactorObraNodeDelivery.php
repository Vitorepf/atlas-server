<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Obra\ObraNodeDelivery;

/**
 * DETERMINISTIC, ZERO-SPEND obra delivery for proving the multi-file execution MACHINERY
 * by construction — without a provider call. For each node it returns a PRE-BAKED simpler
 * version of that node's target file (a genuine cyclomatic drop), so the executor really
 * runs apply→gate→integrated→close and the certifier really measures an aggregate AST drop.
 *
 * CRITICAL HONESTY: this is NOT {@see \App\Services\Ai\Obra\ProviderObraNodeDelivery}, so the
 * executor seals the receipt with execution_mode='fixture_obra_run'. The L4-10 proof
 * ({@see AtlasLoopRefactorObraL410ProofService}) REJECTS that — a fixture can never mint a
 * "real" L4-10, so a fixture run can prove the machinery but can NEVER park/merge as real work.
 * It exists only behind tests; the live lane uses the real provider delivery.
 */
final class FixtureRefactorObraNodeDelivery implements ObraNodeDelivery
{
    /**
     * @param  array<string,string>  $replacements  repo-relative path => the pre-baked simpler file content
     * @param  string  $providerLabel  the engine label the fixture reports (recorded, NOT trusted — the
     *                                  sealed execution_mode='fixture_obra_run' is what the L4-10 proof reads)
     */
    public function __construct(
        private readonly array $replacements,
        private readonly string $providerLabel = 'hermes_cli',
        private readonly string $modelLabel = 'gpt-5.5',
    ) {}

    /**
     * @param  array<string,mixed>  $context  {node_id, plan_id, target_area, repo_dir, obra_worktree, ...}
     * @return array<string,mixed>
     */
    public function deliver(string $request, array $context = []): array
    {
        $target = ltrim((string) ($context['target_area'] ?? ''), '/');
        if ($target === '' || ! array_key_exists($target, $this->replacements)) {
            return ['certified' => false, 'files' => [], 'reason' => 'fixture_no_replacement_for:'.($target !== '' ? $target : 'absent')];
        }

        $content = $this->replacements[$target];

        return [
            'certified' => true,
            'files' => [['path' => $target, 'content' => $content]],
            // The materializer requires gate_receipt =~ /^[a-f0-9]{16,}$/ — a deterministic digest.
            'gate_receipt' => hash('sha256', $target.'|'.$content),
            'provider' => $this->providerLabel,
            'model' => $this->modelLabel,
        ];
    }

    public function label(): string
    {
        return 'fixture_refactor_obra';
    }
}
