<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Throwable;

/**
 * ACDE lever S2 (observe-only slice) — a PROVIDER-SAFE, OBSERVE-ONLY trace of the EarnedAutonomy gate's
 * decisions.
 *
 * S2's end state — plugging the EarnedAutonomy door into a LIVE auto-apply path so the loop can self-improve
 * without the human-review rate ceiling — is the highest-risk lever in the whole programme. The adversarial
 * design pass confirmed the door is real but has NO live actuator today (the auto_apply signal is a verified
 * dead-end), and that arming a real actuator is BLOCKED pending (a) an operator governance decision to remove
 * the rate ceiling and (b) registering the proposal-gate + selector seam as sacred paths. So this slice ships
 * ONLY the observe instrument: it records WHAT the gate would decide (decision, risk rank, earned tier, the
 * safety booleans) so the operator can audit the door's behaviour for a long while BEFORE ever deciding to
 * actuate it. It NEVER applies, merges, or canonizes anything — it cannot, by construction (it has no actuator).
 *
 * PROVIDER-SAFE: the trace carries ONLY decision metadata + changed PATHS — never raw diff/added lines, never
 * code, never a model self-report. Flag-gated default-OFF => record() is inert => byte-identical. Fail-open.
 */
final class AtlasLoopEarnedAutonomyDecisionTrace
{
    public const SCHEMA = 'atlas.loop.earned_autonomy_trace.v1';

    public function __construct(private readonly ?string $storageRoot = null) {}

    /**
     * Record one gate decision as a provider-safe trace + append it to the observe-only audit log. Returns the
     * trace (or [] when OFF). NEVER actuates — there is no apply/merge/canonize path here.
     *
     * @param  array<string,mixed>  $decision     an EarnedAutonomyGateService::decide() / gate admit() result
     * @param  list<string>  $changedPaths
     * @return array<string,mixed>
     */
    public function record(array $decision, array $changedPaths = []): array
    {
        if (! $this->enabled()) {
            return [];
        }
        $safe = $this->toSafeTrace($decision, $changedPaths);

        try {
            $root = $this->storageRoot ?? (function_exists('storage_path') ? storage_path('atlas/loop') : sys_get_temp_dir());
            if (! is_dir($root)) {
                @mkdir($root, 0o755, true);
            }
            @file_put_contents(
                rtrim($root, '/').'/earned_autonomy_trace.jsonl',
                json_encode($safe, JSON_UNESCAPED_SLASHES).PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );
        } catch (Throwable) {
            // observe-only audit log is best-effort — it never affects any decision (it actuates nothing).
        }

        return $safe;
    }

    /**
     * PURE provider-safe projection of a decision: ONLY decision metadata + changed PATHS. No raw added/removed
     * lines, no code, no self-report can ride into the trace — only the machine-resolved decision envelope.
     *
     * @param  array<string,mixed>  $decision
     * @param  list<string>  $changedPaths
     * @return array<string,mixed>
     */
    public function toSafeTrace(array $decision, array $changedPaths = []): array
    {
        $safe = [
            'schema' => self::SCHEMA,
            'decision' => (string) ($decision['decision'] ?? ''),
            'risk_rank' => (int) ($decision['risk_rank'] ?? -1),
            'risk_class' => (string) ($decision['risk_class'] ?? ''),
            'earned_tier' => (int) ($decision['earned_tier'] ?? -1),
            'auto_applied' => ($decision['auto_applied'] ?? false) === true,
            'routed_to_human_gate' => ($decision['routed_to_human_gate'] ?? false) === true,
            'kill_armed' => ($decision['kill_armed'] ?? false) === true,
            'drift_detected' => ($decision['drift_detected'] ?? false) === true,
            'revoked' => ($decision['revoked'] ?? false) === true,
            'changed_paths' => array_values(array_filter(
                array_map(static fn ($p): string => trim((string) $p), $changedPaths),
                static fn (string $p): bool => $p !== '',
            )),
        ];
        $safe['decision_hash'] = substr(hash('sha256', (string) json_encode($safe, JSON_UNESCAPED_SLASHES)), 0, 40);

        return $safe;
    }

    /** Is the observe-only trace ON? Read defensively so a container-less caller never fatals (=> OFF). */
    public function enabled(): bool
    {
        try {
            $app = function_exists('app') ? app() : null;
            if ($app === null || ! $app->bound('config')) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return (bool) config('atlas.loop.earned_autonomy_decision_trace', false);
    }
}
