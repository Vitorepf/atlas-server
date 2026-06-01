<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasMissionControlCockpitSurfaceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Mission Control Cockpit — gesture/receipt gate CLI (debug surface).
 *
 * Doc canon: docs/engineering-knowledge-base/atlas-mission-control-cockpit-spec.md
 *
 * Actions:
 *   catalogue (default)         — list the 7 canonical gestures + receipt rules.
 *   authorize --gesture=<g>     — gate one gesture; pass --receipt-json + flags.
 *   validate-receipt --receipt-json='{...}' — validate an Operator Decision Receipt.
 */
class AtlasMissionControlCockpitSurfaceCommand extends Command
{
    protected $signature = 'atlas:aaeos:mission-control-cockpit-surface
        {--action=catalogue : catalogue|authorize|validate-receipt}
        {--gesture= : gesture id (for authorize)}
        {--receipt-json= : Operator Decision Receipt JSON (for authorize|validate-receipt)}
        {--context-json= : gesture context JSON (for authorize)}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Mission Control Cockpit — gate operator gestures against the Operator Decision Receipt contract.';

    public function handle(AtlasMissionControlCockpitSurfaceService $service): int
    {
        $action = (string) $this->option('action');
        $json = (bool) $this->option('json');

        try {
            $payload = match ($action) {
                'catalogue' => $service->gestureCatalogue(),
                'authorize' => $service->authorizeGesture(
                    (string) ($this->option('gesture') ?? ''),
                    $this->decodeArray((string) ($this->option('receipt-json') ?? '')),
                    $this->decodeArray((string) ($this->option('context-json') ?? '')) ?? [],
                ),
                'validate-receipt' => $service->validateReceipt(
                    $this->decodeArray((string) ($this->option('receipt-json') ?? '')),
                ),
                default => ['error' => "unknown action '{$action}'"],
            };
        } catch (Throwable $e) {
            $payload = [
                'error' => true,
                'message' => $e->getMessage(),
                'action' => $action,
            ];
            $this->emit($payload, $json);

            return self::FAILURE;
        }

        $this->emit($payload, $json);

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeArray(string $raw): ?array
    {
        if (trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, bool $json): void
    {
        if ($json) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return;
        }
        foreach ($payload as $k => $v) {
            $this->line(is_scalar($v) ? "{$k}: {$v}" : "{$k}: ".json_encode($v));
        }
    }
}
