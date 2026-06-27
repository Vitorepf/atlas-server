<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * AUTHOR/JUDGE OVERLAP CHECK — adversarial-critique organ. Given the list of files an author
 * modified and the list of files owned by the gate/judge that approved the work, returns whether
 * the two intersect. Non-empty intersection = Goodhart violation: the author edited the very
 * code that scored its own work. Pure set intersection.
 *
 * No IO. Pétreo: réu would suppress the overlap so its self-judging slipped through.
 */
final class AtlasBrainAuthorJudgeOverlapCheck
{
    public const SCHEMA = 'atlas.brain.author_judge_overlap_check.v1';

    /**
     * @param  list<string>  $authorChangedFiles
     * @param  list<string>  $judgeOwnedFiles
     * @return array{schema:string, violation:bool, overlap:list<string>}
     */
    public function check(array $authorChangedFiles, array $judgeOwnedFiles): array
    {
        $author = array_unique(array_map(static fn ($p) => (string) $p, $authorChangedFiles));
        $judge = array_unique(array_map(static fn ($p) => (string) $p, $judgeOwnedFiles));
        $overlap = array_values(array_intersect($author, $judge));

        return ['schema' => self::SCHEMA, 'violation' => count($overlap) > 0, 'overlap' => $overlap];
    }
}
