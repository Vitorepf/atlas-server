<?php

declare(strict_types=1);

namespace App\Domain\Inbox;

final class InboxRankingService
{
    /**
     * @param  list<array{id:string,age_hours:float,priority:int,starred:bool}>  $items
     * @return list<array{id:string,score:float}>
     */
    public function rank(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            // ---- SCORE BLOCK (extract me into calculateScore) ----
            $recency = max(0.0, 1.0 - ($item['age_hours'] / 168.0)); // 1 week half-life
            $priorityBoost = match (true) {
                $item['priority'] >= 3 => 0.6,
                $item['priority'] === 2 => 0.4,
                $item['priority'] === 1 => 0.2,
                default => 0.0,
            };
            $starBoost = $item['starred'] ? 0.3 : 0.0;
            $score = round(($recency * 0.5) + $priorityBoost + $starBoost, 4);
            // ---- END SCORE BLOCK ----

            $out[] = ['id' => $item['id'], 'score' => $score];
        }

        return $out;
    }
}
