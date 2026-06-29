<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Numeric\NumericSafetyGuard;
use Illuminate\Console\Command;

/**
 * Arms the dormant pure {@see NumericSafetyGuard} static ops at the operator surface: runs the selected guard
 * op (finite / safeDivide / clamp / safeMean) over the supplied args and emits the guarded result (never
 * NaN/INF) as a deterministic fact. Pure (io=0), read-only.
 *
 * --args accepts inline JSON or a path to a JSON file (the op's argument bag).
 */
final class AtlasLoopNumericSafeCommand extends Command
{
    private const OPS = ['finite', 'safeDivide', 'clamp', 'safeMean'];

    protected $signature = 'atlas:loop:numeric-safe {--op=} {--args=} {--json}';

    protected $description = 'Read-only: run a deterministic numeric-safety guard op (never NaN/INF).';

    public function handle(): int
    {
        $op = trim((string) $this->option('op'));
        if ($op === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'op_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }
        if (! in_array($op, self::OPS, true)) {
            $this->line((string) json_encode(['status' => 'unknown_op', 'reason' => 'op_must_be_one_of:'.implode('|', self::OPS)], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $args = [];
        $argsOption = $this->option('args');
        if ($argsOption !== null && trim((string) $argsOption) !== '') {
            $raw = is_file((string) $argsOption) ? (string) file_get_contents((string) $argsOption) : (string) $argsOption;
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                $this->line((string) json_encode(['status' => 'invalid_json', 'args' => (string) $argsOption], JSON_UNESCAPED_SLASHES));

                return self::INVALID;
            }
            $args = $decoded;
        }

        $result = match ($op) {
            'finite' => NumericSafetyGuard::finite($args['value'] ?? null, (float) ($args['default'] ?? 0.0)),
            'safeDivide' => NumericSafetyGuard::safeDivide($args['numerator'] ?? null, $args['denominator'] ?? null, (float) ($args['when_zero'] ?? 0.0)),
            'clamp' => NumericSafetyGuard::clamp($args['value'] ?? null, (float) ($args['min'] ?? 0.0), (float) ($args['max'] ?? 0.0), (float) ($args['default'] ?? 0.0)),
            'safeMean' => NumericSafetyGuard::safeMean((array) ($args['values'] ?? []), (float) ($args['when_empty'] ?? 0.0)),
        };

        $this->line((string) json_encode([
            'schema' => 'atlas.loop.numeric_safe.v1',
            'op' => $op,
            'result' => $result,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
