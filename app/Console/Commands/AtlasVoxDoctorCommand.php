<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Vox\Audit\VoxV3HardeningAuditService;
use App\Services\Ai\Vox\Dogfood\VoxDogfoodService;
use App\Services\Ai\Vox\Gate\VoxV3CertificationPackService;
use App\Services\Ai\Vox\Gate\VoxV3PromotionGateService;
use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use App\Services\Ai\Vox\Readiness\VoxReadinessService;
use App\Services\Ai\Vox\Rivals\VoxRivalsRunner;
use App\Services\Ai\Vox\VoxSchema;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Vox backend doctor (Onda V3.9 / Claude AC).
 *
 * One artisan command, one purpose: answer "Backend Vox está pronto para
 * uso real?" by aggregating every existing read-only Vox service into a
 * single, hash-stable snapshot.
 *
 * Hard rules:
 *   - READ-ONLY. Never writes. Never executes a CLI / provider.
 *   - Never touches `app/Services/Ai/Voice/` (ADR-0003 boundary). Boundary
 *     is asserted by config + by the hardening audit consumed below.
 *   - Never promotes V4 — `certification.v4_unlock_allowed` is the upstream
 *     pack's value (always false in this wave), and this command never
 *     flips it.
 *   - Each section is collected inside its own try/catch so a single
 *     exception cannot mask the others.
 *
 * Aggregation:
 *   - any section.status='fail' → overall 'fail'
 *   - any section.status='warn' OR 'unknown' (and no fail) → overall 'warn'
 *   - otherwise → 'pass'
 *
 * Exit codes:
 *   0 → status='pass'
 *   0 → status='warn' without --strict (Vox usable, some piece honestly missing)
 *   1 → status='warn' with --strict
 *   1 → status='fail'
 *   2 → fatal config/runtime exception (the command itself crashed)
 */
final class AtlasVoxDoctorCommand extends Command
{
    public const SCHEMA = 'atlas.vox.backend_doctor.v1';

    public const STATUS_PASS = 'pass';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';

    protected $signature = 'atlas:vox:doctor
        {--json : Print machine-readable JSON snapshot}
        {--strict : Treat warn as non-zero exit (for CI gates)}
        {--include-certification-pack : Include the full V3 certification pack body (default: summary + hash only)}';

    protected $description = 'Aggregate every Vox read-only health surface (readiness, hardening, metrics, rivals, dogfood, gate, certification) into a single backend snapshot. Read-only.';

