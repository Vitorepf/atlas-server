<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\KnowledgeSync\AtlasKnowledgeSyncCodeIndexReadinessGate;
use App\Services\Ai\SelfConstruction\KnowledgeSync\AtlasKnowledgeSyncDocsDriftGate;
use App\Services\Ai\SelfConstruction\KnowledgeSync\AtlasKnowledgeSyncPostMergePlan;
use App\Services\Ai\SelfConstruction\KnowledgeSync\AtlasKnowledgeSyncRequiredArtifactMap;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only knowledge-freshness runtime. Proves docs, code-index, memory projection, and
 * post-merge refresh actions are current before the external brain trusts stale context packs or
 * stale code intelligence for the next origination cycle. Composes:
 *   1. {@see AtlasKnowledgeSyncRequiredArtifactMap}     — which artifacts a change requires
 *   2. {@see AtlasKnowledgeSyncDocsDriftGate}            — whether docs evidence is fresh/conformant
 *   3. {@see AtlasKnowledgeSyncCodeIndexReadinessGate}   — whether the code index is fresh enough to trust
 *   4. {@see AtlasKnowledgeSyncPostMergePlan}            — the ordered post-merge maintenance plan +
 *      the explicit queue/code-index refresh check gating next_origination_allowed
 *
 * Convergence: docs_drift.required_artifacts defaults to the artifact map's derived
 * required_artifacts unless the caller explicitly overrides it.
 *
 * Never enqueues, mutates evidence, calls providers, or runs git — read-only reporting only.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { artifact_map:{...}, docs_drift:{...}, code_index:{manifest, observations},
 *     post_merge:{artifact_map, candidate}, queue_reality_refresh:{...} }
 */
final class AtlasExternalBrainKnowledgeFreshnessCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:knowledge-freshness
        {--input= : Path to a JSON file with artifact_map, docs_drift, code_index, post_merge, queue_reality_refresh sections}';

    /** @var string */
    protected $description = 'Read-only knowledge-freshness runtime (artifact map + docs drift gate + code-index readiness + post-merge plan).';

    public function handle(
        AtlasKnowledgeSyncRequiredArtifactMap $artifactMap,
        AtlasKnowledgeSyncDocsDriftGate $docsDriftGate,
        AtlasKnowledgeSyncCodeIndexReadinessGate $codeIndexGate,
        AtlasKnowledgeSyncPostMergePlan $postMergePlan,
    ): int {
        $inputPath = trim((string) $this->option('input'));
        if ($inputPath === '' || ! is_file($inputPath)) {
            $this->error('--input=<path> required and must exist');

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($inputPath), true);
        if (! is_array($decoded)) {
            $this->error('invalid input JSON');

            return self::FAILURE;
        }

        $artifactMapFacts = is_array($decoded['artifact_map'] ?? null) ? $decoded['artifact_map'] : [];
        $artifactMapResult = $artifactMap->derive($artifactMapFacts);

        $docsDriftFacts = is_array($decoded['docs_drift'] ?? null) ? $decoded['docs_drift'] : [];
        if (! array_key_exists('required_artifacts', $docsDriftFacts)) {
            $docsDriftFacts['required_artifacts'] = $artifactMapResult['required_artifacts'];
        }
        $docsDriftResult = $docsDriftGate->evaluate($docsDriftFacts);

        $codeIndexSection = is_array($decoded['code_index'] ?? null) ? $decoded['code_index'] : [];
        $codeIndexManifest = is_array($codeIndexSection['manifest'] ?? null) ? $codeIndexSection['manifest'] : [];
        $codeIndexObservations = is_array($codeIndexSection['observations'] ?? null) ? $codeIndexSection['observations'] : [];
        $codeIndexResult = $codeIndexGate->evaluate($codeIndexManifest, $codeIndexObservations);

        $postMergeSection = is_array($decoded['post_merge'] ?? null) ? $decoded['post_merge'] : [];
        $postMergeArtifactMap = is_array($postMergeSection['artifact_map'] ?? null) ? $postMergeSection['artifact_map'] : [];
        $postMergeCandidate = is_array($postMergeSection['candidate'] ?? null) ? $postMergeSection['candidate'] : [];
        $postMergeResult = $postMergePlan->plan($postMergeArtifactMap, $docsDriftResult, $codeIndexResult, $postMergeCandidate);

        $queueRefreshFacts = is_array($decoded['queue_reality_refresh'] ?? null) ? $decoded['queue_reality_refresh'] : [];
        $queueRefreshResult = $postMergePlan->planQueueRealityRefresh($queueRefreshFacts);

        $payload = [
            'status' => 'ok',
            'required_artifacts' => $artifactMapResult['required_artifacts'],
            'docs_drift_conformant' => $docsDriftResult['conformant'],
            'docs_drift_blockers' => $docsDriftResult['blockers'],
            'code_index_ready' => $codeIndexResult['ready'],
            'code_index_blockers' => $codeIndexResult['blockers'],
            'post_merge_blocked' => $postMergeResult['blocked'],
            'post_merge_actions' => $postMergeResult['actions'],
            'post_merge_command_hints' => $postMergeResult['command_hints'],
            'post_merge_blockers' => $postMergeResult['blockers'],
            'next_origination_allowed' => $queueRefreshResult['next_origination_allowed'],
            'missing_refreshes' => $queueRefreshResult['missing_refreshes'],
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
