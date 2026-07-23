<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlow;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;

/**
 * AP-786 owner-flow PREFLIGHT-SIGNAL section: test-authoring + runtime-mutation
 * deliverability signals consumed by the zero-provider pre-flight gate. Bodies moved
 * verbatim from Ap786OwnerFlowExecutor (GOD-DEBULK surgical split).
 */
final class Ap786OwnerFlowPreflightSignalsSection
{
    /**
     * Build a minimax-worker command for atlas_dev when provider=minimax_m27_cli.
     * Uses 'atlas:dev:minimax-worker:run' (allowlisted in AP-759) and passes the
     * same finding/allowed-files/validation-commands/worktree args that the
     * senior-loop receives, without the Cursor-specific --workspace= path shape.
     *
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     * @return list<string>
     */
    /**
     * Test-authoring deliverability signal for the pre-flight gate. A finding is test-authoring
     * only when the finding itself is pure coverage/test work AND its allowed files pair a
     * *Test.php with exactly one non-test PHP subject. Runtime bugfix slices also commonly pair
     * a service with its focused test; treating those as pure test authoring starves factory_max
     * of useful runtime work. When the subject already EXISTS in the worktree, read it to count
     * constructor dependencies and LOC so the gate can refuse to spend a provider call on a
     * subject neither provider can test in one shot. A subject that does NOT exist yet (brand-new
     * class created with its test) is the proven-deliverable path: is_test_authoring=true,
     * subject_exists=false → never blocked.
     *
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return array{is_test_authoring:bool,subject_exists:bool,subject_constructor_deps:int,subject_loc:int,subject_path:string}
     */
    public function testAuthoringSubjectSignal(array $finding, array $allowedFiles, string $worktree): array
    {
        $none = ['is_test_authoring' => false, 'subject_exists' => false, 'subject_constructor_deps' => 0, 'subject_loc' => 0, 'subject_path' => ''];
        if (! $this->isPureTestAuthoringFinding($finding)) {
            return $none;
        }

        $files = AreaFocusStringListNormalizer::trimmedStrings($allowedFiles);
        $tests = array_values(array_filter($files, static fn (string $f): bool => str_ends_with($f, 'Test.php')));
        $subjects = array_values(array_filter($files, static fn (string $f): bool => str_ends_with($f, '.php') && ! str_ends_with($f, 'Test.php')));
        if ($tests === [] || count($subjects) !== 1) {
            return $none; // not a clean single-subject test-authoring shape
        }
        $subjectRel = $subjects[0];
        $worktree = rtrim($worktree, '/');
        $abs = $worktree !== '' ? $worktree.'/'.ltrim($subjectRel, '/') : '';
        if ($abs === '' || ! is_file($abs)) {
            // Subject does not exist yet → brand-new class created together with its test.
            return ['is_test_authoring' => true, 'subject_exists' => false, 'subject_constructor_deps' => 0, 'subject_loc' => 0, 'subject_path' => $subjectRel];
        }
        $code = (string) @file_get_contents($abs);

        return [
            'is_test_authoring' => true,
            'subject_exists' => true,
            'subject_constructor_deps' => $this->constructorParamCount($code),
            'subject_loc' => substr_count($code, "\n") + 1,
            'subject_path' => $subjectRel,
        ];
    }

    /** @param array<string,mixed> $finding */
    public function isPureTestAuthoringFinding(array $finding): bool
    {
        $kind = strtolower(trim((string) ($finding['kind'] ?? '')));
        if (in_array($kind, ['test', 'tests', 'coverage', 'missing_test'], true)) {
            return true;
        }

        $originType = strtolower(trim((string) ($finding['origin_type'] ?? '')));
        if ($originType === 'missing_test' || str_ends_with($originType, '_test')) {
            return true;
        }

        $reason = strtolower(trim((string) ($finding['autonomous_execution_reason'] ?? '')));
        $title = strtolower(trim((string) ($finding['title'] ?? '')));

        return str_contains($reason, 'missing_test')
            || str_contains($title, 'missing test')
            || str_contains($title, 'focused unit coverage')
            || str_contains($title, 'regression coverage');
    }

    /**
     * Runtime-mutation deliverability signal for the zero-provider gate. It is
     * intentionally conservative: a huge existing service is not a safe autonomous
     * provider target unless a structured anchor narrows the edit to a method/symbol.
     *
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return array{is_runtime_mutation:bool,existing_product_files:list<array{file:string,loc:int}>,max_existing_product_loc:int,explicit_narrow_anchor:bool}
     */
    public function runtimeMutationSurfaceSignal(array $finding, array $allowedFiles, string $worktree): array
    {
        $none = [
            'is_runtime_mutation' => false,
            'existing_product_files' => [],
            'max_existing_product_loc' => 0,
            'explicit_narrow_anchor' => false,
            'requires_structured_anchor' => false,
            'structured_anchor_reason' => '',
        ];
        if ($this->isPureTestAuthoringFinding($finding)) {
            return $none;
        }

        $worktree = rtrim($worktree, '/');
        $existing = [];
        $maxLoc = 0;
        foreach (AreaFocusStringListNormalizer::trimmedStrings($allowedFiles) as $file) {
            if (! str_ends_with($file, '.php') || str_ends_with($file, 'Test.php')) {
                continue;
            }
            $abs = $worktree !== '' ? $worktree.'/'.ltrim($file, '/') : '';
            if ($abs === '' || ! is_file($abs)) {
                continue;
            }

            $code = (string) @file_get_contents($abs);
            $loc = substr_count($code, "\n") + 1;
            $existing[] = ['file' => $file, 'loc' => $loc];
            $maxLoc = max($maxLoc, $loc);
        }

        if ($existing === []) {
            return $none;
        }

        return [
            'is_runtime_mutation' => true,
            'existing_product_files' => $existing,
            'max_existing_product_loc' => $maxLoc,
            'explicit_narrow_anchor' => $this->hasStructuredNarrowAnchor($finding),
            'requires_structured_anchor' => $this->existingRuntimeMutationRequiresStructuredAnchor($finding),
            'structured_anchor_reason' => $this->existingRuntimeMutationStructuredAnchorReason($finding),
        ];
    }

