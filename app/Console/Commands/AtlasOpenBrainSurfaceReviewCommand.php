<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasOpenBrainMcpService;
use Illuminate\Console\Command;

final class AtlasOpenBrainSurfaceReviewCommand extends Command
{
    protected $signature = 'atlas:open-brain:surface-review {--json : Emit canonical JSON}';

    protected $description = 'Review Open Brain MCP tool surface against primary tools and usage telemetry.';

    public function handle(AtlasOpenBrainMcpService $openBrain): int
    {
        $payload = $openBrain->surfaceReview();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        }

        foreach ((array) ($payload['tools'] ?? []) as $tool) {
            $this->components->twoColumnDetail(
                (string) ($tool['tool_name'] ?? 'unknown'),
                sprintf(
                    'verdict=%s usage=%d window=%s',
                    (string) ($tool['verdict'] ?? 'unknown'),
                    (int) ($tool['usage_count'] ?? 0),
                    (string) ($tool['window_started_at'] ?? 'n/a'),
                ),
            );
        }

        return self::SUCCESS;
    }
}
