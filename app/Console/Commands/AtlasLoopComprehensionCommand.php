<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionDeltaTracker;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionDocReader;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionGraphSerializer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionStalenessDetector;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use Illuminate\Console\Command;
use Throwable;

final class AtlasLoopComprehensionCommand extends Command
{
    protected $signature = 'atlas:loop:comprehension {action : snapshot|diff|stale|doc-gaps} {--old=} {--new=} {--path=} {--threshold=1800} {--json}';

    protected $description = 'Emit Loop comprehension FACTS: snapshot, diff, stale, or doc-gaps. No scores, no opinions.';

    public function handle(): int
    {
        $action = strtolower(trim((string) $this->argument('action')));

        try {
            $payload = match ($action) {
                'snapshot' => $this->snapshot(),
                'diff' => $this->diff(),
                'stale' => $this->stale(),
                'doc-gaps' => $this->docGaps(),
                default => $this->invalidAction($action),
            };
        } catch (Throwable $e) {
            $payload = [
                'schema' => 'atlas.loop.comprehension.error.v1',
                'ok' => false,
                'action' => $action,
                'error' => $e->getMessage(),
            ];
            $this->emit($payload);

            return self::FAILURE;
        }

        $this->emit($payload);

        return ($payload['ok'] ?? true) === false ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        $model = (new AtlasLoopScopeComprehensionModelBuilder)->build(
            base_path(),
            'app/Services/Ai/AutonomousEvolution/Discovery',
            ['docs_roots' => []],
        );
        $serializer = new AtlasLoopComprehensionGraphSerializer;
        $path = $serializer->writeSnapshot(
            $model->toArray(),
            (string) ($this->option('path') ?: storage_path('app/atlas/loop/comprehension')),
        );

        return [
            'schema' => 'atlas.loop.comprehension.snapshot.v1',
            'snapshot_path' => $path,
            'snapshot_sha256' => hash_file('sha256', $path) ?: '',
            'classes_count' => count($model->inventory),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function diff(): array
    {
        $old = trim((string) $this->option('old'));
        $new = trim((string) $this->option('new'));
        if ($old === '' || $new === '') {
            return $this->failure('diff', 'diff_requires_old_and_new_snapshot_paths');
        }

        $serializer = new AtlasLoopComprehensionGraphSerializer;

        return (new AtlasLoopComprehensionDeltaTracker)->diff(
            $serializer->readSnapshot($old),
            $serializer->readSnapshot($new),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function stale(): array
    {
        return (new AtlasLoopComprehensionStalenessDetector($this->threshold()))
            ->detect((string) $this->option('path'));
    }

    /**
     * @return array<string,mixed>
     */
    private function invalidAction(string $action): array
    {
        return [
            'schema' => 'atlas.loop.comprehension.error.v1',
            'ok' => false,
            'action' => $action,
            'error' => 'invalid_action',
            'allowed_actions' => ['snapshot', 'diff', 'stale', 'doc-gaps'],
        ];
    }

    /**
     * Read-only FACTS over the loop docs: every heading section + every doc-stated gap (a bullet that
     * names a falta/gap/todo/missing/pendente/aberto). $docsDir = --path, else the repo's docs dir.
     *
     * @return array<string,mixed>
     */
    private function docGaps(): array
    {
        $docsDir = (string) ($this->option('path') ?: base_path('docs'));

        return ['schema' => 'atlas.loop.comprehension.doc_gaps.v1']
            + (new AtlasLoopComprehensionDocReader)->read($docsDir);
    }

    /**
     * @return array<string,mixed>
     */
    private function failure(string $action, string $error): array
    {
        return [
            'schema' => 'atlas.loop.comprehension.error.v1',
            'ok' => false,
            'action' => $action,
            'error' => $error,
        ];
    }

    private function threshold(): int
    {
        return max(1, (int) $this->option('threshold'));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return;
        }

        if (($payload['ok'] ?? true) === false) {
            $this->error((string) ($payload['error'] ?? 'comprehension command failed'));

            return;
        }

        foreach ($payload as $key => $value) {
            $this->line($key.': '.(is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES)));
        }
    }
}
