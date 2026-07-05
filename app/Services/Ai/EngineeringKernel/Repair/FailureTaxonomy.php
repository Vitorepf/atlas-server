<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Repair;

/**
 * Engineering Kernel value (OBRA #4 S3): as classes determinísticas de falha do RepairBrain e a
 * estratégia que cada classe autoriza. O reparo deixa de ser "tenta de novo com o erro colado" e
 * vira "entende → escolhe estratégia → age".
 *
 * Pétreo (AC-3.2): TEST_WRONG NUNCA autoriza consertar o teste — a estratégia é ESCALATE (parar e
 * devolver ao humano/spec-adversary). Um executor que "conserta" o teste que o reprova está
 * reescrevendo a própria régua.
 */
final class FailureTaxonomy
{
    public const IMPL_BUG = 'impl_bug';

    public const TEST_WRONG = 'test_wrong';

    public const SPEC_WRONG = 'spec_wrong';

    public const ENV_FLAKE = 'env_flake';

    public const DEPENDENCY_BROKEN = 'dependency_broken';

    public const SCOPE_MISS = 'scope_miss';

    public const UNKNOWN = 'unknown';

    // Estratégias
    public const STRATEGY_REGENERATE_WITH_HINT = 'regenerate_with_hint';

    public const STRATEGY_RERUN_NO_PROVIDER = 'rerun_no_provider';

    public const STRATEGY_ESCALATE_NEVER_AUTOFIX = 'escalate_never_autofix';

    public const STRATEGY_CONTEST_SPEC = 'contest_spec';

    public const STRATEGY_ABORT_BLOCKER = 'abort_blocker';

    public const STRATEGY_DECOMPOSE = 'decompose';

    /** A estratégia autorizada por classe. Único mapa — nenhum caller decide por conta própria. */
    public static function strategyFor(string $class): string
    {
        return match ($class) {
            self::ENV_FLAKE => self::STRATEGY_RERUN_NO_PROVIDER,
            self::TEST_WRONG => self::STRATEGY_ESCALATE_NEVER_AUTOFIX,
            self::SPEC_WRONG => self::STRATEGY_CONTEST_SPEC,
            self::DEPENDENCY_BROKEN => self::STRATEGY_ABORT_BLOCKER,
            self::SCOPE_MISS => self::STRATEGY_DECOMPOSE,
            default => self::STRATEGY_REGENERATE_WITH_HINT, // impl_bug e unknown
        };
    }
}
