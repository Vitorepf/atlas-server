<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * PatternLibrary — the canonical contract every library in the Conversion Pattern OS implements, so a
 * single generic scorer can measure any of them. A library is a set of conversion patterns, each:
 *   key, name, category, weight (leverage), trigger (the human mechanism), lever (how elite copy
 *   deploys it), markers (string or /regex/ used to detect the pattern in a page).
 * One shape → one scorer → every dimension of a funnel measured the same way.
 */
interface PatternLibrary
{
    /** Stable machine name, e.g. 'angle_big_idea', 'persuasion'. */
    public function name(): string;

    /**
     * @return array<int,array{key:string,name:string,category:string,weight:int,trigger:string,lever:string,markers:array<int,string>}>
     */
    public function all(): array;

    /**
     * @return array<int,string>
     */
    public function categories(): array;
}
