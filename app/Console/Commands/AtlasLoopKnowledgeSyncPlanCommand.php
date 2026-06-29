<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\KnowledgeSync\AtlasKnowledgeSyncPostMergePlan;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasKnowledgeSyncPostMergePlan::plan()} at the operator surface: previews the
 * post-merge knowledge-sync plan — which docs/code-index/context-pack actions to run, with command hints —
 * from the artifact map, the docs-drift verdict and the code-index readiness verdict. Read-only: it plans
 * only; it runs no sync. Blocked when docs drift or the code index isn't ready.
 *
 * --artifacts / --docs-drift / --code-index accept inline JSON or a path to a JSON file.
 */
final class AtlasLoopKnowledgeSyncPlanCommand extends Command
{
    protected $signature = 'atlas:loop:knowledge-sync-plan {--artifacts=} {--docs-drift=} {--code-index=} {--json}';

    protected $description = 'Read-only: preview the post-merge knowledge-sync plan (plan only, no sync run).';

    public function handle(AtlasKnowledgeSyncPostMergePlan $planner): int
    {
        $artifacts = $this->jsonRequired('artifacts');
        if ($artifacts === null) {
            return self::INVALID;
        }
        $docsDrift = $this->jsonRequired('docs-drift');
        if ($docsDrift === null) {
            return self::INVALID;
        }
        $codeIndex = $this->jsonRequired('code-index');
        if ($codeIndex === null) {
            return self::INVALID;
        }

        $this->line((string) json_encode(
            $planner->plan($artifacts, $docsDrift, $codeIndex),
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
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => str_replace('-', '_', $name).'_required'], JSON_UNESCAPED_SLASHES));

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
