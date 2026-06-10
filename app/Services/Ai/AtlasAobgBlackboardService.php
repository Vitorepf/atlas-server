<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AOBG N2.F4 — the BLACKBOARD: multiple engines coordinate THROUGH the brain.
 *
 * N2.F1/F2/F3 made the brain INTERVENE for a SINGLE engine. N2.F4 makes the brain the
 * shared COORDINATION surface BETWEEN engines: a compact, expiring table of CLAIMS of
 * work — "claude_code is editing fileX", "codex holds task T". Both Claude Code and
 * Codex speak MCP, so both can {@see claim()} a target and {@see active()} the current
 * claims; the F2 PreToolUse guard reads {@see conflictsFor()} so that when one engine is
 * about to edit a file ANOTHER engine already holds, it can surface "codex is editing
 * this file" — coordination DURING flight, not after.
 *
 * It builds NO parallel engine and stores NO content. A claim is a provider-safe ref:
 * {workspace_id, engine, kind, target, status, claimed_at, expires_at, meta}. `target`
 * is a file PATH or a task REF — a label only. Workspace identity is resolved by the
 * SAME {@see CodeGraphWorkspaceIdentity} the rest of AOBG uses (multi-project, never a
 * cross-workspace leak).
 *
 * GUARANTEES (non-negotiable):
 *  - IDEMPOTENT: the same engine re-claiming the same target collapses onto ONE active
 *    row (UNIQUE(workspace, engine, kind, target) + upsert), refreshing the TTL.
 *  - CONFLICT-AWARE: a claim on a target ANOTHER engine already holds returns
 *    status=conflict (it does NOT steal the claim, and it does NOT throw). The caller
 *    (or the F2 guard) decides what to do with the conflict — coordination, not a gate.
 *  - LAZILY EXPIRING: a claim past its expires_at is marked `stale` on the next read so
 *    a crashed engine's claim never blocks coordination forever (no cron needed).
 *  - FAIL-OPEN: ANY fault (no table, DB error, bad input) degrades to a safe no-finding
 *    answer — coordination is best-effort and must NEVER stall or break a session. This
 *    service NEVER throws.
 *
 * PERF + COST (the create-path-perf memory): every query is workspace-scoped + bounded;
 * read-only-to-the-provider, local DB only (ZERO provider spend); the lazy-expire is a
 * single bounded UPDATE. A slow/broken blackboard degrades to "no claims", never stalls.
 */
class AtlasAobgBlackboardService
{
    public const SCHEMA = 'atlas.aobg.blackboard.v1';

    public const TABLE = 'atlas_aobg_blackboard';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RELEASED = 'released';

    public const STATUS_STALE = 'stale';

    /** Recognised claim kinds (an unknown kind is normalised to `file`). */
    private const KINDS = ['task', 'file', 'mission'];

    public function __construct(
        private readonly CodeGraphWorkspaceIdentity $workspaceIdentity,
    ) {}

