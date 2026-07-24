<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Models\AtlasLongHorizonContinuationPack;
use App\Models\AtlasLongHorizonReplayManifest;
use App\Services\Ai\LongHorizon\Replay\LongHorizonReplayManifestBuilder;
use App\Services\Ai\LongHorizon\Replay\ReplayManifestReader;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * TEOS-I2 · Replay Manifest CLI.
 *
 *   php artisan atlas:long-horizon:replay-manifest build --continuation-pack=<uuid> [--compaction-receipt=<uuid>] [--json]
 *   php artisan atlas:long-horizon:replay-manifest show  --manifest=<uuid> [--json]
 *   php artisan atlas:long-horizon:replay-manifest read  --manifest=<uuid> [--json]
 *   php artisan atlas:long-horizon:replay-manifest list  [--scope-type=...] [--scope-id=...] [--limit=20] [--json]
 *
 * Shorthand: scope-based build is supported when an operator passes
 * `--scope-type` + `--scope-id` instead of `--continuation-pack`. The most
 * recent continuation pack for that scope is used.
 *
 * Exit codes: 0 ok | 1 runtime/not-found | 2 usage error.
 */
final class AtlasLongHorizonReplayManifestCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:long-horizon:replay-manifest
        {action : build|show|read|list}
        {--continuation-pack= : continuation pack uuid for build}
        {--compaction-receipt= : optional compaction receipt uuid for build}
        {--scope-type= : alternative selector for build/list}
        {--scope-id= : alternative selector for build/list}
        {--manifest= : manifest uuid for show/read}
        {--limit=20 : limit for list}
        {--json : machine-readable JSON output (default)}';

    protected $description = 'TEOS-I2 Replay Manifest CLI · build / show / read / list provider-independent manifests.';

    public function handle(LongHorizonReplayManifestBuilder $builder, ReplayManifestReader $reader): int
    {
        $action = (string) $this->argument('action');

        try {
            return match ($action) {
                'build' => $this->renderBuild($builder),
                'show' => $this->renderShow(),
                'read' => $this->renderRead($reader),
                'list' => $this->renderList(),
                default => $this->renderUsageError($action),
            };
        } catch (Throwable $exception) {
            return $this->emit([
                'ok' => false,
                'action' => $action,
                'error' => 'exception',
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ], exit: 1);
        }
    }

    private function renderBuild(LongHorizonReplayManifestBuilder $builder): int
    {
        $packUuid = (string) ($this->option('continuation-pack') ?? '');
        $receiptUuid = (string) ($this->option('compaction-receipt') ?? '');
        $scopeType = (string) ($this->option('scope-type') ?? '');
        $scopeId = (string) ($this->option('scope-id') ?? '');

        $pack = null;
        if ($packUuid !== '') {
            $pack = AtlasLongHorizonContinuationPack::query()->where('uuid', $packUuid)->first();
            if ($pack === null) {
                return $this->emit([
                    'ok' => false,
                    'action' => 'build',
                    'error' => 'continuation_pack_not_found',
                    'uuid' => $packUuid,
                ], exit: 1);
            }
        } elseif ($scopeType !== '') {
            $query = AtlasLongHorizonContinuationPack::query()
                ->where('scope_type', $scopeType)
                ->orderByDesc('created_at');
            if ($scopeId !== '') {
                $query->where('scope_id', $scopeId);
            }
            $pack = $query->first();
            if ($pack === null) {
                return $this->emit([
                    'ok' => false,
                    'action' => 'build',
                    'error' => 'continuation_pack_not_found_for_scope',
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId !== '' ? $scopeId : null,
                ], exit: 1);
            }
        } else {
            return $this->renderUsageError('build requires --continuation-pack=<uuid> or --scope-type=...');
        }

        $receipt = null;
        if ($receiptUuid !== '') {
            $receipt = AtlasLongHorizonCompactionReceipt::query()->where('uuid', $receiptUuid)->first();
            if ($receipt === null) {
                return $this->emit([
                    'ok' => false,
                    'action' => 'build',
                    'error' => 'compaction_receipt_not_found',
                    'uuid' => $receiptUuid,
                ], exit: 1);
            }
        }

        $manifest = $builder->buildFromContinuationPack($pack, $receipt);

        return $this->emit([
            'ok' => true,
            'action' => 'build',
            'manifest' => $this->serialize($manifest),
        ]);
    }

    private function renderShow(): int
    {
        $uuid = (string) ($this->option('manifest') ?? '');
        if ($uuid === '') {
            return $this->renderUsageError('show requires --manifest=<uuid>');
        }
        $manifest = AtlasLongHorizonReplayManifest::query()->where('uuid', $uuid)->first();
        if ($manifest === null) {
            return $this->emit([
                'ok' => false,
                'action' => 'show',
                'error' => 'manifest_not_found',
                'uuid' => $uuid,
            ], exit: 1);
        }

        return $this->emit([
            'ok' => true,
            'action' => 'show',
            'manifest' => $this->serialize($manifest),
        ]);
    }

    private function renderRead(ReplayManifestReader $reader): int
    {
        $uuid = (string) ($this->option('manifest') ?? '');
        if ($uuid === '') {
            return $this->renderUsageError('read requires --manifest=<uuid>');
        }
        $manifest = AtlasLongHorizonReplayManifest::query()->where('uuid', $uuid)->first();
        if ($manifest === null) {
            return $this->emit([
                'ok' => false,
                'action' => 'read',
                'error' => 'manifest_not_found',
                'uuid' => $uuid,
            ], exit: 1);
        }

        return $this->emit([
            'ok' => true,
            'action' => 'read',
            'bundle' => $reader->read($manifest),
        ]);
    }

    private function renderList(): int
    {
        $scopeType = (string) ($this->option('scope-type') ?? '');
        $scopeId = (string) ($this->option('scope-id') ?? '');
        $limit = max(1, min(100, (int) $this->option('limit')));

        $query = AtlasLongHorizonReplayManifest::query()->orderByDesc('created_at')->limit($limit);
        if ($scopeType !== '') {
            $query->where('scope_type', $scopeType);
        }
        if ($scopeId !== '') {
            $query->where('scope_id', $scopeId);
        }

        $manifests = $query->get();

        return $this->emit([
            'ok' => true,
            'action' => 'list',
            'filter' => [
                'scope_type' => $scopeType !== '' ? $scopeType : null,
                'scope_id' => $scopeId !== '' ? $scopeId : null,
                'limit' => $limit,
            ],
            'count' => $manifests->count(),
            'manifests' => $manifests->map(fn (AtlasLongHorizonReplayManifest $m) => $this->serialize($m))->values()->all(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function serialize(AtlasLongHorizonReplayManifest $m): array
    {
        return [
            'uuid' => (string) $m->uuid,
            'schema_version' => (string) $m->schema_version,
            'scope_type' => (string) $m->scope_type,
            'scope_id' => $m->scope_id,
            'continuation_pack_id' => $m->continuation_pack_id,
            'compaction_receipt_id' => $m->compaction_receipt_id,
            'replay_status' => (string) $m->replay_status,
            'context_pack_hash' => $m->context_pack_hash,
            'required_refs' => (array) ($m->required_refs ?? []),
            'available_refs' => (array) ($m->available_refs ?? []),
            'missing_refs' => (array) ($m->missing_refs ?? []),
            'event_refs' => (array) ($m->event_refs ?? []),
            'evidence_refs' => (array) ($m->evidence_refs ?? []),
            'reader_instructions' => (string) $m->reader_instructions,
            'provider_independent_summary' => (string) $m->provider_independent_summary,
            'safety_notes' => (array) ($m->safety_notes ?? []),
            'hash' => (string) $m->hash,
            'created_at' => $m->created_at?->toJSON(),
        ];
    }

    private function renderUsageError(string $hint): int
    {
        return $this->emit([
            'ok' => false,
            'action' => (string) $this->argument('action'),
            'error' => 'usage_error',
            'hint' => $hint,
            'usage' => 'atlas:long-horizon:replay-manifest {build|show|read|list} [--continuation-pack=<uuid>] [--compaction-receipt=<uuid>] [--manifest=<uuid>] [--scope-type=...] [--scope-id=...] [--limit=N] [--json]',
        ], exit: 2);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit = 0): int
    {
        $this->line($this->encode($payload));

        return $exit;
    }
}
