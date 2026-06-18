<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Differential\Shadow;

/**
 * E4 -- Conservative pure-function detector.
 *
 * Determines whether a PHP function body is a candidate for shadow-diffing
 * (old vs new execution on the same inputs). The detection is intentionally
 * CONSERVATIVE: it only classifies a function as pure when it finds NO
 * observable side-effect indicators. Any indicator => impure => NOT eligible.
 *
 * False positives (flagging a pure function as impure) are acceptable: the
 * shadow-diff simply skips that function. False negatives (flagging an
 * impure function as pure) are NOT acceptable: an impure function must NEVER
 * be shadow-diffed because its outputs may depend on external state, making
 * a divergence meaningless or a coincidence misleading (VAL-E4-007).
 *
 * The detector scans the function body (NOT the signature) for impure
 * indicators across the following categories:
 *
 *   - I/O:           echo, print, printf, fprintf, fwrite, fread, fopen,
 *                     file_*, readfile, unlink, rename, mkdir, rmdir, copy.
 *   - DB:             DB::, ->insert/update/delete/save/truncate, Schema::,
 *                     ->persist, ->beginTransaction.
 *   - Network:        curl_*, Http::, Guzzle, fetch(, stream_context.
 *   - Randomness:     rand, mt_rand, random_*, shuffle, array_rand.
 *   - Time:           date, time, microtime, DateTime, Carbon::now/today,
 *                     now(, Date::, strtotime.
 *   - Global state:   $GLOBALS, $_POST/GET/SESSION/SERVER/COOKIE/FILES/ENV,
 *                     static $, global $.
 *   - Subprocess:     exec, shell_exec, system, passthru, proc_, popen,
 *                     backticks.
 *   - Object state:   $this-> (methods that mutate their receiver are impure;
 *                     a shadow-diff of a method dependent on $this would
 *                     compare apples to oranges), self::, static:: (may
 *                     touch static mutable state).
 *   - Other:          header(, http_response_code, ob_start, set_,
 *                     throw new (a throwing function is not pure for
 *                     shadow-diff purposes because the exception is a side
 *                     channel the harness would need to model).
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M5 / E4,
 * e4-shadow-diff-pure-functions feature).
 */
