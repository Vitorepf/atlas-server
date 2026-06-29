<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\NamingPolicy\QuarantineAuthorizationGate;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see QuarantineAuthorizationGate::evaluate()} at the operator surface: previews whether a
 * target path is authorized out of quarantine (in-scope + reachability-dead + unused >90d + a valid operator
 * Decision Receipt v2) and emits the verdict (decision + per-check results + blocking reasons) as
 * deterministic facts. ADVISORY read-only: it previews authorization, authorizes nothing, mutates nothing.
 *
 * --snapshot / --receipt accept inline JSON or a path to a JSON file.
 */
final class AtlasLoopQuarantineCheckCommand extends Command
{
    protected $signature = 'atlas:loop:quarantine-check {--target=} {--snapshot=} {--receipt=} {--json}';

    protected $description = 'Read-only/advisory: preview whether a target path is authorized out of quarantine.';

    public function handle(QuarantineAuthorizationGate $gate): int
    {
        $target = trim((string) $this->option('target'));
        if ($target === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'target_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $snapshot = $this->decode($this->option('snapshot')) ?? [];
        $receipt = $this->decode($this->option('receipt')); // null when absent

        $this->line((string) json_encode(
            $gate->evaluate($target, $snapshot, $receipt),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decode(mixed $value): ?array
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $raw = is_file((string) $value) ? (string) file_get_contents((string) $value) : (string) $value;
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
