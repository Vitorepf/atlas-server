<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Skill Pack Canonical decider.
 *
 * Pure, deterministic runtime for the `atlas.skill_pack.v1` contract. The doc is
 * explicit that it does NOT implement skills — it defines the canonical schema of
 * a local-first skill pack and the single gate that decides whether a skill may be
 * promoted from the user namespace into the core namespace ("O que este doc NAO e:
 * Nao implementa skills; define contrato").
 *
 * This service answers two questions without lying:
 *
 *  1. validateSchema(): does a skill-pack record satisfy the `atlas.skill_pack.v1`
 *     schema (doc "Contratos > Schema")? It enforces every required key, the closed
 *     `namespace` set {core, user}, the closed `sovereignty_class` set
 *     {ok_to_share, sensitive, secret, cyber}, the `skill_id` shape
 *     `atlas.skill.<core|user>.<name>` with the id-namespace matching the declared
 *     namespace, and the `version` shape `v<int>`.
 *
 *  2. evaluatePromotion(): may a `user` skill be promoted to `core`? The doc states
 *     the gate verbatim (doc "Promotion gate user -> core" + "Regras para IA"):
 *       - 30 USES CONSECUTIVOS sem failure (uses_count >= 30 AND failure_count == 0),
 *       - sovereignty_class NOT in {sensitive, secret, cyber} — those NEVER promote,
 *       - Architect review approval registrado,
 *       - 0 incident registrado.
 *     Only a `user` skill is eligible (a `core` skill is already promoted). When and
 *     only when every condition holds, the verdict is `promote_core` and the new
 *     core skill_id / namespace are projected; otherwise the flow routes to
 *     `hold_user` (threshold not met) or `refine` (Architect rejected / incident /
 *     sovereignty-locked) and names every blocking reason.
 *
 * The service is pure: it consumes an already-normalized array and emits a verdict.
 * It never reads a doc, runs a harness, touches a DB, or performs any upload — the
 * marketplace is "100% local. Nenhum upload externo." (doc "Marketplace pessoal").
 *
 * @see docs/engineering-knowledge-base/atlas-skill-pack-canonical.md
 */
final class AtlasSkillPackCanonicalService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.aaeos.skill_pack_canonical.v1';

    /** The schema header a skill-pack record must carry (doc "Schema"). */
    public const SKILL_PACK_SCHEMA_ID = 'atlas.skill_pack.v1';

    /** Closed `namespace` set (doc schema). */
    public const NAMESPACES = ['core', 'user'];

    /** Closed `sovereignty_class` set (doc schema). */
    public const SOVEREIGNTY_CLASSES = ['ok_to_share', 'sensitive', 'secret', 'cyber'];

    /**
     * Sovereignty classes that can NEVER be promoted to core (doc "Regras para IA":
     * "Sovereignty sensitive/secret/cyber NUNCA promove para core.").
     *
     * @var array<int,string>
     */
    public const PROMOTION_LOCKED_SOVEREIGNTY = ['sensitive', 'secret', 'cyber'];

    /** Consecutive failure-free uses required for promotion (doc gate). */
    public const REQUIRED_CONSECUTIVE_USES = 30;

    /** A skill that fails ANY use breaks the consecutive streak (doc gate). */
    public const MAX_FAILURES_FOR_PROMOTION = 0;

    /** Incidents that block promotion (doc gate: "0 incident registrado."). */
    public const MAX_INCIDENTS_FOR_PROMOTION = 0;

    /** Required top-level keys of `atlas.skill_pack.v1` (doc schema). */
    public const REQUIRED_FIELDS = [
        'schema',
        'skill_id',
        'version',
        'namespace',
        'human_name',
        'purpose',
        'code_path',
        'sovereignty_class',
    ];

    /** Closed set of promotion verdicts. */
    public const VERDICT_PROMOTE_CORE = 'promote_core';
    public const VERDICT_HOLD_USER = 'hold_user';
    public const VERDICT_REFINE = 'refine';

    /**
     * Validate a skill-pack record against the `atlas.skill_pack.v1` schema.
     *
     * @param array{
     *   schema?:string,
     *   skill_id?:string,
     *   version?:string,
     *   namespace?:string,
     *   human_name?:string,
     *   purpose?:string,
     *   code_path?:string,
     *   sovereignty_class?:string,
     *   tags?:array<int,string>
     * } $pack
     * @return array{
     *   schema:string,
     *   schema_valid:bool,
     *   errors:array<string,string>,
     *   checked_fields:int
     * }
     */
    public function validateSchema(array $pack): array
    {
        $errors = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            $value = $pack[$field] ?? null;
            if (! is_string($value) || trim($value) === '') {
                $errors[$field] = "required field '{$field}' missing or empty";
            }
        }

        $declaredSchema = (string) ($pack['schema'] ?? '');
        if ($declaredSchema !== '' && $declaredSchema !== self::SKILL_PACK_SCHEMA_ID) {
            $errors['schema'] = sprintf(
                "schema must be '%s'; got '%s'",
                self::SKILL_PACK_SCHEMA_ID,
                $declaredSchema,
            );
        }

        $namespace = (string) ($pack['namespace'] ?? '');
        if ($namespace !== '' && ! in_array($namespace, self::NAMESPACES, true)) {
            $errors['namespace'] = sprintf(
                "namespace must be one of [%s]; got '%s'",
                implode(',', self::NAMESPACES),
                $namespace,
            );
        }

        $sovereignty = (string) ($pack['sovereignty_class'] ?? '');
        if ($sovereignty !== '' && ! in_array($sovereignty, self::SOVEREIGNTY_CLASSES, true)) {
            $errors['sovereignty_class'] = sprintf(
                "sovereignty_class must be one of [%s]; got '%s'",
                implode(',', self::SOVEREIGNTY_CLASSES),
                $sovereignty,
            );
        }

        $version = (string) ($pack['version'] ?? '');
        if ($version !== '' && ! preg_match('/^v\d+$/', $version)) {
            $errors['version'] = sprintf("version '%s' must follow 'v<int>' (e.g. v1)", $version);
        }

        // skill_id shape: atlas.skill.<core|user>.<name>, and its namespace segment
        // must agree with the declared `namespace` (doc: "Skill nova esta em
        // namespace atlas.skill.user.* ate promocao para atlas.skill.core.*").
        $skillId = (string) ($pack['skill_id'] ?? '');
        if ($skillId !== '') {
            if (! preg_match('/^atlas\.skill\.(core|user)\.[a-z0-9_]+$/', $skillId, $m)) {
                $errors['skill_id'] = sprintf(
                    "skill_id '%s' must match atlas.skill.<core|user>.<name>",
                    $skillId,
                );
            } elseif ($namespace !== '' && $m[1] !== $namespace) {
                $errors['skill_id'] = sprintf(
                    "skill_id namespace '%s' disagrees with declared namespace '%s'",
                    $m[1],
                    $namespace,
                );
            }
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'schema_valid' => $errors === [],
            'errors' => $errors,
            'checked_fields' => count(self::REQUIRED_FIELDS),
        ];
    }

    /**
     * Decide whether a `user` skill may be promoted to `core`.
     *
     * @param array{
     *   skill_id?:string,
     *   namespace?:string,
     *   sovereignty_class?:string,
     *   uses_count?:int,
     *   failure_count?:int,
     *   incident_count?:int,
     *   architect_approved?:bool
     * } $skill
     * @return array{
     *   schema:string,
     *   verdict:string,
     *   eligible:bool,
     *   gate:array{
     *     consecutive_uses:array{required:int,actual:int,ok:bool},
     *     no_failures:array{max:int,actual:int,ok:bool},
     *     no_incidents:array{max:int,actual:int,ok:bool},
     *     sovereignty_promotable:array{class:string,locked:bool,ok:bool},
     *     architect_review:array{required:bool,approved:bool,ok:bool}
     *   },
     *   promoted_skill_id:?string,
     *   promoted_namespace:?string,
     *   blocking_reasons:array<int,string>
     * }
     */
    public function evaluatePromotion(array $skill): array
    {
        $namespace = (string) ($skill['namespace'] ?? '');
        $sovereignty = (string) ($skill['sovereignty_class'] ?? '');
        $uses = (int) ($skill['uses_count'] ?? 0);
        $failures = (int) ($skill['failure_count'] ?? 0);
        $incidents = (int) ($skill['incident_count'] ?? 0);
        $architectApproved = ($skill['architect_approved'] ?? false) === true;
        $skillId = (string) ($skill['skill_id'] ?? '');

        $blocking = [];

        // A `core` skill is already promoted; only `user` skills are eligible.
        $eligible = $namespace === 'user';
        if (! $eligible) {
            $blocking[] = $namespace === 'core'
                ? 'skill already in core namespace; nothing to promote'
                : sprintf("namespace must be 'user' to be promotion-eligible; got '%s'", $namespace === '' ? '(none)' : $namespace);
        }

        // Gate 1: 30 consecutive failure-free uses.
        $usesOk = $uses >= self::REQUIRED_CONSECUTIVE_USES;
        if (! $usesOk) {
            $blocking[] = sprintf(
                'needs >= %d consecutive uses; got %d',
                self::REQUIRED_CONSECUTIVE_USES,
                $uses,
            );
        }

        // Gate 1b: any failure breaks the "consecutive" streak.
        $noFailuresOk = $failures <= self::MAX_FAILURES_FOR_PROMOTION;
        if (! $noFailuresOk) {
            $blocking[] = sprintf(
                'streak broken: failure_count must be %d; got %d',
                self::MAX_FAILURES_FOR_PROMOTION,
                $failures,
            );
        }

        // Gate 2: 0 incidents registered.
        $noIncidentsOk = $incidents <= self::MAX_INCIDENTS_FOR_PROMOTION;
        if (! $noIncidentsOk) {
            $blocking[] = sprintf(
                'incident_count must be %d; got %d',
                self::MAX_INCIDENTS_FOR_PROMOTION,
                $incidents,
            );
        }

        // Gate 3: sovereignty sensitive/secret/cyber NEVER promotes to core.
        $sovereigntyLocked = in_array($sovereignty, self::PROMOTION_LOCKED_SOVEREIGNTY, true);
        $sovereigntyOk = ! $sovereigntyLocked;
        if ($sovereigntyLocked) {
            $blocking[] = sprintf(
                "sovereignty_class '%s' NEVER promotes to core (must be ok_to_share)",
                $sovereignty,
            );
        }

        // Gate 4: Architect review approval registered.
        if (! $architectApproved) {
            $blocking[] = 'Architect review approval not registered';
        }

        $allGatesOk = $usesOk && $noFailuresOk && $noIncidentsOk && $sovereigntyOk && $architectApproved;

        if ($eligible && $allGatesOk) {
            $verdict = self::VERDICT_PROMOTE_CORE;
        } elseif (! $eligible || $sovereigntyLocked || ! $noIncidentsOk || (! $architectApproved && $usesOk && $noFailuresOk)) {
            // Hard blockers (not a "wait for more uses" situation): the threshold is
            // met but the skill is locked, has an incident, or Architect rejected; or
            // it is structurally ineligible. The doc flow routes these to "refine".
            $verdict = self::VERDICT_REFINE;
        } else {
            // Threshold not yet met and no hard block: keep accumulating uses.
            $verdict = self::VERDICT_HOLD_USER;
        }

        $promotedId = null;
        $promotedNamespace = null;
        if ($verdict === self::VERDICT_PROMOTE_CORE) {
            $promotedNamespace = 'core';
            // Project atlas.skill.user.<name> -> atlas.skill.core.<name> (doc example).
            if (preg_match('/^atlas\.skill\.user\.([a-z0-9_]+)$/', $skillId, $m)) {
                $promotedId = 'atlas.skill.core.'.$m[1];
            }
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $verdict,
            'eligible' => $eligible,
            'gate' => [
                'consecutive_uses' => [
                    'required' => self::REQUIRED_CONSECUTIVE_USES,
                    'actual' => $uses,
                    'ok' => $usesOk,
                ],
                'no_failures' => [
                    'max' => self::MAX_FAILURES_FOR_PROMOTION,
                    'actual' => $failures,
                    'ok' => $noFailuresOk,
                ],
                'no_incidents' => [
                    'max' => self::MAX_INCIDENTS_FOR_PROMOTION,
                    'actual' => $incidents,
                    'ok' => $noIncidentsOk,
                ],
                'sovereignty_promotable' => [
                    'class' => $sovereignty === '' ? '(none)' : $sovereignty,
                    'locked' => $sovereigntyLocked,
                    'ok' => $sovereigntyOk,
                ],
                'architect_review' => [
                    'required' => true,
                    'approved' => $architectApproved,
                    'ok' => $architectApproved,
                ],
            ],
            'promoted_skill_id' => $promotedId,
            'promoted_namespace' => $promotedNamespace,
            'blocking_reasons' => array_values($blocking),
        ];
    }
}
