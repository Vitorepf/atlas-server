<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;

class AtlasProductDeliveryPatchProposalGateService
{
    public const SCHEMA_VERSION = 'atlas.product_delivery.patch_proposal_gate.v1';

    /**
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $patchManifest
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $delivery, array $patchManifest, array $options = []): array
    {
        $sourceKind = $this->sourceKind($options['source_kind'] ?? $patchManifest['source_kind'] ?? 'human');
        $operations = $this->operations($patchManifest['operations'] ?? []);
        $allowedFiles = $this->allowedFiles($delivery, $patchManifest);
        $proposalHash = MissionCanonicalHash::sha256([
            'source_kind' => $sourceKind,
            'allowed_files' => $allowedFiles,
            'operations' => $operations,
        ]);
        $approval = $this->approval($options['approval'] ?? $patchManifest['approval'] ?? null);
        $risk = $this->risk($delivery);
        $blockers = [];

        if ($operations === []) {
            $blockers[] = ['id' => 'missing_patch_operations'];
        }

        foreach ($operations as $operation) {
            if (! in_array($operation['path'], $allowedFiles, true)) {
                $blockers[] = ['id' => 'path_not_allowed', 'path' => $operation['path']];
            }
            if ($operation['content'] === '') {
                $blockers[] = ['id' => 'empty_replacement_content', 'path' => $operation['path']];
            }
        }

        $approvalRequired = $this->approvalRequired($sourceKind, $risk, (bool) ($options['apply'] ?? false));
        if ($approvalRequired && ! $this->approvalMatches($approval, $proposalHash)) {
            $blockers[] = [
                'id' => 'human_approval_required',
                'source_kind' => $sourceKind,
                'risk_band' => $risk['risk_band'],
            ];
        }

        $blocked = array_values(array_filter(
            $blockers,
            static fn (array $blocker): bool => ($blocker['id'] ?? null) !== 'human_approval_required',
        )) !== [];
        $status = match (true) {
            $blocked => 'blocked',
            $approvalRequired && ! $this->approvalMatches($approval, $proposalHash) => 'needs_human_approval',
            default => 'approved',
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'writes' => false,
            'source_kind' => $sourceKind,
            'risk' => $risk,
            'approval_required' => $approvalRequired,
            'apply_allowed' => $status === 'approved',
            'proposal_hash' => $proposalHash,
            'blockers' => $blockers,
            'operator_decision_contract' => [
                'schema_version' => 'atlas.product_delivery.patch_operator_decision.v1',
                'required' => $approvalRequired,
                'expected_proposal_hash' => $proposalHash,
                'accepted_decision' => 'approved',
                'fields' => ['decision', 'proposal_hash', 'approved_by', 'reason'],
            ],
            'sanitized_patch_manifest' => [
                'schema_version' => 'atlas.product_delivery.sanitized_patch_manifest.v1',
                'source_kind' => $sourceKind,
                'allowed_files' => $allowedFiles,
                'operations' => $operations,
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'auto_apply_provider_patch' => false,
                'human_override_required_for_provider_patch' => in_array($sourceKind, ['provider', 'subagent', 'forge_workcell'], true),
            ],
        ];
        $payload['patch_gate_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function allowedFiles(array $delivery, array $patchManifest): array
    {
        $fromDelivery = data_get($delivery, 'delivery_plan.scope_guard.allowed_files', []);
        $fromManifest = $patchManifest['allowed_files'] ?? [];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $path): ?string => is_scalar($path) && trim((string) $path) !== '' ? trim((string) $path) : null,
            array_merge(is_array($fromDelivery) ? $fromDelivery : [], is_array($fromManifest) ? $fromManifest : []),
        ))));
    }

    /**
     * @return list<array{path:string,content:string,expected_sha256:?string}>
     */
    private function operations(mixed $operations): array
    {
        if (! is_array($operations)) {
            return [];
        }

        $clean = [];
        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }
            $path = trim((string) ($operation['path'] ?? ''));
            if ($path === '' || str_contains($path, '..')) {
                continue;
            }

            $expected = isset($operation['expected_sha256']) && is_scalar($operation['expected_sha256'])
                ? trim((string) $operation['expected_sha256'])
                : null;
            $clean[] = [
                'path' => $path,
                'content' => (string) ($operation['content'] ?? ''),
                'expected_sha256' => $expected !== '' ? $expected : null,
            ];
        }

        return $clean;
    }

    /**
     * @return array{risk_band:string,high_risk_lenses:list<string>}
     */
    private function risk(array $delivery): array
    {
        $lenses = data_get($delivery, 'delivery_plan.required_lenses', []);
        $lenses = is_array($lenses) ? array_values(array_filter(array_map(
            static fn (mixed $lens): ?string => is_scalar($lens) ? trim((string) $lens) : null,
            $lenses,
        ))) : [];
        $highRisk = array_values(array_intersect($lenses, ['security_driven', 'add', 'performance_driven']));

        return [
            'risk_band' => $highRisk === [] ? 'standard' : 'high',
            'high_risk_lenses' => $highRisk,
        ];
    }

    private function approvalRequired(string $sourceKind, array $risk, bool $apply): bool
    {
        return $apply && (in_array($sourceKind, ['provider', 'subagent', 'forge_workcell'], true)
            || ($risk['risk_band'] ?? null) === 'high');
    }

    private function approvalMatches(?array $approval, string $proposalHash): bool
    {
        if ($approval === null) {
            return false;
        }

        return ($approval['decision'] ?? null) === 'approved'
            && ($approval['proposal_hash'] ?? null) === $proposalHash
            && is_scalar($approval['approved_by'] ?? null)
            && trim((string) $approval['approved_by']) !== ''
            && is_scalar($approval['reason'] ?? null)
            && trim((string) $approval['reason']) !== '';
    }

    /**
     * @return array<string,string>|null
     */
    private function approval(mixed $approval): ?array
    {
        if (! is_array($approval)) {
            return null;
        }

        return [
            'decision' => is_scalar($approval['decision'] ?? null) ? trim((string) $approval['decision']) : '',
            'proposal_hash' => is_scalar($approval['proposal_hash'] ?? null) ? trim((string) $approval['proposal_hash']) : '',
            'approved_by' => is_scalar($approval['approved_by'] ?? null) ? trim((string) $approval['approved_by']) : '',
            'reason' => is_scalar($approval['reason'] ?? null) ? trim((string) $approval['reason']) : '',
        ];
    }

    private function sourceKind(mixed $value): string
    {
        $kind = is_scalar($value) ? trim((string) $value) : 'human';

        return in_array($kind, ['human', 'provider', 'subagent', 'forge_workcell'], true) ? $kind : 'provider';
    }
}
