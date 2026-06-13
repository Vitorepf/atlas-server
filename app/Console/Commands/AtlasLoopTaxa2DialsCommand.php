<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopTaxa2DialOverlayService;
use Illuminate\Console\Command;

/**
 * L5-5 TAXA² proof command: compute the governed Loop dial overlay and,
 * when requested, persist the exact receipt consumed by the campaign supervisor.
 */
final class AtlasLoopTaxa2DialsCommand extends Command
{
    protected $signature = 'atlas:loop:taxa2-dials
        {--hours= : Lookback window in hours (default config atlas.loop.taxa2_dials.window_hours)}
        {--write-receipt : Persist an append-only dial receipt}
        {--require-adjusted : Exit non-zero unless at least one dial changed}
        {--strict : Exit non-zero when the overlay is disabled}
        {--json : Machine-readable JSON output}';

    protected $description = 'Compute TAXA² raise-only, clamped Loop dials from measured outcomes and optionally write an audit receipt.';

    public function handle(AtlasLoopTaxa2DialOverlayService $overlay): int
    {
        $payload = $overlay->evaluate([
            'hours' => $this->hoursOption(),
            'write_receipt' => (bool) $this->option('write-receipt') || (bool) config('atlas.loop.taxa2_dials.receipt_on_command', true),
            'source' => 'atlas:loop:taxa2-dials',
        ]);

        $exit = self::SUCCESS;
        if ((bool) $this->option('strict') && ($payload['status'] ?? null) === 'disabled') {
            $exit = self::FAILURE;
        }
        if ((bool) $this->option('require-adjusted') && ! (bool) ($payload['changed'] ?? false)) {
            $exit = self::FAILURE;
        }

        return $this->emit($payload, $exit);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->components->info('Atlas Loop TAXA² dials');
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Changed', (bool) ($payload['changed'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Scenarios', (string) data_get($payload, 'base_dials.scenarios_per_task').' -> '.(string) data_get($payload, 'effective_dials.scenarios_per_task'));
        $this->components->twoColumnDetail('Queue watermark', (string) data_get($payload, 'base_dials.queue_low_watermark').' -> '.(string) data_get($payload, 'effective_dials.queue_low_watermark'));
        $this->components->twoColumnDetail('Refill batch', (string) data_get($payload, 'base_dials.refill_batch').' -> '.(string) data_get($payload, 'effective_dials.refill_batch'));
        $this->components->twoColumnDetail('Receipt', (string) data_get($payload, 'receipt.receipt_path', 'not written'));

        return $exit;
    }

    private function hoursOption(): ?int
    {
        $raw = trim((string) ($this->option('hours') ?? ''));
        if ($raw === '' || ! ctype_digit($raw)) {
            return null;
        }

        return (int) $raw;
    }
}
