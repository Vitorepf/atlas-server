<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Semantic;

final class AtlasMaestroSemanticRejectionReceipt
{
    /**
     * @param  array<string,mixed>  $panelResult
     */
    public function compose(string $packetId, array $panelResult): string
    {
        if (($panelResult['pass'] ?? false) === true) {
            return $this->encode([
                'error' => 'semantic_rejection_receipt_requires_failed_panel',
                'packet_id' => $packetId,
            ]);
        }

        $votes = array_values(array_map('boolval', (array) ($panelResult['votes'] ?? [])));
        $receipt = [
            'packet_id' => $packetId,
            'ts_utc' => (string) ($panelResult['ts_utc'] ?? '1970-01-01T00:00:00Z'),
            'panel_votes' => array_slice(array_pad($votes, 3, false), 0, 3),
            'rejected_voters' => $this->rejectedVoters($panelResult),
        ];

        foreach (['offending_symbol', 'defining_file', 'sibling_observed', 'expected_role_tokens'] as $key) {
            if (array_key_exists($key, $panelResult)) {
                $receipt[$key] = $panelResult[$key];
            }
        }
        if (! array_intersect(['offending_symbol', 'defining_file', 'sibling_observed', 'expected_role_tokens'], array_keys($receipt))) {
            $receipt['offending_symbol'] = 'unknown';
        }

        $receipt['respec_suggestion'] = $this->respecSuggestion($receipt['rejected_voters']);

        return $this->encode($receipt);
    }

    /**
     * @param  array<string,string>  $rejectedVoters
     */
    private function respecSuggestion(array $rejectedVoters): string
    {
        $map = [
            'allowed_files_intent' => 'widen_allowed_files',
            'orphan_caller'        => 'fix_wiring',
            'acceptance_symbol'    => 'fix_symbol_resolution',
        ];
        $suggestions = [];
        foreach ($map as $voter => $suggestion) {
            if (isset($rejectedVoters[$voter])) {
                $suggestions[] = $suggestion;
            }
        }

        return $suggestions !== [] ? implode('+', $suggestions) : 'respec_not_determined';
    }

    /**
     * @param  array<string,mixed>  $panelResult
     * @return array<string,string>
     */
    private function rejectedVoters(array $panelResult): array
    {
        $names = ['allowed_files_intent', 'orphan_caller', 'acceptance_symbol'];
        $votes = array_values(array_map('boolval', (array) ($panelResult['votes'] ?? [])));
        $reasons = (array) ($panelResult['voter_reasons'] ?? []);
        $out = [];

        foreach ($names as $index => $name) {
            if (($votes[$index] ?? false) === false) {
                $out[$name] = (string) ($reasons[$name] ?? 'voter_rejected');
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $payload = $this->sortKeys($payload);

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    private function sortKeys(array $value): array
    {
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = $this->isList($child)
                    ? array_map(fn (mixed $item): mixed => is_array($item) ? $this->sortKeys($item) : $item, $child)
                    : $this->sortKeys($child);
            }
        }
        if (! $this->isList($value)) {
            ksort($value);
        }

        return $value;
    }

    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }
}
