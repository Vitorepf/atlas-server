<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Foundry\FoundryExhaustionRarityGateService;
use Illuminate\Console\Command;

/**
 * Foundry AP-B · exhaustion & rarity gate (Invariant I8).
 *
 * Thin, DECIDE-ONLY entrypoint over FoundryExhaustionRarityGateService::decide().
 * It evaluates eligibility (not_eligible | eligible | blocked | skipped) and prints
 * the verdict. It GENERATES NOTHING, NEVER unlocks generation (that is the
 * downstream, separately-gated AP-C), NEVER writes canon, NEVER merges, NEVER calls
 * a provider. Read-only.
 *
 * Default-off: with the Frontier flag off the gate returns status=skipped and the
 * honest backlog_exhausted stop remains authoritative.
 */
class AtlasFoundryExhaustionGateCommand extends Command
{
    protected $signature = 'atlas:foundry:exhaustion-gate '
        .'{--area= : Loop area id (default agentic_engineering_os) — names the real ledger read POST-RUN} '
        .'{--focus=dev_forge : Loop focus key for the ledger read} '
        .'{--window= : Consecutive measured-zero-admissible cycles required (window_n, >=2)} '
        .'{--enabled : Consult the gate (default OFF: status=skipped, honest backlog_exhausted stop authoritative)} '
        .'{--input= : Path to a decide() input JSON (merged under the flags; omit for none)} '
        .'{--json : Emit JSON}';

    protected $description = 'Decide Foundry AP-B eligibility (I8 exhaustion & rarity gate); decides only, generates nothing, never unlocks generation.';

    public function handle(FoundryExhaustionRarityGateService $gate): int
    {
        $input = [];
        $path = $this->option('input');

        if ($path !== null && $path !== '') {
            if (! is_file((string) $path) || ! is_readable((string) $path)) {
                $this->error('Input file not found or unreadable: '.(string) $path);

                return self::FAILURE;
            }
            $decoded = json_decode((string) file_get_contents((string) $path), true);
            if (! is_array($decoded)) {
                $this->error('Input JSON is invalid.');

                return self::FAILURE;
            }
            $input = $decoded;
        }

        // Flags map onto the decide() input seam. The gate reads the REAL loop ledger
        // (Reliable24hLoopRunnerService::readLedgerRecords for this area/focus) POST-RUN
        // and is read-only/decide-only: it never re-runs the session, never writes the
        // ledger, never merges, never unlocks generation. Explicit flags win over --input.
        $area = $this->option('area');
        if ($area !== null && $area !== '') {
            $input['area'] = (string) $area;
        }

        $focus = $this->option('focus');
        if ($focus !== null && $focus !== '') {
            $input['focus'] = (string) $focus;
        }

        $window = $this->option('window');
        if ($window !== null && $window !== '') {
            $input['window_n'] = (int) $window;
        }

        // Default OFF: only --enabled (or config frontier_mode) consults the gate.
        // Absent the flag the caller treats the run as the unchanged honest
        // backlog_exhausted stop (status=skipped). NEVER force-disable here, so config
        // frontier_mode still resolves inside the service.
        if ((bool) $this->option('enabled')) {
            $input['exhaustion_rarity_gate_enabled'] = true;
        }

        $result = $gate->decide($input);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('Status', (string) ($result['status'] ?? ''));
            $this->components->twoColumnDetail('Budget leg', (string) ($result['budget_leg'] ?? ''));
            $this->components->twoColumnDetail('Rarity leg', (string) ($result['rarity_leg'] ?? ''));
            $this->components->twoColumnDetail('Consecutive zero-admissible cycles', (string) ($result['consecutive_admissible_zero_cycles'] ?? 0));
            $this->components->twoColumnDetail('Window N', (string) ($result['window_n'] ?? 0));
            $this->components->twoColumnDetail('Drop reason', (string) ($result['drop_reason'] ?? '—'));
            $this->components->twoColumnDetail('Fallback is honest stop', ($result['fallback_is_honest_stop'] ?? false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Gate hash', (string) ($result['gate_hash'] ?? ''));
        }

        return self::SUCCESS;
    }
}
