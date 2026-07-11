#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Atlas scheduler watchdog — EXTERNAL cadence agent (EVI-01).
 *
 * Standalone: does NOT boot Laravel. Lives outside schedule:run so it can
 * detect silent death of the patient (com.atlas.scheduler) without dying
 * with it.
 *
 * Checks:
 *   (a) heartbeat.jsonl freshness vs 300s threshold
 *   (b) artisan boot smoke in a timed subprocess
 *   (c) new "PHP Fatal error" blocks in launchd.err.log
 *   (d) unified ACOS watchdog plugins (WDG-01) via read-only artisan
 *   (e) operational volume (VOL-01) via read-only artisan — alert "janela faminta"
 *   (f) rollback triggers (ROL-01) via read-only artisan — alert on simulated/fired condition
 *   (g) ACOS long-horizon gate receipt warnings (EVI-07) — warning-only echo
 *
 * On failure: append watchdog-alarm.jsonl, kickstart patient, local notify.
 * Warning-only checks do NOT kickstart the scheduler (operational hunger ≠ patient death).
 * Kill-switch: storage/atlas/scheduler/watchdog-disabled → exit 0 silent.
 *
 * Env overrides (tests + install):
 *   ATLAS_WATCHDOG_ROOT, ATLAS_WATCHDOG_STORAGE, ATLAS_WATCHDOG_PHP,
 *   ATLAS_WATCHDOG_ARTISAN, ATLAS_WATCHDOG_PATIENT_LABEL,
 *   ATLAS_WATCHDOG_KICKSTART_CMD, ATLAS_WATCHDOG_NOTIFY_CMD,
 *   ATLAS_WATCHDOG_THRESHOLD_SECONDS, ATLAS_WATCHDOG_COOLDOWN_SECONDS,
 *   ATLAS_WATCHDOG_BOOT_TIMEOUT_SECONDS, ATLAS_WATCHDOG_DRY_RUN,
 *   ATLAS_WATCHDOG_UNIFIED_CHECK, ATLAS_WATCHDOG_UNIFIED_CMD,
 *   ATLAS_WATCHDOG_VOLUME_CHECK, ATLAS_WATCHDOG_VOLUME_CMD,
 *   ATLAS_WATCHDOG_ROLLBACK_CHECK, ATLAS_WATCHDOG_ROLLBACK_CMD,
 *   ATLAS_WATCHDOG_ACOS_LONG_HORIZON_RECEIPT
 */
$root = rtrim((string) (getenv('ATLAS_WATCHDOG_ROOT') ?: dirname(__DIR__)), '/');
$storage = rtrim((string) (getenv('ATLAS_WATCHDOG_STORAGE') ?: $root.'/storage/atlas/scheduler'), '/');
$php = (string) (getenv('ATLAS_WATCHDOG_PHP') ?: PHP_BINARY);
$artisan = (string) (getenv('ATLAS_WATCHDOG_ARTISAN') ?: $root.'/artisan');
$patientLabel = (string) (getenv('ATLAS_WATCHDOG_PATIENT_LABEL') ?: 'com.atlas.scheduler');
$threshold = (int) (getenv('ATLAS_WATCHDOG_THRESHOLD_SECONDS') ?: 300);
$cooldown = (int) (getenv('ATLAS_WATCHDOG_COOLDOWN_SECONDS') ?: 900);
$bootTimeout = (int) (getenv('ATLAS_WATCHDOG_BOOT_TIMEOUT_SECONDS') ?: 20);
$dryRun = filter_var((string) (getenv('ATLAS_WATCHDOG_DRY_RUN') ?: '0'), FILTER_VALIDATE_BOOLEAN);

$heartbeatPath = $storage.'/heartbeat.jsonl';
$errLogPath = $storage.'/launchd.err.log';
$alarmPath = $storage.'/watchdog-alarm.jsonl';
$statePath = $storage.'/watchdog-state.json';
$disabledPath = $storage.'/watchdog-disabled';

if (is_file($disabledPath)) {
    fwrite(STDOUT, json_encode(['status' => 'disabled', 'reason' => 'kill_switch'], JSON_UNESCAPED_SLASHES)."\n");
    exit(0);
}

if (! is_dir($storage)) {
    @mkdir($storage, 0775, true);
}

$now = time();
$failures = [];

