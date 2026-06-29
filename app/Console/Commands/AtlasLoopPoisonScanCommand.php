<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskHiddenPoisonDetector;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasTaskHiddenPoisonDetector::detect()} at the operator surface: scans a task
 * packet for hidden-poison patterns the blocking_deficiencies pipeline does not already catch (removed-target
 * leftover, contradictory acceptance, unavailable dependency, duplicate canonical symbol, ambiguous
 * instruction, permanent-autonomy-dependency wording) and emits the findings.
 *
 * Pure + read-only: it scans and reports; it never mutates the packet or the queue.
 */
final class AtlasLoopPoisonScanCommand extends Command
{
    protected $signature = 'atlas:loop:poison-scan {--packet=} {--json}';

    protected $description = 'Read-only hidden-poison scan of a task packet (findings advisory).';

    public function handle(): int
    {
        $raw = trim((string) $this->option('packet'));
        if ($raw === '') {
            return $this->refuse('poison-scan requires --packet=<json object or path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $packet = json_decode($raw, true);
        if (! is_array($packet)) {
            return $this->refuse('--packet must be a JSON object');
        }

        $result = app(AtlasTaskHiddenPoisonDetector::class)->detect($packet);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('clean: '.($result['clean'] ? 'yes' : 'no').'  findings: '.count($result['found_patterns']));
            foreach ($result['found_patterns'] as $f) {
                $this->line('  '.$f['pattern_id']);
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
