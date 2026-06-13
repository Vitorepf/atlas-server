<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

final class HandoffCompletenessScorer
{
    /**
     * @param  array<int, string>  $present
     * @param  array<int, string>  $required
     * @return array{ratio: float, status: string}
     */
    public function score(array $present, array $required): array
    {
        $distinctRequired = $this->distinctTypes($required);

        if ($distinctRequired === []) {
            return ['ratio' => 1.0, 'status' => 'ready'];
        }

        $presentSet = $this->distinctTypes($present);

        $matched = 0;
        foreach ($distinctRequired as $type => $isRequired) {
            if (isset($presentSet[$type])) {
                $matched++;
            }
        }

        $ratio = (float) $matched / (float) count($distinctRequired);

        return [
            'ratio' => $ratio,
            'status' => $ratio === 1.0 ? 'ready' : 'blocked',
        ];
    }

    /**
     * @param  array<int, string>  $types
     * @return array<string, true>
     */
    private function distinctTypes(array $types): array
    {
        $distinct = [];

        foreach ($types as $type) {
            $trimmed = trim((string) $type);

            if ($trimmed === '') {
                continue;
            }

            $distinct[strtolower($trimmed)] = true;
        }

        return $distinct;
    }
}
