<?php

namespace App\Services\Ai\Programming\Governance;

use Illuminate\Support\Str;

/**
 * Classifies a raw programming intent into intent_type, scope_mode and risk_level.
 *
 * Pure logic — no DB, no IO. Heuristic keyword matching in PT/EN.
 * Explicit operator overrides via $options always win over heuristic.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
 */
class ProgrammingWorkItemClassifier
{
    /**
     * Keyword sets keyed by intent_type. Order matters: more specific types come first
     * so that "Criar migration" is classified as migration, not feature.
     *
     * @var array<string,list<string>>
     */
    private const INTENT_KEYWORDS = [
        'self_construction' => ['self construct', 'self-construct', 'auto-constru', 'autoconstru', 'self-improve'],
        'cartography' => ['cartograf', 'cartography', 'topologia', 'topology'],
        'migration' => ['migration', 'migracao', 'migração', 'alter table', 'create table', 'drop column', 'schema'],
        'test' => ['testes', 'unit test', 'phpunit', 'pest', 'cobertura', 'coverage', 'test '],
        'architecture' => ['architecture', 'arquitetura', 'redesenh', 'redesign', 'restructure', 'reestrutur', 'extract service', 'extrair serviço', 'extrair servico'],
        'docs' => ['documenta', 'documentação', 'readme', 'markdown', 'docs ', ' docs', 'manual ', 'guia '],
        'refactor' => ['refactor', 'refator', 'refatore', 'refatoracao', 'refatoração', 'cleanup', 'limpa'],
        'bugfix' => ['bugfix', 'bug ', ' bug', 'conserte', 'corrija', 'corrigir', 'falha', 'erro', 'crash', 'broken', 'quebrad', 'fix '],
        'feature' => ['feature', 'add ', 'adicionar', 'adicionando', 'novo ', 'nova ', 'criar', 'implement', 'introduz', 'introduce'],
    ];

    /** @var list<string> */
    private const STRUCTURAL_SIGNALS = [
        'arquitetura', 'architecture',
        'schema', 'migration', 'migracao', 'migração',
        'security', 'seguranca', 'segurança', 'vulnerab',
        'multi-arquivo', 'multiarquivo', 'cross-module', 'cross-cutting',
        'kernel', 'core',
        'provider', 'runtime',
        'breaking change', 'breaking',
        'refactor', 'refator', 'reestrutur', 'redesign', 'redesenh',
        'self construct', 'self-construct',
    ];

    /** @var list<string> */
    private const HIGH_RISK_SIGNALS = [
        'security', 'seguranca', 'segurança',
        'schema', 'migration', 'migracao', 'migração',
        'production', 'producao', 'produção',
        'breaking change', 'breaking',
        'auth', 'autenticacao', 'autenticação',
        'payment', 'pagamento',
        'core', 'kernel',
    ];

    /**
     * @param  array<string,mixed>  $options
     * @return array{intent_type:string,scope_mode:string,risk_level:string,signals:array<string,list<string>>}
     */
    public function classify(string $intent, array $options = []): array
    {
        $normalized = $this->normalize($intent);

        $intentType = $this->resolveIntentType($normalized, $options);
        $scopeMode = $this->resolveScopeMode($normalized, $intentType, $options);
        $riskLevel = $this->resolveRiskLevel($normalized, $intentType, $scopeMode, $options);

        return [
            'intent_type' => $intentType,
            'scope_mode' => $scopeMode,
            'risk_level' => $riskLevel,
            'signals' => [
                'structural_matches' => $this->matchedKeywords($normalized, self::STRUCTURAL_SIGNALS),
                'high_risk_matches' => $this->matchedKeywords($normalized, self::HIGH_RISK_SIGNALS),
            ],
        ];
    }

    private function normalize(string $text): string
    {
        return strtolower(Str::ascii($text));
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function resolveIntentType(string $normalized, array $options): string
    {
        $override = $this->stringOption($options, 'type');
        if ($override !== null && array_key_exists($override, self::INTENT_KEYWORDS)) {
            return $override;
        }
        if ($override !== null) {
            return 'other';
        }

        foreach (self::INTENT_KEYWORDS as $type => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($normalized, $needle)) {
                    return $type;
                }
            }
        }

        return 'other';
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function resolveScopeMode(string $normalized, string $intentType, array $options): string
    {
        $override = $this->stringOption($options, 'mode');
        if ($override === 'compact' || $override === 'structural') {
            return $override;
        }

        if (in_array($intentType, ['architecture', 'migration', 'self_construction', 'cartography'], true)) {
            return ProgrammingScopeMode::Structural->value;
        }

        if ($this->containsAny($normalized, self::STRUCTURAL_SIGNALS)) {
            return ProgrammingScopeMode::Structural->value;
        }

        if (in_array($intentType, ['refactor', 'feature'], true)) {
            return ProgrammingScopeMode::Structural->value;
        }

        return ProgrammingScopeMode::Compact->value;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function resolveRiskLevel(string $normalized, string $intentType, string $scopeMode, array $options): string
    {
        $override = $this->stringOption($options, 'risk');
        if (in_array($override, ['low', 'medium', 'high', 'critical'], true)) {
            return (string) $override;
        }

        if ($this->containsAny($normalized, self::HIGH_RISK_SIGNALS)) {
            return 'high';
        }

        if ($scopeMode === ProgrammingScopeMode::Structural->value) {
            return $intentType === 'docs' ? 'low' : 'medium';
        }

        return 'low';
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $needles
     * @return list<string>
     */
    private function matchedKeywords(string $haystack, array $needles): array
    {
        return array_values(array_filter($needles, static fn (string $needle): bool => str_contains($haystack, $needle)));
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function stringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : strtolower($trimmed);
    }
}
