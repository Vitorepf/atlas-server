<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon;

use App\Models\AtlasLongHorizonContinuationPack;
use App\Services\Ai\LongHorizon\Replay\LongHorizonReplayManifestBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * L6-12: emit a scoped long-horizon continuation pack and replay manifest.
 *
 * This is the explicit writer that feeds the existing read-only continuity
 * certification service. It uses the canonical atlas.long_horizon.* schemas
 * and never copies raw chat or calls providers.
 */
final class LongHorizonContinuityPackEmitterService
{
    public const SCHEMA_VERSION = 'atlas.long_horizon.continuity_pack_emitter.v1';

    public function __construct(
        private readonly LongHorizonReplayManifestBuilder $replayBuilder,
        private readonly LongHorizonContinuityCertificationService $certifier,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function emit(array $options = []): array
    {
        $cfg = (array) config('atlas.long_horizon.continuity_pack_emitter', []);
        $enabled = (bool) ($options['enabled'] ?? $cfg['enabled'] ?? true);
        $scopeType = trim((string) ($options['scope_type'] ?? $cfg['scope_type'] ?? AtlasLongHorizonCanon::SCOPE_TYPE_LONG_HORIZON));
        $scopeId = trim((string) ($options['scope_id'] ?? $cfg['scope_id'] ?? 'fable-lista-6'));
        $evidenceRoot = (string) ($options['evidence_root'] ?? $cfg['evidence_root'] ?? storage_path('app/atlas/evidence'));
        $docPath = (string) ($options['doc_path'] ?? $cfg['doc_path'] ?? base_path('docs/fable-lista-6-14-itens.md'));
        $maxEvidenceRefs = max(2, (int) ($options['max_evidence_refs'] ?? $cfg['max_evidence_refs'] ?? 40));
        $staleAfterDays = max(1, (int) ($options['stale_after_days'] ?? $cfg['stale_after_days'] ?? 21));
        $strictReplay = (bool) ($options['strict_replay'] ?? $cfg['strict_replay'] ?? true);
        $requiredEvidenceKinds = $this->stringList($options['required_evidence_kinds'] ?? $cfg['required_evidence_kinds'] ?? ['doc', 'artifact']);
        $certifyBefore = (bool) ($options['certify_before'] ?? true);

        if (! $enabled) {
            return $this->payload('disabled', false, [], null, null, null, null, ['continuity_pack_emitter_disabled'], []);
        }

        if (! in_array($scopeType, AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES, true)) {
            return $this->payload('blocked', false, [], null, null, null, null, ['scope_type_not_in_long_horizon_canon'], [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
            ]);
        }

        $before = $certifyBefore
            ? $this->certify($scopeType, $scopeId, $strictReplay, $requiredEvidenceKinds)
            : null;

        $evidenceRefs = $this->collectEvidenceRefs($docPath, $evidenceRoot, $maxEvidenceRefs);
        if ($evidenceRefs === []) {
            return $this->payload('blocked', false, [], null, null, null, $before, ['continuity_evidence_refs_missing'], [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'doc_path' => $docPath,
                'evidence_root' => $evidenceRoot,
            ]);
        }

        $now = CarbonImmutable::now('UTC');
        $payload = $this->packPayload(
            scopeType: $scopeType,
            scopeId: $scopeId !== '' ? $scopeId : null,
            evidenceRefs: $evidenceRefs,
            staleAfter: $now->addDays($staleAfterDays)->startOfDay(),
        );

        $pack = $this->persistPack($payload);
        $manifest = $this->replayBuilder->buildFromContinuationPack($pack);
        $after = $this->certify($scopeType, $scopeId !== '' ? $scopeId : null, $strictReplay, $requiredEvidenceKinds);
        $ab = $this->abSummary($before, $after);

        $blockers = [];
        if (($after['status'] ?? null) !== LongHorizonContinuityCertificationService::STATUS_READY) {
            $blockers[] = 'continuity_certification_not_ready';
        }
        if ((string) ($manifest->replay_status ?? '') !== AtlasLongHorizonCanon::REPLAY_STATUS_READY) {
            $blockers[] = 'replay_manifest_not_ready';
        }

        return $this->payload(
            status: $blockers === [] ? 'continuity_pack_ready' : 'continuity_pack_not_ready',
            certified: $blockers === [],
            evidenceRefs: $evidenceRefs,
            pack: $pack,
            manifest: $manifest,
            certification: $after,
            beforeCertification: $before,
            blockers: $blockers,
            config: [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'evidence_root' => $evidenceRoot,
                'doc_path' => $docPath,
                'max_evidence_refs' => $maxEvidenceRefs,
                'stale_after_days' => $staleAfterDays,
                'strict_replay' => $strictReplay,
                'required_evidence_kinds' => $requiredEvidenceKinds,
                'ab_summary' => $ab,
            ],
        );
    }

    /**
     * @param  list<array<string,mixed>>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function packPayload(string $scopeType, ?string $scopeId, array $evidenceRefs, CarbonImmutable $staleAfter): array
    {
        $contextPackHash = hash('sha256', json_encode($evidenceRefs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $stateSummary = 'Fable continuity pack for '.$scopeType.':'.($scopeId ?? 'null')
            .' with '.count($evidenceRefs).' provider-safe evidence refs and replay manifest support.';

        $payload = [
            'uuid' => 'l6-12-'.substr(hash('sha256', $scopeType.'|'.($scopeId ?? '').'|'.$contextPackHash), 0, 24),
            'schema_version' => AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'objective' => 'Resume the Fable long-horizon execution from provider-safe evidence, not raw chat.',
            'current_phase' => 'continuity_certification',
            'state_summary' => $stateSummary,
            'decisions' => [
                ['kind' => 'schema_reuse', 'ref' => 'atlas.long_horizon.continuation_pack.v2'],
                ['kind' => 'schema_reuse', 'ref' => 'atlas.long_horizon.replay_manifest.v1'],
                ['kind' => 'safety', 'ref' => 'no_raw_chat_provider_handoff'],
            ],
            'superseded_decisions' => [],
            'open_tasks' => [
                ['kind' => 'list_item', 'ref' => 'L6-12 onward'],
            ],
            'completed_tasks' => [
                ['kind' => 'list_item', 'ref' => 'L6-10'],
                ['kind' => 'list_item', 'ref' => 'L6-11 gate'],
            ],
            'blockers' => [],
            'risks' => [
                ['kind' => 'anti_overclaim', 'ref' => 'yellow_items_require_future_real_outcomes'],
            ],
            'evidence_refs' => $evidenceRefs,
            'context_manifest' => $this->contextManifest($evidenceRefs, $staleAfter),
            'context_pack_hash' => $contextPackHash,
            'summary_hash' => hash('sha256', $stateSummary),
            'source_receipts' => [],
            'stale_after' => $staleAfter,
            'safe_resume_mode' => AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            'next_safe_action' => 'run atlas:long-horizon:continuity-certify --strict-replay before executing resumed work',
            'human_decisions_required' => [],
            'confidence' => 0.86,
        ];
        $payload['pack_hash'] = AtlasLongHorizonContinuationPack::canonicalPackHash($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function persistPack(array $payload): AtlasLongHorizonContinuationPack
    {
        $existing = AtlasLongHorizonContinuationPack::query()
            ->where('scope_type', (string) $payload['scope_type'])
            ->where('scope_id', $payload['scope_id'])
            ->where('pack_hash', (string) $payload['pack_hash'])
            ->orderByDesc('created_at')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return AtlasLongHorizonContinuationPack::query()->create($payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function certify(string $scopeType, ?string $scopeId, bool $strictReplay, array $requiredEvidenceKinds): array
    {
        return $this->certifier->certify([
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'strict_replay_required' => $strictReplay,
            'required_evidence_kinds' => $requiredEvidenceKinds,
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function collectEvidenceRefs(string $docPath, string $evidenceRoot, int $max): array
    {
        $refs = [];
        if (File::exists($docPath)) {
            $refs[] = $this->fileRef('doc', $docPath);
        }

        if (File::isDirectory($evidenceRoot)) {
            $files = collect(File::allFiles($evidenceRoot))
                ->filter(fn ($file): bool => $file->isFile())
                ->sortBy(fn ($file): string => $file->getRealPath() ?: $file->getPathname())
                ->take(max(1, $max - count($refs)));

            foreach ($files as $file) {
                $refs[] = $this->fileRef('artifact', $file->getPathname());
            }
        }

        return array_values(array_filter($refs));
    }

    /**
     * @return array<string,mixed>
     */
    private function fileRef(string $kind, string $path): array
    {
        $absolute = $path;
        if (! Str::startsWith($absolute, DIRECTORY_SEPARATOR)) {
            $absolute = base_path($path);
        }

        return [
            'kind' => $kind,
            'ref' => $this->relativePath($absolute),
            'source_hash_actual' => File::exists($absolute) ? hash_file('sha256', $absolute) : null,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $evidenceRefs
     * @return list<array<string,mixed>>
     */
    private function contextManifest(array $evidenceRefs, CarbonImmutable $staleAfter): array
    {
        return array_map(static fn (array $ref): array => [
            'kind' => (string) ($ref['kind'] ?? 'artifact'),
            'ref' => (string) ($ref['ref'] ?? ''),
            'required' => true,
            'missing' => false,
            'source_hash_expected' => $ref['source_hash_actual'] ?? null,
            'source_hash_actual' => $ref['source_hash_actual'] ?? null,
            'valid_until' => $staleAfter->toIso8601String(),
        ], $evidenceRefs);
    }

    /**
     * @return array<string,mixed>
     */
    private function abSummary(?array $before, array $after): array
    {
        $beforeP0 = (int) data_get($before, 'summary.p0_blockers', 0);
        $afterP0 = (int) data_get($after, 'summary.p0_blockers', 0);

        return [
            'baseline_status' => $before['status'] ?? 'not_measured',
            'candidate_status' => $after['status'] ?? 'unknown',
            'baseline_p0_blockers' => $beforeP0,
            'candidate_p0_blockers' => $afterP0,
            'p0_blocker_delta' => $beforeP0 - $afterP0,
            'measured_improvement' => $before !== null && $afterP0 < $beforeP0,
        ];
    }

    private function relativePath(string $absolute): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($absolute, $base)) {
            return substr($absolute, strlen($base));
        }

        return $absolute;
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value,
        ), static fn (string $item): bool => $item !== '')));
    }

    /**
     * @param  list<array<string,mixed>>  $evidenceRefs
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private function payload(
        string $status,
        bool $certified,
        array $evidenceRefs,
        ?AtlasLongHorizonContinuationPack $pack,
        mixed $manifest,
        ?array $certification,
        ?array $beforeCertification,
        array $blockers,
        array $config,
    ): array {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'status' => $status,
            'certified' => $certified,
            'completion_claim_allowed' => $certified,
            'continuation_pack' => $pack === null ? null : [
                'uuid' => (string) $pack->uuid,
                'scope_type' => (string) $pack->scope_type,
                'scope_id' => $pack->scope_id,
                'pack_hash' => (string) $pack->pack_hash,
                'context_pack_hash' => $pack->context_pack_hash,
                'evidence_ref_count' => count((array) $pack->evidence_refs),
                'stale_after' => $pack->stale_after?->toJSON(),
            ],
            'replay_manifest' => $manifest === null ? null : [
                'uuid' => (string) $manifest->uuid,
                'replay_status' => (string) $manifest->replay_status,
                'hash' => (string) $manifest->hash,
                'missing_ref_count' => count((array) $manifest->missing_refs),
            ],
            'certification' => $certification,
            'before_certification' => $beforeCertification === null ? null : [
                'status' => $beforeCertification['status'] ?? null,
                'summary' => $beforeCertification['summary'] ?? null,
                'blocker_ids' => array_values(array_filter(array_map(
                    static fn (array $blocker): ?string => isset($blocker['check_id']) ? (string) $blocker['check_id'] : null,
                    (array) ($beforeCertification['blockers'] ?? []),
                ))),
            ],
            'evidence_ref_count' => count($evidenceRefs),
            'blockers' => $blockers,
            'config' => $config,
            'claim_policy' => [
                'provider_calls_made' => false,
                'provider_tokens_spent' => false,
                'uses_existing_long_horizon_schemas' => true,
                'raw_chat_copied' => false,
                'certifier_remains_read_only' => true,
            ],
        ];
        $payload['receipt_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SCHEMA_VERSION,
            'status' => $status,
            'pack' => $payload['continuation_pack'],
            'manifest' => $payload['replay_manifest'],
            'blockers' => $blockers,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload;
    }
}
