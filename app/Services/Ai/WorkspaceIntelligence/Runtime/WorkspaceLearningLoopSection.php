<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence\Runtime;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceRuntimeService;

/**
 * GOD-DEBULK FASE C extraction of the AWIS workspace learning-loop engine
 * (operational memory candidates, evidence learning feedback, closed-loop status and
 * next-action projection) from AtlasWorkspaceIntelligenceRuntimeService.
 *
 * Method bodies are moved VERBATIM. This family is self-contained (no callbacks into
 * façade helpers); the mother/__call seam is kept for parity with the sibling sections.
 */
final class WorkspaceLearningLoopSection
{
    private ?AtlasWorkspaceIntelligenceRuntimeService $mother = null;

    public function setMother(AtlasWorkspaceIntelligenceRuntimeService $mother): self
    {
        $this->mother = $mother;

        return $this;
    }

    /**
     * @param  array<int,mixed>  $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        if ($this->mother === null) {
            throw new \RuntimeException(self::class.' mother not bound for '.$name);
        }

        // ponytail: parity seam with the sibling sections — rebind into the façade scope
        // so any shared private helper stays reachable without widening visibility.
        return (function () use ($name, $arguments) {
            return $this->{$name}(...$arguments);
        })->call($this->mother);
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<string,mixed>  $continuity
     * @param  array<string,mixed>  $artifactFabric
     * @param  array<string,mixed>  $artifactIntelligence
     * @param  array<string,mixed>  $contracts
     * @param  array<string,mixed>  $evolution
     * @param  array<string,mixed>  $changeMemory
     * @param  array<string,mixed>  $focusMap
     * @param  array<string,mixed>  $nextSessionBrain
     * @param  array<string,mixed>  $liveExecutionMemory
     * @return array<string,mixed>
     */
    public function workspaceLearningLoop(
        ?array $profile,
        string $task,
        array $continuity,
        array $artifactFabric,
        array $artifactIntelligence,
        array $contracts,
        array $evolution,
        array $changeMemory,
        array $focusMap,
        array $nextSessionBrain,
        array $liveExecutionMemory,
    ): array {
        $workspaceId = $profile['slug'] ?? null;
        $artifacts = array_values(array_filter((array) ($artifactFabric['artifacts'] ?? []), 'is_array'));
        $artifactHashes = array_values(array_filter(array_map(
            static fn (array $artifact): string => (string) ($artifact['artifact_hash'] ?? ''),
            $artifacts,
        )));
        $decisions = array_values((array) data_get($continuity, 'current_truth_pack.active_decisions', []));
        $blockers = array_values((array) data_get($continuity, 'current_truth_pack.open_blockers', []));
        $riskZones = array_values((array) ($profile['critical_areas'] ?? []));
        $criticalChanges = array_values((array) data_get($changeMemory, 'critical_areas_touched', []));
        $changeHash = (string) data_get($changeMemory, 'change_hash', '');

        $events = array_values(array_filter([
            [
                'event_type' => 'workspace_bound',
                'event_status' => $workspaceId === null ? 'blocked' : 'observed',
                'source_ref' => $workspaceId === null ? 'workspace:missing' : 'workspace:'.$workspaceId,
            ],
            trim($task) !== '' ? [
                'event_type' => 'task_received',
                'event_status' => 'observed',
                'source_ref' => 'task:'.hash('sha256', trim($task)),
            ] : null,
            count((array) data_get($continuity, 'segmentation_map', [])) > 0 ? [
                'event_type' => 'conversation_segmented',
                'event_status' => 'observed',
                'source_ref' => 'archive:'.(string) data_get($continuity, 'raw_archive.archive_hash', ''),
            ] : null,
            count($artifactHashes) > 0 ? [
                'event_type' => 'artifacts_compiled',
                'event_status' => 'observed',
                'source_ref' => 'artifact_fabric:'.(string) ($artifactFabric['artifact_fabric_hash'] ?? ''),
            ] : null,
            data_get($changeMemory, 'status') !== 'blocked' ? [
                'event_type' => 'workspace_changes_observed',
                'event_status' => data_get($changeMemory, 'dirty') === true ? 'dirty' : 'clean',
                'source_ref' => $changeHash !== '' ? 'workspace_change_memory:'.$changeHash : 'workspace_change_memory:unknown',
            ] : null,
        ]));

        $nextAction = $this->workspaceLearningNextAction($contracts, $artifactIntelligence);
        $loopClosed = $workspaceId !== null
            && data_get($contracts, 'execution_readiness_status') === 'ready'
            && data_get($artifactIntelligence, 'artifact_replay.replay_ready') === true
            && $nextAction['status'] !== 'blocked';

        $payload = [
            'schema_version' => 'atlas.awis.workspace_intelligence_loop.v1',
            'status' => $loopClosed ? 'ready' : 'blocked',
            'workspace_id' => $workspaceId,
            'event_intake' => [
                'schema_version' => 'atlas.awis.event_intake.v1',
                'events' => $events,
                'event_count' => count($events),
                'raw_conversation_stored' => false,
            ],
            'understanding' => [
                'schema_version' => 'atlas.awis.event_understanding.v1',
                'decisions' => $decisions,
                'blockers' => $blockers,
                'risk_zones' => $riskZones,
                'changed_top_level_areas' => array_values((array) data_get($changeMemory, 'top_level_areas', [])),
                'critical_areas_touched' => $criticalChanges,
                'summary_hash' => MissionCanonicalHash::sha256([$decisions, $blockers, $riskZones, $criticalChanges]),
            ],
            'operational_memory' => [
                'schema_version' => 'atlas.awis.operational_memory_update.v1',
                'memory_scope' => 'workspace',
                'promotion_policy' => 'candidate_only_until_evidence_review',
                'canonical_doc_rewrite_allowed' => false,
                'candidate_units' => [
                    'current_truth_pack',
                    'workspace_runbook_delta',
                    'failure_signature_candidate',
                    'pattern_library_signal',
                    'workspace_change_memory',
                    'workspace_focus_map',
                    'workspace_live_execution_memory',
                    'workspace_next_session_brain',
                ],
                'memory_candidate_hash' => MissionCanonicalHash::sha256([
                    'workspace_id' => $workspaceId,
                    'decisions' => $decisions,
                    'blockers' => $blockers,
                    'patterns' => data_get($evolution, 'pattern_library.patterns', []),
                    'workspace_change_hash' => $changeHash,
                    'workspace_focus_hash' => data_get($focusMap, 'focus_hash'),
                    'workspace_live_execution_memory_hash' => data_get($liveExecutionMemory, 'live_memory_hash'),
                    'workspace_next_session_brain_hash' => data_get($nextSessionBrain, 'brain_hash'),
                ]),
            ],
            'context_application' => [
                'schema_version' => 'atlas.awis.context_application.v1',
                'default_context_units' => ['workspace_brief', 'task_packet', 'context_pack', 'test_plan', 'risk_sheet'],
                'context_pack_hash' => (string) data_get($artifactIntelligence, 'artifact_context_compiler.current_truth_pack_hash', ''),
                'artifact_hashes' => $artifactHashes,
                'workspace_change_memory_hash' => $changeHash !== '' ? $changeHash : null,
                'workspace_focus_hash' => data_get($focusMap, 'focus_hash'),
                'workspace_live_execution_memory_hash' => data_get($liveExecutionMemory, 'live_memory_hash'),
                'workspace_next_session_brain_hash' => data_get($nextSessionBrain, 'brain_hash'),
                'focused_repositories' => data_get($focusMap, 'focused_repositories', []),
                'focused_areas' => data_get($focusMap, 'focused_areas', []),
                'focused_commands' => data_get($focusMap, 'focused_commands', []),
                'changed_files_preview' => array_values((array) data_get($changeMemory, 'changed_files_preview', [])),
                'critical_changes_require_review' => $criticalChanges !== [],
                'raw_conversation_included' => false,
                'raw_diff_included' => false,
                'provider_prompt_allowed' => data_get($contracts, 'execution_readiness_status') === 'ready',
                'stale_policy' => 'regenerate_awis_before_mutative_execution',
            ],
            'next_action' => $nextAction,
            'evidence_learning' => [
                'schema_version' => 'atlas.awis.evidence_learning.v1',
                'evidence_refs' => array_values(array_filter(array_merge(
                    array_map(static fn (string $hash): string => 'awis_artifact:'.$hash, array_slice($artifactHashes, 0, 5)),
                    [
                        ($artifactFabric['artifact_fabric_hash'] ?? null) !== null ? 'awis_artifact_fabric:'.$artifactFabric['artifact_fabric_hash'] : null,
                        data_get($artifactIntelligence, 'artifact_graph.graph_hash') !== null ? 'awis_artifact_graph:'.data_get($artifactIntelligence, 'artifact_graph.graph_hash') : null,
                        ($contracts['contract_hash'] ?? null) !== null ? 'awis_contract:'.$contracts['contract_hash'] : null,
                        ($evolution['evolution_hash'] ?? null) !== null ? 'awef:'.$evolution['evolution_hash'] : null,
                        $changeHash !== '' ? 'awis_workspace_change_memory:'.$changeHash : null,
                        data_get($focusMap, 'focus_hash') !== null ? 'awis_workspace_focus_map:'.data_get($focusMap, 'focus_hash') : null,
                        data_get($liveExecutionMemory, 'live_memory_hash') !== null ? 'awis_workspace_live_execution_memory:'.data_get($liveExecutionMemory, 'live_memory_hash') : null,
                        data_get($nextSessionBrain, 'brain_hash') !== null ? 'awis_workspace_next_session_brain:'.data_get($nextSessionBrain, 'brain_hash') : null,
                    ],
                ))),
                'missing_evidence' => $loopClosed ? [] : ['ready_workspace_contract_or_replay_required'],
                'feedback_targets' => ['AEMOR', 'AWEF', 'workspace_runbook', 'context_autopilot'],
                'learning_score' => $loopClosed ? 0.98 : 0.0,
            ],
            'closed_loop' => [
                'event_to_understanding' => count($events) > 0,
                'understanding_to_memory' => true,
                'memory_to_context' => count($artifactHashes) > 0,
                'context_to_next_action' => $nextAction['status'] !== 'blocked',
                'next_action_to_evidence' => true,
                'evidence_to_learning' => true,
                'loop_closed' => $loopClosed,
            ],
            'claim_policy' => [
                'read_only' => true,
                'provider_calls_made' => false,
                'spends_tokens' => false,
                'raw_conversation_returned' => false,
                'raw_diff_returned' => false,
                'absolute_workspace_path_returned' => false,
                'auto_promotes_memory' => false,
                'cross_workspace_learning_allowed' => false,
            ],
        ];
        $payload['loop_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $contracts
     * @param  array<string,mixed>  $artifactIntelligence
     * @return array<string,mixed>
     */
    private function workspaceLearningNextAction(array $contracts, array $artifactIntelligence): array
    {
        if (data_get($contracts, 'execution_readiness_status') !== 'ready') {
            return [
                'schema_version' => 'atlas.awis.next_action.v1',
                'status' => 'blocked',
                'action' => 'regenerate_or_certify_workspace_artifacts',
                'target' => 'AWCO',
                'reasons' => ['workspace_contract_not_ready'],
            ];
        }

        $simulationDecision = (string) data_get($artifactIntelligence, 'artifact_simulation.decision', 'blocked');
        $target = in_array('multi_domain', (array) data_get($artifactIntelligence, 'artifact_simulation.escalate_to_forge_when', []), true)
            ? 'atlas_dev_or_forge'
            : 'atlas_dev';

        return [
            'schema_version' => 'atlas.awis.next_action.v1',
            'status' => $simulationDecision === 'blocked' ? 'blocked' : 'ready',
            'action' => 'prepare_provider_safe_handoff',
            'target' => $target,
            'reasons' => ['workspace_bound', 'artifacts_certified', 'raw_conversation_excluded'],
        ];
    }
}
