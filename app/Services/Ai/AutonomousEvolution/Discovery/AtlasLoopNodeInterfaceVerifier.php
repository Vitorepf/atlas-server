<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * ACDE Leap 6 (design-judgement ceiling) — verify a workspace's REAL AST surface against the HUMAN-frozen
 * node-interface contract.
 *
 * For each file the contract names, read its actual source (from the replay workspace), extract its real
 * interface via {@see AtlasLoopNodeInterfaceExtractor} (decorrelated AST, not the provider LLM), and prove
 * the file HONORS the frozen requirements: the named type exists, exposes the required public methods,
 * implements/extends the required types, and carries NONE of the forbidden imports (dependency-direction).
 * Any miss is an 'interface_contract_violation:<file>:<detail>' reason fed to the REPLAN loop / cert refusal.
 *
 * Deterministic + ungameable: the bar is human-authored, the surface is an AST census. Pure (no provider,
 * no DB). Name resolution honours the file's namespace + use-imports so a short written name matches the
 * contract's FQN.
 */
final class AtlasLoopNodeInterfaceVerifier
{
    public function __construct(private readonly ?AtlasLoopNodeInterfaceExtractor $extractor = null) {}

    /**
     * @param  array<string, array{fqn:?string, required_public_methods:list<string>, implements:list<string>, extends:list<string>, forbidden_imports:list<string>}>  $contract
     * @param  callable(string): ?string  $readSource  (relativeFile) => source, or null/'' if absent
     * @return list<string> violation reasons (empty => the contract is honored)
     */
    public function verify(array $contract, callable $readSource): array
    {
        $extractor = $this->extractor ?? new AtlasLoopNodeInterfaceExtractor;
        $violations = [];
        $support = new AtlasLoopNodeInterfaceVerifierSupport;

        foreach ($contract as $file => $req) {
            array_push($violations, ...$support->verifyFile($file, $req, $readSource, $extractor));
        }

        return $violations;
    }
}