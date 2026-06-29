<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\WorkerSwarm\AtlasSelfConstructionWorkerResultNormalizer;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasSelfConstructionWorkerResultNormalizer::normalize()} at the operator surface:
 * normalizes a raw worker result against its task into a canonical shape — court status (verified_pass /
 * verified_fail / pending_review / blocked), verified evidence, out-of-scope files and blockers — emitted as
 * deterministic facts. Pure and read-only.
 *
 * --task / --result accept inline JSON or a path to a JSON file.
 */
final class AtlasLoopWorkerResultNormalizeCommand extends Command
{
    protected $signature = 'atlas:loop:worker-result-normalize {--task=} {--result=} {--json}';

    protected $description = 'Read-only: normalize a worker result against its task into a canonical verified shape.';

    public function handle(AtlasSelfConstructionWorkerResultNormalizer $normalizer): int
    {
        $task = $this->jsonRequired('task');
        if ($task === null) {
            return self::INVALID;
        }
        $result = $this->jsonRequired('result');
        if ($result === null) {
            return self::INVALID;
        }

        $this->line((string) json_encode(
            $normalizer->normalize($task, $result),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>|null  null signals an emitted error (caller returns INVALID)
     */
    private function jsonRequired(string $name): ?array
    {
        $value = $this->option($name);
        if ($value === null || trim((string) $value) === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => $name.'_required'], JSON_UNESCAPED_SLASHES));

            return null;
        }
        $raw = is_file((string) $value) ? (string) file_get_contents((string) $value) : (string) $value;
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'option' => $name], JSON_UNESCAPED_SLASHES));

            return null;
        }

        return $decoded;
    }
}