// (a) Heartbeat freshness
$age = heartbeatAgeSeconds($heartbeatPath, $now);
if ($age === null || $age > $threshold) {
    $failures[] = [
        'check' => 'heartbeat_stale',
        'age_seconds' => $age,
        'threshold_seconds' => $threshold,
    ];
}

// (b) Artisan boot smoke
$boot = artisanBootSmoke($php, $artisan, $root, $bootTimeout);
if (! $boot['ok']) {
    $failures[] = [
        'check' => 'artisan_boot_fatal',
        'exit_code' => $boot['exit_code'],
        'stderr_tail' => $boot['stderr_tail'],
    ];
}

// (c) New PHP Fatal error blocks
$state = readState($statePath);
$fatal = countNewFatalBlocks($errLogPath, $state);
if ($fatal['new_blocks'] > 0) {
    $failures[] = [
        'check' => 'php_fatal_in_err_log',
        'new_blocks' => $fatal['new_blocks'],
        'total_blocks' => $fatal['total_blocks'],
    ];
}
$state['err_log_offset'] = $fatal['offset'];
$state['err_log_fatal_blocks'] = $fatal['total_blocks'];
$state['last_check_at'] = gmdate('c', $now);
writeState($statePath, $state);

$warnings = [];

// (d-f) Read-only artisan checks; all fail-open on boot/parse failure.
if ($boot['ok']) {
    $unified = unifiedWatchdogCheck($php, $artisan, $root, $bootTimeout);
    if (($unified['alert'] ?? false) === true) {
        $warnings[] = [
            'check' => 'acos_unified_watchdog',
            'alert_code' => $unified['alert_code'] ?? 'acos_unified_watchdog_alert',
            'status' => $unified['status'] ?? null,
            'alert_count' => is_array($unified['alerts'] ?? null) ? count($unified['alerts']) : 0,
            'checks' => array_values(array_filter(
                array_map(static fn (mixed $check): ?string => is_array($check) ? (string) ($check['id'] ?? '') : null, (array) ($unified['checks'] ?? [])),
                static fn (?string $id): bool => is_string($id) && $id !== '',
            )),
            'alerts' => $unified['alerts'] ?? [],
        ];
    }

    $volume = operationalVolumeCheck($php, $artisan, $root, $bootTimeout);
    if (($volume['alert'] ?? false) === true) {
        $warnings[] = [
            'check' => 'operational_volume_janela_faminta',
            'alert_code' => $volume['alert_code'] ?? 'janela_faminta',
            'dev_count' => arrayPath($volume, 'windows.dev.count'),
            'dev_threshold' => arrayPath($volume, 'windows.dev.threshold'),
            'forge_count' => arrayPath($volume, 'windows.forge.count'),
            'forge_threshold' => arrayPath($volume, 'windows.forge.threshold'),
        ];
    }

    $rollback = rollbackTriggerCheck($php, $artisan, $root, $bootTimeout);
    if (($rollback['alert'] ?? false) === true) {
        $first = is_array($rollback['alerts'][0] ?? null) ? $rollback['alerts'][0] : [];
        $warnings[] = [
            'check' => 'rollback_trigger_fired',
            'alert_code' => $rollback['alert_code'] ?? 'rollback_trigger_fired',
            'trigger_id' => $first['trigger_id'] ?? null,
            'slices' => $first['slices'] ?? [],
            'rollback_action' => $first['rollback_action'] ?? [],
            'simulated' => $first['simulated'] ?? false,
        ];
    }
}

$longHorizon = acosLongHorizonGateWarning($root);
if (($longHorizon['alert'] ?? false) === true) {
    $warnings[] = [
        'check' => 'acos_long_horizon_gate_warning',
        'alert_code' => 'acos_long_horizon_gate_warning',
        'receipt_path' => $longHorizon['receipt_path'] ?? null,
        'receipt_status' => $longHorizon['status'] ?? null,
        'receipt_certified' => $longHorizon['certified'] ?? null,
        'receipt_warnings' => $longHorizon['warnings'] ?? [],
    ];
}

$result = [
    'schema_version' => 'atlas.scheduler.watchdog.v1',
    'checked_at' => gmdate('c', $now),
    'status' => $failures === [] ? ($warnings === [] ? 'healthy' : 'warning') : 'unhealthy',
    'failures' => $failures,
    'warnings' => $warnings,
    'heartbeat_age_seconds' => $age,
    'boot_ok' => $boot['ok'],
    'patient_label' => $patientLabel,
];

