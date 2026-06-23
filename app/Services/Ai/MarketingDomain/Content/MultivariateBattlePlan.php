<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;

/**
 * MultivariateBattlePlan — generates N ORTHOGONAL variants of a bridge so the operator can split-test
 * and learn which AXIS actually moves the needle (not which random page won). Dimensions: angle (big-
 * idea archetype) × hook (opening formula) × awareness (Schwartz level). Each variant runs the
 * orchestrator with the axis pinned, and the plan returns a matrix: variant_id, axis_values, the
 * bridge, the audit. The diff matrix lets the operator A/B-test by AXIS, not just by page. Provider-
 * free. Anti-Goodhart: variants that fail the hollowness gate after amplification are flagged.
 */
class MultivariateBattlePlan
{
    public function __construct(
        private readonly ConversionOrchestrator $orchestrator = new ConversionOrchestrator,
        private readonly AntiGoodhartGuard $guard = new AntiGoodhartGuard,
    ) {}

    /**
     * @param  array<string,mixed>  $opts  angles, hooks, awarenesses (arrays of axis values),
     *                                     until, max_iterations, brand, niche, page_kind
     * @return array{n_variants:int,axes:array<string,array<int,string>>,variants:array<int,array<string,mixed>>,recommended:?array<string,mixed>}
     */
    public function plan(AiMarketingVslAsset $asset, array $opts = []): array
    {
        $angles = (array) ($opts['angles'] ?? ['hidden_cause', 'common_enemy', 'contrarian_truth']);
        $hooks = (array) ($opts['hooks'] ?? ['hook_callout_specific', 'hook_warning']);
        $awarenesses = (array) ($opts['awarenesses'] ?? ['problem_aware', 'solution_aware']);

        $variants = [];
        $vid = 0;
        foreach ($angles as $angle) {
            foreach ($hooks as $hook) {
                foreach ($awarenesses as $aw) {
                    $vid++;
                    $variants[] = $this->buildVariant($vid, $angle, $hook, $aw, $asset, $opts);
                }
            }
        }

        return [
            'n_variants' => count($variants),
            'axes' => ['angle' => array_values($angles), 'hook' => array_values($hooks), 'awareness' => array_values($awarenesses)],
            'variants' => $variants,
            'recommended' => $this->recommend($variants),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildVariant(int $vid, string $angle, string $hook, string $awareness, AiMarketingVslAsset $asset, array $opts): array
    {
        // Pin this axis triple by seeding the asset with awareness override + passing the angle/hook
        // intent into the orchestrator. The amplifier will lean into those when picking what to inject.
        $assetClone = clone $asset;
        $assetClone->awareness_level = $awareness;

        $out = $this->orchestrator->orchestrate($assetClone, [
            'until' => (string) ($opts['until'] ?? 'strong'),
            'max_iterations' => (int) ($opts['max_iterations'] ?? 3),
            'brand' => (string) ($opts['brand'] ?? 'The Daily Wellness Report'),
            'niche' => (string) ($opts['niche'] ?? ''),
            'page_kind' => (string) ($opts['page_kind'] ?? 'bridge'),
            'pin_angle' => $angle,
            'pin_hook' => $hook,
        ]);

        $hollow = $this->guard->inspect($this->copyOf($out['bridge']));

        return [
            'variant_id' => 'v'.$vid,
            'axes' => ['angle' => $angle, 'hook' => $hook, 'awareness' => $awareness],
            'overall_score' => $out['after']['overall_score'],
            'grade' => $out['after']['grade'],
            'hollowness' => $hollow['hollowness'],
            'hollowness_grade' => $hollow['grade'],
            'injected' => $out['injected'],
            'rejected' => $out['rejected'] ?? [],
            'html' => $out['html'],
            'bridge' => $out['bridge'],
            'flagged' => $hollow['hollowness'] >= 50,    // flag thin/hollow variants
        ];
    }

    /**
     * Pick the variant with the highest score among those that pass the hollowness gate.
     *
     * @param  array<int,array<string,mixed>>  $variants
     */
    private function recommend(array $variants): ?array
    {
        $safe = array_filter($variants, fn ($v) => empty($v['flagged']));
        if ($safe === []) {
            return null;
        }
        usort($safe, fn ($a, $b) => ($b['overall_score'] <=> $a['overall_score']));

        return $safe[0];
    }

    /** Flatten the bridge's visible copy for the hollowness check. */
    private function copyOf(array $bridge): string
    {
        $parts = [
            (string) ($bridge['headline'] ?? ''),
            (string) ($bridge['kicker'] ?? ''),
            (string) ($bridge['subheadline'] ?? ''),
            (string) ($bridge['lead_paragraph'] ?? ''),
            (string) ($bridge['ps'] ?? ''),
        ];
        foreach ((array) ($bridge['body_sections'] ?? []) as $s) {
            $parts[] = is_array($s) ? (string) ($s['heading'] ?? '').' '.(string) ($s['body'] ?? $s['text'] ?? '') : (string) $s;
        }
        foreach ((array) ($bridge['cta_blocks'] ?? []) as $c) {
            $parts[] = is_array($c) ? (string) ($c['label'] ?? '') : (string) $c;
        }

        return trim(implode("\n", array_filter($parts)));
    }
}
