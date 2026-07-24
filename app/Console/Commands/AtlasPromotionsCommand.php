<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AcosProgram\PromotionProtocol;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasPromotionsCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:promotions
        {--flag= : Managed flag id to flip}
        {--to= : Target state: off, shadow, live, rolled_back, suspended_pending_evidence}
        {--window= : Observation window id for a flip}
        {--receipt= : Flip receipt ref}
        {--actor=atlas : Actor recording the flip}
        {--reason= : Human-readable reason}
        {--json : Machine-readable output}';

    protected $description = 'ELEV-26s - list ACOS Max promotion states or record a governed promotion flip.';

    public function handle(PromotionProtocol $protocol): int
    {
        $flag = trim((string) $this->option('flag'));
        $to = trim((string) $this->option('to'));

        $payload = $flag !== '' || $to !== ''
            ? $protocol->flip($flag, $to, [
                'observation_window_id' => trim((string) $this->option('window')),
                'receipt' => trim((string) $this->option('receipt')),
                'actor' => trim((string) $this->option('actor')),
                'reason' => trim((string) $this->option('reason')),
            ])
            : $protocol->report();

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        if (($payload['ok'] ?? true) === false) {
            $this->components->warn('[promotion-protocol] blocked: '.($payload['reason'] ?? 'unknown'));

            return self::SUCCESS;
        }

        if (($payload['status'] ?? null) === 'recorded') {
            $event = (array) ($payload['event'] ?? []);
            $this->components->info(sprintf(
                '[promotion-protocol] %s %s -> %s (%s)',
                (string) ($event['flag_id'] ?? ''),
                (string) ($event['from_state'] ?? ''),
                (string) ($event['to_state'] ?? ''),
                (string) ($event['observation_window_id'] ?? ''),
            ));

            return self::SUCCESS;
        }

        foreach ((array) ($payload['flags'] ?? []) as $flagRow) {
            if (! is_array($flagRow)) {
                continue;
            }
            $this->components->twoColumnDetail(
                (string) ($flagRow['id'] ?? ''),
                (string) ($flagRow['status'] ?? '').' / '.(string) ($flagRow['state'] ?? ''),
            );
        }

        return self::SUCCESS;
    }
}
