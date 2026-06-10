<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Rsi\EarnedAutonomy;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * Earned Autonomy · External Kill Switch + Dead-Man (OUTSIDE loop control).
 *
 * This is the hard, operator-only off-switch for the earned-autonomy layer. It
 * sits OUTSIDE the loop: nothing in the RSI loop, the composer, the red-team or
 * any auto path may disarm it or extend its dead-man. There is, by design, NO
 * disarm-for-the-loop method here that the machinery could reach — only an
 * explicit operator actor through an operator-driven path may call {@see arm()}
 * or {@see disarm()}, and a {@see disarm()} is irreversible-for-the-cycle.
 *
 * {@see isArmed()} returns true ONLY when BOTH hold:
 *   (a) the earned-autonomy master flag is enabled
 *       (config atlas.foundry.rsi.earned_autonomy.mode OR an explicit
 *        $input['earned_autonomy_mode_enabled'] === true), AND
 *   (b) the append-only kill-switch ledger's folded last state is ARMED
 *       (DEFAULT-OFF: absence of any event folds to DISARMED).
 *
 * SEPARATELY, the layer is considered KILLED — and the composer must route
 * EVERYTHING to the human gate — when ANY of these is true:
 *   - the operator kill file exists
 *     (storage/atlas/earned_autonomy/AUTONOMY_KILL), OR
 *   - the dead-man heartbeat file is older than the frozen TTL (or absent), OR
 *   - the earned-autonomy master flag is not truthy.
 * {@see isAutonomyKilled()} expresses exactly that, fail-closed.
 *
 * INVARIANT earned_autonomy.default_off: with the flag off and no arm event,
 * isArmed() === false and isAutonomyKilled() === true — the composer always
 * returns human_gate (byte-identical to today's proposal-only behaviour).
 *
 * INVARIANT earned_autonomy.kill_cannot_disarm: arm()/disarm() REQUIRE a
 * non-empty operator $actor; the service never self-arms and exposes no path a
 * loop component could use to clear the kill file or extend the dead-man. Those
 * are operator-only, via the filesystem.
 *
 * Deterministic + provider-free: a single injected clock seam drives every
 * timestamp; the ledger is append-only JSONL; hashes are canonical. This service
 * NEVER calls a provider, NEVER applies anything, NEVER merges.
 */
final class KillAuthorityService
{
    public const SCHEMA = 'atlas.foundry.rsi.earned_autonomy.kill_authority.v1';

    public const STATE_ARMED = 'armed';

    public const STATE_DISARMED = 'disarmed';

    public const EVENT_ARM = 'armed';

    public const EVENT_DISARM = 'disarmed';

    public const EVENT_SCHEMA = 'atlas.foundry.rsi.earned_autonomy.kill_authority_event.v1';

    /** Operator kill-file name; its mere existence forces autonomy killed. */
    public const KILL_FILE = 'AUTONOMY_KILL';

    /** Dead-man heartbeat file name; staleness beyond the TTL forces killed. */
    public const HEARTBEAT_FILE = 'AUTONOMY_HEARTBEAT';

    /**
     * Frozen dead-man TTL (seconds). A heartbeat older than this — or missing
     * entirely — means the operator is no longer attesting liveness, so the
     * layer is treated as killed. Conservative: 15 minutes.
     */
    public const DEAD_MAN_TTL_SECONDS = 900;

    /** @var Closure(): string returns an ISO-8601 UTC timestamp. */
    private readonly Closure $clock;

    private ?string $storageRootOverride = null;

    /**
     * @param  Closure(): string|null  $clock  injected clock seam (ISO-8601 UTC);
     *                                          defaults to the real UTC wall clock.
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): string => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);
    }

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    /**
     * Is the kill switch armed for an auto-apply decision?
     *
     * True ONLY if BOTH the master flag is enabled AND the folded ledger state
     * is ARMED. Default-off: no events => DISARMED => false, so the composer
     * always routes to the human gate unless the operator has explicitly armed.
     *
     * @param  array<string,mixed>  $input  flag override seam (`earned_autonomy_mode_enabled` === true)
     */
    public function isArmed(array $input = []): bool
    {
        if (! $this->flagEnabled($input)) {
            return false;
        }

        return $this->state() === self::STATE_ARMED;
    }

    /**
     * Is the earned-autonomy layer KILLED (route everything to human)?
     *
     * Fail-closed: true if the master flag is off, OR the operator kill file
     * exists, OR the dead-man heartbeat is stale/missing. This is a pure read of
     * operator-controlled filesystem state + the flag; the loop cannot clear it.
     *
     * @param  array<string,mixed>  $input  flag override seam
     */
    public function isAutonomyKilled(array $input = []): bool
    {
        if (! $this->flagEnabled($input)) {
            return true;
        }

        if ($this->killFileExists()) {
            return true;
        }

        return $this->deadManExpired();
    }

    /**
     * Arm the kill switch (operator-only). Requires a non-empty operator id; the
     * service never self-arms. Appends EVENT_ARM and writes a fresh heartbeat so
     * the dead-man starts ticking from the operator's attested liveness.
     *
     * @param  array<string,mixed>  $context  operator-supplied audit context
     * @return array<string,mixed>  the appended event
     */
    public function arm(array $context, string $actor): array
    {
        return $this->record(self::EVENT_ARM, $context, $actor, null);
    }

    /**
     * Disarm the kill switch (operator-only, immediate kill). Requires a
     * non-empty operator id and a reason. There is intentionally no loop-facing
     * variant: a disarm is irreversible-for-the-cycle — once disarmed mid-run,
     * isArmed() === false for every subsequent proposal until the operator arms
     * again through this same explicit path.
     *
     * @param  array<string,mixed>  $context  operator-supplied audit context
     * @return array<string,mixed>  the appended event
     */
    public function disarm(array $context, string $actor, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('KillAuthority::disarm requires a non-empty reason (operator-only).');
        }

        return $this->record(self::EVENT_DISARM, $context, $actor, $reason);
    }

    /**
     * The folded last state of the append-only kill-switch ledger. DISARMED is
     * the default when no events exist (DEFAULT-OFF). Pure fold, oldest-first.
     */
    public function state(): string
    {
        $state = self::STATE_DISARMED;
        foreach ($this->replay() as $event) {
            $type = (string) ($event['event'] ?? '');
            if ($type === self::EVENT_ARM) {
                $state = self::STATE_ARMED;
            } elseif ($type === self::EVENT_DISARM) {
                $state = self::STATE_DISARMED;
            }
        }

        return $state;
    }

    /**
     * Replay the append-only kill-switch ledger (deterministic fold, no side
     * effects). Oldest event first.
     *
     * @return list<array<string,mixed>>
     */
    public function replay(): array
    {
        return AppendOnlyJsonlStore::read($this->ledgerPath());
    }

    public function ledgerPath(): string
    {
        return $this->storageRoot().'/kill_authority.jsonl';
    }

    /**
     * Append one arm/disarm event (append-only). A non-empty operator $actor is
     * mandatory — the service never self-arms/disarms.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function record(string $eventType, array $context, string $actor, ?string $reason): array
    {
        $actor = trim($actor);
        if ($actor === '') {
            throw new \InvalidArgumentException(
                'KillAuthority::'.$eventType.' requires a non-empty operator actor id; the service never self-arms.'
            );
        }

        $event = [
            'schema_version' => self::EVENT_SCHEMA,
            'event' => $eventType,
            'recorded_at' => ($this->clock)(),
            'actor' => $actor,
            'reason' => $reason,
            'context' => $context,
        ];
        $event['event_hash'] = MissionCanonicalHash::sha256($event);

        AppendOnlyJsonlStore::appendUsingFilePutContents(
            $this->ledgerPath(),
            $event,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            FILE_APPEND,
        );

        if ($eventType === self::EVENT_ARM) {
            // The operator's arm attests liveness — start the dead-man ticking.
            $this->writeHeartbeat();
        }

        return $event;
    }

    /**
     * Default-off flag resolution mirroring RsiSelfImprovementProposalGate:
     * config flag (default false) OR an explicit $input override === true.
     *
     * @param  array<string,mixed>  $input
     */
    private function flagEnabled(array $input): bool
    {
        $config = (bool) config('atlas.foundry.rsi.earned_autonomy.mode', false);
        $override = ($input['earned_autonomy_mode_enabled'] ?? null) === true;

        return $config || $override;
    }

    private function killFileExists(): bool
    {
        return is_file($this->storageRoot().'/'.self::KILL_FILE);
    }

    /**
     * The dead-man is expired when the heartbeat file is missing OR its recorded
     * timestamp is older than the frozen TTL relative to the injected clock.
     * Fail-closed: an unreadable / unparseable / future-skewed heartbeat counts
     * as expired (we cannot prove liveness => not alive).
     */
    private function deadManExpired(): bool
    {
        $path = $this->storageRoot().'/'.self::HEARTBEAT_FILE;
        if (! is_file($path)) {
            return true;
        }

        $beat = trim((string) File::get($path));
        $beatTs = strtotime($beat);
        if ($beatTs === false) {
            return true; // unparseable heartbeat — cannot prove liveness.
        }

        $now = strtotime(($this->clock)());
        if ($now === false) {
            return true; // unparseable clock — fail closed.
        }

        $age = $now - $beatTs;
        // A future-skewed heartbeat (age < 0) is suspicious — fail closed.
        if ($age < 0) {
            return true;
        }

        return $age > self::DEAD_MAN_TTL_SECONDS;
    }

    private function writeHeartbeat(): void
    {
        $path = $this->storageRoot().'/'.self::HEARTBEAT_FILE;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, ($this->clock)().PHP_EOL);
    }

    private function storageRoot(): string
    {
        $root = $this->storageRootOverride
            ?? (function_exists('storage_path')
                ? storage_path('atlas/earned_autonomy')
                : sys_get_temp_dir().'/atlas/earned_autonomy');

        return rtrim($root, '/');
    }
}
