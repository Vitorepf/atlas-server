<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\SelfExtension;

/**
 * Structured result of a contract verification. {@see AtlasLoopAutopoieticContractVerifier} returns this VO.
 *
 * @phpstan-type Violation array{code:string, detail:string}
 * @phpstan-type TaskFabricReadiness array{readiness_to_enqueue:bool, blockers:list<string>, required_evidence:list<string>, allowed_file_roots:list<string>, safe_next_action:string}
 */
final class AutopoieticVerifierReport
{
    /**
     * @param  list<array{code:string, detail:string}>  $violations
     * @param  array{readiness_to_enqueue:bool, blockers:list<string>, required_evidence:list<string>, allowed_file_roots:list<string>, safe_next_action:string}  $taskFabricReadiness
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $violations,
        public readonly array $taskFabricReadiness = [],
    ) {}

    /**
     * @return array{ok:bool, violations:list<array{code:string, detail:string}>, task_fabric_readiness:array}
     */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'violations' => $this->violations, 'task_fabric_readiness' => $this->taskFabricReadiness];
    }
}

/**
 * AUTOPOIESIS · SELF-EXTENSION — the STATIC contract verifier. Given a scope manifest produced by
 * {@see AtlasLoopAutopoieticBootstrapper}, it proves — with NO runtime exec, NO network, NO file writes — that
 * the freshly bootstrapped scope honors the canonical Quaternity contracts:
 *
 *   (a) manifest_sha256 recomputes byte-identical from the on-disk stub files referenced (a single mutated byte
 *       ⇒ MANIFEST_SHA_MISMATCH);
 *   (b) each of the 3 stub files declares its required interface, in the manifest namespace, with the exact
 *       required method signature — verified by the PHP TOKEN parser (token_get_all), never regex on raw bytes:
 *         loop    → nextCandidate(): ?CandidateRef
 *         cortex  → comprehend(string $scopeId): ComprehensionReport
 *         maestro → orchestrate(array $plan): OrchestrationReceipt
 *   (c) scope_id / namespace / roots are internally consistent with the file-system layout.
 *
 * Anti-Goodhart: a passing verdict MEANS the contract is real — a renamed method surfaces as
 * CONTRACT_METHOD_MISSING, a drifted namespace as CONTRACT_NAMESPACE_MISMATCH. Pure function, no side effects.
 */
final class AtlasLoopAutopoieticContractVerifier
{
    /** Per-role expected interface name + method contract. */
    private const CONTRACTS = [
        'loop' => ['interface' => 'LoopSubstrateContract', 'method' => 'nextCandidate', 'params' => [], 'return' => '?CandidateRef'],
        'cortex' => ['interface' => 'CortexComprehensionContract', 'method' => 'comprehend', 'params' => ['string'], 'return' => 'ComprehensionReport'],
        'maestro' => ['interface' => 'MaestroOrchestrationContract', 'method' => 'orchestrate', 'params' => ['array'], 'return' => 'OrchestrationReceipt'],
    ];

