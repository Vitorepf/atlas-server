<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\CognitiveMemory\AtlasCognitiveWorkingSetMemoryService;
use Illuminate\Console\Command;

/**
 * SIS1 (Obra #20) — Working Memory UNA persistida: read/write surface.
 *
 * `atlas:working-set --track='{...}'` records a hot-context item for a scope;
 * `atlas:working-set --show` reads it back. Because the working set now persists
 * to one shared file, a fact recorded in one invocation is visible in the next —
 * the survives-the-process primitive underneath mobile→desktop continuity.
 */
class AtlasWorkingSetCommand extends Command
{
    protected $signature = 'atlas:working-set
        {--scope=default : working-set scope (operator/project)}
        {--track= : JSON item to record {content, content_hash?, must_keep?}}
        {--mode=balanced : budget mode for the view}
        {--json : machine-readable output}';

    protected $description = 'SIS1 — read/write the persisted working memory (survives the process).';

    public function handle(): int
    {
        // Explicit shared path → the UNA persistida surface. (DI-resolved
        // instances stay in-process; persistence is opt-in by path.)
        $ws = new AtlasCognitiveWorkingSetMemoryService(AtlasCognitiveWorkingSetMemoryService::sharedPath());
        $scope = (string) $this->option('scope');

        if ($raw = (string) ($this->option('track') ?? '')) {
            try {
                $item = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                $this->error('--track precisa ser JSON válido: '.$e->getMessage());

                return self::FAILURE;
            }
            if (! is_array($item)) {
                $this->error('--track deve ser um objeto JSON');

                return self::FAILURE;
            }
            $ws->track($scope, $item);
        }

        $view = $ws->workingSet($scope, (string) $this->option('mode'));

        if ($this->option('json')) {
            $this->line((string) json_encode($view + ['persist_path' => $ws->persistPath()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(sprintf('[working-set] scope=%s kept=%d/%d (persistido: %s)', $scope, $view['kept'], $view['total_tracked'], $ws->persistPath()));
        foreach ($view['items'] as $it) {
            $this->line(sprintf('  %s  heat=%s', substr((string) ($it['content'] ?? $it['content_hash'] ?? '?'), 0, 60), (string) ($it['heat_score'] ?? '?')));
        }

        return self::SUCCESS;
    }
}
