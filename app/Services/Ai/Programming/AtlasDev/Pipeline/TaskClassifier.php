<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;

/**
 * Deterministic task classifier.
 *
 * Maps `(normalizedIntent, userConstraints, surfaceContext)` to a TaskKind
 * + intent_clarity_level without consulting a model. Rules are observable and
 * traceable: matchedRules in the returned TaskClassification list which
 * patterns fired, so callers can explain "why patch?" to an operator.
 *
 * Rule precedence (highest wins):
 *   1. question   — explicit read-only ask / explain / where / what / why
 *   2. risky      — auth/billing/migration/security keywords
 *   3. repair     — failing tests / fix / corrigir / bug
 *   4. review     — review / revise / inspect diff
 *   5. frontend   — UI keywords AND a frontend-shaped surface
 *   6. patch      — default for action verbs with reference tokens
 */
class TaskClassifier
{
    private const RISKY_TOKENS = [
        'auth', 'authentication', 'login', 'session', 'password', 'secret',
        'token', 'jwt', 'oauth', 'permission', 'permissoes', 'permissão',
        'billing', 'payment', 'stripe', 'invoice', 'cobranca', 'cobrança',
        'migration', 'migracao', 'migração', 'rollback',
        'production', 'producao', 'produção', 'prod ', 'prod release',
        'security', 'seguranca', 'segurança', 'sensitive',
        'pii', 'compliance', 'gdpr', 'lgpd',
    ];

    private const REPAIR_TOKENS = [
        'corrija', 'corrigir', 'corrige', 'fix ', 'fixar', 'conserta', 'conserte', 'consertar',
        'teste falhando', 'testes falhando', 'failing test', 'failing tests',
        'broken', 'quebrado', 'quebrada', 'red ci', 'ci vermelho',
        'bug', 'regressao', 'regressão', 'regression',
    ];

    private const REVIEW_TOKENS = [
        'review ', 'revisar', 'revise ', 'revise o', 'inspecione', 'inspect ',
        'pode revisar', 'analise', 'analisar este diff', 'check this diff',
    ];

    private const FRONTEND_TOKENS = [
        'tela', 'screen', 'screenshot', 'ui ', 'ux ', 'componente', 'component',
        'mockup', 'pixel', 'spacing', 'tipografia', 'typography',
        'design', 'estilo', 'css', 'tailwind',
    ];

    private const FRONTEND_SURFACE_HINTS = [
        'atlas_desktop_ai', 'atlas_app', 'atlas_code', 'atlas_frontend', 'atlas_cli_dev',
    ];

    private const QUESTION_PREFIXES = [
        'responda ', 'responde ', 'answer ',
        'explique ', 'explica ', 'explain ', 'descreva ', 'descreve ',
        'o que ', 'qual ', 'quais ', 'quantos ', 'quantas ', 'onde ',
        'where ', 'what ', 'how ', 'why ', 'por que ', 'por quê ', 'porque ',
    ];

    private const ACTION_VERBS = [
        'corrija', 'corrigir', 'corrige', 'fix ', 'ajuste', 'ajusta',
        'remova', 'remover', 'remove', 'apagar', 'delete', 'adicione',
        'adicionar', 'add ', 'crie', 'create', 'rename', 'renomeie',
        'conserta', 'conserte', 'consertar', 'refator', 'refactor', 'extract', 'extraia', 'rode', 'execute',
        'altere', 'alterar', 'update ', 'atualize', 'atualizar', 'edit ',
        'apply ', 'implement ', 'implemente',
    ];

