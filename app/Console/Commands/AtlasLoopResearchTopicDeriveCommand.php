<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopResearchTopicDeriver;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopResearchTopicDeriver::derive()} at the operator surface: reads a target's
 * structural signals from a JSON file and, against the repo root, emits the derived (clean, public) research
 * topic as a deterministic fact (the frontier feed). Read-only — it only maps shape→concept and routes it
 * through the egress filter; it pulls nothing. Flag-gated default-OFF ⇒ no topic.
 */
final class AtlasLoopResearchTopicDeriveCommand extends Command
{
    protected $signature = 'atlas:loop:research-topic-derive {--input=} {--json}';

    protected $description = 'Read-only: derive the (egress-clean) research topic for a target from its structural signals.';

    public function handle(AtlasLoopResearchTopicDeriver $deriver): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'input_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }
        if (! is_file($input)) {
            $this->line((string) json_encode(['status' => 'input_not_found', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $signals = json_decode((string) file_get_contents($input), true);
        if (! is_array($signals)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $topic = $deriver->derive($signals, base_path());

        $this->line((string) json_encode([
            'schema' => 'atlas.loop.research_topic_derive.v1',
            'has_topic' => $topic !== null,
            'derived_topic' => $topic,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