    public function handle(
        VoxReadinessService $readiness,
        VoxV3HardeningAuditService $hardening,
        VoxV3PromotionGateService $gate,
        VoxV3CertificationPackService $certPack,
        VoxMetricsService $metrics,
        VoxRivalsRunner $rivals,
        VoxDogfoodService $dogfood,
    ): int {
        try {
            $sections = [
                'health' => $this->collectHealth(),
                'readiness' => $this->collect('readiness', fn () => $readiness->probe(), function (array $r): array {
                    return [
                        'status' => $this->normaliseStatus(
                            $r['status'] ?? null,
                            successAliases: ['ready'],
                            warnAliases: ['partial', 'unknown'],
                            failAliases: ['blocked'],
                        ),
                        'observed' => (string) ($r['status'] ?? 'unknown'),
                        'summary' => (string) ($r['summary'] ?? ''),
                        'capabilities' => (array) ($r['capabilities'] ?? []),
                        'next_actions' => array_values((array) ($r['next_actions'] ?? [])),
                    ];
                }),
                'hardening' => $this->collect('hardening', fn () => $hardening->audit(), function (array $a): array {
                    return [
                        'status' => $this->normaliseStatus(
                            $a['status'] ?? null,
                            successAliases: ['pass'],
                            warnAliases: ['warn', 'unknown'],
                            failAliases: ['fail'],
                        ),
                        'observed' => (string) ($a['status'] ?? 'unknown'),
                        'summary' => (array) ($a['summary'] ?? []),
                    ];
                }),
                'metrics' => $this->collect('metrics', fn () => $metrics->snapshot(), function (array $m): array {
                    $hg = (array) ($m['hard_gates'] ?? []);
                    $rawAudio = (int) ($hg['raw_audio_persisted_count'] ?? 0);
                    $bypass = (int) ($hg['confirmation_bypass_count'] ?? 0);
                    $destructive = (int) ($hg['destructive_action_without_receipt'] ?? 0);
                    $status = ($rawAudio > 0 || $bypass > 0 || $destructive > 0)
                        ? self::STATUS_FAIL
                        : self::STATUS_PASS;

                    return [
                        'status' => $status,
                        'observed' => (string) ($m['status'] ?? 'unknown'),
                        'summary' => (array) ($m['summary'] ?? []),
                        'hard_gates' => $hg,
                    ];
                }),
                'rivals' => $this->collect('rivals', fn () => $rivals->report(), function (array $r): array {
                    $total = (int) ($r['cases_total'] ?? 0);
                    $storage = (string) ($r['storage_status'] ?? 'unknown');
                    $setupNext = $r['setup_next_action'] ?? null;
                    // Rivals having ZERO recorded cases is honest "ainda não
                    // calibrado" — we surface as warn so the doctor flags
                    // it (V3 promotion gate needs at least 1.2× multiplier).
                    // `storage_status='setup_pending'` is a SEPARATE warn:
                    // the table itself is missing; operator needs to migrate.
                    $status = $total > 0 ? self::STATUS_PASS : self::STATUS_WARN;

                    return [
                        'status' => $status,
                        'cases_total' => $total,
                        'storage_status' => $storage,
                        'setup_next_action' => is_string($setupNext) ? $setupNext : null,
                        'vox_wins' => (int) ($r['vox_wins'] ?? 0),
                        'baseline_wins' => (int) ($r['baseline_wins'] ?? 0),
                        'ties' => (int) ($r['ties'] ?? 0),
                        'action_regret_score' => (float) ($r['action_regret_score'] ?? 0),
                        'rivals_voice_multiplier' => (float) ($r['rivals_voice_multiplier'] ?? 0),
                        'recommendation' => (string) ($r['recommendation'] ?? ''),
                    ];
                }),
                'dogfood' => $this->collect('dogfood', fn () => $dogfood->report(), function (array $d): array {
                    $total = (int) data_get($d, 'totals.total', data_get($d, 'sessions_total', 0));
                    $status = $total > 0 ? self::STATUS_PASS : self::STATUS_WARN;

                    return [
                        'status' => $status,
                        'schema' => (string) ($d['schema'] ?? ''),
                        'sessions_total' => $total,
                        // Keep payload compact: surface only the headline
                        // counters. The full report is reachable via the
                        // HTTP endpoint when needed.
                        'totals' => (array) ($d['totals'] ?? []),
                    ];
                }),
                'gate_v3' => $this->collect('gate_v3', fn () => $gate->evaluate(), function (array $g): array {
                    $observed = (string) ($g['status'] ?? 'unknown');
                    $blockers = array_values((array) ($g['blockers'] ?? []));
                    $warming = array_values((array) ($g['warming_up'] ?? []));

                    // Distinguish "blocked because of safety violation" from
                    // "blocked because operator hasn't generated data yet".
                    // The first must surface as fail; the second is a warn
                    // (V3 promotion is gated, but Vox itself is usable).
                    $safetyBlockers = array_values(array_filter(
                        $blockers,
                        static fn (string $b): bool => in_array($b, [
                            'raw_audio_persisted_zero',
                            'confirmation_bypass_zero',
                            'destructive_action_without_receipt_zero',
                        ], true),
                    ));
                    $setupOnlyBlockers = array_values(array_diff($blockers, $safetyBlockers));

                    if ($observed === 'ready_for_vitor_review') {
                        $status = self::STATUS_PASS;
                    } elseif ($observed === 'warming_up') {
                        $status = self::STATUS_WARN;
                    } elseif ($observed === 'blocked') {
                        $status = $safetyBlockers !== [] ? self::STATUS_FAIL : self::STATUS_WARN;
                    } else {
                        $status = self::STATUS_WARN;
                    }

                    return [
                        'status' => $status,
                        'observed' => $observed,
                        'blockers' => $blockers,
                        'safety_blockers' => $safetyBlockers,
                        'setup_only_blockers' => $setupOnlyBlockers,
                        'warming_up' => $warming,
                        'explicit_vitor_approval_required' => (bool) ($g['explicit_vitor_approval_required'] ?? true),
                    ];
                }),
                'certification' => $this->collectCertification(
                    $certPack,
                    (bool) $this->option('include-certification-pack'),
                ),
            ];

            $overall = $this->aggregateStatus($sections);
            $nextActions = $this->collectNextActions($sections);

            $snapshot = [
                'schema' => self::SCHEMA,
                'status' => $overall,
                'sections' => $sections,
                'next_actions' => $nextActions,
                'read_only' => true,
                'v4_unlock_allowed' => false,
                'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            ];

            $this->emit($snapshot);

            return $this->exitCode($overall);
        } catch (Throwable $e) {
            $payload = [
                'schema' => self::SCHEMA,
                'status' => self::STATUS_FAIL,
                'fatal' => [
                    'code' => 'doctor_fatal_exception',
                    'message' => $e->getMessage(),
                    'class' => $e::class,
                ],
                'read_only' => true,
                'v4_unlock_allowed' => false,
                'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            ];
            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('atlas:vox:doctor fatal exception: '.$e->getMessage());
            }

            return 2;
        }
    }

    /**
     * `health` section · derived from configuration + VoxSchema constants,
     * NOT by hitting `/ai/vox/health` over HTTP (that would require the
     * server to be running, which is hostile in a CI doctor). The endpoint
     * itself is fully covered by `tests/Feature/Ai/Vox/AtlasAiVoxControllerTest`.
     *
     * @return array<string,mixed>
     */
    private function collectHealth(): array
    {
        $modes = [
            VoxSchema::MODE_DICTATION,
            VoxSchema::MODE_PROMPT_POLISH,
            VoxSchema::MODE_INTENT_COMPILE,
            VoxSchema::MODE_GOVERNED_EXECUTE,
        ];

        return [
            'status' => self::STATUS_PASS,
            'kernel_vox_version' => VoxSchema::KERNEL_VOX_VERSION,
            'compiler_version' => VoxSchema::COMPILER_VERSION,
            'default_language' => VoxSchema::DEFAULT_LANGUAGE,
            'modes_supported' => $modes,
            'kernel_guarantees' => [
                'provider_call' => true, // V3 unlocks CLI shell-out behind tokens
                'tool_call' => false,
                'raw_audio_accepted' => false,
                'terminal_execute' => false,
                'destructive_auto_execute' => false,
                'voice_realtime_touched' => false,
            ],
            'voice_realtime_status' => 'paused_until_v6',
        ];
    }

    /**
     * Wrap a service collector with try/catch so a failure in one section
     * never masks the others. On exception, the section becomes
     * `status='fail'` with a structured `error` so the human/CI sees
     * exactly where to look.
     *
     * @param  callable():array<string,mixed>  $collector
     * @param  callable(array<string,mixed>):array<string,mixed>  $shape
     * @return array<string,mixed>
     */
    private function collect(string $name, callable $collector, callable $shape): array
    {
        try {
            $raw = $collector();

            return $shape($raw);
        } catch (Throwable $e) {
            return [
                'status' => self::STATUS_FAIL,
                'error' => [
                    'code' => $name.'_collector_exception',
                    'message' => $e->getMessage(),
                    'class' => $e::class,
                ],
            ];
        }
    }

    /**
     * Certification pack is heavy. By default we include only the
     * non-volatile summary + hash; `--include-certification-pack` flips
     * to the full body for forensic inspection.
     *
     * @return array<string,mixed>
     */
    private function collectCertification(VoxV3CertificationPackService $svc, bool $includeFull): array
    {
        try {
            $pack = $svc->build();
            $gateStatus = (string) ($pack['gate_status'] ?? '');

            // Same nuance as gate_v3: a `blocked` cert pack is only a fail
            // when the underlying blockers are safety violations. A pack
            // blocked solely because the operator hasn't run V3 yet (no
            // eclipse tests, no usage window) is a warning — the pack is
            // honestly waiting for evidence, not flagging a regression.
            //
            // The cert pack emits `blockers[]` as objects with
            // `{code, source, severity}`; extract just `code` so the safety
            // check matches our string allowlist below.
            $blockerCodes = [];
            foreach ((array) ($pack['blockers'] ?? []) as $rawBlocker) {
                if (is_string($rawBlocker) && $rawBlocker !== '') {
                    $blockerCodes[] = $rawBlocker;
                } elseif (is_array($rawBlocker) && isset($rawBlocker['code'])) {
                    $blockerCodes[] = (string) $rawBlocker['code'];
                }
            }
            $safetyBlockerCodes = [
                'raw_audio_persisted_zero',
                'confirmation_bypass_zero',
                'destructive_action_without_receipt_zero',
                'safety_invariant_raw_audio_persisted_zero_failed',
                'safety_invariant_confirmation_bypass_zero_failed',
                'safety_invariant_destructive_without_receipt_zero_failed',
                'safety_invariant_terminal_execute_disabled_failed',
                'safety_invariant_voice_realtime_untouched_failed',
            ];
            $safetyBlockers = array_values(array_filter(
                $blockerCodes,
                static fn (string $b): bool => in_array($b, $safetyBlockerCodes, true),
            ));
            $blockers = $blockerCodes;
            if ($gateStatus === 'ready_for_vitor_review') {
                $status = self::STATUS_PASS;
            } elseif ($gateStatus === 'warming_up') {
                $status = self::STATUS_WARN;
            } elseif ($gateStatus === 'blocked') {
                $status = $safetyBlockers !== [] ? self::STATUS_FAIL : self::STATUS_WARN;
            } else {
                $status = self::STATUS_WARN;
            }

            // Surface a clear setup_pending marker when rivals storage is
            // missing — the doctor's `next_actions` will pick this up and
            // surface "rode `php artisan migrate`" to the operator.
            $rivalsStorage = (string) data_get($pack, 'rivals_report.storage_status', 'unknown');
            $rivalsSetupNext = data_get($pack, 'rivals_report.setup_next_action');

            $section = [
                'status' => $status,
                'observed_gate_status' => $gateStatus,
                'certification_hash' => (string) ($pack['certification_hash'] ?? ''),
                'readiness_summary' => (string) ($pack['readiness_summary'] ?? ''),
                'vitor_review_required' => (bool) ($pack['vitor_review_required'] ?? true),
                'safety_blockers' => $safetyBlockers,
                'setup_only_blockers' => array_values(array_diff($blockers, $safetyBlockers)),
                'rivals_storage_status' => $rivalsStorage,
                'rivals_setup_next_action' => is_string($rivalsSetupNext) ? $rivalsSetupNext : null,
                // The pack ITSELF is the canonical source of v4_unlock_allowed
                // and is hard-coded to false. The doctor surfaces it as-is.
                'v4_unlock_allowed' => (bool) ($pack['v4_unlock_allowed'] ?? false),
            ];
            if ($includeFull) {
                $section['pack'] = $pack;
            }

            return $section;
        } catch (Throwable $e) {
            return [
                'status' => self::STATUS_FAIL,
                'error' => [
                    'code' => 'certification_collector_exception',
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param  list<string>  $successAliases
     * @param  list<string>  $warnAliases
     * @param  list<string>  $failAliases
     */
    private function normaliseStatus(
        ?string $observed,
        array $successAliases,
        array $warnAliases,
        array $failAliases,
    ): string {
        if ($observed === null || $observed === '') {
            return self::STATUS_WARN; // honest: unknown is never silently pass
        }
        if (in_array($observed, $failAliases, true)) {
            return self::STATUS_FAIL;
        }
        if (in_array($observed, $warnAliases, true)) {
            return self::STATUS_WARN;
        }
        if (in_array($observed, $successAliases, true)) {
            return self::STATUS_PASS;
        }

        // Treating 'ok' as pass for VoxMetricsService snapshot 'status'.
        if ($observed === 'ok') {
            return self::STATUS_PASS;
        }

        return self::STATUS_WARN;
    }

    /**
     * @param  array<string,array<string,mixed>>  $sections
     */
    private function aggregateStatus(array $sections): string
    {
        $hasFail = false;
        $hasNonPass = false;
        foreach ($sections as $section) {
            $s = (string) ($section['status'] ?? '');
            if ($s === self::STATUS_FAIL) {
                $hasFail = true;
            }
            if ($s !== self::STATUS_PASS) {
                $hasNonPass = true;
            }
        }
        if ($hasFail) {
            return self::STATUS_FAIL;
        }
        if ($hasNonPass) {
            return self::STATUS_WARN;
        }

        return self::STATUS_PASS;
    }

    /**
     * @param  array<string,array<string,mixed>>  $sections
     * @return list<string>
     */
    private function collectNextActions(array $sections): array
    {
        $actions = [];
        // Readiness next_actions are already operator-grade strings; surface them.
        foreach ((array) data_get($sections, 'readiness.next_actions', []) as $action) {
            if (is_string($action) && $action !== '') {
                $actions[] = $action;
            }
        }
        // Storage setup pending? Surface the migrate hint front-and-center
        // so operators don't have to read the section bodies to find it.
        $rivalsSetup = (string) data_get($sections, 'rivals.setup_next_action', '');
        if ($rivalsSetup !== '') {
            $actions[] = $rivalsSetup;
        }
        $certSetup = (string) data_get($sections, 'certification.rivals_setup_next_action', '');
        if ($certSetup !== '' && $certSetup !== $rivalsSetup) {
            $actions[] = $certSetup;
        }
        foreach ($sections as $name => $section) {
            $s = (string) ($section['status'] ?? '');
            if ($s === self::STATUS_FAIL) {
                $actions[] = "investigar seção '{$name}' — status=fail";
            } elseif ($s === self::STATUS_WARN) {
                $actions[] = "revisar seção '{$name}' — status=warn (unknown/partial)";
            }
        }

        return array_values(array_unique($actions));
    }

    private function exitCode(string $status): int
    {
        if ($status === self::STATUS_FAIL) {
            return 1;
        }
        if ($status === self::STATUS_WARN) {
            return (bool) $this->option('strict') ? 1 : 0;
        }

        return 0;
    }

    /** @param  array<string,mixed>  $snapshot */
    private function emit(array $snapshot): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $snapshot,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }

        // Compact human summary.
        $status = (string) $snapshot['status'];
        $marker = match ($status) {
            self::STATUS_PASS => '✓',
            self::STATUS_WARN => '!',
            self::STATUS_FAIL => '✖',
            default => '?',
        };
        $this->line("[atlas:vox:doctor] {$marker} status={$status}  generated_at={$snapshot['generated_at']}");
        foreach ((array) $snapshot['sections'] as $name => $section) {
            $sMark = match ((string) ($section['status'] ?? '')) {
                self::STATUS_PASS => '·',
                self::STATUS_WARN => '!',
                self::STATUS_FAIL => '✖',
                default => '?',
            };
            $observed = (string) ($section['observed'] ?? $section['observed_gate_status'] ?? '');
            $note = $observed !== '' ? "  observed={$observed}" : '';
            $this->line("  {$sMark} [{$section['status']}] {$name}{$note}");
        }
        $actions = (array) ($snapshot['next_actions'] ?? []);
        if (! empty($actions)) {
            $this->line('  next_actions:');
            foreach ($actions as $a) {
                $this->line("    - {$a}");
            }
        }
    }
}
