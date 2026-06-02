<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrain\RecallTrigger;

/**
 * Detects the legitimate no-recall exceptions for the atlas_memory_recall path.
 *
 * Mirrors the CLAUDE.md exception list: renaming inside a single function,
 * typo / whitespace edits, purely operational commands, and meta questions
 * about Atlas itself never require a memory recall before acting.
 *
 * Pure, deterministic, first-match ordered (rename > typo > operational > meta).
 */
final class RecallExceptionDetector
{
    private const EXCEPTION_RENAME = 'rename_in_function';

    private const EXCEPTION_TYPO = 'typo_or_whitespace';

    private const EXCEPTION_OPERATIONAL = 'operational_command';

    private const EXCEPTION_META = 'meta_about_atlas';

    /**
     * @var array<int, string>
     */
    private const RENAME_VERBS = ['rename', 'renomear', 'mudar nome'];

    /**
     * @var array<int, string>
     */
    private const RENAME_TARGETS = ['function', 'method', 'variable', 'funcao'];

    /**
     * @var array<int, string>
     */
    private const TYPO_CUES = ['typo', 'erro de digitacao', 'whitespace', 'indent', 'espaco', 'identacao'];

    /**
     * @var array<int, string>
     */
    private const OPERATIONAL_VERBS = ['run', 'exec', 'rodar', 'executar', 'git', 'artisan', 'npm', 'ls'];

    /**
     * @var array<int, string>
     */
    private const META_INTERROGATIVES = ['what', 'how', 'o que', 'como'];

    /**
     * @var array<int, string>
     */
    private const META_SUBJECTS = ['atlas', 'memory', 'memoria', 'governance'];

    /**
     * @return array{is_exception: bool, exception: string|null, reason: string|null}
     */
    public function detect(string $description): array
    {
        $normalized = strtolower(trim($description));

        if ($this->matchesRename($normalized)) {
            return $this->exception(self::EXCEPTION_RENAME);
        }

        if ($this->matchesTypo($normalized)) {
            return $this->exception(self::EXCEPTION_TYPO);
        }

        if ($this->matchesOperational($normalized)) {
            return $this->exception(self::EXCEPTION_OPERATIONAL);
        }

        if ($this->matchesMeta($normalized)) {
            return $this->exception(self::EXCEPTION_META);
        }

        return [
            'is_exception' => false,
            'exception' => null,
            'reason' => null,
        ];
    }

    private function matchesRename(string $normalized): bool
    {
        return $this->containsAny($normalized, self::RENAME_VERBS)
            && $this->containsAny($normalized, self::RENAME_TARGETS);
    }

    private function matchesTypo(string $normalized): bool
    {
        return $this->containsAny($normalized, self::TYPO_CUES);
    }

    private function matchesOperational(string $normalized): bool
    {
        $leadingVerb = $this->leadingToken($normalized);

        return $leadingVerb !== '' && in_array($leadingVerb, self::OPERATIONAL_VERBS, true);
    }

    private function matchesMeta(string $normalized): bool
    {
        if (! $this->containsAny($normalized, self::META_SUBJECTS)) {
            return false;
        }

        $isQuestionShape = str_contains($normalized, '?');

        return $isQuestionShape || $this->containsAny($normalized, self::META_INTERROGATIVES);
    }

    /**
     * @return array{is_exception: bool, exception: string, reason: string}
     */
    private function exception(string $name): array
    {
        return [
            'is_exception' => true,
            'exception' => $name,
            'reason' => $name,
        ];
    }

    private function leadingToken(string $normalized): string
    {
        if ($normalized === '') {
            return '';
        }

        $tokens = preg_split('/\s+/', $normalized, 2);

        if ($tokens === false || ! isset($tokens[0])) {
            return '';
        }

        return $tokens[0];
    }

    /**
     * @param array<int, string> $needles
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
}
