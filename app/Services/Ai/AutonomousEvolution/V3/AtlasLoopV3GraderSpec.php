<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V3;

use ReflectionClass;
use Throwable;

/**
 * V3 GRADER SPEC — the IMMUTABLE contract every Loop-authored deterministic grader must satisfy. The Loop today
 * has exactly ONE operator-authored ruler (AtlasLoopWiringMaterialGrader) gating every origination claim; a
 * single ruler is a single point of admission failure. Before the Loop may AUTHOR new graders (for claim
 * classes the current ruler cannot admit), every candidate grader must pass THIS spec.
 *
 * A conforming grader is: final, declares strict_types, exposes public validateClaim(array,string):array, and
 * returns a verdict carrying the required keys even on a synthetic empty-claim probe. FAIL-CLOSED: any throw
 * (uninstantiable, validateClaim throws, …) ⇒ ok=false — a grader that can't be safely probed is never admitted.
 */
final class AtlasLoopV3GraderSpec
{
    public const SCHEMA = 'atlas.loop.v3.grader_spec.v1';

    /** @return list<string> */
    public static function requiredMethods(): array
    {
        return ['validateClaim'];
    }

    /** @return list<string> */
    public static function requiredVerdictKeys(): array
    {
        return ['material', 'reason'];
    }

    /**
     * Reflect $fqcn and check it satisfies the grader contract.
     *
     * @return array{ok:bool, violations:list<string>}
     */
    public static function validate(string $fqcn): array
    {
        $violations = [];

        try {
            if (! class_exists($fqcn)) {
                return ['ok' => false, 'violations' => ['class_does_not_exist:'.$fqcn]];
            }

            $ref = new ReflectionClass($fqcn);

            if (! $ref->isFinal()) {
                $violations[] = 'class_not_final';
            }

            $file = $ref->getFileName();
            $src = $file !== false ? (string) @file_get_contents($file) : '';
            if (preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)/', $src) !== 1) {
                $violations[] = 'missing_strict_types';
            }

            $hasValidateClaim = true;
            foreach (self::requiredMethods() as $method) {
                if (! $ref->hasMethod($method) || ! $ref->getMethod($method)->isPublic()) {
                    $violations[] = 'missing_or_non_public_method:'.$method;
                    if ($method === 'validateClaim') {
                        $hasValidateClaim = false;
                    }
                }
            }

            if ($hasValidateClaim && $ref->getMethod('validateClaim')->getNumberOfParameters() < 2) {
                $violations[] = 'validateClaim_wrong_arity';
                $hasValidateClaim = false;
            }

            // Synthetic empty-claim probe — only attempted when validateClaim is callable as specified.
            if ($hasValidateClaim) {
                $verdict = $ref->newInstance()->validateClaim([], ''); // throws ⇒ caught below (fail-closed)
                if (! is_array($verdict)) {
                    $violations[] = 'verdict_not_array';
                } else {
                    foreach (self::requiredVerdictKeys() as $key) {
                        if (! array_key_exists($key, $verdict)) {
                            $violations[] = 'verdict_missing_key:'.$key;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $violations[] = 'probe_threw:'.$e->getMessage();

            return ['ok' => false, 'violations' => array_values(array_unique($violations))];
        }

        return ['ok' => $violations === [], 'violations' => array_values(array_unique($violations))];
    }
}
