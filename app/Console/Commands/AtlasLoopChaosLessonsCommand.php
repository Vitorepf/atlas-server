<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Antifragile\AtlasLoopChaosSignalLabeler;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Closure;
use Illuminate\Console\Command;
use Throwable;

/**
 * Arms the dormant {@see AtlasLoopChaosSignalLabeler} at the operator surface: reads recent negative loop events
 * (give-back / cancelled records carrying kind, shape_token, provider, reason) and mints a per-event
 * attributable lesson via label() — refusing (unattributable) when no shape_token is present. Each failure
 * becomes a shape-keyed lesson instead of being lost. Read-only.
 */
final class AtlasLoopChaosLessonsCommand extends Command
{
    /** Container key for an injected event source (test seam): callable(int $limit): list<array>. */
    private const EVENT_SOURCE_BINDING = 'atlas.loop.chaos_lessons.event_source';

    protected $signature = 'atlas:loop:chaos-lessons {--limit=50} {--json}';

    protected $description = 'Read-only chaos-lesson extraction: turn negative loop events into attributable shape-keyed lessons.';

    public function handle(AtlasLoopChaosSignalLabeler $labeler): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $lessons = array_map(
            static fn (array $event): array => $labeler->label($event),
            $this->events($limit),
        );

        $this->line((string) json_encode([
            'schema_version' => 'atlas.loop.chaos_lessons.v1',
            'lessons_count' => count($lessons),
            'lessons' => $lessons,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function events(int $limit): array
    {
        $app = $this->getLaravel();
        if ($app->bound(self::EVENT_SOURCE_BINDING)) {
            $source = $app->make(self::EVENT_SOURCE_BINDING);
            if (is_callable($source)) {
                $result = $source($limit);

                return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
            }
        }

        return $this->fromServingStore();
    }

    /**
     * Best-effort projection of negative serving-store records into labeler events. Fail-open.
     *
     * @return list<array{kind:string, shape_token:string, provider:string, reason:string}>
     */
    private function fromServingStore(): array
    {
        try {
            $events = [];
            foreach (['released', 'cancelled', 'given_back', 'blocked'] as $status) {
                foreach (AtlasTaskServingStack::queueRepo()->list(['status' => $status]) as $row) {
                    $events[] = [
                        'kind' => 'given_back',
                        'shape_token' => (string) (data_get($row, 'give_back.shape_token') ?? data_get($row, 'task_packet.shape_token') ?? ''),
                        'provider' => (string) (data_get($row, 'provider') ?? ''),
                        'reason' => (string) (data_get($row, 'give_back.reason') ?? data_get($row, 'reason') ?? $status),
                    ];
                }
            }

            return $events;
        } catch (Throwable) {
            return [];
        }
    }
}
