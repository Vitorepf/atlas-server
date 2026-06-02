<?php

namespace App\Services\Ai\Hermes\Mesh;

use App\Services\Ai\Hermes\HermesAdapterReceipt;
use Illuminate\Support\Str;

/**
 * Pure parser that turns Hermes session history into Atlas EVIDENCE CANDIDATES.
 *
 * Hermes is an executor/transport only. The caller runs the read-only
 * `hermes sessions list --json` and hands the ALREADY-DECODED array to this
 * importer; this class NEVER shells out, NEVER calls a provider, and NEVER
 * mutates state. It produces governed, side-effect-free candidates that ATLS
 * (Atlas) may later promote to evidence via a sovereign downstream gate.
 *
 * Sovereignty + safety:
 *  - These candidates are NEVER truth and NEVER memory. They are quarantined
 *    `evidence_candidate` rows awaiting an Atlas gate.
 *  - `promotion_allowed_now` is ALWAYS false — Hermes can never self-promote a
 *    session into the Evidence Ledger.
 *  - `hermes_session_can_decide` is ALWAYS false; `authority` is always `atlas`.
 *  - No raw session id / title / content ever reaches the receipt — only the
 *    sha256 `session_id_hash` (via {@see HermesAdapterReceipt::hashValue()}).
 *
 * Fail-closed: importing is enabled ONLY when the operator policy is the Atlas
 * adapter (`config('atlas.ai.providers.hermes_cli.session_evidence_policy')`
 * === `atlas_adapter`, surfaced as `$policy['enabled']`) AND the listing is a
 * non-empty array of session entries. Otherwise it returns an empty, disabled,
 * blocked receipt — it never throws and never enables.
 */
class HermesSessionEvidenceImporter
{
    use HermesAdapterReceipt;

    /**
     * @param  array<int,mixed>  $sessionListing  Decoded output of `hermes sessions list --json`.
     * @param  array<string,mixed>  $policy  Atlas policy projection; reads `enabled` (bool).
     * @return array<string,mixed>  Sealed `atlas.hermes.session_evidence_import.v1` receipt.
     */
    public function import(array $sessionListing, array $policy): array
    {
        $enabled = $this->policyEnabled($policy);

        if (! $enabled) {
            return $this->blocked('session_evidence_policy_not_atlas_adapter');
        }

        $entries = $this->sessionEntries($sessionListing);
        if ($entries === []) {
            return $this->blocked('session_listing_empty_or_invalid');
        }

        $candidates = [];
        foreach ($entries as $entry) {
            $candidate = $this->candidate($entry);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        if ($candidates === []) {
            return $this->blocked('session_listing_empty_or_invalid');
        }

        return $this->withReceiptHash($this->baseReceipt() + [
            'enabled' => true,
            'candidates' => $candidates,
            'imported_count' => count($candidates),
            'blocked_reason' => null,
            'status' => 'evidence_candidates_imported',
        ]);
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    private function policyEnabled(array $policy): bool
    {
        return ($policy['enabled'] ?? false) === true;
    }

    /**
     * Normalize the decoded CLI payload into a flat list of session entries.
     * Accepts either a bare array of entries or a `{ "sessions": [...] }` shape.
     *
     * @param  array<int|string,mixed>  $sessionListing
     * @return array<int,array<string,mixed>>
     */
    private function sessionEntries(array $sessionListing): array
    {
        $raw = $sessionListing;
        if (isset($sessionListing['sessions']) && is_array($sessionListing['sessions'])) {
            $raw = $sessionListing['sessions'];
        }

        $entries = [];
        foreach ($raw as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Build one quarantined evidence candidate from a single session entry.
     * Returns null when the entry carries no usable session identifier.
     *
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>|null
     */
    private function candidate(array $entry): ?array
    {
        $sessionId = $this->string($entry['id'] ?? $entry['session_id'] ?? null, 4096);
        if ($sessionId === null) {
            return null;
        }

        $candidate = [
            'class' => 'evidence_candidate',
            'session_id_hash' => $this->hashValue([$sessionId]),
        ];

        $turnCount = $this->turnCount($entry);
        if ($turnCount !== null) {
            $candidate['turn_count'] = $turnCount;
        }

        $createdAt = $this->string($entry['created_at'] ?? null, 64);
        if ($createdAt !== null) {
            $candidate['created_at'] = $createdAt;
        }

        return $candidate;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function turnCount(array $entry): ?int
    {
        $value = $entry['turn_count'] ?? $entry['turns'] ?? null;

        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function baseReceipt(): array
    {
        return [
            'schema_version' => 'atlas.hermes.session_evidence_import.v1',
            'importer' => 'hermes_session_evidence_importer',
            'authority' => 'atlas',
            'hermes_session_can_decide' => false,
            'promotion_allowed_now' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $reason): array
    {
        return $this->withReceiptHash($this->baseReceipt() + [
            'enabled' => false,
            'candidates' => [],
            'imported_count' => 0,
            'blocked_reason' => $reason,
            'status' => 'evidence_import_blocked',
        ]);
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
