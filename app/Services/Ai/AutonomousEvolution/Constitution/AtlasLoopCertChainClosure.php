<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Constitution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopSemanticImplementationCertifier;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

/**
 * LOOP-OS · FASE 3 · SLICE 4.5 — the REFLECTION-DERIVED cert-chain closure (pétreo / FORBIDDEN under Constitution/).
 *
 * The R4 keystone: the certifier DELEGATES its verdict. Protecting (or Merkle-hashing) the top certifier file
 * alone is a blinder hole — a candidate could weaken a DELEGATED gate (HonestyGate, MutationAdequacy, a node
 * extractor reached two hops down) and the judge goes blinder undetected. So the protected/hashed set is the
 * full TRANSITIVE closure, and it is DERIVED HERE (not a hand-list that rots): a BFS from the certifier over
 *   (a) every constructor parameter class-type (reflection), AND
 *   (b) every inline `new X(` delegation in each closure file's source (resolved via the file's use-map),
 * repo-wide (it reaches {@see AdversarialProofPanelService} OUTSIDE the AutonomousEvolution/ subtree).
 *
 * Design rule (§3.3): any verdict-bearing class reachable only by a NON-walkable path (dynamic container
 * make(), a config()-string class name) is itself a structural-battery-REJECTED pattern — gate code must
 * declare its dependencies statically so this closure is computable. The §3.6 sentinel asserts this walker's
 * output ⊇ the documented closure, so a future delegate added off a walkable path is caught, and one added off
 * an unwalkable path fails the structural rule.
 */
final class AtlasLoopCertChainClosure
{
    /** The root whose verdict is transitively delegated to the whole closure. */
    public const ROOT = AtlasLoopSemanticImplementationCertifier::class;

    /** Only classes under these namespace roots are part of the loop's judge (skip framework/vendor types). */
    private const APP_ROOTS = ['App\\Services\\Ai\\AutonomousEvolution\\', 'App\\Services\\Ai\\SoftwareCompanyStewardship\\'];

    /**
     * FROZEN declared-dynamic supplement: verdict-bearing collaborators the cert chain reaches through a
     * NON-walkable path (an `app()` make / a dynamic class name) so static reflection cannot see them. Per
     * §3.3 such a path is a structural-battery-REJECTED pattern; until the live chain is refactored to declare
     * these statically, they are pinned here (provenance: §3.3 enumeration + FORBIDDEN_SELF_TARGETS) so the
     * closure can never silently DROP a delegated gate. They seed the BFS, so their OWN static sub-deps
     * (e.g. DeadCodeAnalyzer→Support, ChangedSymbolCoverageCensus→NodeInterfaceExtractor) are walked too. The
     * §3.6 sentinel asserts the union ⊇ the documented closure, so this list can grow but never shrink.
     */
    private const DECLARED_DYNAMIC_DELEGATIONS = [
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasEvolutionFrozenJudge',
        'App\\Services\\Ai\\AutonomousEvolution\\Verify\\AtlasDeadCodeAnalyzerSupport',
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopBehavioralEquivalenceGate',
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopChangedSymbolCoverageCensus',
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopQualityGrader',
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopDeliveryConfidenceModel',
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopCompletenessGate',
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopJudgeConsensusGate',
        'App\\Services\\Ai\\AutonomousEvolution\\Discovery\\AtlasLoopNodeInterfaceExtractor',
    ];

    /**
     * The transitive cert-chain closure as a sorted, unique list of fully-qualified class names — the judge
     * plus every collaborator its verdict is delegated to.
     *
     * @return list<class-string>
     */
    public function classes(): array
    {
        $seen = [];
        $queue = array_merge([self::ROOT], self::DECLARED_DYNAMIC_DELEGATIONS);
        while ($queue !== []) {
            $fqcn = array_shift($queue);
            $fqcn = ltrim($fqcn, '\\');
            if (isset($seen[$fqcn]) || ! $this->isAppClass($fqcn) || ! class_exists($fqcn)) {
                continue;
            }
            $seen[$fqcn] = true;
            foreach ($this->directDelegates($fqcn) as $dep) {
                if (! isset($seen[ltrim($dep, '\\')])) {
                    $queue[] = $dep;
                }
            }
        }
        $out = array_keys($seen);
        sort($out);

        /** @var list<class-string> $out */
        return $out;
    }