    /**
     * @param  array<string,mixed>  $manifest  expects keys: scope_id, namespace, roots, primitives{loop,cortex,maestro=>path}, manifest_sha256
     */
    public function verify(array $manifest): AutopoieticVerifierReport
    {
        $violations = [];

        $namespace = trim((string) ($manifest['namespace'] ?? ''));
        $primitives = is_array($manifest['primitives'] ?? null) ? $manifest['primitives'] : [];

        // Resolve + read the 3 referenced stub files (read-only).
        $sources = [];
        foreach (array_keys(self::CONTRACTS) as $role) {
            $path = trim((string) ($primitives[$role] ?? ''));
            if ($path === '' || ! is_file($path)) {
                $violations[] = ['code' => 'CONTRACT_FILE_MISSING', 'detail' => "role={$role} path=".($path === '' ? '(none)' : $path)];

                continue;
            }
            $sources[$role] = (string) file_get_contents($path);
        }

        // (a) manifest_sha256 must recompute byte-identical from the on-disk files.
        if (count($sources) === count(self::CONTRACTS)) {
            $recomputed = $this->recomputeManifestSha($primitives);
            if (! hash_equals((string) ($manifest['manifest_sha256'] ?? ''), $recomputed)) {
                $violations[] = ['code' => 'MANIFEST_SHA_MISMATCH', 'detail' => 'expected='.$recomputed.' manifest='.(string) ($manifest['manifest_sha256'] ?? '')];
            }
        }

        // (b) each present stub honors its interface name, namespace, and method signature.
        foreach (self::CONTRACTS as $role => $spec) {
            if (! isset($sources[$role])) {
                continue; // already reported as missing file
            }
            $parsed = $this->parseInterface($sources[$role]);
            if ($parsed === null) {
                $violations[] = ['code' => 'CONTRACT_INTERFACE_MISSING', 'detail' => "role={$role} no interface declaration found"];

                continue;
            }
            if ($namespace !== '' && $parsed['namespace'] !== $namespace) {
                $violations[] = ['code' => 'CONTRACT_NAMESPACE_MISMATCH', 'detail' => "role={$role} expected={$namespace} found={$parsed['namespace']}"];
            }
            if ($parsed['name'] !== $spec['interface']) {
                $violations[] = ['code' => 'CONTRACT_INTERFACE_NAME_MISMATCH', 'detail' => "role={$role} expected={$spec['interface']} found={$parsed['name']}"];
            }
            $method = $parsed['methods'][$spec['method']] ?? null;
            if ($method === null
                || $method['params'] !== $spec['params']
                || $method['return'] !== $spec['return']) {
                $violations[] = ['code' => 'CONTRACT_METHOD_MISSING', 'detail' => "role={$role} required={$spec['method']}(".implode(',', $spec['params']).'): '.$spec['return']];
            }
        }

        // (c) layout consistency: each primitive path sits under a declared root + namespace folder.
        $roots = array_values(array_map('strval', (array) ($manifest['roots'] ?? [])));
        foreach ($primitives as $role => $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            if (! $this->pathUnderRootAndNamespace($path, $roots, $namespace)) {
                $violations[] = ['code' => 'SCOPE_LAYOUT_INCONSISTENT', 'detail' => "role={$role} path={$path} not under any root+namespace"];
            }
        }

        $violationCodes = array_flip(array_column($violations, 'code'));
        $taskFabricReadiness = [
            'readiness_to_enqueue' => $violations === [],
            'blockers' => array_values(array_map(
                static fn (array $v): string => $v['code'].': '.$v['detail'],
                $violations,
            )),
            'required_evidence' => [
                'primitive_files_present',
                'manifest_sha_verified',
                'contract_interfaces_validated',
                'scope_layout_consistent',
            ],
            'allowed_file_roots' => array_values(array_map('strval', (array) ($manifest['roots'] ?? []))),
            'safe_next_action' => match (true) {
                $violations === [] => 'enqueue_ready',
                isset($violationCodes['CONTRACT_FILE_MISSING']) => 'fix_missing_primitive_files',
                isset($violationCodes['SCOPE_LAYOUT_INCONSISTENT']) => 'fix_scope_layout',
                default => 'fix_contract_violations',
            },
        ];

        return new AutopoieticVerifierReport($violations === [], array_values($violations), $taskFabricReadiness);
    }

