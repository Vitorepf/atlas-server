<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;

/**
 * SURFACE-AUDIT concern, extracted from the god-class
 * {@see AgentControlPlaneChainIntegrityAuditService}.
 *
 * Owns the documentation/cli/invoker/test surface shape computations:
 * documentationAudit, cliSurface, invokerSurface, testSurface, the
 * allQuartetMethodsPresent and countDuplicates helpers.
 *
 * Holds an optional reference to the parent audit service so it can reuse the
 * shared cliBaseForSlice helper without duplicating it. The parent constructor
 * accepts this collaborator as a nullable second arg and instantiates one by
 * default (lazy); tests pass a fake parent to exercise the cross-cutting
 * helper paths.
 */
final class AgentControlPlaneChainIntegritySurfaceAuditor
{
    public function __construct(
        private readonly ?AgentControlPlaneChainIntegrityAuditService $parent = null,
    ) {}

    /**
     * @param  list<array<string, string>>  $deepChain
     * @return array<string, mixed>
     */
    public function documentationAudit(array $deepChain): array
    {
        $path = base_path('docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md');
        $exists = is_file($path);
        $contents = $exists ? (string) @file_get_contents($path) : '';
        $bulletsFound = [];
        $duplicateBullets = [];
        if ($exists) {
            foreach ($deepChain as $slice) {
                $bullet = $slice['doc_bullet'];
                if ($bullet === '') {
                    continue;
                }
                // Match the canonical bullet anchor: a list item that begins with
                // "- expose and implement automatic dispatch scheduler one-shot tick Codex real invoker <slice phrase>"
                // so that we ignore in-passing "routes next to <slice>" mentions inside other bullets.
                $pattern = '/^\\s*-\\s+expose\\s+(?:and\\s+implement\\s+)?automatic\\s+dispatch\\s+scheduler\\s+one-shot\\s+tick\\s+Codex\\s+real\\s+invoker\\s+'
                    .preg_quote($bullet, '/').'\\b/m';
                $count = preg_match_all($pattern, $contents) ?: 0;
                $bulletsFound[$slice['slice_key']] = $count;
                if ($count >= 2) {
                    $duplicateBullets[] = $slice['slice_key'];
                }
            }
        }

        return [
            'contract_doc_present' => $exists,
            'contract_doc_path' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            'contract_doc_byte_size' => $exists ? strlen($contents) : 0,
            'slice_bullets_found' => $bulletsFound,
            'duplicate_slice_bullets' => $duplicateBullets,
        ];
    }

    /**
     * @param  list<array<string, string>>  $deepChain
     * @return array<string, mixed>
     */
    public function cliSurface(array $deepChain): array
    {
        $expectedOptions = [
            'agent-control-plane',
            'agent-control-plane-chain-integrity-certification',
            'agent-control-plane-chain-integrity-certification-status',
            'agent-control-plane-chain-integrity-certification-preflight',
            'agent-control-plane-chain-integrity-certification-implementation-packet',
        ];
        foreach ($deepChain as $slice) {
            $sliceKey = (string) $slice['slice_key'];
            $cliBase = $this->cliBaseForSlice($sliceKey);
            if ($cliBase === '') {
                continue;
            }
            // For slice families whose base already ends in `_contract`, the CLI
            // contract option uses the base CLI key (no extra `-contract` suffix)
            // — the rest of the quintet keeps the suffix convention.
            $expectedOptions[] = str_ends_with($sliceKey, '_contract') ? $cliBase : ($cliBase.'-contract');
            $expectedOptions[] = $cliBase.'-preflight';
            $expectedOptions[] = $cliBase.'-implementation-packet';
            $expectedOptions[] = $cliBase.'-status';
        }
        $expectedOptions = array_values(array_unique($expectedOptions));

        $present = [];
        $missing = [];
        try {
            $kernel = app(ConsoleKernelContract::class);
            $registry = $kernel->all();
            $command = $registry['atlas:ai:self-construction'] ?? null;
            if ($command === null) {
                throw new \RuntimeException('atlas:ai:self-construction command is not registered.');
            }
            $definition = $command->getDefinition();
            foreach ($expectedOptions as $optionName) {
                if ($definition->hasOption($optionName)) {
                    $present[] = $optionName;
                } else {
                    $missing[] = $optionName;
                }
            }
            $aligned = $missing === [];
        } catch (\Throwable $error) {
            $missing = $expectedOptions;
            $present = [];
            $aligned = false;
        }

        return [
            'command_name' => 'atlas:ai:self-construction',
            'expected_options' => $expectedOptions,
            'present_options' => $present,
            'missing_options' => $missing,
            'handlers_aligned' => $aligned,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sliceReports
     */
    public function allQuartetMethodsPresent(array $sliceReports): bool
    {
        foreach ($sliceReports as $report) {
            $checks = (array) ($report['checks'] ?? []);
            if (
                ($checks['contract_method_exists'] ?? false) !== true
                || ($checks['preflight_method_exists'] ?? false) !== true
                || ($checks['implementation_packet_method_exists'] ?? false) !== true
                || ($checks['status_method_exists'] ?? false) !== true
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, string>>  $deepChain
     * @return array<string, mixed>
     */
    public function invokerSurface(array $deepChain): array
    {
        $missing = [];
        foreach ($deepChain as $slice) {
            $invokerClass = $slice['invoker_class'];
            if ($invokerClass === '' || ! class_exists($invokerClass)) {
                $missing[] = $slice['slice_key'];

                continue;
            }
            if ($slice['prepare_method'] === '' || ! method_exists($invokerClass, $slice['prepare_method'])) {
                $missing[] = $slice['slice_key'];
            }
        }

        return [
            'deep_checked' => count($deepChain),
            'missing' => $missing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function testSurface(): array
    {
        $dedicatedTestPath = base_path('tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneChainIntegrityAuditTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php');

        return [
            'dedicated_test_path' => 'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneChainIntegrityAuditTest.php',
            'dedicated_test_present' => is_file($dedicatedTestPath),
            'command_test_present' => is_file($commandTestPath),
        ];
    }

    /**
     * @param  list<string>  $values
     */
    public function countDuplicates(array $values): int
    {
        return count($values) - count(array_unique($values));
    }

    /**
     * Inline replica of the parent audit service's `cliBaseForSlice`. Kept as a
     * private helper so this collaborator does not need to consult its parent
     * for a 4-line constant transformation; preserves byte-identical output.
     */
    private function cliBaseForSlice(string $sliceKey): string
    {
        if ($sliceKey === '') {
            return '';
        }

        return 'agent-'.str_replace('_', '-', $sliceKey);
    }
}