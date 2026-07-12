<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use Illuminate\Support\Str;

/**
 * MAXC-03 — task-profile priors for the AOBG source budget.
 *
 * Detects one of three deterministic task profiles from the raw task text —
 * `debug`, `planning`, `ops` — reusing the ALREADY-PROVEN lexicon of
 * {@see ContextRetrievalRouter} (see the `needsCode` / `needsGraph` /
 * `needsEvidence` helpers). Returns a source-budget multiplier PRIOR:
 *
 *   debug     → code ↑ (evidence-heavy work needs code+evidence in the pack)
 *   planning  → memory ↑ (decisions/research need the durable memory)
 *   ops       → code ↑ (touch/patch work is code-first)
 *
 * The prior is composable BELOW the measured `source_selection_policy` (COM-05):
 * whenever the measured policy is `applied_to_initial_pack=true`, it wins. When
 * the measured policy is `insufficient_signal` / `observed` / `inactive`, the
 * prior is used pure. The top-1 floor per present source is untouched — this
 * shifts budget between sources, it NEVER starves a source.
 *
 * Pure: no DB, no provider call, no telemetry side-effect. Deterministic
 * against the input text.
 */
final class TaskProfileBudgetPriorService
{
    public const SCHEMA_VERSION = 'atlas.aobg.task_profile_budget_prior.v1';

    public const PROFILE_DEBUG = 'debug';

    public const PROFILE_PLANNING = 'planning';

    public const PROFILE_OPS = 'ops';

    public const PROFILE_UNKNOWN = 'unknown';

    /**
     * Deterministic multiplier priors per profile. Numbers are bounded [0.7, 1.4]
     * on purpose: this is a nudge, not a knockout. Combined with the top-1
     * floor per section it never zeroes a source (piso inviolável COM-05).
     *
     * @var array<string, array<string, float>>
     */
    private const PROFILE_PRIORS = [
        self::PROFILE_DEBUG => [
            'code' => 1.20,
            'memory' => 0.90,
            'graph' => 1.05,
        ],
        self::PROFILE_PLANNING => [
            'code' => 0.90,
            'memory' => 1.25,
            'graph' => 1.10,
        ],
        self::PROFILE_OPS => [
            'code' => 1.15,
            'memory' => 0.95,
            'graph' => 1.00,
        ],
        self::PROFILE_UNKNOWN => [
            'code' => 1.0,
            'memory' => 1.0,
            'graph' => 1.0,
        ],
    ];

    /**
     * Extra terms MAXC-03 uses on top of the router's lexicon. Kept short and
     * unambiguous — the goal is deterministic classification, not a language
     * model. See `atlas-acos-max-frontier-plan-v1.md` MAXC-03 for the profile
     * semantics.
     *
     * @var array<string, list<string>>
     */
    private const PROFILE_TERMS = [
        self::PROFILE_DEBUG => [
            'bug', 'debug', 'fix', 'falha', 'falhou', 'quebrou', 'quebrando',
            'regressao', 'regressão', 'stacktrace', 'stack trace', 'trace',
            'erro', 'exception', 'race condition', 'flaky', 'crash',
            'reproduzir', 'reproduce',
        ],
        self::PROFILE_PLANNING => [
            'plano', 'planejar', 'planejamento', 'roadmap', 'decisao', 'decisão',
            'arquitetura', 'design', 'desenhar', 'projetar', 'trade-off', 'tradeoff',
            'estrategia', 'estratégia', 'proposta', 'research', 'pesquisa',
            'consolidar', 'consolidação',
        ],
        self::PROFILE_OPS => [
            'deploy', 'rollout', 'migração', 'migracao', 'ops', 'runtime',
            'cadência', 'cadencia', 'schedule', 'watchdog', 'monitorar', 'monitoring',
            'incident', 'incidente', 'ligar', 'desligar', 'rotate', 'rotacao', 'rotação',
        ],
    ];

    /**
     * Classify a task string into one of the deterministic profiles.
     * Ties: debug wins over ops wins over planning (debug is highest-impact
     * on the pack budget; planning is the most defensive profile).
     */
    public function classify(string $task): string
    {
        $text = mb_strtolower(trim($task));
        if ($text === '') {
            return self::PROFILE_UNKNOWN;
        }

        $scores = [];
        foreach (self::PROFILE_TERMS as $profile => $terms) {
            $scores[$profile] = 0;
            foreach ($terms as $t) {
                if (Str::contains($text, $t)) {
                    $scores[$profile]++;
                }
            }
        }

        $max = max($scores);
        if ($max <= 0) {
            return self::PROFILE_UNKNOWN;
        }

        foreach ([self::PROFILE_DEBUG, self::PROFILE_OPS, self::PROFILE_PLANNING] as $profile) {
            if ($scores[$profile] === $max) {
                return $profile;
            }
        }

        return self::PROFILE_UNKNOWN;
    }

    /**
     * @return array<string, float>
     */
    public function priorMultipliers(string $profile): array
    {
        $priors = self::PROFILE_PRIORS[$profile] ?? self::PROFILE_PRIORS[self::PROFILE_UNKNOWN];

        return [
            'code' => (float) $priors['code'],
            'memory' => (float) $priors['memory'],
            'graph' => (float) $priors['graph'],
        ];
    }

    /**
     * Build the delivery-policy dimension recorded in the pack. This is what
     * the pack observability + COM-07 political series read.
     *
     * @return array<string, mixed>
     */
    public function describe(string $task): array
    {
        $profile = $this->classify($task);
        $priors = $this->priorMultipliers($profile);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'profile' => $profile,
            'prior_multipliers' => $priors,
            'applied' => $profile !== self::PROFILE_UNKNOWN,
            'notes' => 'MAXC-03: measured source_selection_policy wins when applied; this prior is used only when measured is inactive/observed/insufficient_signal.',
        ];
    }
}
