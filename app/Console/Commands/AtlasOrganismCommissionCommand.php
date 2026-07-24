<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Organism\AtlasOrganismMissionService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use App\Support\YesNo;

/**
 * AOBG N4.F3 — `atlas:organism:commission`: an INTENT that SPANS domains → a cross-domain
 * mission of brain-anchored, honestly-validated DOMAIN PROPOSALS (PROPOSE-ONLY, cost-free).
 *
 * THE ORGANISM, operable: the operator declares an intent in natural language and Atlas
 * decomposes it (reusing the N3 plan-DAG), routes each node to a DOMAIN (finance, marketing,
 * cyber, …), governs each cross-domain crossing with the ARPTL veto, generates a brain-
 * anchored proposal per allowed+registered node validated on the domain's HONEST metric
 * (finance = DSR/PBO; win-rate FORBIDDEN), and records them into the brain (cross-domain
 * COMPOUNDING). EVERY node's actuation gate is 'requires_operator' — Atlas NEVER executes
 * real money/orders/ad-spend/purchases/publishing.
 *
 *     atlas:organism:commission "find a BTC trade idea; then draft a marketing campaign for it"
 *     atlas:organism:commission "..." --anchor=engineering --max=8 --json
 *
 * Cost-free by default (deterministic decomposer + router; on-machine/stubbable proposers).
 * The actuation of any real-world action is the OPERATOR's, never Atlas's.
 */
class AtlasOrganismCommissionCommand extends Command
{
    public const SCHEMA = 'atlas.organism.commission_command.v1';

    protected $signature = 'atlas:organism:commission
        {intent : The operator intent (may span domains) to commission as a cross-domain mission}
        {--workspace= : Workspace id/label for the mission header (defaults to "default")}
        {--anchor= : The mission anchor domain for the ARPTL crossing (default engineering)}
        {--fallback= : Domain for an unroutable node (default = anchor)}
        {--max= : Override the plan-DAG node cap (default config atlas.obra.max_nodes)}
        {--no-persist : Build the plan in-memory only, do not write the mission tables}
        {--json : Output the cross-domain proposal plan as JSON}';

    protected $description = 'AOBG N4.F3: commission a cross-domain mission — decompose an intent, route nodes to domains, propose+validate per domain (PROPOSE-ONLY, requires_operator, cost-free).';

    public function handle(AtlasOrganismMissionService $service): int
    {
        $intent = trim((string) ($this->argument('intent') ?? ''));
        if ($intent === '') {
            $this->error('intent is required');

            return self::INVALID;
        }

        $opts = ['persist' => ! (bool) $this->option('no-persist')];
        foreach (['workspace' => 'workspace', 'anchor' => 'anchor_domain', 'fallback' => 'fallback_domain'] as $flag => $optKey) {
            $val = $this->option($flag);
            if (is_string($val) && trim($val) !== '') {
                $opts[$optKey] = trim($val);
            }
        }
        $max = $this->option('max');
        if (is_string($max) && is_numeric(trim($max))) {
            $opts['max_nodes'] = (int) max(1, (int) floor((float) trim($max)));
        }

        try {
            $mission = $service->commission($intent, $opts);
        } catch (InvalidArgumentException $e) {
            $this->error('organism mission refused: '.$e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($mission, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->render($mission);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $mission
     */
    private function render(array $mission): void
    {
        $this->info(sprintf(
            'atlas:organism:commission  mission=%s  anchor=%s  workspace=%s  nodes=%d  domains=[%s]',
            (string) ($mission['mission_id'] ?? ''),
            (string) ($mission['anchor_domain'] ?? ''),
            (string) ($mission['workspace_id'] ?? ''),
            (int) ($mission['node_count'] ?? 0),
            implode(', ', array_map('strval', (array) ($mission['domains_spanned'] ?? []))),
        ));
        $this->line('');

        foreach ((array) ($mission['nodes'] ?? []) as $node) {
            $outcome = (string) ($node['outcome'] ?? '');
            $domain = (string) ($node['domain'] ?? '');
            $sensitive = (bool) ($node['sensitive'] ?? false) ? ' [sensitive · on-machine]' : '';

            $this->line(sprintf(
                '  [%d] %s  → domain=%s%s  (routed_by=%s)',
                (int) ($node['seq'] ?? 0),
                (string) ($node['title'] ?? ''),
                $domain,
                $sensitive,
                (string) ($node['routed_by'] ?? '?'),
            ));

            $validation = (array) ($node['validation'] ?? []);
            switch ($outcome) {
                case AtlasOrganismMissionService::OUTCOME_PROPOSED:
                    $this->line(sprintf(
                        '      PROPOSED · metric=%s value=%s passed=%s',
                        (string) ($validation['metric'] ?? '?'),
                        $validation['value'] === null ? 'null' : (string) $validation['value'],
                        YesNo::format($validation['passed'] ?? false),
                    ));
                    $this->line('      method:  '.(string) ($validation['method'] ?? ''));
                    break;
                case AtlasOrganismMissionService::OUTCOME_VETOED:
                    $this->line('      VETOED (ARPTL): '.(string) ($node['veto_reason'] ?? ''));
                    break;
                case AtlasOrganismMissionService::OUTCOME_NO_HANDLER:
                    $this->line('      NO HANDLER for domain "'.$domain.'" (no proposer registered — honest, no fabricated proposal)');
                    break;
            }
            $this->line('      actuation: '.(string) ($node['actuation_gate'] ?? 'requires_operator'));
            $this->line('');
        }

        $this->comment(
            'PROPOSE-ONLY ceiling ('.(string) ($mission['ceiling'] ?? '').'): every node is requires_operator. '
            .'Atlas does NOT execute real money/orders/ad-spend/purchases/publishing — the operator executes any real-world action themselves.'
        );
    }
}
