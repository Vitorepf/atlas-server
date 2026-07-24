<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Services\Ai\LongHorizon\LongHorizonContinuityCertificationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * TEOS-I2 M10 · Long-Horizon Continuity Certification CLI.
 *
 *   php artisan atlas:long-horizon:continuity-certify
 *     --scope-type=<mission|work_order|obra|dev_run|dev_session|...>
 *     --scope-id=<uuid|slug>
 *     [--continuation-pack=<uuid>]
 *     [--intended-mode=execute|read_only|review|...]
 *     [--strict]                  // strict freshness — warn escalates to blocked
 *     [--strict-replay]           // strict replay — missing replay manifest = P0
 *     [--required-evidence=kind1,kind2]
 *     [--json]                    // default true; preserved for compat
 *
 * Exit codes:
 *   0 — `ready` or `partial` (when not --strict);
 *   1 — `blocked`, or `partial`/`blocked` when --strict.
 *
 * Read-only. Never executes a provider, never runs benchmark/rivals.
 */
class AtlasLongHorizonContinuityCertifyCommand extends Command
{
    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:long-horizon:continuity-certify
        {--scope-type= : scope_type (mission|work_order|obra|dev_run|dev_session|dev_workstream|forge_run|forge_obra|work_packet|thread|long_horizon)}
        {--scope-id= : scope_id (uuid/slug)}
        {--continuation-pack= : explicit continuation pack uuid}
        {--intended-mode= : execute|read_only|review|repair|ask_human|blocked|escalate_to_forge}
        {--strict : escalate any freshness warn to blocked}
        {--strict-replay : missing replay manifest becomes a P0 blocker}
        {--required-evidence= : comma-separated evidence kinds required for completion}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas TEOS-I2 · long-horizon continuity certification for a (scope_type, scope_id).';

    public function handle(LongHorizonContinuityCertificationService $service): int
    {
        $scopeType = $this->stringOption('scope-type');
        if ($scopeType === null) {
            $this->emit(['ok' => false, 'error' => 'missing_scope_type', 'usage' => 'atlas:long-horizon:continuity-certify --scope-type=<type> [--scope-id=<id>]']);

            return self::FAILURE;
        }

        $required = $this->stringOption('required-evidence');
        $requiredKinds = $required === null
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $required)), fn ($v): bool => $v !== ''));

        $input = [
            'scope_type' => $scopeType,
            'scope_id' => $this->stringOption('scope-id'),
            'continuation_pack_id' => $this->stringOption('continuation-pack'),
            'intended_mode' => $this->stringOption('intended-mode'),
            'strict' => (bool) $this->option('strict'),
            'strict_replay_required' => (bool) $this->option('strict-replay'),
            'required_evidence_kinds' => $requiredKinds,
        ];

        try {
            $report = $service->certify($input);
        } catch (Throwable $e) {
            $this->emit([
                'ok' => false,
                'error' => 'exception',
                'type' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        $this->emit($report);

        $status = $report['status'] ?? LongHorizonContinuityCertificationService::STATUS_BLOCKED;
        if ($status === LongHorizonContinuityCertificationService::STATUS_BLOCKED) {
            return self::FAILURE;
        }
        if ((bool) $this->option('strict') && $status !== LongHorizonContinuityCertificationService::STATUS_READY) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }


    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }
}
