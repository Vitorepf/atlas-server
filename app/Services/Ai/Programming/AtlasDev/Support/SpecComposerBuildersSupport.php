<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Support;

use App\Services\Ai\Programming\AtlasDev\Pipeline\RiskLevelScorer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;

/**
 * Pure SpecComposer builders/helpers peeled from the plan-layer composer.
 *
 * No I/O, no config(), no DI, no model/provider calls. Callers pass already
 * resolved mode/profile/verbs and scalar inputs. SpecComposer remains the
 * orchestration facade that owns elevation config + IntentActionExtractor.
 */
final class SpecComposerBuildersSupport
{
    public const MODE_READ_ONLY = 'read_only';

    public const MODE_PATCH = 'patch';

    public const MODE_REPAIR = 'repair';

    public const MODE_REVIEW = 'review';

    public const MODE_FRONTEND_VISUAL = 'frontend_visual';

    public const MODE_ESCALATE_PREVIEW = 'escalate_preview';

    public const SCOPE_COMPACT = 'compact';

    public const SCOPE_STRUCTURAL = 'structural';

    public const PROFILE_PHP_LARAVEL = 'php_laravel';

    public const PROFILE_TS_REACT = 'ts_react';

    public const PROFILE_GENERIC_NO_TEST = 'generic_no_test';

    /**
     * @param  list<string>  $verificationCommands
     * @param  list<string>  $expectedFiles
     * @param  list<string>  $intentVerbs
     * @return list<array{id: string, description: string, verification: string, verification_ref: ?string}>
     */
    public static function buildAcceptanceCriteria(
        string $mode,
        string $verificationProfile,
        array $verificationCommands,
        array $expectedFiles,
        array $intentVerbs = [],
        bool $e2Enabled = false,
    ): array {
        if ($mode === self::MODE_READ_ONLY) {
            return [[
                'id' => 'ac_1',
                'description' => 'resposta cita refs reais (file://, doc://, symbol://) e nao inventa caminho',
                'verification' => 'manual',
                'verification_ref' => null,
            ]];
        }

        if ($mode === self::MODE_REVIEW) {
            return [[
                'id' => 'ac_1',
                'description' => 'review identifica pontos verificaveis com refs',
                'verification' => 'manual',
                'verification_ref' => null,
            ]];
        }

        if ($mode === self::MODE_ESCALATE_PREVIEW) {
            return [[
                'id' => 'ac_1',
                'description' => 'preview de promotion para Forge gerado com reasons observable, sem patch aplicado',
                'verification' => 'evidence',
                'verification_ref' => null,
            ]];
        }

        $criteria = [];

        if ($e2Enabled) {
            foreach (self::buildBehavioralAcceptanceCriteria($verificationProfile, $verificationCommands, $intentVerbs) as $behavioral) {
                $criteria[] = $behavioral;
            }
        }

        $i = 1;
        foreach ($verificationCommands as $cmd) {
            $criteria[] = [
                'id' => 'ac_cmd_'.$i,
                'description' => "comando '{$cmd}' termina com exit_code=0",
                'verification' => 'test',
                'verification_ref' => $cmd,
            ];
            $i++;
        }

        if ($expectedFiles !== []) {
            $criteria[] = [
                'id' => 'ac_scope',
                'description' => 'diff toca somente arquivos previstos em expected_files',
                'verification' => 'scope_guard',
                'verification_ref' => null,
            ];
        }

        if ($criteria === []) {
            $criteria[] = [
                'id' => 'ac_1',
                'description' => 'patch produzido reflete o goal sem expandir escopo',
                'verification' => 'manual',
                'verification_ref' => null,
            ];
        }

        return $criteria;
    }

