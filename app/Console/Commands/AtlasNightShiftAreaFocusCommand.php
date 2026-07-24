<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\NightShift\AreaFocusLoopReadModelService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Night Shift · Area Focus Loop · read-only read model (slice 1).
 *
 * Surfaces the canonical Area Focus Loop scout for a chosen area (default
 * `agentic_engineering_os`) without executing any work. No writes, no provider,
 * no branch, no Dev/Forge dispatch — operator review only.
 */
class AtlasNightShiftAreaFocusCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:night-shift:area-focus
        {--area=agentic_engineering_os : Canonical area_id to focus}
        {--hours=24 : Self-Directed Evolution gap window in hours}
        {--limit= : Cap the number of findings}
        {--json : Emit JSON}';

    protected $description = 'Atlas Night Shift · Area Focus Loop read-only read model (no writes, no provider, no execution, no Dev/Forge dispatch).';

    public function handle(AreaFocusLoopReadModelService $readModel): int
    {
        $input = [
            'area_id' => (string) $this->option('area'),
            'hours' => (int) $this->option('hours'),
        ];
        if (($limit = $this->option('limit')) !== null && $limit !== '') {
            $input['limit'] = (int) $limit;
        }

        $payload = $readModel->project($input);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return ($payload['status'] ?? '') === AreaFocusLoopReadModelService::STATUS_BLOCKED
                ? self::FAILURE
                : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Area Focus Loop', 'read-only read model (slice 1)');
        $this->components->twoColumnDetail('Area', (string) ($payload['area_id'] ?? '?'));
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Mode', (string) ($payload['mode'] ?? '?'));
        $this->components->twoColumnDetail('Findings', (string) ($payload['finding_count'] ?? 0));

        $routing = $payload['routing_summary'] ?? [];
        if (is_array($routing)) {
            $this->components->twoColumnDetail(
                'Routing (advisory)',
                sprintf(
                    'sde=%d · dev=%d · forge=%d · queued=%d · inbox=%d',
                    (int) ($routing['self_directed_evolution'] ?? 0),
                    (int) ($routing['atlas_dev'] ?? 0),
                    (int) ($routing['atlas_forge'] ?? 0),
                    (int) ($routing['queued'] ?? 0),
                    (int) ($routing['inbox_only'] ?? 0),
                ),
            );
        }

        $inbox = $payload['morning_inbox'] ?? [];
        if (is_array($inbox)) {
            $this->components->twoColumnDetail(
                'Morning Inbox',
                sprintf(
                    '%d operator decision(s) → %s',
                    (int) ($inbox['decision_count'] ?? 0),
                    (string) ($inbox['destination'] ?? '?'),
                ),
            );
        }

        foreach ($payload['findings'] ?? [] as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $this->line(sprintf(
                '  [%s] %s · sev=%s · route=%s',
                (string) ($finding['source'] ?? '?'),
                (string) ($finding['title'] ?? ''),
                (string) ($finding['severity'] ?? '?'),
                (string) ($finding['route'] ?? '?'),
            ));
        }

        foreach ($payload['blockers'] ?? [] as $blocker) {
            if (! is_array($blocker)) {
                continue;
            }
            $this->warn(sprintf(
                '  blocker: %s · %s · %s',
                (string) ($blocker['source'] ?? '?'),
                (string) ($blocker['reason'] ?? '?'),
                (string) ($blocker['detail'] ?? ''),
            ));
        }

        return ($payload['status'] ?? '') === AreaFocusLoopReadModelService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }
}
