<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * §3 · ARCHITECT PHASE — close "selo sem veto": make the projected design contract ENFORCEABLE.
 *
 * The {@see AtlasLoopGroundedProjectionRoles} critic raises a `consumer_intact` obligation for every real
 * caller of the target, and the worker attaches the typed obligations to the task. But the certifier's
 * {@see AtlasLoopCrossFileConsumerGateService} reads `acceptance['consumer_contracts']` — NOT
 * `acceptance['obligations']` — so the grounded contract would be computed and then ignored: a seal with no
 * veto. This translator bridges the gap. It turns the projection's `consumer_intact` obligations into the
 * explicit consumer-contract shape the gate already enforces (it replays the caller's test in the candidate
 * workspace when the target's symbol changes), so a refactor that breaks a real caller's behavior FAILS the
 * consumer gate and is refused certification — the projected contract becomes a real veto, not decoration.
 *
 * Pure + deterministic: the caller→runnable-command mapping is INJECTED (the worker resolves each caller's
 * sibling test; a fixture passes a double), so the translation is unit-testable and the enforcement is
 * provable end-to-end against the real gate. A caller with no runnable test yields a contract with an empty
 * command — the gate then records it UNVERIFIED rather than failing closed (an honest limit: an untested
 * caller cannot be replayed, but it also never fabricates a pass).
 */
final class AtlasLoopProjectionObligationContracts
{
    /**
     * @param  list<array<string,mixed>>  $obligations  the engine's typed obligations (consumer_intact ones are translated)
     * @param  list<string>  $consumers  the real caller rel-paths (original case — the obligation target is lossy-lowercased)
     * @param  string  $changedSymbol  the evolution's target class short-name (the symbol whose change risks the callers)
     * @param  callable(string): ?string  $commandFor  caller rel-path → a runnable consumer test command, or null (unverifiable ⇒ skipped)
     * @return list<array<string,mixed>>  explicit consumer_contracts the cross-file consumer gate enforces
     */
    public function toConsumerContracts(array $obligations, array $consumers, string $changedSymbol, callable $commandFor): array
    {
        $changedSymbol = trim($changedSymbol);
        if ($changedSymbol === '' || ! $this->hasConsumerObligation($obligations)) {
            return []; // only a contract whose convergence actually raised a consumer obligation is enforced
        }

        $out = [];
        $seen = [];
        foreach ($consumers as $caller) {
            $caller = ltrim(trim((string) $caller), '/');
            if ($caller === '' || isset($seen[$caller])) {
                continue;
            }
            $seen[$caller] = true;
            $command = $commandFor($caller);
            $out[] = [
                'source' => 'projection_obligation',
                'changed_symbol' => $changedSymbol,
                'consumer_file' => $caller,
                'test_path' => is_string($command) && str_contains($command, 'tests/') ? $this->testPathOf($command) : null,
                'command' => is_string($command) ? trim($command) : '',
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $obligations
     */
    private function hasConsumerObligation(array $obligations): bool
    {
        foreach ($obligations as $o) {
            if (is_array($o) && ($o['kind'] ?? '') === 'consumer_intact') {
                return true;
            }
        }

        return false;
    }

    /** Best-effort extraction of the test file token from a phpunit/php command, for the contract summary. */
    private function testPathOf(string $command): ?string
    {
        foreach (preg_split('/\s+/', trim($command)) ?: [] as $token) {
            $token = trim($token, "'\"");
            if (str_starts_with($token, 'tests/')) {
                return $token;
            }
        }

        return null;
    }
}
