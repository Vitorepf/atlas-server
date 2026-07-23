<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionCompletionEvidenceHashComposerService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService;
/**
 * GOD-DEBULK extracted stateful operator-evidence-draft status family from AtlasSelfConstructionReadinessService (completion evidence hash composer, draft hash finalizer, draft workspace publisher, artifact template pack, draft workspace inspector).
 * Bound via setMother(); undefined method calls bridge through __call and undefined
 * property reads bridge through __get (ReflectionMethod / ReflectionProperty on the mother)
 * so the moved bodies stay byte-identical to the god service originals.
 */
final class ReadinessProjectionOperatorEvidenceDraftSection
{
    private ?AtlasSelfConstructionReadinessService $mother = null;

    public function setMother(AtlasSelfConstructionReadinessService $mother): self
    {
        $this->mother = $mother;

        return $this;
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($this->mother === null) {
            throw new \RuntimeException('ReadinessProjectionOperatorEvidenceDraftSection mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }

    public function __get(string $name): mixed
    {
        $property = new \ReflectionProperty($this->mother, $name);

        return $property->getValue($this->mother);
    }


    public function atlasSelfConstructionCompletionEvidenceHashComposerStatus(array $options = []): array
    {
        $result = (new AtlasSelfConstructionCompletionEvidenceHashComposerService)->compose([
            'runtime_promotion_receipt' => (array) ($options['runtime_promotion_receipt'] ?? $this->decodeJsonOption($options['runtime_promotion_receipt_json'] ?? null)),
            'completion_receipt' => (array) ($options['completion_receipt'] ?? $this->decodeJsonOption($options['completion_receipt_json'] ?? null)),
            'real_provider_smoke' => (array) ($options['real_provider_smoke'] ?? $this->decodeJsonOption($options['real_provider_smoke_json'] ?? null)),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_completion_evidence_hash_composer',
            label: 'Atlas Self-Construction Completion Evidence Hash Composer',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'composer_hash' => (string) data_get($result, 'composer_hash'),
                'runtime_promotion_receipt_hash' => (string) data_get($result, 'runtime_promotion_receipt.computed_hash'),
                'human_completion_receipt_hash' => (string) data_get($result, 'human_completion_receipt.computed_hash'),
                'real_provider_smoke_hash' => (string) data_get($result, 'real_provider_smoke.computed_hash'),
                'completion_claim_allowed' => false,
            ],
        );
    }

    public function atlasSelfConstructionOperatorEvidenceDraftHashFinalizerStatus(array $options = []): array
    {
        $result = (new AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => (string) ($options['operator_draft_workspace_path'] ?? ''),
            'write_computed_operator_draft_hashes' => (bool) ($options['write_computed_operator_draft_hashes'] ?? false),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_operator_evidence_draft_hash_finalizer',
            label: 'Atlas Self-Construction Operator Evidence Draft Hash Finalizer',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'finalizer_hash' => (string) data_get($result, 'finalizer_hash'),
                'artifact_count' => (int) data_get($result, 'artifact_count', 0),
                'ready_artifact_count' => (int) data_get($result, 'ready_artifact_count', 0),
                'blocked_artifact_count' => (int) data_get($result, 'blocked_artifact_count', 0),
                'written_artifact_count' => (int) data_get($result, 'written_artifact_count', 0),
                'write_requested' => (bool) data_get($result, 'write_requested', false),
                'completion_allowed' => false,
            ],
        );
    }

    public function atlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherStatus(array $options = []): array
    {
        $result = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspacePublisherService)->publish([
            'operator_draft_workspace_path' => (string) ($options['operator_draft_workspace_path'] ?? ''),
            'publish_operator_draft_workspace' => (bool) ($options['publish_operator_draft_workspace'] ?? false),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_operator_evidence_draft_workspace_publisher',
            label: 'Atlas Self-Construction Operator Evidence Draft Workspace Publisher',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'publisher_hash' => (string) data_get($result, 'publisher_hash'),
                'artifact_count' => (int) data_get($result, 'artifact_count', 0),
                'publishable_artifact_count' => (int) data_get($result, 'publishable_artifact_count', 0),
                'blocked_artifact_count' => (int) data_get($result, 'blocked_artifact_count', 0),
                'published_artifact_count' => (int) data_get($result, 'published_artifact_count', 0),
                'publish_requested' => (bool) data_get($result, 'publish_requested', false),
                'completion_allowed' => false,
            ],
        );
    }

    public function atlasSelfConstructionOperatorEvidenceArtifactTemplatePackStatus(array $options = []): array
    {
        $result = (new AtlasSelfConstructionOperatorEvidenceArtifactTemplatePackService($this->mother))->build($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_operator_evidence_artifact_template_pack',
            label: 'Atlas Self-Construction Operator Evidence Artifact Template Pack',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'template_pack_hash' => (string) data_get($result, 'template_pack_hash'),
                'template_count' => (int) data_get($result, 'template_count', 0),
                'operator_draft_workspace_status' => (string) data_get($result, 'operator_draft_workspace.status', ''),
                'operator_draft_workspace_persisted' => (bool) data_get($result, 'operator_draft_workspace.persisted', false),
                'operator_draft_workspace_directory' => (string) data_get($result, 'operator_draft_workspace.workspace_directory', ''),
                'operator_draft_workspace_manifest_path' => (string) data_get($result, 'operator_draft_workspace.manifest_path', ''),
                'operator_draft_workspace_cli_path' => (string) data_get($result, 'operator_draft_workspace.workspace_cli_path', ''),
                'operator_draft_workspace_private_storage_path' => (string) data_get($result, 'operator_draft_workspace.workspace_private_storage_path', ''),
                'operator_draft_workspace_manifest_cli_path' => (string) data_get($result, 'operator_draft_workspace.manifest_cli_path', ''),
                'operator_draft_workspace_manifest_private_storage_path' => (string) data_get($result, 'operator_draft_workspace.manifest_private_storage_path', ''),
                'operator_draft_workspace_artifact_count' => (int) data_get($result, 'operator_draft_workspace.artifact_count', data_get($result, 'operator_draft_workspace.manifest.artifact_count', 0)),
                'operator_draft_workspace_next_required_submission' => (string) data_get($result, 'operator_draft_workspace.manifest.next_required_submission', ''),
                'operator_draft_workspace_finalize_hashes_command' => (string) data_get($result, 'operator_draft_workspace.manifest.files.0.command_to_finalize_workspace_hashes', ''),
                'operator_draft_workspace_publish_command' => (string) data_get($result, 'operator_draft_workspace.manifest.command_to_publish_finalized_workspace', ''),
                'operator_draft_workspace_can_persist_completion_evidence' => (bool) data_get($result, 'operator_draft_workspace.can_persist_completion_evidence_from_draft_workspace', false),
                'operator_draft_workspace_can_promote_completion' => (bool) data_get($result, 'operator_draft_workspace.can_promote_completion_from_draft_workspace', false),
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
            ],
        );
    }

    public function atlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorStatus(array $options = []): array
    {
        $result = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService)->inspect([
            'operator_draft_workspace_path' => (string) ($options['operator_draft_workspace_path'] ?? ''),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_operator_evidence_draft_workspace_inspector',
            label: 'Atlas Self-Construction Operator Evidence Draft Workspace Inspector',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'inspector_hash' => (string) data_get($result, 'inspector_hash'),
                'manifest_path' => (string) data_get($result, 'manifest_path'),
                'artifact_count' => (int) data_get($result, 'artifact_count', 0),
                'violation_count' => (int) data_get($result, 'violation_count', 0),
                'warning_count' => (int) data_get($result, 'warning_count', 0),
                'workspace_safe_for_operator_editing' => (bool) data_get($result, 'workspace_safe_for_operator_editing', false),
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
            ],
        );
    }

}