final class PureFunctionDetector
{
    /**
     * The impure-indicator patterns. A function body matching ANY of these
     * is classified impure (conservative). Patterns are case-insensitive
     * word-boundary regexes tuned for PHP syntax.
     *
     * Each entry is [pattern, label]. The label is carried in skip reasons
     * for auditability (which indicator tripped).
     *
     * @var list<array{pattern: non-empty-string, label: non-empty-string}>
     */
    public const IMPURE_INDICATORS = [
        // -- I/O -----------------------------------------------------------
        ['pattern' => '/\becho\b|\bprint\b\s*\(|\bprintf\b\s*\(/i', 'label' => 'output: echo/print/printf'],
        ['pattern' => '/\bf(write|read|open|close|puts|gets|flush|eof)\b\s*\(/i', 'label' => 'file-handle I/O'],
        ['pattern' => '/\bfile_(get_contents|put_contents|exists|read|write|size|mtime|atime)\b\s*\(/i', 'label' => 'filesystem'],
        ['pattern' => '/\b(readfile|unlink|rename|mkdir|rmdir|copy|tmpfile|tempnam|move_uploaded_file)\b\s*\(/i', 'label' => 'filesystem-mutation'],
        ['pattern' => '/\b(realpath|is_dir|is_file|is_writable|is_readable|glob|scandir|opendir|readdir)\b\s*\(/i', 'label' => 'filesystem-read'],

        // -- DB ------------------------------------------------------------
        ['pattern' => '/\bDB\s*::|\bSchema\s*::/', 'label' => 'database facade'],
        ['pattern' => '/->(insert|update|delete|save|truncate|persist|beginTransaction|commit|rollBack)\s*\(/i', 'label' => 'database write/mutation'],
        ['pattern' => '/\b(artisan|migration|migrate)\b/i', 'label' => 'migration/artisan'],

        // -- Network ------------------------------------------------------
        ['pattern' => '/\bcurl_[a-z]/i', 'label' => 'curl'],
        ['pattern' => '/\bHttp\s*::|\bGuzzle/i', 'label' => 'http client'],
        ['pattern' => '/\bstream_context_(create|set_option)\s*\(/i', 'label' => 'stream context'],
        ['pattern' => '/\b(socket_|fsockopen|pfsockopen|stream_socket)\b/i', 'label' => 'socket'],

        // -- Randomness ---------------------------------------------------
        ['pattern' => '/\b(rand|mt_rand|mt_srand|srand|random_int|random_bytes|random_float)\s*\(/i', 'label' => 'randomness'],
        ['pattern' => '/\b(shuffle|array_rand|str_shuffle)\s*\(/i', 'label' => 'randomness-shuffle'],
        ['pattern' => '/\buniqid\s*\(/i', 'label' => 'uniqid'],

        // -- Time ---------------------------------------------------------
        ['pattern' => '/\b(date|time|microtime|strtotime|getdate|gettimeofday)\s*\(/i', 'label' => 'time'],
        ['pattern' => '/\b(DateTime|DateTimeImmutable|DateTimeInterface)\b/i', 'label' => 'DateTime'],
        ['pattern' => '/\bCarbon\s*::|\bCarbon::(now|today|tomorrow|yesterday)\b/i', 'label' => 'Carbon time'],
        ['pattern' => '/\bnow\s*\(\s*\)/i', 'label' => 'now()'],

        // -- Global / external state --------------------------------------
        ['pattern' => '/\$GLOBALS\b|\$_(POST|GET|SESSION|SERVER|COOKIE|FILES|ENV|REQUEST)\b/', 'label' => 'superglobal'],
        ['pattern' => '/\bglobal\s+\$/i', 'label' => 'global keyword'],
        ['pattern' => '/\bstatic\s+\$/i', 'label' => 'static local state'],

        // -- Subprocess ---------------------------------------------------
        ['pattern' => '/\b(exec|shell_exec|system|passthru|proc_open|proc_close|popen|pcntl_exec)\s*\(/i', 'label' => 'subprocess'],
        ['pattern' => '/`[^`]+`/', 'label' => 'backtick subprocess'],

        // -- Object / static state (conservative) -------------------------
        ['pattern' => '/\$this->/', 'label' => 'this-receiver (object state)'],
        ['pattern' => '/\b(self|static|parent)\s*::/', 'label' => 'static-class reference'],
        ['pattern' => '/\bnew\s+\\\\?[A-Za-z_]/', 'label' => 'object construction (conservative)'],

        // -- Error/output control -----------------------------------------
        ['pattern' => '/\b(header|http_response_code|set_error_handler|set_exception_handler|register_shutdown_function)\s*\(/i', 'label' => 'http/error control'],
        ['pattern' => '/\bob_(start|end_clean|end_flush|clean|flush|get_contents|get_level)\s*\(/i', 'label' => 'output buffering'],

        // -- Exception as side-channel ------------------------------------
        ['pattern' => '/\bthrow\b/i', 'label' => 'throw (side-channel)'],
        // Yield / generators: a generator's output is lazy and depends on
        // consumer state; treat as impure for shadow-diff safety.
        ['pattern' => '/\byield\b/i', 'label' => 'generator yield'],
    ];

    /**
     * Returns true when the function body has NO impure indicator
     * (conservative: candidate for shadow-diff). Returns false when ANY
     * indicator is present (definitely impure or conservatively impure).
     *
     * The body should be the function's interior source (without the
     * signature line). The detector scans the body text for indicator
     * patterns. A function with an empty body is conservatively treated as
     * pure (it produces no output and has no side effects).
     */
    public function isLikelyPure(string $body): bool
    {
        return $this->impureIndicator($body) === null;
    }

    /**
     * Returns the first impure indicator label found in the body, or null
     * when the body appears pure. Exposed so skip reasons carry WHICH
     * indicator tripped (auditability, VAL-CROSS-016 evidence surface).
     */
    public function impureIndicator(string $body): ?string
    {
        foreach (self::IMPURE_INDICATORS as $indicator) {
            if (preg_match($indicator['pattern'], $body) === 1) {
                return $indicator['label'];
            }
        }

        return null;
    }
}