    /** @param array<string,mixed> $finding */
    public function hasStructuredNarrowAnchor(array $finding): bool
    {
        $paths = [
            'target_method',
            'target_symbol',
            'method_anchor',
            'symbol_anchor',
            'line_anchor',
            'surgical_anchor',
            'mutation_anchor',
            'self_construction_packet.target_method',
            'self_construction_packet.target_symbol',
            'self_construction_packet.method_anchor',
            'self_construction_packet.surgical_anchor',
            'self_construction_packet.task_packet.target_method',
            'self_construction_packet.task_packet.target_symbol',
            'self_construction_packet.task_packet.surgical_anchor',
            'self_construction_packet.task_packet.continuation_context.target_method',
            'self_construction_packet.task_packet.continuation_context.target_symbol',
        ];

        foreach ($paths as $path) {
            $value = data_get($finding, $path);
            if (is_string($value) && $this->isConcreteNarrowAnchor($path, $value)) {
                return true;
            }
        }

        return false;
    }

    public function isConcreteNarrowAnchor(string $path, string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (in_array($path, [
            'target_method',
            'method_anchor',
            'line_anchor',
            'self_construction_packet.target_method',
            'self_construction_packet.method_anchor',
            'self_construction_packet.task_packet.target_method',
            'self_construction_packet.task_packet.continuation_context.target_method',
        ], true)) {
            return true;
        }

        if (str_contains($path, 'target_symbol') || str_contains($path, 'symbol_anchor')) {
            return $this->looksLikeConcreteSymbol($value);
        }

        if (str_contains($path, 'surgical_anchor') || str_contains($path, 'mutation_anchor')) {
            if (preg_match('/(?:^|[;\s])(?:target_)?method\s*:\s*[^;\s]+/i', $value) === 1
                || preg_match('/(?:^|[;\s])line(?:_anchor)?\s*:\s*\d+/i', $value) === 1) {
                return true;
            }
            if (preg_match('/(?:^|[;\s])(?:target_)?symbol\s*:\s*([^;]+)/i', $value, $match) === 1) {
                return $this->looksLikeConcreteSymbol(trim((string) $match[1]));
            }
        }

        return false;
    }

    public function looksLikeConcreteSymbol(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, 'runtime_signal:')) {
            return false;
        }

        return str_contains($value, '::')
            || str_contains($value, '->')
            || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\([^)]*\)\z/', $value) === 1
            || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $value) === 1;
    }

    /** @param array<string,mixed> $finding */
    public function existingRuntimeMutationRequiresStructuredAnchor(array $finding): bool
    {
        return $this->existingRuntimeMutationStructuredAnchorReason($finding) !== '';
    }

    /** @param array<string,mixed> $finding */
    public function existingRuntimeMutationStructuredAnchorReason(array $finding): string
    {
        if ((string) ($finding['origin_type'] ?? '') === 'self_construction_admission_packet') {
            return 'self_construction_admission_packet';
        }
        if ((string) ($finding['active_slice_kind'] ?? '') === 'self_construction_packet') {
            return 'self_construction_packet';
        }
        if (is_array($finding['self_construction_packet'] ?? null)) {
            return 'self_construction_packet';
        }

        return '';
    }

    /** Count the parameters of the class __construct signature (0 when none/absent). */
    public function constructorParamCount(string $code): int
    {
        if (preg_match('/function\s+__construct\s*\(/i', $code, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return 0;
        }
        $start = (int) $m[0][1] + strlen($m[0][0]);
        $len = strlen($code);
        $depth = 1;
        $params = '';
        for ($i = $start; $i < $len && $depth > 0; $i++) {
            $ch = $code[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $params .= $ch;
        }
        $params = trim($params);
        if ($params === '') {
            return 0;
        }
        $count = 1;
        $d = 0;
        $plen = strlen($params);
        for ($i = 0; $i < $plen; $i++) {
            $c = $params[$i];
            if ($c === '(' || $c === '[' || $c === '<') {
                $d++;
            } elseif ($c === ')' || $c === ']' || $c === '>') {
                $d--;
            } elseif ($c === ',' && $d === 0) {
                $count++;
            }
        }

        return $count;
    }
}
