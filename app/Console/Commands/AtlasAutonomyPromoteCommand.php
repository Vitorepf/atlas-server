<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopTierPromotionChainService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Obra #14 H3.2 · S49 — operator-only tier promotion CLI.
 *
 * Loads an operator decision receipt (JSON file) and runs it through the
 * promotion chain. Without a valid SIGNED receipt the chain blocks honestly.
 * NEVER auto-promotes; NEVER invokes a provider.
 */
class AtlasAutonomyPromoteCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:autonomy:promote
        {area : Canonical area_id (e.g. agentic_engineering_os)}
        {--tier=1 : Requested autonomy tier}
        {--receipt= : Path to the operator decision receipt JSON}
        {--json : Emit JSON}';

    protected $description = 'Atlas Autônomos autonomy tier promotion (S49): evaluate an operator-signed receipt and persist the promotion. No receipt = honest block. Never invokes a provider.';

    public function handle(AtlasLoopTierPromotionChainService $chain): int
    {
        $receipt = $this->loadReceipt();
        $receipt['requested_tier'] = $receipt['requested_tier'] ?? (int) $this->option('tier');

        $result = $chain->promote($receipt, (string) $this->argument('area'));

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));
        } else {
            $this->components->twoColumnDetail('Decision', strtoupper((string) $result['decision']));
            $this->components->twoColumnDetail('Area', (string) $result['area_id']);
            $this->components->twoColumnDetail('Tier', (string) $result['tier']);
            if ($result['blockers'] !== []) {
                $this->components->twoColumnDetail('Blockers', implode(', ', $result['blockers']));
            }
        }

        return $result['decision'] === 'promote' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadReceipt(): array
    {
        $path = trim((string) $this->option('receipt'));
        if ($path === '' || ! is_file($path)) {
            return []; // no receipt ⇒ the evaluator blocks with operator_receipt_signature_missing
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