if ($failures === [] && $warnings === []) {
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES)."\n");
    exit(0);
}

if ($failures === [] && $warnings !== []) {
    if (! $dryRun) {
        appendJsonl($alarmPath, $result + [
            'action' => 'warning',
            'notified' => notifyLocal(warningMessage($warnings)),
        ]);
    } else {
        $result['dry_run'] = true;
    }
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES)."\n");
    exit(0);
}

if (inCooldown($alarmPath, $now, $cooldown)) {
    $result['status'] = 'unhealthy_cooldown';
    $result['action'] = 'suppressed_cooldown';
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES)."\n");
    exit(0);
}

$alarm = $result + [
    'action' => 'alarm',
    'kickstarted' => false,
    'notified' => false,
];

if (! $dryRun) {
    appendJsonl($alarmPath, $alarm);
    $alarm['kickstarted'] = kickstartPatient($patientLabel);
    $alarm['notified'] = notifyLocal('Atlas scheduler watchdog: '.implode(',', array_column($failures, 'check')));
    // Rewrite last alarm line with action outcomes (append second line — append-only honest).
    appendJsonl($alarmPath, [
        'schema_version' => 'atlas.scheduler.watchdog.action.v1',
        'at' => gmdate('c'),
        'kickstarted' => $alarm['kickstarted'],
        'notified' => $alarm['notified'],
        'patient_label' => $patientLabel,
        'failure_checks' => array_column($failures, 'check'),
    ]);
} else {
    $alarm['dry_run'] = true;
}

fwrite(STDOUT, json_encode($alarm, JSON_UNESCAPED_SLASHES)."\n");
exit(0);

// --- helpers (plain PHP, no framework) ---

function heartbeatAgeSeconds(string $path, int $now): ?int
{
    if (! is_file($path) || filesize($path) === 0) {
        return null;
    }

    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return null;
    }
    $size = filesize($path);
    $read = min(8192, $size);
    fseek($fh, -$read, SEEK_END);
    $chunk = stream_get_contents($fh) ?: '';
    fclose($fh);

    $lines = array_values(array_filter(array_map('trim', explode("\n", $chunk)), static fn ($l) => $l !== ''));
    if ($lines === []) {
        return null;
    }
    $last = json_decode($lines[count($lines) - 1], true);
    if (! is_array($last) || ! isset($last['timestamp'])) {
        // fall back to mtime
        return max(0, $now - (int) filemtime($path));
    }
    $ts = strtotime((string) $last['timestamp']);
    if ($ts === false) {
        return max(0, $now - (int) filemtime($path));
    }

    return max(0, $now - $ts);
}

/**
 * @return array{ok:bool,exit_code:int,stderr_tail:string}
 */
function artisanBootSmoke(string $php, string $artisan, string $cwd, int $timeout): array
{
    if (! is_file($artisan)) {
        return ['ok' => false, 'exit_code' => 127, 'stderr_tail' => 'artisan_missing'];
    }

    // Prefer an explicit boot command override (tests); else php artisan list --raw.
    $override = getenv('ATLAS_WATCHDOG_BOOT_CMD');
    if (is_string($override) && $override !== '') {
        $cmd = $override;
    } else {
        $cmd = escapeshellarg($php).' '.escapeshellarg($artisan).' list --raw';
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, $cwd, null);
    if (! is_resource($proc)) {
        return ['ok' => false, 'exit_code' => 1, 'stderr_tail' => 'proc_open_failed'];
    }
    fclose($pipes[0]);

    $start = time();
    $stdout = '';
    $stderr = '';
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $exit = null;
    while (true) {
        $status = proc_get_status($proc);
        // Capture exitcode on the FIRST observation of termination (PHP only
        // returns the real code once; subsequent reads yield -1).
        if (! $status['running'] && $exit === null) {
            $exit = (int) $status['exitcode'];
        }
        $stdout .= (string) fread($pipes[1], 8192);
        $stderr .= (string) fread($pipes[2], 8192);
        if ($exit !== null) {
            break;
        }
        if ((time() - $start) >= $timeout) {
            proc_terminate($proc, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);

            return ['ok' => false, 'exit_code' => 124, 'stderr_tail' => 'timeout'];
        }
        usleep(50_000);
    }
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $tail = substr(trim($stderr !== '' ? $stderr : $stdout), -400);

    return ['ok' => $exit === 0, 'exit_code' => (int) $exit, 'stderr_tail' => $tail];
}