    /**
     * Claim a target for an engine. Idempotent + conflict-aware + fail-open.
     *
     * @param  string  $engine  the engine claiming (claude_code|codex|cursor|atlas|...).
     * @param  string  $kind    one of task|file|mission (default file).
     * @param  string  $target  the file path or task/mission ref being claimed.
     * @param  array<string,mixed>  $opts  optional:
     *   - ttl: claim lifetime in seconds (default config; clamped to max_ttl_seconds).
     *   - workspace / cwd: scope (path OR id); default the primary atlas-server.
     *   - meta: small bounded note ref (no content).
     * @return array{schema:string, ok:bool, status:string, claim:?array<string,mixed>, conflict:?array<string,mixed>, workspace:string, provider_bound:bool}
     */
    public function claim(string $engine, string $kind, string $target, array $opts = []): array
    {
        try {
            if (! $this->tableReady()) {
                return $this->failOpen('table_unavailable');
            }

            $engine = $this->normalizeEngine($engine);
            $kind = $this->normalizeKind($kind);
            $target = $this->normalizeTarget($target);
            if ($engine === '' || $target === '') {
                return $this->failOpen('engine_and_target_required');
            }

            $workspaceId = $this->resolveWorkspaceId($opts);
            $now = Carbon::now();

            // Expire anything past its TTL first so a stale claim never blocks.
            $this->expireStale($workspaceId, $now);

            // CONFLICT: another engine already actively holds this exact target.
            $conflict = $this->activeConflict($workspaceId, $target, $engine, $now);
            if ($conflict !== null) {
                return [
                    'schema' => self::SCHEMA,
                    'ok' => false,
                    'status' => 'conflict',
                    'claim' => null,
                    'conflict' => $conflict,
                    'workspace' => $workspaceId,
                    'provider_bound' => true,
                ];
            }

            $ttl = $this->resolveTtl($opts);
            $id = $this->claimId($workspaceId, $engine, $kind, $target);
            $expiresAt = $now->copy()->addSeconds($ttl);
            $meta = $this->normalizeMeta($opts['meta'] ?? null);

            // IDEMPOTENT upsert: the same engine re-claiming the same target collapses
            // onto this one row and refreshes claimed_at/expires_at/status=active.
            DB::table(self::TABLE)->updateOrInsert(
                [
                    'workspace_id' => $workspaceId,
                    'engine' => $engine,
                    'kind' => $kind,
                    'target' => $target,
                ],
                [
                    'id' => $id,
                    'status' => self::STATUS_ACTIVE,
                    'claimed_at' => $now,
                    'expires_at' => $expiresAt,
                    'meta' => $this->encodeMeta($meta),
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );

            return [
                'schema' => self::SCHEMA,
                'ok' => true,
                'status' => self::STATUS_ACTIVE,
                'claim' => [
                    'id' => $id,
                    'engine' => $engine,
                    'kind' => $kind,
                    'target' => $target,
                    'workspace_id' => $workspaceId,
                    'status' => self::STATUS_ACTIVE,
                    'claimed_at' => $now->toJSON(),
                    'expires_at' => $expiresAt->toJSON(),
                    'ttl_seconds' => $ttl,
                    'meta' => $meta,
                ],
                'conflict' => null,
                'workspace' => $workspaceId,
                'provider_bound' => true,
            ];
        } catch (Throwable) {
            return $this->failOpen('error');
        }
    }

    /**
     * Release a claim by id (the engine is done). Idempotent — releasing an unknown or
     * already-released claim is a clean no-op. Fail-open.
     *
     * @return array{schema:string, ok:bool, released:bool, id:string}
     */
    public function release(string $claimId): array
    {
        $claimId = trim($claimId);
        try {
            if (! $this->tableReady() || $claimId === '') {
                return ['schema' => self::SCHEMA, 'ok' => true, 'released' => false, 'id' => $claimId];
            }

            $affected = DB::table(self::TABLE)
                ->where('id', $claimId)
                ->where('status', self::STATUS_ACTIVE)
                ->update([
                    'status' => self::STATUS_RELEASED,
                    'updated_at' => Carbon::now(),
                ]);

            return ['schema' => self::SCHEMA, 'ok' => true, 'released' => $affected > 0, 'id' => $claimId];
        } catch (Throwable) {
            return ['schema' => self::SCHEMA, 'ok' => true, 'released' => false, 'id' => $claimId];
        }
    }

    /**
     * The current ACTIVE claims for a workspace (stale ones expired first). Workspace-
     * scoped + bounded. Fail-open to an empty list.
     *
     * @param  array<string,mixed>  $opts  workspace / cwd scope.
     * @return array{schema:string, ok:bool, workspace:string, count:int, claims:list<array<string,mixed>>, provider_bound:bool, honesty:string}
     */
    public function active(array $opts = []): array
    {
        try {
            if (! $this->tableReady()) {
                return $this->emptyActive('');
            }

            $workspaceId = $this->resolveWorkspaceId($opts);
            $now = Carbon::now();
            $this->expireStale($workspaceId, $now);

            $cap = max(1, (int) config('atlas.aobg.blackboard.max_active', 200));
            $rows = DB::table(self::TABLE)
                ->where('workspace_id', $workspaceId)
                ->where('status', self::STATUS_ACTIVE)
                ->orderByDesc('claimed_at')
                ->limit($cap)
                ->get();

            $claims = [];
            foreach ($rows as $row) {
                $claims[] = $this->presentRow($row);
            }

            return [
                'schema' => self::SCHEMA,
                'ok' => true,
                'workspace' => $workspaceId,
                'count' => count($claims),
                'claims' => $claims,
                'provider_bound' => true,
                'honesty' => 'active claims only; stale claims expired by TTL on read',
            ];
        } catch (Throwable) {
            return $this->emptyActive('');
        }
    }

    /**
     * The active claims on a given target (the F2 guard's cross-engine conflict read).
     * Optionally EXCLUDE the asking engine's own claim (so the guard only surfaces
     * OTHER engines). Workspace-scoped, stale-expired, bounded. Fail-open to [].
     *
     * @param  array<string,mixed>  $opts  workspace / cwd scope + optional except_engine.
     * @return array{schema:string, ok:bool, workspace:string, target:string, count:int, claims:list<array<string,mixed>>, provider_bound:bool}
     */
    public function conflictsFor(string $target, array $opts = []): array
    {
        try {
            $target = $this->normalizeTarget($target);
            if (! $this->tableReady() || $target === '') {
                return ['schema' => self::SCHEMA, 'ok' => true, 'workspace' => '', 'target' => $target, 'count' => 0, 'claims' => [], 'provider_bound' => true];
            }

            $workspaceId = $this->resolveWorkspaceId($opts);
            $now = Carbon::now();
            $this->expireStale($workspaceId, $now);

            $exceptEngine = $this->normalizeEngine((string) ($opts['except_engine'] ?? ''));
            $cap = max(1, (int) config('atlas.aobg.blackboard.max_active', 200));

            $query = DB::table(self::TABLE)
                ->where('workspace_id', $workspaceId)
                ->where('target', $target)
                ->where('status', self::STATUS_ACTIVE);
            if ($exceptEngine !== '') {
                $query->where('engine', '!=', $exceptEngine);
            }

            $rows = $query->orderByDesc('claimed_at')->limit($cap)->get();

            $claims = [];
            foreach ($rows as $row) {
                $claims[] = $this->presentRow($row);
            }

            return [
                'schema' => self::SCHEMA,
                'ok' => true,
                'workspace' => $workspaceId,
                'target' => $target,
                'count' => count($claims),
                'claims' => $claims,
                'provider_bound' => true,
            ];
        } catch (Throwable) {
            return ['schema' => self::SCHEMA, 'ok' => true, 'workspace' => '', 'target' => $target, 'count' => 0, 'claims' => [], 'provider_bound' => true];
        }
    }

    // ------------------------------------------------------------------
    // internals (fail-safe — never throw)
    // ------------------------------------------------------------------

    /**
     * Mark every claim past its expires_at `stale` (lazy expiry — no cron). Bounded to
     * the workspace + the active set. Best-effort: a failure degrades to "nothing
     * expired" (the read still only returns active rows, so a stale row not yet flipped
     * is simply still listed until the next successful pass — never a hard error).
     */
    private function expireStale(string $workspaceId, Carbon $now): void
    {
        try {
            DB::table(self::TABLE)
                ->where('workspace_id', $workspaceId)
                ->where('status', self::STATUS_ACTIVE)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $now)
                ->update([
                    'status' => self::STATUS_STALE,
                    'updated_at' => $now,
                ]);
        } catch (Throwable) {
            // best-effort; never a gate
        }
    }

