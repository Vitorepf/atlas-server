<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism;

use App\Models\AtlasAurgNode;
use App\Services\Ai\Reality\AtlasRealityGraphSnapshotBuilderService;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AOBG N4.F1 — production {@see OrganismProposalRecorder} over the AURG brain store.
 *
 * Records each validated domain proposal as a provider-safe DOMAIN node in the reality
 * graph (the same store {@see \App\Services\Ai\Reality\AtlasRealityGraphIngestionService}
 * writes obra/mission outcomes into), so the NEXT cross-domain mission can read prior
 * proposals via {@see priorProposals()} — cross-domain COMPOUNDING.
 *
 * PROVIDER-SAFE + SENSITIVE: the node carries the proposal's provider-safe content/
 * rationale (redacted label) + brain_refs + the honest-metric NUMBERS only — never the
 * on-machine `payload`, never source. A sensitive proposal is stored sensitive => true.
 *
 * FAIL-OPEN: when AURG is disabled or the store is absent, returns recorded:false with an
 * honest reason — never throws, never fabricates. The organism still returns the proposal.
 */
final class RealityGraphProposalRecorder implements OrganismProposalRecorder
{
    private const SOURCE_KIND = 'domain';

    public function record(DomainProposal $proposal, array $validation): array
    {
        if (! (bool) config('atlas.aurg.enabled', true)) {
            return ['recorded' => false, 'node_ref' => $proposal->ref(), 'reason' => 'aurg_disabled'];
        }
        if (! $this->storePresent()) {
            return ['recorded' => false, 'node_ref' => $proposal->ref(), 'reason' => 'store_missing'];
        }

        $ref = $proposal->ref();
        $id = self::SOURCE_KIND.':'.AtlasRealityGraphSnapshotBuilderService::NODE_DOMAIN.':'.$ref;

        // Provider-safe numbers from the honest verdict — no payload echo, no source.
        $metric = (string) ($validation['metric'] ?? 'unknown');
        $value = isset($validation['value']) && is_numeric($validation['value']) ? (float) $validation['value'] : null;
        $passed = (bool) ($validation['passed'] ?? false);
        $method = (string) ($validation['method'] ?? '');

        try {
            AtlasAurgNode::query()->upsert([[
                'id' => mb_substr($id, 0, 300),
                'kind' => AtlasRealityGraphSnapshotBuilderService::NODE_DOMAIN,
                'source_kind' => self::SOURCE_KIND,
                'source_id' => mb_substr($ref, 0, 220),
                'label' => mb_substr(AtlasSecurity::redactString($proposal->content !== '' ? $proposal->content : $proposal->intent), 0, 220),
                'workspace_id' => null,
                'provider_safe' => true,
                'sensitive' => $proposal->sensitive,
                'meta' => json_encode([
                    'organism_proposal' => true,
                    'domain' => $proposal->domain,
                    'intent' => AtlasSecurity::redactString($proposal->intent),
                    'rationale' => AtlasSecurity::redactString($proposal->rationale),
                    'brain_refs' => array_slice($proposal->brainRefs, 0, 25),
                    'validation' => array_filter([
                        'metric' => $metric,
                        'value' => $value,
                        'passed' => $passed,
                        'method' => $method,
                    ], static fn ($v): bool => $v !== null && $v !== ''),
                    'actuation_gate' => 'requires_operator',
                    'ceiling' => AbstractDomainActuator::CEILING,
                    'never_auto_promote' => true,
                    'ts' => $proposal->ts,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                'content_hash' => hash('sha256', 'organism|'.$ref.'|'.$metric.'|'.($passed ? '1' : '0')),
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['id'], ['label', 'sensitive', 'meta', 'content_hash', 'updated_at']);
        } catch (Throwable $e) {
            return ['recorded' => false, 'node_ref' => $ref, 'reason' => 'store_error'];
        }

        return ['recorded' => true, 'node_ref' => $id];
    }

    public function priorProposals(?string $domain = null, int $limit = 10): array
    {
        if (! (bool) config('atlas.aurg.enabled', true) || ! $this->storePresent()) {
            return [];
        }

        try {
            $q = AtlasAurgNode::query()
                ->where('source_kind', self::SOURCE_KIND)
                ->where('kind', AtlasRealityGraphSnapshotBuilderService::NODE_DOMAIN)
                ->orderByDesc('updated_at')
                ->limit(max(1, $limit));

            $rows = $q->get(['source_id', 'label', 'sensitive', 'meta']);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $meta = is_array($row->meta) ? $row->meta : [];
            if (($meta['organism_proposal'] ?? false) !== true) {
                continue;
            }
            $rowDomain = (string) ($meta['domain'] ?? '');
            if ($domain !== null && $rowDomain !== $domain) {
                continue;
            }
            // Provider-safe label-only projection (the compounding signal the next
            // mission reads): domain + redacted intent + honest verdict. No payload.
            $out[] = [
                'ref' => (string) $row->source_id,
                'domain' => $rowDomain,
                'intent' => (string) ($meta['intent'] ?? ''),
                'sensitive' => (bool) $row->sensitive,
                'validation' => $meta['validation'] ?? [],
            ];
        }

        return $out;
    }

    private function storePresent(): bool
    {
        try {
            return Schema::hasTable('atlas_aurg_nodes');
        } catch (Throwable) {
            return false;
        }
    }
}
