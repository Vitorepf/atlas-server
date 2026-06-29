<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\SelfConstructionCapabilityLadderLevelClassifier;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see SelfConstructionCapabilityLadderLevelClassifier::classify()} at the operator
 * surface: reads a capability's prerequisite signals JSON and emits its ladder classification (level, label,
 * achieved prerequisites, where the chain broke).
 *
 * Pure + read-only: it classifies and reports — it mutates nothing, calls no provider/DB. The ladder is
 * SEQUENTIAL: the level is the count of consecutive leading prerequisites satisfied (the first gap caps it).
 */
final class AtlasLoopCapabilityLevelCommand extends Command
{
    protected $signature = 'atlas:loop:capability-level {--signals=} {--json}';

    protected $description = 'Read-only capability-ladder level classification from prerequisite signals.';

    public function handle(): int
    {
        $raw = trim((string) $this->option('signals'));
        if ($raw === '') {
            return $this->refuse('capability-level requires --signals=<json object or path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }

        $signals = json_decode($raw, true);
        if (! is_array($signals)) {
            return $this->refuse('--signals must be a JSON object');
        }

        $classification = app(SelfConstructionCapabilityLadderLevelClassifier::class)->classify($signals);

        if ($this->option('json')) {
            $this->line((string) json_encode($classification, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('level: '.$classification['level'].'  label: '.$classification['label']);
            $this->line('achieved: '.implode(', ', $classification['achieved_prerequisites']));
        }

        return self::SUCCESS;
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