    /**
     * The closure as fqcn => repo-relative file path (for the Merkle + the freeze census).
     *
     * @return array<class-string,string>
     */
    public function files(string $repoRoot): array
    {
        $repoRoot = rtrim($repoRoot, '/').'/';
        $out = [];
        foreach ($this->classes() as $fqcn) {
            try {
                $file = (new ReflectionClass($fqcn))->getFileName();
            } catch (Throwable) {
                $file = false;
            }
            if (is_string($file) && str_starts_with($file, $repoRoot)) {
                $out[$fqcn] = substr($file, strlen($repoRoot));
            }
        }

        return $out;
    }

    /**
     * Merkle root over the closure's ON-DISK file bytes: sha256 chain over the (sorted) per-file
     * sha256(path . "\0" . bytes). Any byte change to ANY delegate (top or deep) moves the root — the anchor
     * the candidate-bytes proof binds to so "the probe ran the candidate, not the live class" is checkable
     * over the FULL closure, never just the top file.
     */
    public function merkleRoot(string $repoRoot): string
    {
        $files = $this->files($repoRoot);
        ksort($files);
        $acc = 'atlas-loop-cert-chain-merkle';
        foreach ($files as $rel) {
            $abs = rtrim($repoRoot, '/').'/'.$rel;
            $bytes = is_file($abs) ? (string) @file_get_contents($abs) : '';
            $acc = hash('sha256', $acc."\n".hash('sha256', $rel."\0".$bytes));
        }

        return $acc;
    }

    /**
     * Direct delegates of one class: constructor param class-types (reflection) ∪ inline `new X(` in its
     * source (resolved through the file's use-map + namespace).
     *
     * @return list<class-string>
     */
    private function directDelegates(string $fqcn): array
    {
        $deps = [];
        try {
            $ref = new ReflectionClass($fqcn);
        } catch (Throwable) {
            return [];
        }

        $ctor = $ref->getConstructor();
        if ($ctor !== null) {
            foreach ($ctor->getParameters() as $param) {
                $type = $param->getType();
                if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                    $deps[] = $type->getName();
                }
            }
        }

        $file = $ref->getFileName();
        if (is_string($file) && is_file($file)) {
            foreach ($this->inlineNewClasses((string) @file_get_contents($file)) as $dep) {
                $deps[] = $dep;
            }
        }

        return array_values(array_filter($deps, fn (string $d): bool => $this->isAppClass(ltrim($d, '\\'))));
    }

    /**
     * Resolve every `new X(` in a source file to a fully-qualified class name via the file's namespace +
     * use-map. Catches the inline delegations reflection over constructor params cannot see (e.g. the
     * certifier's `new AtlasLoopHeldOutDeltaCertifier(new AtlasLoopMetricHarness)`).
     *
     * @return list<class-string>
     */
    private function inlineNewClasses(string $source): array
    {
        $namespace = preg_match('/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $source, $nm) === 1 ? trim($nm[1], '\\') : '';
        $useMap = [];
        if (preg_match_all('/^\s*use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $source, $um, PREG_SET_ORDER) > 0) {
            foreach ($um as $u) {
                $full = ltrim($u[1], '\\');
                $alias = $u[2] ?? substr($full, (int) strrpos('\\'.$full, '\\'));
                $useMap[ltrim($alias, '\\')] = $full;
            }
        }

        $out = [];
        if (preg_match_all('/\bnew\s+(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)\s*\(/', $source, $nm2) > 0) {
            foreach ($nm2[1] as $raw) {
                $out[] = $this->resolveName($raw, $namespace, $useMap);
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    /**
     * @param  array<string,string>  $useMap
     */
    private function resolveName(string $raw, string $namespace, array $useMap): string
    {
        if (str_starts_with($raw, '\\')) {
            return ltrim($raw, '\\'); // already fully-qualified
        }
        $head = explode('\\', $raw)[0];
        if (isset($useMap[$head])) {
            $tail = substr($raw, strlen($head));

            return $useMap[$head].$tail;
        }

        return ($namespace !== '' ? $namespace.'\\' : '').$raw; // same-namespace
    }

    private function isAppClass(string $fqcn): bool
    {
        foreach (self::APP_ROOTS as $root) {
            if (str_starts_with($fqcn, $root)) {
                return true;
            }
        }

        return false;
    }
}