    /**
     * @param  list<string>  $verificationCommands
     * @param  list<string>  $intentVerbs
     * @return list<array{id: string, description: string, verification: string, verification_ref: ?string}>
     */
    public static function buildBehavioralAcceptanceCriteria(
        string $verificationProfile,
        array $verificationCommands,
        array $intentVerbs,
    ): array {
        if ($intentVerbs === []) {
            return [];
        }

        $verificationRef = self::resolveBehavioralVerificationRef($verificationProfile, $verificationCommands);
        if ($verificationRef === null) {
            return [];
        }

        $criteria = [];
        foreach ($intentVerbs as $verbLabel) {
            $slug = self::verbSlug($verbLabel);
            $criteria[] = [
                'id' => 'ac_behavior_'.$slug,
                'description' => "diff implementa observavelmente o verbo de intencao '{$verbLabel}' (coberto por: {$verificationRef})",
                'verification' => 'test',
                'verification_ref' => $verificationRef,
            ];
        }

        return $criteria;
    }

    /**
     * @param  list<string>  $verificationCommands
     */
    public static function resolveBehavioralVerificationRef(string $verificationProfile, array $verificationCommands): ?string
    {
        foreach ($verificationCommands as $cmd) {
            $trimmed = trim($cmd);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return match ($verificationProfile) {
            self::PROFILE_PHP_LARAVEL => 'composer test',
            self::PROFILE_TS_REACT => 'pnpm test',
            default => null,
        };
    }

    public static function verbSlug(string $verbLabel): string
    {
        $slug = strtolower(trim($verbLabel));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? $slug;

        return trim($slug, '_');
    }

    /**
     * @return list<string>
     */
    public static function buildNonGoals(string $mode): array
    {
        $base = [
            'nao expandir escopo alem dos arquivos previstos',
            'nao introduzir dependencias novas sem mini-spec atualizado',
        ];
        if ($mode === self::MODE_REPAIR) {
            $base[] = 'nao reescrever feature, apenas restaurar comportamento esperado';
        }
        if ($mode === self::MODE_FRONTEND_VISUAL) {
            $base[] = 'nao alterar contrato de API para resolver problema visual';
        }
        if ($mode === self::MODE_ESCALATE_PREVIEW) {
            $base[] = 'nao aplicar patch no fast path';
            $base[] = 'nao tomar decisao automatica de promotion';
        }

        return $base;
    }

    /**
     * @param  list<string>  $intentVerbs
     * @return list<array{description: string, observable_by: string}>
     */
    public static function buildExpectedBehavior(string $mode, array $intentVerbs): array
    {
        if ($mode === self::MODE_READ_ONLY) {
            return [[
                'description' => 'resposta humana com refs canonicas e limites de confianca',
                'observable_by' => 'human',
            ]];
        }
        if ($mode === self::MODE_REVIEW) {
            return [[
                'description' => 'review aponta riscos e refs com confidence visivel',
                'observable_by' => 'human',
            ]];
        }
        if ($mode === self::MODE_ESCALATE_PREVIEW) {
            return [[
                'description' => 'preview de Forge promotion contem reasons, signals, score e human_action_required=true',
                'observable_by' => 'evidence',
            ]];
        }

        if ($intentVerbs === []) {
            return [[
                'description' => 'comportamento esperado verificavel pelos comandos de verificacao da spec',
                'observable_by' => 'test',
            ]];
        }

        $behaviors = [];
        foreach ($intentVerbs as $verbLabel) {
            $behaviors[] = [
                'description' => "diff implementa o verbo de intenção '{$verbLabel}' de forma observável pelos comandos de verificação da spec",
                'observable_by' => 'test',
            ];
        }

        return $behaviors;
    }

    /**
     * @return list<array{text: string, confidence: string}>
     */
    public static function buildAssumptions(string $mode, string $discoveryConfidence): array
    {
        $assumptions = [];
        if ($discoveryConfidence === CodeDiscoveryManifest::CONFIDENCE_HYPOTHESIS
            || $discoveryConfidence === CodeDiscoveryManifest::CONFIDENCE_BLOCKING_AMBIGUITY) {
            $assumptions[] = [
                'text' => 'arquivos alvo dependem de confirmacao adicional (confidence='.$discoveryConfidence.')',
                'confidence' => $discoveryConfidence === CodeDiscoveryManifest::CONFIDENCE_BLOCKING_AMBIGUITY ? 'blocking' : 'inference',
            ];
        } elseif ($discoveryConfidence === CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE) {
            $assumptions[] = [
                'text' => 'arquivos provaveis inferidos por simbolos/paths reais no workspace',
                'confidence' => 'inference',
            ];
        }
        if ($mode === self::MODE_ESCALATE_PREVIEW) {
            $assumptions[] = [
                'text' => 'risco/escopo demanda decisao humana antes de qualquer patch',
                'confidence' => 'blocking',
            ];
        }

        return $assumptions;
    }

    /**
     * @param  list<string>  $verificationCommands
     * @return list<string>
     */
    public static function buildCompletionCriteria(string $mode, array $verificationCommands): array
    {
        if ($mode === self::MODE_READ_ONLY) {
            return ['resposta entregue com refs e limites de confianca'];
        }
        if ($mode === self::MODE_REVIEW) {
            return ['review entregue com refs verificaveis'];
        }
        if ($mode === self::MODE_ESCALATE_PREVIEW) {
            return ['promotion_preview persistido com decision_ref'];
        }

        $criteria = [];
        if ($verificationCommands !== []) {
            $criteria[] = 'todos os comandos da verification_plan passam';
        }
        $criteria[] = 'scope_guard nao reporta forbidden_touch';
        $criteria[] = 'nenhum gate required termina em failed';

        return $criteria;
    }

    /**
     * @param  list<string>  $expectedFiles
     */
    public static function buildRollback(string $mode, array $expectedFiles): string
    {
        if ($mode === self::MODE_READ_ONLY
            || $mode === self::MODE_REVIEW
            || $mode === self::MODE_ESCALATE_PREVIEW) {
            return 'sem rollback aplicavel (no patch)';
        }
        if ($expectedFiles === []) {
            return 'git checkout -- .';
        }
        $first = $expectedFiles[0];

        return "git checkout -- {$first}";
    }

    public static function buildGoal(string $normalizedIntent): string
    {
        $intent = trim($normalizedIntent);

        return $intent !== '' ? $intent : 'goal nao inferivel; aguardar clarificacao do operador';
    }

    /**
     * @return list<string>
     */
    public static function buildBlockedActions(): array
    {
        return [
            'production_write',
            'migration_apply',
            'secret_access',
            'broad_refactor',
            'council_invoke',
            'forge_invoke_direct',
        ];
    }

    /**
     * @return list<string>
     */
    public static function buildEvidenceRequired(string $mode): array
    {
        if ($mode === self::MODE_READ_ONLY) {
            return ['context_refs'];
        }
        if ($mode === self::MODE_REVIEW) {
            return ['review_notes', 'context_refs'];
        }
        if ($mode === self::MODE_ESCALATE_PREVIEW) {
            return ['escalation_decision', 'reasons'];
        }

        return [
            'diff_hash',
            'changed_files',
            'test_output_hash',
            'scope_guard_receipt',
            'verification_receipt',
        ];
    }

    public static function resolveIntentText(string $normalizedIntent, string $rawIntent, bool $writeAllowed): string
    {
        $normalized = trim($normalizedIntent);
        if ($normalized !== '') {
            return $normalized;
        }

        if (! $writeAllowed) {
            return '';
        }

        $raw = trim($rawIntent);
        if ($raw !== '') {
            return $raw;
        }

        return 'write_task_intent_unavailable';
    }

    public static function relativise(string $workspace, string $absolute): string
    {
        $workspace = rtrim($workspace, DIRECTORY_SEPARATOR);
        if ($workspace !== '' && str_starts_with($absolute, $workspace.DIRECTORY_SEPARATOR)) {
            return substr($absolute, strlen($workspace) + 1);
        }

        return $absolute;
    }

    public static function mapContextRefKind(string $kind): string
    {
        return match ($kind) {
            ContextRef::KIND_TEST => 'test',
            ContextRef::KIND_SYMBOL => 'symbol',
            ContextRef::KIND_KNOWLEDGE => 'knowledge',
            ContextRef::KIND_DECISION => 'decision',
            ContextRef::KIND_LEARNING => 'learning',
            ContextRef::KIND_TECHNICAL_CONTEXT => 'technical_context',
            ContextRef::KIND_HARNESS_LEARNING => 'harness_learning',
            ContextRef::KIND_MEMORY => 'memory',
            ContextRef::KIND_CODE => 'code',
            default => 'context',
        };
    }

    public static function testRefToPath(string $ref): ?string
    {
        if (str_starts_with($ref, 'file://')) {
            return substr($ref, 7);
        }
        if (str_contains($ref, '://')) {
            return null;
        }

        return $ref;
    }

    public static function phpUnitFilterFromPath(string $path): ?string
    {
        $base = basename($path);
        if (! str_ends_with($base, 'Test.php')) {
            return null;
        }

        return substr($base, 0, -4);
    }

    public static function resolveScopeMode(string $riskLevel): string
    {
        if (in_array($riskLevel, [RiskLevelScorer::R3, RiskLevelScorer::R4, RiskLevelScorer::R5], true)) {
            return self::SCOPE_STRUCTURAL;
        }

        return self::SCOPE_COMPACT;
    }

    public static function maxCandidates(string $riskLevel): int
    {
        return match ($riskLevel) {
            RiskLevelScorer::R0 => 4,
            RiskLevelScorer::R1 => 4,
            RiskLevelScorer::R2 => 6,
            RiskLevelScorer::R3 => 10,
            RiskLevelScorer::R4 => 12,
            RiskLevelScorer::R5 => 12,
            default => 6,
        };
    }

    /**
     * @return list<string>
     */
    public static function resolveEscalationTriggers(string $taskKind, string $riskLevel, bool $writeImplied): array
    {
        if (in_array($riskLevel, [RiskLevelScorer::R4, RiskLevelScorer::R5], true)) {
            return [
                'risk_level_at_or_above_r4',
                'human_action_required',
            ];
        }
        if ($taskKind === TaskClassification::KIND_REPAIR) {
            return [
                'same_signature_failure_twice',
                'diff_grew_without_progress',
                'new_scope_appeared',
            ];
        }
        if ($writeImplied) {
            return [
                'scope_explosion',
                'same_signature_failure_twice',
            ];
        }

        return [];
    }

    /**
     * @param  list<string>  $discoveryForbidden
     * @return list<string>
     */
    public static function forbiddenFilesFor(string $mode, array $discoveryForbidden): array
    {
        $base = ['vendor/*', 'node_modules/*', 'storage/framework/*'];

        $extra = [];
        if ($mode === self::MODE_ESCALATE_PREVIEW) {
            $extra[] = 'database/migrations/*';
            $extra[] = 'config/*';
        }

        $clean = array_filter(
            $discoveryForbidden,
            static fn (string $g): bool => ! str_contains(strtolower($g), '.env'),
        );

        return AtlasDevStringListNormalizer::uniqueTrimmedStrings(array_merge($base, $extra, $clean));
    }

    /**
     * @param  list<string>  $constraints
     */
    public static function constraintValue(array $constraints, string $key): ?string
    {
        $prefix = strtolower($key).'=';
        foreach ($constraints as $constraint) {
            if (! is_string($constraint)) {
                continue;
            }
            $trimmed = trim($constraint);
            if (str_starts_with(strtolower($trimmed), $prefix)) {
                $value = trim(substr($trimmed, strlen($prefix)));

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    public static function normalizeModelFamily(string $provider, string $model): string
    {
        if ($provider !== 'claude_cli') {
            return $model;
        }

        $normalized = strtolower(trim($model));
        if ($normalized === 'sonnet' || str_contains($normalized, 'sonnet')) {
            return 'sonnet';
        }

        return $model;
    }

    /**
     * @return list<string>
     */
    public static function noWriteModes(): array
    {
        return [
            self::MODE_READ_ONLY,
            self::MODE_REVIEW,
            self::MODE_ESCALATE_PREVIEW,
        ];
    }

    public static function writeAllowed(bool $envelopeWriteAllowed, string $mode): bool
    {
        if (! $envelopeWriteAllowed) {
            return false;
        }

        return ! in_array($mode, self::noWriteModes(), true);
    }
}