/**
 * @param  array<string,mixed>  $state
 * @return array{new_blocks:int,total_blocks:int,offset:int}
 */
function countNewFatalBlocks(string $path, array $state): array
{
    if (! is_file($path)) {
        return ['new_blocks' => 0, 'total_blocks' => 0, 'offset' => 0];
    }
    $offset = (int) ($state['err_log_offset'] ?? 0);
    $prevBlocks = (int) ($state['err_log_fatal_blocks'] ?? 0);
    $size = (int) filesize($path);
    if ($offset > $size) {
        $offset = 0;
        $prevBlocks = 0;
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return ['new_blocks' => 0, 'total_blocks' => $prevBlocks, 'offset' => $offset];
    }
    fseek($fh, $offset);
    $chunk = stream_get_contents($fh) ?: '';
    fclose($fh);
    $newInChunk = substr_count($chunk, 'PHP Fatal error');
    $total = $prevBlocks + $newInChunk;

    return ['new_blocks' => $newInChunk, 'total_blocks' => $total, 'offset' => $size];
}

/** @return array<string,mixed> */
function readState(string $path): array
{
    if (! is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($decoded) ? $decoded : [];
}

/** @param array<string,mixed> $state */
function writeState(string $path, array $state): void
{
    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX);
}

function inCooldown(string $alarmPath, int $now, int $cooldown): bool
{
    if ($cooldown <= 0 || ! is_file($alarmPath)) {
        return false;
    }
    $fh = fopen($alarmPath, 'rb');
    if ($fh === false) {
        return false;
    }
    $size = filesize($alarmPath);
    $read = min(16384, $size);
    fseek($fh, -$read, SEEK_END);
    $chunk = stream_get_contents($fh) ?: '';
    fclose($fh);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $chunk)), static fn ($l) => $l !== ''));
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $row = json_decode($lines[$i], true);
        if (! is_array($row)) {
            continue;
        }
        if (($row['action'] ?? null) === 'alarm' || ($row['status'] ?? null) === 'unhealthy') {
            $at = isset($row['checked_at']) ? strtotime((string) $row['checked_at']) : false;
            if ($at !== false && ($now - $at) < $cooldown) {
                return true;
            }

            return false;
        }
    }

    return false;
}

/** @param array<string,mixed> $row */
function appendJsonl(string $path, array $row): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
}

function kickstartPatient(string $label): bool
{
    $override = getenv('ATLAS_WATCHDOG_KICKSTART_CMD');
    if (is_string($override) && $override !== '') {
        $code = 0;
        system($override, $code);

        return $code === 0;
    }
    if (PHP_OS_FAMILY !== 'Darwin') {
        return false;
    }
    $uid = function_exists('posix_getuid') ? (int) posix_getuid() : 0;
    $cmd = 'launchctl kickstart -k '.escapeshellarg('gui/'.$uid.'/'.$label).' 2>/dev/null';
    $code = 0;
    system($cmd, $code);

    return $code === 0;
}

function notifyLocal(string $message): bool
{
    $override = getenv('ATLAS_WATCHDOG_NOTIFY_CMD');
    if (is_string($override) && $override !== '') {
        $code = 0;
        // message available via ATLAS_WATCHDOG_NOTIFY_MESSAGE for custom cmds
        putenv('ATLAS_WATCHDOG_NOTIFY_MESSAGE='.$message);
        system($override, $code);

        return $code === 0;
    }
    if (PHP_OS_FAMILY !== 'Darwin') {
        return false;
    }
    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $message);
    $cmd = 'osascript -e '.escapeshellarg('display notification "'.$escaped.'" with title "Atlas Scheduler Watchdog"');
    $code = 0;
    @system($cmd.' >/dev/null 2>&1', $code);

    return $code === 0;
}

/** @param list<array<string,mixed>> $warnings */
function warningMessage(array $warnings): string
{
    $checks = array_values(array_filter(
        array_map(static fn (array $warning): string => (string) ($warning['check'] ?? ''), $warnings),
        static fn (string $check): bool => $check !== '',
    ));

    return 'Atlas scheduler watchdog warning: '.($checks === [] ? 'warning' : implode(',', $checks));
}

