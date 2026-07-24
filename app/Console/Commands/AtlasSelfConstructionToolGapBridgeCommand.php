<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionToolGapBridgeService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use App\Support\YesNo;

/**
 * L5-4 · Bridge recurrent capability gaps from the Loop loss-observer into the
 * governed self-construction corridor (parked proposal, human approval required).
 *
 * Default-safe: dry-run unless --write. Never approves, stages, promotes, merges
 * or runs a provider. The proposal is always parked for operator review.
 */
final class AtlasSelfConstructionToolGapBridgeCommand extends Command
{
    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:self-construction:tool-gap-bridge
        {--campaign= : Scope to one campaign_id (default: all)}
        {--window-hours= : Loss observation window in hours (default: config)}
        {--min-occurrences= : Minimum recurrences to qualify a capability gap (default: config)}
        {--max-proposals= : Cap on proposals emitted per run (default: config)}
        {--write : Persist the parked self-construction proposal(s); default is dry-run}
        {--strict : Exit non-zero unless at least one capability gap was routed}
        {--json : Canonical JSON output}';

    protected $description = 'L5-4 · Detect recurrent capability gaps from the Loop loss-observer and route them into the governed self-construction corridor (parked, human approval required).';

    public function handle(AtlasSelfConstructionToolGapBridgeService $bridge): int
    {
        try {
            $result = $bridge->detectAndRoute(array_filter([
                'campaign_id' => $this->stringOption('campaign'),
                'window_hours' => $this->intOption('window-hours'),
                'min_occurrences' => $this->intOption('min-occurrences'),
                'max_proposals' => $this->intOption('max-proposals'),
                'write' => (bool) $this->option('write') ?: null,
            ], static fn (mixed $v): bool => $v !== null));
        } catch (QueryException) {
            $payload = ['status' => 'runtime_refused', 'reason' => 'loop_db_unavailable'];
            if ($this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('tool-gap-bridge: loop-funnel DB unavailable ('.$e->getMessage().')');
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->info('Self-construction tool-gap bridge (L5-4)');
            $this->components->twoColumnDetail('Status', (string) $result['status']);
            $this->components->twoColumnDetail('Observed patterns', (string) $result['observed_pattern_count']);
            $this->components->twoColumnDetail('Capability gaps', (string) $result['capability_gap_count']);
            $this->components->twoColumnDetail('Proposals', (string) $result['proposal_count']);
            foreach ((array) $result['proposals'] as $p) {
                $this->line(sprintf(
                    '- %s · %dx · %s · %s · approval_required=%s',
                    (string) ($p['tool_name'] ?? 'unknown'),
                    (int) ($p['occurrences'] ?? 0),
                    (string) ($p['proposal_id'] ?? ''),
                    (string) ($p['service_class'] ?? ''),
                    YesNo::trueFalse($p['requires_human_approval'] ?? true),
                ));
            }
        }

        if ((bool) $this->option('strict') && (int) $result['capability_gap_count'] === 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function intOption(string $key): ?int
    {
        $raw = trim((string) ($this->option($key) ?: ''));

        return $raw === '' || ! ctype_digit($raw) ? null : (int) $raw;
    }

}
