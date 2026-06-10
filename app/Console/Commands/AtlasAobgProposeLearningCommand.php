<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasOpenBrainWriteBackService;
use Illuminate\Console\Command;

/**
 * AOBG N1.F2 — `atlas:aobg:propose-learning`: the CLI mirror of the propose_learning
 * write-back tool, for testing + audit. An external session (or the operator) proposes
 * a learning/decision → the canonical capture pipeline (quality gate + provider-safety)
 * → lands as a PROPOSAL requiring human review. NEVER auto-promotes, NEVER mutates
 * canonical memory. Input is treated as untrusted.
 *
 *     atlas:aobg:propose-learning --kind=retrieval_hint \
 *         --summary="cache the workspace resolver result per-request" \
 *         --evidence=app/Services/.../Resolver.php:42 --json
 *
 * Always exits 0 — a quality/validation rejection is a normal, audited answer. The
 * proposal lands as pending_review; the operator approves via the existing review CLI.
 */
class AtlasAobgProposeLearningCommand extends Command
{
    public const SCHEMA = 'atlas.aobg.propose_learning_command.v1';

    protected $signature = 'atlas:aobg:propose-learning
        {--kind= : One of policy|routing|gate|benchmark|heuristic|retrieval_hint|memory|failure_pattern — required}
        {--summary= : The proposed learning, one sentence — required}
        {--evidence=* : A citation (file:line / id / hash), repeatable — at least one required}
        {--scope= : Scope (default global)}
        {--flow-id= : Optional audit flow id}
        {--provider= : Provider/agent label}
        {--privacy-class= : Self-declared privacy class — must be normal or omitted}
        {--workspace= : Workspace path or id (defaults to the primary atlas-server)}
        {--json : Output the result envelope as JSON}';

    protected $description = 'AOBG N1.F2: propose a learning from an external session — lands as pending_review (quality-gated, provider-safe, NEVER auto-applied).';

    public function handle(AtlasOpenBrainWriteBackService $service): int
    {
        $evidence = array_values(array_filter((array) $this->option('evidence'), 'is_string'));

        $input = array_filter([
            'kind' => $this->stringOpt('kind'),
            'summary' => $this->stringOpt('summary'),
            'evidence_refs' => $evidence,
            'scope' => $this->stringOpt('scope'),
            'flow_id' => $this->stringOpt('flow-id'),
            'provider' => $this->stringOpt('provider'),
            'privacy_class' => $this->stringOpt('privacy-class'),
            'workspace' => $this->stringOpt('workspace'),
        ], static fn ($v): bool => $v !== null && $v !== []);

        $result = $service->proposeLearning($input);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if (($result['ok'] ?? false) === true) {
            $this->info(sprintf(
                'proposed  id=%s  status=%s  kind=%s  applied=no  auto_promoted=no',
                (string) ($result['proposal_id'] ?? ''),
                (string) ($result['status'] ?? ''),
                (string) ($result['kind'] ?? ''),
            ));
        } else {
            $this->warn(sprintf(
                'not proposed  status=%s  reason=%s',
                (string) ($result['status'] ?? 'n/a'),
                (string) ($result['reason'] ?? 'n/a'),
            ));
        }

        return self::SUCCESS;
    }

    private function stringOpt(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
