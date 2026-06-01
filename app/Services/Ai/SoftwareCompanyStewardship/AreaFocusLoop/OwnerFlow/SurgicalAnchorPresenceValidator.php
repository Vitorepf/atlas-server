<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

final class SurgicalAnchorPresenceValidator
{
    /**
     * @param  array<string,mixed>  $finding
     * @return array{decision:'anchor_concrete'|'anchor_missing_or_vague', matched_path:?string, reason:string}
     */
    public function validate(array $finding): array
    {
        foreach ($this->anchorPaths() as $path) {
            $value = data_get($finding, $path);
            if (! is_string($value) || ! $this->isConcreteNarrowAnchor($path, $value)) {
                continue;
            }

            return [
                'decision' => 'anchor_concrete',
                'matched_path' => $path,
                'reason' => 'Concrete surgical anchor found at '.$path.'.',
            ];
        }

        return [
            'decision' => 'anchor_missing_or_vague',
            'matched_path' => null,
            'reason' => 'No concrete target_method, target_symbol, line_anchor, surgical_anchor, or mutation_anchor was found.',
        ];
    }

    /** @return list<string> */
    private function anchorPaths(): array
    {
        return [
            'target_method',
            'target_symbol',
            'method_anchor',
            'symbol_anchor',
            'line_anchor',
            'surgical_anchor',
            'mutation_anchor',
            'self_construction_packet.target_method',
            'self_construction_packet.target_symbol',
            'self_construction_packet.method_anchor',
            'self_construction_packet.surgical_anchor',
            'self_construction_packet.task_packet.target_method',
            'self_construction_packet.task_packet.target_symbol',
            'self_construction_packet.task_packet.surgical_anchor',
            'self_construction_packet.task_packet.continuation_context.target_method',
            'self_construction_packet.task_packet.continuation_context.target_symbol',
        ];
    }

    private function isConcreteNarrowAnchor(string $path, string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (in_array($path, [
            'target_method',
            'method_anchor',
            'line_anchor',
            'self_construction_packet.target_method',
            'self_construction_packet.method_anchor',
            'self_construction_packet.task_packet.target_method',
            'self_construction_packet.task_packet.continuation_context.target_method',
        ], true)) {
            return true;
        }

        if (str_contains($path, 'target_symbol') || str_contains($path, 'symbol_anchor')) {
            return $this->looksLikeConcreteSymbol($value);
        }

        if (str_contains($path, 'surgical_anchor') || str_contains($path, 'mutation_anchor')) {
            if (preg_match('/(?:^|[;\s])(?:target_)?method\s*:\s*[^;\s]+/i', $value) === 1
                || preg_match('/(?:^|[;\s])line(?:_anchor)?\s*:\s*\d+/i', $value) === 1) {
                return true;
            }

            if (preg_match('/(?:^|[;\s])(?:target_)?symbol\s*:\s*([^;]+)/i', $value, $match) === 1) {
                return $this->looksLikeConcreteSymbol(trim((string) $match[1]));
            }
        }

        return false;
    }

    private function looksLikeConcreteSymbol(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, 'runtime_signal:')) {
            return false;
        }

        return str_contains($value, '::')
            || str_contains($value, '->')
            || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\([^)]*\)\z/', $value) === 1
            || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $value) === 1;
    }
}
