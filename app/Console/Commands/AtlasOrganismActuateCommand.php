<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Organism\AtlasOrganismService;
use App\Services\Ai\Organism\DomainProposal;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * AOBG N4.F4 — `atlas:organism:actuate`: drive a domain proposal through the HARDENED
 * PROPOSE-ONLY boundary (cost-free).
 *
 * This is the operable proof of the load-bearing safety of N4: the operator asks Atlas to
 * "actuate" a proposal and Atlas — BY CONSTRUCTION — RECORDS it + returns 'requires_operator'
 * + the human instruction, and performs ZERO real-world side effect (no money/orders/ad-spend/
 * purchases/publishing). Every attempt flows through the single AtlasOrganismActuationGate,
 * which writes an append-only audit receipt (see atlas:organism:status --actuations).
 *
 *     # propose first (on-machine, cost-free), then actuate the SAME intent's proposal:
 *     atlas:organism:actuate --domain=marketing --intent="draft a campaign for the audience"
 *     atlas:organism:actuate --domain=finance --intent="mean-reversion BTC idea" --json
 *
 * The actuation ALWAYS returns requires_operator — Atlas never executes the real-world action.
 * The operator executes it themselves. PROPOSE-ONLY ceiling, labelled everywhere.
 */
class AtlasOrganismActuateCommand extends Command
{
    public const SCHEMA = 'atlas.organism.actuate_command.v1';

    protected $signature = 'atlas:organism:actuate
        {--domain= : the canonical domain to actuate in (e.g. finance, marketing)}
        {--intent= : the operator intent the proposal answers (a propose-only domain proposal is generated, then actuated)}
        {--json : machine-readable output}';

    protected $description = 'AOBG N4.F4: actuate a domain proposal through the hardened propose-only gate — RECORDS + returns requires_operator + writes an audit receipt. NEVER executes a real-world action (cost-free).';

    public function handle(AtlasOrganismService $organism): int
    {
        $domain = trim((string) ($this->option('domain') ?? ''));
        $intent = trim((string) ($this->option('intent') ?? ''));
        if ($domain === '' || $intent === '') {
            $this->error('both --domain and --intent are required');

            return self::INVALID;
        }

        try {
            // 1) PROPOSE (on-machine, cost-free) — a brain-anchored, honestly-validated proposal.
            $proposed = $organism->propose($domain, $intent);
            // 2) Rebuild the proposal object from the provider-safe view to actuate it through
            //    the hardened gate. (No payload is needed to actuate — actuation is propose-only.)
            $proposal = DomainProposal::fromArray(
                ($proposed['proposal'] ?? []) + ['domain' => $domain, 'intent' => $intent]
            );
            $result = $organism->actuate($proposal);
        } catch (InvalidArgumentException $e) {
            $this->error('actuation refused: '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('actuation error: '.$e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'proposal' => $proposed['proposal'] ?? [],
                'validation' => $proposed['validation'] ?? [],
                'actuation' => $result,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(sprintf('atlas:organism:actuate  domain=%s  status=%s', $domain, (string) ($result['status'] ?? '')));
        $this->line('  proposal_ref: '.(string) ($result['proposal_ref'] ?? ''));
        $this->line('  ceiling:      '.(string) ($result['ceiling'] ?? ''));
        $audit = (array) ($result['audit'] ?? []);
        $this->line(sprintf(
            '  audit:        recorded=%s  receipt=%s%s',
            ($audit['recorded'] ?? false) ? 'yes' : 'no',
            (string) ($audit['receipt_ref'] ?? ''),
            isset($audit['reason']) ? '  reason='.(string) $audit['reason'] : '',
        ));
        $this->line('');
        $this->line('  instructions: '.(string) ($result['instructions'] ?? ''));
        $this->line('');
        $this->comment(
            'PROPOSE-ONLY: status is requires_operator — Atlas does NOT execute real money/orders/'
            .'ad-spend/purchases/publishing. The operator executes any real-world action themselves.'
        );

        return self::SUCCESS;
    }
}