    public function classify(OperationEnvelope $envelope): TaskClassification
    {
        $haystack = $this->buildHaystack($envelope);
        $matched = [];

        if ($this->startsWithAny($envelope->normalizedIntent, self::QUESTION_PREFIXES, $matched, 'question:')) {
            return new TaskClassification(
                taskKind: TaskClassification::KIND_QUESTION,
                intentClarityLevel: $envelope->intentClarityLevel,
                matchedRules: $matched,
                writeImplied: false,
            );
        }

        if ($this->containsAny($haystack, self::RISKY_TOKENS, $matched, 'risky:')) {
            return new TaskClassification(
                taskKind: TaskClassification::KIND_RISKY,
                intentClarityLevel: $this->clarityForRisky($envelope, $matched),
                matchedRules: $matched,
                writeImplied: false,
            );
        }

        if ($this->containsAny($haystack, self::REPAIR_TOKENS, $matched, 'repair:')) {
            return new TaskClassification(
                taskKind: TaskClassification::KIND_REPAIR,
                intentClarityLevel: $envelope->intentClarityLevel,
                matchedRules: $matched,
                writeImplied: true,
            );
        }

        if ($this->containsAny($haystack, self::REVIEW_TOKENS, $matched, 'review:')) {
            return new TaskClassification(
                taskKind: TaskClassification::KIND_REVIEW,
                intentClarityLevel: $envelope->intentClarityLevel,
                matchedRules: $matched,
                writeImplied: false,
            );
        }

        if ($this->isFrontendKind($envelope, $haystack, $matched)) {
            return new TaskClassification(
                taskKind: TaskClassification::KIND_FRONTEND,
                intentClarityLevel: $envelope->intentClarityLevel,
                matchedRules: $matched,
                writeImplied: true,
            );
        }

        $hasAction = $this->containsAny($haystack, self::ACTION_VERBS, $matched, 'action:');
        if ($hasAction) {
            return new TaskClassification(
                taskKind: TaskClassification::KIND_PATCH,
                intentClarityLevel: $envelope->intentClarityLevel,
                matchedRules: $matched,
                writeImplied: true,
            );
        }

        // No verb, no reference, no question word: treat as low-clarity question
        // so plan-only gating activates instead of guessing patch intent.
        $matched[] = 'fallback:no_match';

        return new TaskClassification(
            taskKind: TaskClassification::KIND_QUESTION,
            intentClarityLevel: $envelope->intentClarityLevel === IntakeNormalizer::CLARITY_HIGH
                ? IntakeNormalizer::CLARITY_MEDIUM
                : ($envelope->intentClarityLevel === IntakeNormalizer::CLARITY_BLOCKING
                    ? IntakeNormalizer::CLARITY_BLOCKING
                    : IntakeNormalizer::CLARITY_LOW),
            matchedRules: $matched,
            writeImplied: false,
        );
    }

    private function buildHaystack(OperationEnvelope $envelope): string
    {
        $parts = [$envelope->normalizedIntent];
        foreach ($envelope->userConstraints as $constraint) {
            if (is_string($constraint)) {
                $parts[] = $constraint;
            }
        }

        return strtolower(implode("\n", $parts));
    }

    /**
     * @param  list<string>  $needles
     * @param  list<string>  $matched  appended in place
     */
    private function containsAny(string $haystack, array $needles, array &$matched, string $tagPrefix): bool
    {
        $hit = false;
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }
            if (str_contains($haystack, $needle)) {
                $hit = true;
                $matched[] = $tagPrefix.trim($needle);
            }
        }

        return $hit;
    }

    /**
     * @param  list<string>  $needles
     * @param  list<string>  $matched
     */
    private function startsWithAny(string $value, array $needles, array &$matched, string $tagPrefix): bool
    {
        $lower = strtolower($value);
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }
            if (str_starts_with($lower, strtolower($needle))) {
                $matched[] = $tagPrefix.trim($needle);

                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $matched
     */
    private function isFrontendKind(OperationEnvelope $envelope, string $haystack, array &$matched): bool
    {
        $surface = strtolower($envelope->surfaceId);
        $isFrontendSurface = in_array($surface, self::FRONTEND_SURFACE_HINTS, true);
        $local = [];
        $hasTokens = $this->containsAny($haystack, self::FRONTEND_TOKENS, $local, 'frontend:');
        if ($hasTokens && $isFrontendSurface) {
            foreach ($local as $tag) {
                $matched[] = $tag;
            }
            $matched[] = 'frontend:surface_hint';

            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $matched
     */
    private function clarityForRisky(OperationEnvelope $envelope, array $matched): string
    {
        // Risky tasks are R4/R5 territory by default; clarity stays low so the
        // routing layer keeps them in plan-only / forge preview, never patch.
        if ($envelope->intentClarityLevel === IntakeNormalizer::CLARITY_BLOCKING) {
            return IntakeNormalizer::CLARITY_BLOCKING;
        }

        return IntakeNormalizer::CLARITY_LOW;
    }
}