    /**
     * The single ACTIVE conflicting claim on $target held by a DIFFERENT engine (the
     * most recent), or null. Used by claim() and (indirectly) the F2 guard. Already
     * stale-expired by the caller.
     *
     * @return array<string,mixed>|null
     */
    private function activeConflict(string $workspaceId, string $target, string $engine, Carbon $now): ?array
    {
        $row = DB::table(self::TABLE)
            ->where('workspace_id', $workspaceId)
            ->where('target', $target)
            ->where('status', self::STATUS_ACTIVE)
            ->where('engine', '!=', $engine)
            ->orderByDesc('claimed_at')
            ->first();

        return $row !== null ? $this->presentRow($row) : null;
    }

    /**
     * Present a DB row as a provider-safe claim array (labels/ids/timestamps only).
     *
     * @return array<string,mixed>
     */
    private function presentRow(object $row): array
    {
        return [
            'id' => (string) ($row->id ?? ''),
            'engine' => (string) ($row->engine ?? ''),
            'kind' => (string) ($row->kind ?? ''),
            'target' => (string) ($row->target ?? ''),
            'workspace_id' => (string) ($row->workspace_id ?? ''),
            'status' => (string) ($row->status ?? ''),
            'claimed_at' => $this->jsonTime($row->claimed_at ?? null),
            'expires_at' => $this->jsonTime($row->expires_at ?? null),
            'meta' => $this->decodeMeta($row->meta ?? null),
        ];
    }