    /**
     * The canonical file-content hash the manifest must carry. Shared by the verifier and the bootstrap fixture
     * so there is ONE source of truth: sha256 over the sorted {role => sha256(file bytes)} map.
     *
     * @param  array<string,mixed>  $primitives
     */
    public function recomputeManifestSha(array $primitives): string
    {
        $map = [];
        foreach (array_keys(self::CONTRACTS) as $role) {
            $path = trim((string) ($primitives[$role] ?? ''));
            $map[$role] = is_file($path) ? hash('sha256', (string) file_get_contents($path)) : '';
        }
        ksort($map);

        return hash('sha256', (string) json_encode($map, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Parse ONE interface declaration from PHP source via the token stream (never regex on raw bytes).
     *
     * @return array{namespace:string, name:string, methods:array<string,array{params:list<string>, return:string}>}|null
     */
    private function parseInterface(string $source): ?array
    {
        /** @var list<array{0:int|null,1:string}> $tokens */
        $tokens = [];
        foreach (token_get_all($source) as $t) {
            $tokens[] = is_array($t) ? [$t[0], $t[1]] : [null, $t];
        }
        $n = count($tokens);

        $namespace = '';
        $interfaceName = null;
        $methods = [];

        for ($i = 0; $i < $n; $i++) {
            [$id, $text] = $tokens[$i];

            if ($id === T_NAMESPACE) {
                $parts = '';
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($tokens[$j][1] === ';' || $tokens[$j][1] === '{') {
                        break;
                    }
                    if ($tokens[$j][0] === T_WHITESPACE) {
                        continue;
                    }
                    $parts .= $tokens[$j][1];
                }
                $namespace = trim($parts);

                continue;
            }

            if ($id === T_INTERFACE) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($tokens[$j][0] === T_STRING) {
                        $interfaceName = $tokens[$j][1];
                        break;
                    }
                }

                continue;
            }

            if ($id === T_FUNCTION) {
                // method name = next T_STRING
                $name = null;
                $k = $i + 1;
                for (; $k < $n; $k++) {
                    if ($tokens[$k][0] === T_STRING) {
                        $name = $tokens[$k][1];
                        break;
                    }
                    if ($tokens[$k][1] === '(') {
                        break;
                    }
                }
                if ($name === null) {
                    continue;
                }
                // find '(' ... matching ')'
                $open = $k;
                while ($open < $n && $tokens[$open][1] !== '(') {
                    $open++;
                }
                $depth = 0;
                $close = $open;
                for (; $close < $n; $close++) {
                    if ($tokens[$close][1] === '(') {
                        $depth++;
                    } elseif ($tokens[$close][1] === ')') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                }
                $params = $this->paramTypes(array_slice($tokens, $open + 1, max(0, $close - $open - 1)));

                // return type: tokens after ')' colon, up to ';' or '{'
                $return = '';
                $sawColon = false;
                for ($m = $close + 1; $m < $n; $m++) {
                    $tt = $tokens[$m][1];
                    if ($tt === ';' || $tt === '{') {
                        break;
                    }
                    if (! $sawColon) {
                        if ($tt === ':') {
                            $sawColon = true;
                        }

                        continue;
                    }
                    if ($tokens[$m][0] === T_WHITESPACE) {
                        continue;
                    }
                    $return .= $tt;
                }

                $methods[$name] = ['params' => $params, 'return' => trim($return)];

                continue;
            }
        }

        if ($interfaceName === null) {
            return null;
        }

        return ['namespace' => $namespace, 'name' => $interfaceName, 'methods' => $methods];
    }

    /**
     * Reduce a parameter token slice to the ordered list of (normalized) type strings — the type tokens that
     * precede each $variable.
     *
     * @param  list<array{0:int|null,1:string}>  $tokens
     * @return list<string>
     */
    private function paramTypes(array $tokens): array
    {
        $types = [];
        $current = '';
        foreach ($tokens as [$id, $text]) {
            if ($text === ',') {
                $current = '';

                continue;
            }
            if ($id === T_VARIABLE) {
                $types[] = trim($current);

                continue;
            }
            if ($id === T_WHITESPACE) {
                continue;
            }
            $current .= $text;
        }

        return $types;
    }

    /**
     * @param  list<string>  $roots
     */
    private function pathUnderRootAndNamespace(string $path, array $roots, string $namespace): bool
    {
        $needle = str_replace('\\', '/', $namespace);
        $normalized = str_replace('\\', '/', $path);
        foreach ($roots as $root) {
            $root = trim(str_replace('\\', '/', $root), '/');
            if ($root !== '' && str_contains($normalized, $root) && ($needle === '' || str_contains($normalized, $needle))) {
                return true;
            }
        }

        return false;
    }
}
