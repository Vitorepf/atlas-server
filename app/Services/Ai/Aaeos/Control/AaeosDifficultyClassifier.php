<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

/**
 * Classifies objective difficulty L0–L5. Policy-pure.
 */
final class AaeosDifficultyClassifier
{
    public const SCHEMA = 'atlas.aaeos.difficulty.v1';

    /**
     * @param  array<string,mixed>  $objective  from AaeosIntentCompiler
     * @return array{schema:string,level:int,label:string,reasons:list<string>}
     */
    public function classify(array $objective): array
    {
        $text = strtolower((string) ($objective['objective'] ?? ''));
        $reasons = [];
        $level = AaeosDifficultyLevel::L1;

        if ((bool) ($objective['multi_packet'] ?? false)
            || (bool) preg_match('/\b(obra|multi[- ]?day|weeks?|months?)\b/', $text)) {
            $level = AaeosDifficultyLevel::L4;
            $reasons[] = 'multi_packet_or_long_horizon';
        }

        if ((bool) preg_match('/\b(kernel|distributed|consensus|cryptograph|security\s+hard|correctness|formal)\b/', $text)
            || (bool) ($objective['irreversible'] ?? false)) {
            $level = AaeosDifficultyLevel::L5;
            $reasons[] = 'frontier_or_irreversible_markers';
        }

        if ($level < AaeosDifficultyLevel::L4
            && ((int) ($objective['modules_hint'] ?? 0) >= 3
                || (bool) preg_match('/\b(migration|cross[- ]module|refactor\s+subsystem)\b/', $text))) {
            $level = AaeosDifficultyLevel::L3;
            $reasons[] = 'cross_module_or_migration';
        }

        if ($level <= AaeosDifficultyLevel::L1
            && (bool) preg_match('/\b(feature|endpoint|command|dto|new\s+service)\b/', $text)) {
            $level = AaeosDifficultyLevel::L2;
            $reasons[] = 'bounded_feature_markers';
        }

        if ((bool) preg_match('/\b(typo|whitespace|rename|comment[- ]only)\b/', $text)
            && $level <= AaeosDifficultyLevel::L1) {
            $level = AaeosDifficultyLevel::L0;
            $reasons[] = 'typo_rename_markers';
        }

        if ($reasons === []) {
            $reasons[] = 'default_local_bug_or_task';
        }

        return [
            'schema' => self::SCHEMA,
            'level' => $level,
            'label' => AaeosDifficultyLevel::label($level),
            'reasons' => $reasons,
        ];
    }
}
