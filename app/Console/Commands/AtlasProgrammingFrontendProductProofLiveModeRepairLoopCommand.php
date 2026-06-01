<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendProductProofLiveModeRepairLoopService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Live-mode repair-loop product-proof gate evaluator CLI.
 *
 *   php artisan atlas:aaeos:programming-frontend-product-proof-live-mode-repair-loop
 *     [--viewport=desktop]
 *     [--stages=select_element,generate_preview,accept_patch,prove_recovery]
 *     [--evidence=browser_pick_event,preview_variant_event,accepted_variant_diff,recover_session]
 *     [--claims-parity]
 *     [--external-replay]
 *     [--real-journal]
 *     [--json]
 *
 * Read-only, deterministic. Emits the proven/incomplete/claim_violation verdict
 * + receipt for one declared live-mode repair-loop run. With no options it
 * evaluates the canonical fully-proven local sample.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-product-proof-live_mode_repair_loop.md
 */
class AtlasProgrammingFrontendProductProofLiveModeRepairLoopCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-frontend-product-proof-live-mode-repair-loop
        {--viewport= : viewport the cycle ran in (default desktop)}
        {--stages= : comma-separated completed stage ids, in order (default all four)}
        {--evidence= : comma-separated captured evidence event ids (default all four)}
        {--claims-parity : the run claims live-mode operational parity}
        {--external-replay : a real external-baseline replay journal exists}
        {--real-journal : a real (non-manifest) cycle journal exists}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Evaluate one Atlas Frontend live-mode repair-loop run against the documented manifest contract.';

    public function handle(AtlasProgrammingFrontendProductProofLiveModeRepairLoopService $service): int
    {
        try {
            $run = $service->provenSample();

            $viewport = $this->option('viewport');
            if (is_string($viewport) && trim($viewport) !== '') {
                $run['viewport'] = trim($viewport);
            }

            $stages = $this->splitList($this->option('stages'));
            if ($stages !== null) {
                $run['completed_stages'] = $stages;
            }

            $evidence = $this->splitList($this->option('evidence'));
            if ($evidence !== null) {
                $run['evidence'] = $evidence;
            }

            $run['claims_live_mode_parity'] = (bool) $this->option('claims-parity');
            $run['external_replay_present'] = (bool) $this->option('external-replay');
            $run['real_journal_present'] = (bool) $this->option('real-journal');

            $verdict = $service->evaluate($run);

            $this->line((string) json_encode(
                ['ok' => true, 'verdict' => $verdict],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'live_mode_repair_loop_proof_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /**
     * Split a comma-separated option into a clean list, or null when not supplied.
     *
     * @return list<string>|null
     */
    private function splitList(mixed $raw): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $parts = [];
        foreach (explode(',', $raw) as $item) {
            $trimmed = trim($item);
            if ($trimmed !== '') {
                $parts[] = $trimmed;
            }
        }

        return $parts;
    }
}
