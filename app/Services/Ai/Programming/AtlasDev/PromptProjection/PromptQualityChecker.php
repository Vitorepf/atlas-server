<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\PromptProjection;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\PromptSections;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\QualityChecks;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;

/**
 * Runs the 6 mandatory quality checks from contracts doc 5.4 over a candidate
 * ProviderPromptProjection. Any failed check blocks the prompt from being sent.
 *
 * Pure function: same (sections, rendered text, task contract) = same verdict.
 */
final class PromptQualityChecker
{
    /**
     * Tokens that signal evaluation/benchmark leakage. Case + Unicode are
     * normalised before matching so "Ríváls", "BENCH-MARK" and "rivals" all hit.
     */
    public const BENCHMARK_TOKENS = [
        'rival',
        'rivals',
        'benchmark',
        'benchmarks',
        'leaderboard',
        'arena battle',
        'opus challenge',
        'opus vs sonnet',
        'sonnet vs opus',
        'forge battle',
        'messy human local',
        'messy_human_local',
        'beat the model',
        'beat opus',
        'beat sonnet',
        'beat claude',
        'win the benchmark',
        'score competitivo',
        'arena corpus',
        'provider arena',
        'compete against',
    ];

    public const FORGE_COUNCIL_TOKENS = [
        'forge',
        'forge battle',
        'council',
        'council session',
        'open council',
        'rival council',
        'forge invoke',
        'invoke forge',
        'invoke council',
        'topology rival',
    ];

    public const PROVIDER_UNSAFE_TOKENS = [
        'api key',
        'api_key',
        'authorization: bearer',
        'bearer ey',
        'sk-ant-',
        'anthropic_api_key',
        'openai_api_key',
        'aws_secret_access_key',
        '.env',
        'password=',
        'secret=',
        'private_key',
    ];

    public const UNBOUNDED_SCOPE_TOKENS = [
        'all files',
        'todos os arquivos',
        'qualquer arquivo',
        'refactor the whole',
        'refatorar tudo',
        'reescrever tudo',
        'rewrite everything',
        'mude o que precisar',
        'sem limite de arquivos',
        'no scope limit',
    ];

    public function check(
        PromptSections $sections,
        string $renderedPromptText,
        LightTaskContract $taskContract,
        bool $providerSafeRequested = true,
    ): QualityChecks {
        $haystack = $this->buildHaystack($sections, $renderedPromptText);
        $instructionHaystack = $this->buildInstructionHaystack($sections);

        return new QualityChecks(
            noMissingRequiredSections: $this->checkRequiredSections($sections, $taskContract),
            noUnboundedScope: $this->checkUnboundedScope($sections, $taskContract, $haystack),
            noHiddenBenchmarkInstruction: $this->checkNoBenchmarkLeakage($haystack),
            noConflictingFileRules: $this->checkNoConflictingFileRules($sections),
            noForgeOrCouncilLeakage: $this->checkNoForgeOrCouncilLeakage($instructionHaystack),
            providerSafe: $providerSafeRequested
                && $this->checkProviderSafe($sections, $haystack)
                && $this->checkProviderLockFallbackForbidden($taskContract),
        );
    }

    private function checkRequiredSections(PromptSections $sections, LightTaskContract $taskContract): bool
    {
        if (! $sections->hasAllRequiredSections()) {
            return false;
        }

        if ($sections->operatingRules === []) {
            return false;
        }

        if ($sections->acceptanceCriteria === []) {
            return false;
        }

        if ($sections->stopConditions === []) {
            return false;
        }

        if ($sections->outputContract === []) {
            return false;
        }

        // Write-capable contracts must declare at least one allowed file. A
        // missing allowed_files list under a write contract = unbounded write.
        if ($taskContract->allowsWrite() && $sections->allowedFiles === []) {
            return false;
        }

        // Write-capable contracts must declare non_goals so the model sees the
        // explicit scope boundary. A write prompt with empty non_goals is a
        // scope leak waiting to happen — the model only sees "do X" without
        // "do not do Y".
        if ($taskContract->allowsWrite() && $sections->nonGoals === []) {
            return false;
        }

        return true;
    }

    /**
     * Atlas Dev fast path requires `fallback_allowed=false` on the provider
     * lock. A prompt projected against a contract that permits fallback is
     * not provider-safe to send: the lock is the only thing that prevents
     * the run from silently rerouting to another engine, and the receipt
     * wouldn't be able to honestly record which provider/model actually ran.
     */
    private function checkProviderLockFallbackForbidden(LightTaskContract $taskContract): bool
    {
        return $taskContract->providerLock->fallbackAllowed === false;
    }

