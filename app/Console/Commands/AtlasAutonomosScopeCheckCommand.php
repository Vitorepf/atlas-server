<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use Illuminate\Console\Command;

/**
 * A3 — answers, for a path, the one question the Autônomo cannot ask today:
 * "can I even touch this?"
 *
 * FORBIDDEN_AXES values are PREFIXES, not paths — `cartografia/` blocks
 * `cartografia/x.ts`. Matching them with an exact-set intersection would report
 * every real file as free, so the check is str_starts_with, exactly as
 * AgentControlPlaneTaskPacketBuilder:249 and the validator itself do it.
 *
 * Read-only: it reads the constant, writes nothing, claims nothing.
 */
class AtlasAutonomosScopeCheckCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:autonomos:scope-check {path* : repo-relative path(s) to check} {--json}';

    protected $description = 'Says whether a path crosses the Autônomos FORBIDDEN_AXES (hard blocker → reject_commit).';

    public function handle(): int
    {
        $results = [];
        foreach ((array) $this->argument('path') as $raw) {
            $path = ltrim(str_replace('\\', '/', trim((string) $raw)), '/');
            $axis = null;
            $prefix = null;
            foreach (AgentControlPlaneScopeLockRuntimeValidator::FORBIDDEN_AXES as $name => $candidate) {
                if (str_starts_with($path, $candidate)) {
                    $axis = $name;
                    $prefix = $candidate;
                    break;
                }
            }
            $results[] = [
                'path' => $path,
                'blocked' => $axis !== null,
                'axis' => $axis,
                'prefix' => $prefix,
                'blocker' => $axis !== null ? 'forbidden_axis' : null,
                'commit_decision' => $axis !== null
                    ? AgentControlPlaneScopeLockRuntimeValidator::COMMIT_DECISION_REJECT_COMMIT
                    : AgentControlPlaneScopeLockRuntimeValidator::COMMIT_DECISION_VALID,
            ];
        }

        $blocked = array_values(array_filter($results, static fn (array $r): bool => $r['blocked']));

        if ($this->option('json')) {
            $this->jsonLine([
                'schema_version' => 'atlas.autonomos.scope_check.v1',
                'checked' => count($results),
                'blocked_count' => count($blocked),
                'results' => $results,
                'forbidden_axes' => AgentControlPlaneScopeLockRuntimeValidator::FORBIDDEN_AXES,
            ]);
        } else {
            foreach ($results as $r) {
                $this->line($r['blocked']
                    ? sprintf('BLOCKED  %s  (axis=%s prefix=%s → reject_commit)', $r['path'], $r['axis'], $r['prefix'])
                    : sprintf('free     %s', $r['path']));
            }
        }

        return $blocked === [] ? self::SUCCESS : self::FAILURE;
    }
}
