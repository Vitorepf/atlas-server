<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Differential\Shadow;

use Symfony\Component\Process\Process;

/**
 * E4 -- Default {@see ShadowDiffHarness} that executes old vs new function
 * bodies in a sandboxed PHP subprocess.
 *
 * The harness writes a temporary PHP probe script that:
 *   1. Defines the old function under the name `shadowdiff_old` and the new
 *      under `shadowdiff_new` (the {@see ShadowDiffService::wrapAsCallable}
 *      already names them).
 *   2. Iterates over the probe inputs and invokes both functions with each
 *      tuple, catching any Throwable.
 *   3. Serializes each return value via {@see self::serializeReturn()} and
 *      emits a single JSON line on stdout.
 *
 * The probe runs with a bounded timeout ({@see self::TIMEOUT_SECONDS}) and
 * with the pcov extension disabled (`-d pcov.enable=0`) so coverage overhead
 * does not slow it or skew outputs. Functions that hit a fatal/trap are
 * caught by the probe's try/catch and reported as a per-input error.
 *
 * On ANY top-level failure (script write failure, subprocess crash, JSON
 * parse failure, timeout) the harness returns a FAILED result so the
 * service skips the symbol with a reason — never fabricates a divergence
 * and never crashes the pipeline (honest ceiling, VAL-E4-011).
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M5 / E4,
 * e4-shadow-diff-pure-functions feature).
 */