    private function checkUnboundedScope(
        PromptSections $sections,
        LightTaskContract $taskContract,
        string $haystack,
    ): bool {
        if ($taskContract->allowsWrite() && $sections->allowedFiles === []) {
            return false;
        }

        if ($taskContract->maxFilesChanged > 0
            && count($sections->allowedFiles) > $taskContract->maxFilesChanged * 4) {
            // Sanity: prompt should not declare 4x more files than the task
            // contract permits to change. Detects accidental scope explosion.
            return false;
        }

        return ! $this->containsAny($haystack, self::UNBOUNDED_SCOPE_TOKENS);
    }

    private function checkNoBenchmarkLeakage(string $haystack): bool
    {
        $haystack = $this->redactPathLikeTokens($haystack);
        $haystack = $this->redactBenchmarkCodeIdentifierTokens($haystack);

        return ! $this->containsAny($haystack, self::BENCHMARK_TOKENS);
    }

    private function checkNoConflictingFileRules(PromptSections $sections): bool
    {
        return ! $sections->hasFileRuleConflict();
    }

    private function checkNoForgeOrCouncilLeakage(string $haystack): bool
    {
        $haystack = $this->redactPathLikeTokens($haystack);
        $haystack = $this->redactCodeIdentifierTokens($haystack);

        return ! $this->containsAny($haystack, self::FORGE_COUNCIL_TOKENS);
    }

    private function checkProviderSafe(PromptSections $sections, string $haystack): bool
    {
        if (! $sections->isProviderSafe()) {
            return false;
        }

        return ! $this->containsAny($haystack, self::PROVIDER_UNSAFE_TOKENS);
    }

    private function buildHaystack(PromptSections $sections, string $renderedPromptText): string
    {
        $parts = [
            $sections->objective,
            implode("\n", $sections->operatingRules),
            implode("\n", $sections->contextRefs),
            implode("\n", $sections->allowedFiles),
            implode("\n", $sections->forbiddenFiles),
            implode("\n", $sections->expectedTests),
            implode("\n", $sections->acceptanceCriteria),
            implode("\n", $sections->stopConditions),
            implode("\n", $sections->escalationConditions),
            implode("\n", $sections->outputContract),
            $renderedPromptText,
        ];

        return $this->normalise(implode("\n", $parts));
    }

    private function buildInstructionHaystack(PromptSections $sections): string
    {
        $parts = [
            $sections->objective,
            implode("\n", $sections->operatingRules),
            implode("\n", $sections->contextRefs),
            implode("\n", $sections->allowedFiles),
            implode("\n", $sections->forbiddenFiles),
            implode("\n", $sections->expectedTests),
            implode("\n", $sections->acceptanceCriteria),
            implode("\n", $sections->stopConditions),
            implode("\n", $sections->escalationConditions),
            implode("\n", $sections->outputContract),
        ];

        return $this->normalise(implode("\n", $parts));
    }

    /**
     * @param  list<string>  $tokens
     */
    private function containsAny(string $haystack, array $tokens): bool
    {
        foreach ($tokens as $token) {
            $needle = $this->normalise($token);
            if ($needle === '') {
                continue;
            }
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function redactPathLikeTokens(string $haystack): string
    {
        return (string) preg_replace(
            '~(?<![A-Za-z0-9_])(?:[A-Za-z0-9_.@:+-]+/){1,}[A-Za-z0-9_.@:+-]+~',
            '[path]',
            $haystack,
        );
    }

    private function redactCodeIdentifierTokens(string $haystack): string
    {
        return (string) preg_replace(
            '~\b[A-Za-z_][A-Za-z0-9_]*Forge[A-Za-z0-9_]*\b~i',
            '[identifier]',
            $haystack,
        );
    }

    private function redactBenchmarkCodeIdentifierTokens(string $haystack): string
    {
        return (string) preg_replace(
            '~\b[A-Za-z_][A-Za-z0-9_]*(?:Benchmark|Rivals?)[A-Za-z0-9_]*\b~i',
            '[identifier]',
            $haystack,
        );
    }

    /**
     * Lowercase + Unicode-normalise (NFKD) + strip combining marks. Lets
     * "Rivals", "RIVALS" and "Ríváls" all collide on the same haystack.
     */
    private function normalise(string $value): string
    {
        $value = (string) mb_strtolower($value, 'UTF-8');

        if (class_exists(\Normalizer::class)) {
            $normalised = \Normalizer::normalize($value, \Normalizer::FORM_KD);
            if (is_string($normalised)) {
                $value = $normalised;
            }
        }

        $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;

        return $value;
    }
}