    private function jsonTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value)->toJSON();
        } catch (Throwable) {
            return null;
        }
    }

    private function claimId(string $workspaceId, string $engine, string $kind, string $target): string
    {
        $hash = substr(hash('sha1', $workspaceId.'|'.$target), 0, 16);

        return $engine.':'.$kind.':'.$hash;
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function resolveTtl(array $opts): int
    {
        // The configured ABSOLUTE ceiling on any TTL (anti-runaway). The default is
        // itself clamped to the ceiling, so a misconfigured default > max can never
        // exceed the operator's hard cap.
        $max = max(1, (int) config('atlas.aobg.blackboard.max_ttl_seconds', 86400));
        $default = min($max, max(1, (int) config('atlas.aobg.blackboard.default_ttl_seconds', 3600)));

        $raw = $opts['ttl'] ?? null;
        $ttl = $default;
        if (is_int($raw) && $raw > 0) {
            $ttl = $raw;
        } elseif (is_string($raw) && is_numeric(trim($raw)) && (int) trim($raw) > 0) {
            $ttl = (int) trim($raw);
        } elseif (is_float($raw) && $raw > 0) {
            $ttl = (int) floor($raw);
        }

        return max(1, min($ttl, $max));
    }

    private function normalizeEngine(string $engine): string
    {
        $engine = strtolower(trim($engine));
        $engine = preg_replace('/[^a-z0-9._-]+/', '_', $engine) ?? $engine;

        return substr(trim($engine, '_-.'), 0, 40);
    }

    private function normalizeKind(string $kind): string
    {
        $kind = strtolower(trim($kind));

        return in_array($kind, self::KINDS, true) ? $kind : 'file';
    }

    private function normalizeTarget(string $target): string
    {
        $target = str_replace('\\', '/', trim($target));
        $cap = max(1, (int) config('atlas.aobg.blackboard.max_target_chars', 500));

        return mb_substr($target, 0, $cap);
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeMeta(mixed $meta): array
    {
        if (! is_array($meta)) {
            return [];
        }
        $out = [];
        $i = 0;
        foreach ($meta as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue; // refs/labels only — never nested content
            }
            $out[(string) $key] = is_scalar($value) ? mb_substr((string) $value, 0, 200) : null;
            if (++$i >= 10) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function encodeMeta(array $meta): string
    {
        $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json !== false ? $json : '{}';
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMeta(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Resolve the workspace id: explicit `workspace` (path or id) wins, else `cwd`,
     * else the primary default. A specific-resolver throw falls back to default once;
     * a TOTAL identity outage propagates to the caller's fail-open net.
     *
     * @param  array<string,mixed>  $opts
     */
    private function resolveWorkspaceId(array $opts): string
    {
        try {
            $explicit = $this->stringOpt($opts, 'workspace');
            if ($explicit !== null) {
                return $this->workspaceIdentity->resolveWorkspaceOrId($explicit);
            }
            $cwd = $this->stringOpt($opts, 'cwd');
            if ($cwd !== null) {
                return $this->workspaceIdentity->resolve($cwd);
            }

            return $this->workspaceIdentity->default();
        } catch (Throwable) {
            return $this->workspaceIdentity->default();
        }
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function stringOpt(array $opts, string $key): ?string
    {
        $raw = $opts[$key] ?? null;
        if (! is_scalar($raw)) {
            return null;
        }
        $raw = trim((string) $raw);

        return $raw !== '' ? $raw : null;
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{schema:string, ok:bool, status:string, claim:null, conflict:null, workspace:string, provider_bound:bool, fail_open:bool, reason:string}
     */
    private function failOpen(string $reason): array
    {
        return [
            'schema' => self::SCHEMA,
            'ok' => false,
            'status' => 'unavailable',
            'claim' => null,
            'conflict' => null,
            'workspace' => '',
            'provider_bound' => true,
            'fail_open' => true,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{schema:string, ok:bool, workspace:string, count:int, claims:list<array<string,mixed>>, provider_bound:bool, honesty:string}
     */
    private function emptyActive(string $workspaceId): array
    {
        return [
            'schema' => self::SCHEMA,
            'ok' => true,
            'workspace' => $workspaceId,
            'count' => 0,
            'claims' => [],
            'provider_bound' => true,
            'honesty' => 'active claims only; stale claims expired by TTL on read',
        ];
    }
}
