<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasVoxOperationalThinkingInterfaceService;
use Illuminate\Console\Command;

/**
 * Runtime surface for the Atlas Vox Operational Thinking Interface doc.
 *
 * Without args it prints the governance snapshot (Escada Vox V0-V10, the Leis
 * Vox, the canonical flow and the minimum VOX_* events). With --promote it
 * evaluates a ladder promotion (one rung at a time, Lei 0.9 freeze for V4+),
 * optionally relative to --current and with --gate-v3-green when GATE V3 is
 * certified.
 *
 * @see docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md
 */
final class AtlasVoxOperationalThinkingInterfaceCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-vox-operational-thinking-interface
        {--promote= : Evaluate promotion to this ladder level (0-10)}
        {--current=3 : Current ladder level for the promotion check (default 3)}
        {--gate-v3-green : Treat GATE V3 as certified green for the promotion check}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect and evaluate the Atlas Vox Operational Thinking Interface (Escada Vox V0-V10, Leis Vox, GATE V3 promotion freeze, flow, events).';

    public function handle(AtlasVoxOperationalThinkingInterfaceService $service): int
    {
        $json = (bool) $this->option('json');

        try {
            $promote = $this->option('promote');
            if (is_string($promote) && trim($promote) !== '') {
                return $this->emit(
                    $service->canPromoteTo(
                        (int) $promote,
                        (int) $this->option('current'),
                        (bool) $this->option('gate-v3-green'),
                    ),
                    $json
                );
            }

            return $this->emit($service->snapshot(), $json);
        } catch (\Throwable $e) {
            $this->emit([
                'schema_version' => AtlasVoxOperationalThinkingInterfaceService::SCHEMA_VERSION,
                'error' => true,
                'message' => $e->getMessage(),
            ], $json);

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, bool $json): int
    {
        $this->line((string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        return self::SUCCESS;
    }
}