/** @return array<string,mixed> */
function acosLongHorizonGateWarning(string $root): array
{
    $path = getenv('ATLAS_WATCHDOG_ACOS_LONG_HORIZON_RECEIPT');
    if (! is_string($path) || trim($path) === '') {
        $path = $root.'/storage/app/atlas/evidence/acos-long-horizon-gate.json';
    }

    if (! is_file($path)) {
        return ['alert' => false, 'skipped' => true, 'reason' => 'receipt_missing', 'receipt_path' => $path];
    }

    $raw = file_get_contents($path);
    $receipt = is_string($raw) ? json_decode($raw, true) : null;
    if (! is_array($receipt)) {
        return ['alert' => false, 'skipped' => true, 'reason' => 'receipt_invalid_json', 'receipt_path' => $path];
    }

    $warnings = array_values(array_filter(
        (array) ($receipt['warnings'] ?? []),
        static fn (mixed $warning): bool => is_scalar($warning) && trim((string) $warning) !== '',
    ));
    if ($warnings === []) {
        return ['alert' => false, 'receipt_path' => $path];
    }

    return [
        'alert' => true,
        'receipt_path' => $path,
        'status' => $receipt['status'] ?? null,
        'certified' => $receipt['certified'] ?? null,
        'warnings' => array_map(static fn (mixed $warning): string => (string) $warning, $warnings),
    ];
}

/**
 * WDG-01 — unified Laravel watchdog plugin runner via artisan subprocess.
 *
 * @return array<string,mixed>
 */
function unifiedWatchdogCheck(string $php, string $artisan, string $cwd, int $timeout): array
{
    $enabled = getenv('ATLAS_WATCHDOG_UNIFIED_CHECK');
    if ($enabled === '0' || $enabled === 'false') {
        return ['ok' => true, 'skipped' => true, 'alert' => false];
    }

    $override = getenv('ATLAS_WATCHDOG_UNIFIED_CMD');
    if (is_string($override) && $override !== '') {
        $cmd = $override;
    } else {
        if (! is_file($artisan)) {
            return ['ok' => false, 'skipped' => true, 'alert' => false, 'reason' => 'artisan_missing'];
        }
        $cmd = escapeshellarg($php).' '.escapeshellarg($artisan).' atlas:watchdog:run --json';
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, $cwd, null);
    if (! is_resource($proc)) {
        return ['ok' => false, 'skipped' => true, 'alert' => false, 'reason' => 'proc_open_failed'];
    }
    fclose($pipes[0]);

    $start = time();
    $stdout = '';
    $stderr = '';
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $exit = null;
    while (true) {
        $status = proc_get_status($proc);
        if (! $status['running'] && $exit === null) {
            $exit = (int) $status['exitcode'];
        }
        $stdout .= (string) fread($pipes[1], 8192);
        $stderr .= (string) fread($pipes[2], 8192);
        if ($exit !== null) {
            break;
        }
        if ((time() - $start) >= $timeout) {
            proc_terminate($proc, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);

            return ['ok' => false, 'skipped' => true, 'alert' => false, 'reason' => 'timeout'];
        }
        usleep(50_000);
    }
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $stdout = trim($stdout);
    if ($stdout === '') {
        return ['ok' => $exit === 0, 'skipped' => true, 'alert' => false, 'reason' => 'empty_stdout', 'exit_code' => $exit];
    }

    $decoded = json_decode($stdout, true);
    if (! is_array($decoded)) {
        return [
            'ok' => false,
            'skipped' => true,
            'alert' => true,
            'alert_code' => 'acos_unified_watchdog_unavailable',
            'reason' => 'invalid_json',
            'stderr_tail' => substr(trim($stderr !== '' ? $stderr : $stdout), -400),
            'exit_code' => $exit,
        ];
    }

    return $decoded + ['ok' => $exit === 0, 'exit_code' => $exit];
}

/**
 * VOL-01 — read-only operational volume via artisan subprocess (fail-open).
 *
 * Prerequisite (named, not fixed): GAP-HERMES-01 — Hermes transport may drop
 * final stdout chunks; Autônomos/brain-writer volume can read falsely low.
 *
 * @return array<string,mixed>
 */