final class PhpSubprocessShadowDiffHarness implements ShadowDiffHarness
{
    /**
     * Per-probe timeout. Pure functions execute in microseconds; 10 seconds
     * is a generous ceiling that catches infinite loops without flaking on
     * slow CI.
     */
    public const TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private readonly string $phpBinary = '/opt/homebrew/bin/php',
    ) {}

    public function shadowDiff(string $oldBodySource, string $newBodySource, array $probeInputs): ShadowDiffHarnessResult
    {
        $probeScript = $this->buildProbeScript($oldBodySource, $newBodySource, $probeInputs);

        $tmp = @tempnam(sys_get_temp_dir(), 'atlas_shadowdiff_');
        if ($tmp === false) {
            return ShadowDiffHarnessResult::failed('could not create temp probe script');
        }

        try {
            if (@file_put_contents($tmp, $probeScript) === false) {
                return ShadowDiffHarnessResult::failed('could not write probe script');
            }

            $process = new Process(
                [$this->phpBinary, '-d', 'pcov.enable=0', $tmp],
                null,
                null,
                null,
                self::TIMEOUT_SECONDS,
            );

            try {
                $process->run();
            } catch (\Throwable $e) {
                return ShadowDiffHarnessResult::failed('subprocess threw: '.$e->getMessage());
            }

            if (! $process->isSuccessful()) {
                $err = trim($process->getErrorOutput());

                return ShadowDiffHarnessResult::failed(
                    $err !== '' ? $err : 'subprocess exit code '.$process->getExitCode(),
                );
            }

            $stdout = trim($process->getOutput());
            if ($stdout === '') {
                return ShadowDiffHarnessResult::failed('empty probe output');
            }

            $payload = json_decode($stdout, true);
            if (! is_array($payload) || ($payload['ok'] ?? null) !== true) {
                return ShadowDiffHarnessResult::failed(
                    is_array($payload) && isset($payload['error'])
                        ? 'probe: '.(string) $payload['error']
                        : 'malformed probe output',
                );
            }

            $oldOutputs = is_array($payload['old'] ?? null) ? array_map('strval', $payload['old']) : [];
            $newOutputs = is_array($payload['new'] ?? null) ? array_map('strval', $payload['new']) : [];

            return ShadowDiffHarnessResult::executed($oldOutputs, $newOutputs);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Build the PHP probe script that defines both function versions and
     * emits old/new serialized outputs as a single JSON line.
     *
     * @param  list<list<mixed>>  $probeInputs
     */
    private function buildProbeScript(string $oldBodySource, string $newBodySource, array $probeInputs): string
    {
        // The probe inputs are embedded as a JSON literal; each entry is a
        // list of positional arguments. The probe calls both functions with
        // each tuple inside try/catch and records the serialized return or
        // an error sentinel.
        $inputsJson = json_encode(array_map(
            static function (array $tuple): array {
                // JSON round-trip coerces PHP scalars to JSON natives; the
                // probe side decodes them back to PHP scalars. Arrays stay
                // arrays.
                return $tuple;
            },
            $probeInputs,
        ));

        // Embed the function bodies verbatim. The bodies come from
        // ShadowDiffService::wrapAsCallable() and already carry the
        // `function shadowdiff_old(...) { ... }` header.
        //
        // IMPORTANT: the probe script is self-contained and does NOT load the
        // Composer autoloader (the subprocess only defines the two functions
        // and calls them). The serializer is therefore inlined into the
        // script (mirrors self::serializeReturn() / serializeArray()) so the
        // probe does not depend on the harness class being autoloadable.
        $serializer = <<<'PHP'
$serializer = static function ($value): string {
    if ($value === null) {
        return '(null)';
    }
    if (is_scalar($value)) {
        return var_export($value, true);
    }
    if (is_array($value)) {
        $parts = [];
        foreach ($value as $k => $v) {
            $parts[] = var_export($k, true).'=>'.$serializer($v);
        }

        return 'array['.implode(',', $parts).']';
    }
    if (is_object($value)) {
        return 'object:'.get_class($value);
    }
    if (is_resource($value)) {
        return 'resource:'.get_resource_type($value);
    }

    return '('.gettype($value).')';
};
PHP;

        return "<?php\n"
            ."declare(strict_types=1);\n"
            .$oldBodySource."\n"
            .$newBodySource."\n"
            .$serializer."\n"
            .'$inputs = json_decode('.var_export((string) $inputsJson, true).', true);'."\n"
            .'if (! is_array($inputs)) { echo json_encode(["ok" => false, "error" => "inputs decode failed"]); exit(0); }'."\n"
            .'$oldOutputs = [];'."\n"
            .'$newOutputs = [];'."\n"
            .'foreach ($inputs as $tuple) {'."\n"
            .'    $args = is_array($tuple) ? array_values($tuple) : [];'."\n"
            .'    try {'."\n"
            .'        $oldOutputs[] = $serializer(call_user_func_array("shadowdiff_old", $args));'."\n"
            .'    } catch (\\Throwable $e) {'."\n"
            .'        $oldOutputs[] = "(threw: ".get_class($e).")";'."\n"
            .'    }'."\n"
            .'    try {'."\n"
            .'        $newOutputs[] = $serializer(call_user_func_array("shadowdiff_new", $args));'."\n"
            .'    } catch (\\Throwable $e) {'."\n"
            .'        $newOutputs[] = "(threw: ".get_class($e).")";'."\n"
            .'    }'."\n"
            .'}'."\n"
            .'echo json_encode(["ok" => true, "old" => $oldOutputs, "new" => $newOutputs]);'."\n";
    }

    /**
     * Serialize a return value to a stable string for comparison. Public so
     * the probe script can reference it via the fully-qualified name.
     */
    public static function serializeReturn(mixed $value): string
    {
        if ($value === null) {
            return '(null)';
        }
        if (is_scalar($value)) {
            return var_export($value, true);
        }
        if (is_array($value)) {
            return self::serializeArray($value);
        }
        if (is_object($value)) {
            return 'object:'.get_class($value);
        }
        if (is_resource($value)) {
            return 'resource:'.get_resource_type($value);
        }

        return '('.gettype($value).')';
    }

    /**
     * Serialize an array deterministically (sorts associative keys so two
     * arrays with the same content but different key order compare equal
     * only when the order matches — but for pure-function outputs the order
     * is usually significant, so we preserve it).
     */
    private static function serializeArray(array $value): string
    {
        $parts = [];
        foreach ($value as $k => $v) {
            $parts[] = var_export($k, true).'=>'.self::serializeReturn($v);
        }

        return 'array['.implode(',', $parts).']';
    }
}
