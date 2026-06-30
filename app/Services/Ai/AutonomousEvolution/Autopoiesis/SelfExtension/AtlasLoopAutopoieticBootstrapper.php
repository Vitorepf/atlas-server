<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\SelfExtension;


use App\Services\Ai\SelfConstruction\Support\RecursivelyCanonicalizesArrays;
use RuntimeException;

final class ScopeOverlapsLoopCoreException extends RuntimeException
{
}

final class AtlasLoopAutopoieticBootstrapper
{
    use RecursivelyCanonicalizesArrays;

    /** @var array<string, true> */
    private array $emitted = [];

    /**
     * @param  array{
     *   namespace:string,
     *   operator_intent:array{rationale:string,scope_id:string},
     *   roots:list<string>,
     *   scope_id:string
     * }  $scope
     * @return array{
     *   bytes_written:int,
     *   files:array<string,string>,
     *   manifest:array<string,mixed>,
     *   manifest_json:string,
     *   manifest_sha256:string
     * }
     */
    public function bootstrap(array $scope): array
    {
        $roots = $this->roots($scope);
        $this->guardAgainstLoopCoreOverlap($roots);

        $namespace = trim((string) ($scope['namespace'] ?? ''));
        $scopeId = trim((string) ($scope['scope_id'] ?? ''));
        $operatorIntent = is_array($scope['operator_intent'] ?? null) ? $scope['operator_intent'] : [];

        if ($namespace === '' || $scopeId === '' || $operatorIntent === []) {
            throw new RuntimeException('Scope descriptor must contain scope_id, namespace, and operator_intent.');
        }

        $bundle = $this->bundle($scopeId, $roots, $namespace, $operatorIntent);
        $bundleHash = hash('sha256', json_encode($bundle['files'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $bytesWritten = isset($this->emitted[$bundleHash]) ? 0 : $this->countBytes($bundle['files']);
        $this->emitted[$bundleHash] = true;

        return [
            'bytes_written' => $bytesWritten,
            'files' => $bundle['files'],
            'manifest' => $bundle['manifest'],
            'manifest_json' => $bundle['manifest_json'],
            'manifest_sha256' => $bundle['manifest_sha256'],
        ];
    }

    /**
     * @param  list<string>  $roots
     */
    private function guardAgainstLoopCoreOverlap(array $roots): void
    {
        foreach ($roots as $root) {
            $normalized = $this->normalizePath($root);
            if (str_starts_with($normalized, 'app/Services/Ai/AutonomousEvolution/')) {
                throw new ScopeOverlapsLoopCoreException('Scope roots overlap Loop core paths.');
            }
        }
    }

    /**
     * @param  array{rationale:string,scope_id:string}  $operatorIntent
     * @return array{
     *   files:array<string,string>,
     *   manifest:array<string,mixed>,
     *   manifest_json:string,
     *   manifest_sha256:string
     * }
     */
    private function bundle(string $scopeId, array $roots, string $namespace, array $operatorIntent): array
    {
        $root = $roots[0];
        $baseDir = $this->normalizePath($root).'/'.str_replace('\\', '/', $namespace);
        $manifestPath = $baseDir.'/scope.manifest.json';
        $configPath = $baseDir.'/scope.config.append.php';

        $loopPath = $baseDir.'/LoopSubstrateContract.php';
        $cortexPath = $baseDir.'/CortexComprehensionContract.php';
        $maestroPath = $baseDir.'/MaestroOrchestrationContract.php';

        $files = [
            $configPath => $this->configSnippet($scopeId, $namespace, $roots),
            $cortexPath => $this->contractStub($namespace, 'CortexComprehensionContract'),
            $loopPath => $this->contractStub($namespace, 'LoopSubstrateContract'),
            $maestroPath => $this->contractStub($namespace, 'MaestroOrchestrationContract'),
        ];
        ksort($files);

        $manifest = [
            'contract_version' => 'autopoiesis-bootstrapper-v1',
            'installed_at_atomic' => 'ATOMIC:BOOTSTRAP:'.$scopeId,
            'namespace' => $namespace,
            'operator_intent' => [
                'rationale' => (string) ($operatorIntent['rationale'] ?? ''),
                'scope_id' => (string) ($operatorIntent['scope_id'] ?? ''),
            ],
            'primitives' => [
                'cortex' => $cortexPath,
                'loop' => $loopPath,
                'maestro' => $maestroPath,
            ],
            'roots' => $roots,
            'scope_id' => $scopeId,
        ];
        $manifest = $this->canonicalize($manifest);
        // Seal using the verifier's canonical file-content scheme: sha256({role => sha256(file_bytes)}).
        // This matches AtlasLoopAutopoieticContractVerifier::recomputeManifestSha() so the hash_equals
        // check passes on the first real bootstrap attempt.
        $sealMap = [
            'cortex'  => hash('sha256', $files[$cortexPath]),
            'loop'    => hash('sha256', $files[$loopPath]),
            'maestro' => hash('sha256', $files[$maestroPath]),
        ];
        ksort($sealMap);
        $manifestSha = hash('sha256', (string) json_encode($sealMap, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $manifest['manifest_sha256'] = $manifestSha;
        $manifest = $this->canonicalize($manifest);
        $manifestJson = (string) json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $files[$manifestPath] = $manifestJson;
        ksort($files);

        return [
            'files' => $files,
            'manifest' => $manifest,
            'manifest_json' => $manifestJson,
            'manifest_sha256' => $manifestSha,
        ];
    }

    /**
     * @param  array<string,mixed>|list<mixed>  $value
     * @return array<string,mixed>|list<mixed>
     */

    /**
     * @param  list<string>  $roots
     */
    private function configSnippet(string $scopeId, string $namespace, array $roots): string
    {
        $payload = [
            'namespace' => $namespace,
            'roots' => $roots,
            'scope_id' => $scopeId,
        ];

        return "<?php\n\nreturn ".var_export($payload, true).";\n";
    }

    private function contractStub(string $namespace, string $contract): string
    {
        // Method signatures must match AtlasLoopAutopoieticContractVerifier::CONTRACTS exactly
        // so that parseInterface() satisfies the CONTRACT_METHOD_MISSING gate.
        $method = match ($contract) {
            'LoopSubstrateContract'        => '    public function nextCandidate(): ?CandidateRef;',
            'CortexComprehensionContract'  => '    public function comprehend(string $scopeId): ComprehensionReport;',
            'MaestroOrchestrationContract' => '    public function orchestrate(array $plan): OrchestrationReceipt;',
            default                        => '',
        };

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

interface {$contract}
{
{$method}
}
PHP;
    }

    /**
     * @param  array<string,string>  $files
     */
    private function countBytes(array $files): int
    {
        $total = 0;
        foreach ($files as $content) {
            $total += strlen($content);
        }

        return $total;
    }

    private function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    /**
     * @param  array<string,mixed>  $scope
     * @return list<string>
     */
    private function roots(array $scope): array
    {
        $roots = array_values(array_filter(
            is_array($scope['roots'] ?? null) ? $scope['roots'] : [],
            static fn (mixed $root): bool => is_string($root) && trim($root) !== ''
        ));
        $roots = array_map(fn (string $root): string => $this->normalizePath($root), $roots);
        $roots = array_values(array_unique($roots));
        sort($roots);

        if ($roots === []) {
            throw new RuntimeException('Scope descriptor must contain at least one concrete root.');
        }

        return $roots;
    }
}
