<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\V4\AtlasLoopV4MetaObjectiveOriginator;
use Closure;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopV4MetaObjectiveOriginator::originate()} at the operator surface via a
 * DETERMINISTIC writer: it cites the first real NUMERIC fact from the supplied origination outcomes and proposes
 * a positive origination delta. The originator's anti-Goodhart gates (grounded citations, anti-proxy vocabulary,
 * reachability ceiling) still decide; the writer is in-memory and never an LLM.
 *
 * Pure read-only: it originates a grounded meta-objective (or its refusal) and reports; it seeds/mutates nothing.
 */
final class AtlasLoopMetaObjectiveOriginateCommand extends Command
{
    protected $signature = 'atlas:loop:meta-objective-originate {--origination=} {--delivery=} {--capability=} {--json}';

    protected $description = 'Read-only meta-objective origination over outcome facts (deterministic grounded writer).';

    public function handle(): int
    {
        $origination = $this->readJson('origination');
        if ($origination === null) {
            return $this->refuse('meta-objective-originate requires --origination=<json object or path>');
        }
        $delivery = $this->readJson('delivery') ?? [];
        $capability = $this->readJson('capability') ?? [];

        // Deterministic writer: cite the first numeric origination fact (matching the originator's fact index),
        // propose a small positive origination delta. No LLM.
        $writer = static function (array $orig, array $del, array $cap): ?array {
            foreach ($orig as $key => $value) {
                if (is_int($value) || is_float($value)) {
                    return [
                        'objective' => 'Grow origination throughput grounded in the measured origination outcomes.',
                        'cited_facts' => ['origination.'.((string) $key).'='.((string) $value)],
                        'target_metric' => 'origination',
                        'target_delta' => 0.5,
                    ];
                }
            }

            return null; // no numeric origination fact ⇒ ungrounded
        };

        $result = (new AtlasLoopV4MetaObjectiveOriginator(Closure::fromCallable($writer)))
            ->originate($origination, $delivery, $capability);

        $facts = ['schema' => 'atlas.loop.v4_meta_objective_originate.v1'] + $result;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('originated: '.($result['originated'] ? 'yes' : 'no').'  reason: '.($result['reason'] ?? '-'));
        }

        return self::SUCCESS;
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $option): ?array
    {
        $raw = trim((string) $this->option($option));
        if ($raw === '') {
            return null;
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
