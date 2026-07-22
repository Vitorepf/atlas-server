<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V2;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMultiRepoMergeAuthority;

final class AtlasLoopV2EnterpriseMergeGate
{
    public const SCHEMA_VERSION = 'atlas.ai.loop_v2.merge_gate.v1';

    public function __construct(
        private readonly AtlasLoopV2ScopeManifestRegistry $registry,
        private readonly AtlasLoopMultiRepoMergeAuthority $multiRepoAuth,
        private readonly AtlasLoopV2BlastRadiusCalculator $blast,
        private readonly AtlasLoopV2AuditJournal $journal,
    ) {}

    /**
     * @param  array<string,mixed>  $proposal
     * @return array{
     *     allowed:bool,
     *     audit_line_hash:string,
     *     authority_scope:string,
     *     blast_tier:string,
     *     deny_reason:?string,
     *     schema_version:string,
     *     scope_id:string
     * }
     */
    public function authorize(string $scopeId, array $proposal): array
    {
        $scopeId = trim($scopeId);
        $manifest = $this->registry->findById($scopeId);
        if ($manifest === null) {
            return $this->audit($this->decision(false, 'unknown_scope', $scopeId, 'not_calculated', 'not_evaluated'));
        }

        $authority = $this->multiRepoAuth->authorize((string) $manifest['repo_root_absolute']);
        $authorityScope = trim((string) ($authority['scope'] ?? 'unknown'));
        if (($authority['allowed'] ?? false) !== true) {
            return $this->audit($this->decision(
                false,
                'multi_repo_denied:'.(string) ($authority['reason'] ?? 'unknown'),
                $scopeId,
                'not_calculated',
                $authorityScope !== '' ? $authorityScope : 'unknown',
            ));
        }

        $blast = $this->blast->calculate(
            $this->stringList($proposal['changed_files'] ?? []),
            $this->stringListMap($proposal['import_graph'] ?? []),
            $this->stringList($proposal['critical_paths'] ?? []),
        );
        $blastTier = (string) $blast['tier'];
        $riskTier = strtolower(trim((string) ($manifest['risk_tier'] ?? '')));

        if ($blastTier === 'critical' && in_array($riskTier, ['low', 'medium'], true)) {
            return $this->audit($this->decision(
                false,
                'blast_radius_above_scope_tier',
                $scopeId,
                $blastTier,
                $authorityScope,
            ));
        }

        if ($blastTier === 'wide' && ! $this->approved($proposal['operator_approval'] ?? [])) {
            return $this->audit($this->decision(
                false,
                'operator_approval_required_for_wide_blast',
                $scopeId,
                $blastTier,
                $authorityScope,
            ));
        }

        return $this->audit($this->decision(true, null, $scopeId, $blastTier, $authorityScope));
    }

    /**
     * @return array{
     *     allowed:bool,
     *     audit_line_hash:string,
     *     authority_scope:string,
     *     blast_tier:string,
     *     deny_reason:?string,
     *     schema_version:string,
     *     scope_id:string
     * }
     */
    private function decision(bool $allowed, ?string $denyReason, string $scopeId, string $blastTier, string $authorityScope): array
    {
        return [
            'allowed' => $allowed,
            'audit_line_hash' => '',
            'authority_scope' => $authorityScope,
            'blast_tier' => $blastTier,
            'deny_reason' => $denyReason,
            'schema_version' => self::SCHEMA_VERSION,
            'scope_id' => $scopeId,
        ];
    }

    /**
     * @param  array<string,mixed>  $decision
     * @return array{
     *     allowed:bool,
     *     audit_line_hash:string,
     *     authority_scope:string,
     *     blast_tier:string,
     *     deny_reason:?string,
     *     schema_version:string,
     *     scope_id:string
     * }
     */
    private function audit(array $decision): array
    {
        $entry = $this->journal->append('merge_gate_decision', $decision);
        $decision['audit_line_hash'] = (string) $entry['line_hash'];

        return $decision;
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return array_values($out);
    }

    /**
     * @param  mixed  $map
     * @return array<string,list<string>>
     */
    private function stringListMap(mixed $map): array
    {
        if (! is_array($map)) {
            return [];
        }

        $out = [];
        foreach ($map as $key => $values) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $out[$key] = $this->stringList($values);
        }

        return $out;
    }

    private function approved(mixed $approval): bool
    {
        if (! is_array($approval)) {
            return false;
        }

        return ($approval['approved'] ?? false) === true
            && trim((string) ($approval['operator_id'] ?? '')) !== '';
    }
}
