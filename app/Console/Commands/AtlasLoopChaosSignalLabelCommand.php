<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Antifragile\AtlasLoopChaosSignalLabeler;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopChaosSignalLabeler::label()} at the operator surface: reads a negative loop
 * event from a JSON file and emits its attributable, shape-keyed learning label as a deterministic fact.
 *
 * Read-only + pure + fact-only: without a shape_token the labeler refuses to mint a lesson (it never launders a
 * generic failure into false learning). No provider/DB/mutation.
 */
final class AtlasLoopChaosSignalLabelCommand extends Command
{
    protected $signature = 'atlas:loop:chaos-signal-label {--input=} {--json}';

    protected $description = 'Read-only chaos-signal label for a negative loop event (shape-keyed learning signal).';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('chaos-signal-label requires --input=<path to a readable event JSON>');
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            return $this->refuse('input file is not a JSON object');
        }
        $event = isset($decoded['event']) && is_array($decoded['event']) ? $decoded['event'] : $decoded;

        $label = app(AtlasLoopChaosSignalLabeler::class)->label($event);

        if ($this->option('json')) {
            $this->line((string) json_encode($label, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('label: '.$label['label'].'  attributable: '.($label['attributable'] ? 'yes' : 'no'));
            if ($label['lesson'] !== null) {
                $this->line($label['lesson']);
            }
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
