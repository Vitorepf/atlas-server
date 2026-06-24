<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Supply;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;

/**
 * §2 · FEATURE-FRONTIER — the SUPPLY lane that ORIGINATES features the operator never asked for but the
 * SCOPE itself DEMANDS. It is one rung past the DocGap lane: DocGap mints the capabilities the doc author
 * already CATALOGUED as gaps (`docStatedGaps`); this lane mints the capabilities the canonical docs PROMISE
 * in prose (`docPurposes`) that NEITHER any inventoried symbol provides NOR any stated gap names — the true
 * frontier the author has not even written down as missing.
 *
 * SHAPE (mirrors DocGap): the lane mints a DIRECTIVE only. The engine authors the capability + a reproduction
 * test that is RED before it exists and GREEN after (red_required ⇒ FrozenJudge Guard 4 diff_earned certifies;
 * a capability whose test was never red proves nothing). The DETERMINISTIC half (which frontier, the red-gated
 * objective) is here; the AUTHORING is model-bound and fenced — never faked.
 *
 * ANTI-GOODHART (load-bearing — the operator was burned by proxy optimization): this lane mints ONLY material
 * red→green feature work. It NEVER mints coverage-only, characterization, behavior-preserving refactor, or any
 * cosmetic/spec-preserving churn. The `purpose` prose is writable-untrusted ({@see AtlasLoopScopeComprehensionModel}):
 * it is used ONLY to NAME a candidate, never as proof — the red→green cert is the sole authority on whether a
 * real, testable capability was actually built. A blank capability/purpose, or one a symbol already provides /
 * a doc-gap already names, is DROPPED (fail-closed, no overlap with the DocGap lane).
 */
final class FeatureFrontierSupplyLane implements SupplyLaneContract
{
    /** A feature-frontier originates a NEW capability ⇒ a red→green feature shape (distinct from DocGap's 'feature'). */
    public const OBJECTIVE_KIND = 'feature_frontier';

    /**
     * Mint one origination directive per distinct feature-frontier capability in the model.
     *
     * @return list<array{objective:string, payload:array<string,mixed>, members:list<never>}>
     */
    public function mint(AtlasLoopScopeComprehensionModel $model, string $repoRoot): array
    {
        // DocGap owns the NAMED gaps — index them so this lane never overlaps that lane.
        $statedGaps = [];
        foreach ($model->docStatedGaps as $gap) {
            $name = trim((string) $gap);
            if ($name !== '') {
                $statedGaps[$name] = true;
            }
        }

        // Dedup by capability (first occurrence wins); ksort gives a deterministic order.
        $byCapability = [];
        foreach ($model->docPurposes as $key => $purposeRaw) {
            $capability = $this->capabilityName((string) $key);
            $purpose = trim((string) $purposeRaw);

            // FAIL-CLOSED: a degenerate entry (empty capability or empty/whitespace purpose) is dropped.
            if ($capability === '' || $purpose === '') {
                continue;
            }
            // The doc author already catalogued this as a gap ⇒ the DocGap lane owns it (no double-supply).
            if (isset($statedGaps[$capability])) {
                continue;
            }
            // A symbol already provides it ⇒ not a frontier (the docs describe an EXISTING capability).
            if ($this->inventoryProvides($model, $capability)) {
                continue;
            }
            // Anti-double-mint: one directive per distinct capability.
            if (isset($byCapability[$capability])) {
                continue;
            }

            $byCapability[$capability] = $this->spec($capability, $purpose);
        }

        ksort($byCapability, SORT_STRING);

        return array_values($byCapability);
    }

    /**
     * The capability short-name for a docPurposes key. The model keys docPurposes by FQCN
     * (fqcn => first-docblock-sentence), so the capability is the segment after the last namespace
     * separator; a key with no separator is already a bare capability name.
     */
    private function capabilityName(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        $pos = strrpos($key, '\\');

        return $pos === false ? $key : substr($key, $pos + 1);
    }

    /**
     * Does ANY inventoried symbol provide this capability? True when an inventory node's path basename
     * (minus .php) equals the capability, OR its FQCN ends with `\{capability}` (or equals it bare).
     */
    private function inventoryProvides(AtlasLoopScopeComprehensionModel $model, string $capability): bool
    {
        foreach ($model->inventory as $item) {
            if (! is_array($item)) {
                continue;
            }
            $relPath = (string) ($item['rel_path'] ?? '');
            if ($relPath !== '' && pathinfo($relPath, PATHINFO_FILENAME) === $capability) {
                return true;
            }
            $fqcn = ltrim((string) ($item['fqcn'] ?? ''), '\\');
            if ($fqcn !== '' && ($fqcn === $capability || str_ends_with($fqcn, '\\'.$capability))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{objective:string, payload:array<string,mixed>, members:list<never>}
     */
    private function spec(string $capability, string $purpose): array
    {
        return [
            'objective' => sprintf(
                'O escopo descreve a capability `%s` ("%s") mas nenhum símbolo provê e nenhum doc-gap a nomeia. '
                .'Originar a feature: red test que falha por inexistência → implementar até verde (Guard 4 diff_earned certifica).',
                $capability,
                $purpose,
            ),
            'payload' => [
                'objective_kind' => self::OBJECTIVE_KIND,
                'source' => 'feature_frontier',
                'capability' => $capability,
                'purpose' => $purpose,
                // The new capability's test must be RED first, GREEN after — an always-green test certifies nothing.
                'red_required' => true,
                'comprehension_originated' => true,      // provenance: the brain, not the proxy scan
                'provenance' => AtlasLoopScopeComprehensionModel::PROVENANCE_WRITABLE_PROSE,
            ],
            'members' => [],
        ];
    }
}