function operationalVolumeCheck(string $php, string $artisan, string $cwd, int $timeout): array
{
    $enabled = getenv('ATLAS_WATCHDOG_VOLUME_CHECK');
    if ($enabled === '0' || $enabled === 'false') {
        return ['ok' => true, 'skipped' => true, 'alert' => false];
    }

    $override = getenv('ATLAS_WATCHDOG_VOLUME_CMD');
    if (is_string($override) && $override !== '') {
        $cmd = $override;
    } else {
        if (! is_file($artisan)) {
            return ['ok' => false, 'skipped' => true, 'alert' => false, 'reason' => 'artisan_missing'];
        }
        $cmd = escapeshellarg($php).' '.escapeshellarg($artisan).' atlas:acos:operational-volume --json';
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, $cwd, null);
    if (! is_resource($proc)) {
        return ['ok' => false, 'skipped' => true, 'alert' => false, 'reason' => 'proc_open_failed'];
    }
    fclose($pipes[0]);

    $start = time();
    $stdout = '';
    $stderr = '';
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $exit = null;
    while (true) {
        $status = proc_get_status($proc);
        if (! $status['running'] && $exit === null) {
            $exit = (int) $status['exitcode'];
        }
        $stdout .= (string) fread($pipes[1], 8192);
        $stderr .= (string) fread($pipes[2], 8192);
        if ($exit !== null) {
            break;
        }
        if ((time() - $start) >= $timeout) {
            proc_terminate($proc, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);

            return ['ok' => false, 'skipped' => true, 'alert' => false, 'reason' => 'timeout'];
        }
        usleep(50_000);
    }
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $decoded = json_decode(trim($stdout), true);
    if (! is_array($decoded)) {
        return [
            'ok' => false,
            'skipped' => true,
            'alert' => false,
            'reason' => 'invalid_json',
            'stderr_tail' => substr(trim($stderr !== '' ? $stderr : $stdout), -400),
            'exit_code' => $exit,
        ];
    }

    return $decoded + ['ok' => true, 'exit_code' => $exit];
}

/**
 * ROL-01 rollback trigger check — read-only artisan subprocess.
 *
 * @return array<string,mixed>
 */
function rollbackTriggerCheck(string $php, string $artisan, string $cwd, int $timeout): array
{
    $enabled = getenv('ATLAS_WATCHDOG_ROLLBACK_CHECK');
    if ($enabled === '0' || $enabled === 'false') {
        return ['ok' => true, 'skipped' => true, 'alert' => false];
    }

    $override = getenv('ATLAS_WATCHDOG_ROLLBACK_CMD');
    if (is_string($override) && $override !== '') {
        $cmd = $override;
    } else {
        if (! is_file($artisan)) {
            return ['ok' => false, 'skipped' => true, 'alert' => false, 'reason' => 'artisan_missing'];
        }
        $cmd = escapeshellarg($php).' '.escapeshellarg($artisan).' atlas:acos:rollback-triggers --json';
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, $cwd, null);
    if (! is_resource($proc)) {
        return ['ok' => false, 'skipped' => true, 'alert' => false, 'reason' => 'proc_open_failed'];
    }
    fclose($pipes[0]);

    $start = time();
    $stdout = '';
    $stderr = '';
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $exit = null;
    while (true) {
        $status = proc_get_status($proc);
        if (! $status['running'] && $exit === null) {
            $exit = (int) $status['exitcode'];
        }
        $stdout .= (string) fread($pipes[1], 8192);
        $stderr .= (string) fread($pipes[2], 8192);
        if ($exit !== null) {
            break;
        }
        if ((time() - $start) >= $timeout) {
            proc_terminate($proc, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);

            return ['ok' => false, 'skipped' => true, 'alert' => false, 'reason' => 'timeout'];
        }
        usleep(50_000);
    }
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $decoded = json_decode(trim($stdout), true);
    if (! is_array($decoded)) {
        return [
            'ok' => false,
            'skipped' => true,
            'alert' => false,
            'reason' => 'invalid_json',
            'stderr_tail' => substr(trim($stderr !== '' ? $stderr : $stdout), -400),
            'exit_code' => $exit,
        ];
    }

    return $decoded + ['ok' => true, 'exit_code' => $exit];
}

/**
 * @param  array<string,mixed>  $array
 */
function arrayPath(array $array, string $path): mixed
{
    $cursor = $array;
    foreach (explode('.', $path) as $segment) {
        if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
            return null;
        }
        $cursor = $cursor[$segment];
    }

    return $cursor;
}
