<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\LearningTransfer;

/**
 * Pure planner — turns an ADMITTED lesson into a bounded, provider-safe FUTURE-context update plan.
 * NEVER writes docs, memory, prompts, or templates directly; only emits a plan + bounded targets.
 *
 * Lesson classes route to canonical surfaces:
 *   - duplicate_capability        ⇒ packet_template_surface (add a `requires_unique_capability` flag)
 *   - scope_gap                   ⇒ packet_template_surface (broaden default allowed_files heuristic)
 *   - forbidden_target            ⇒ docs_surface (refresh forbidden-core docs)
 *   - contradictory_acceptance    ⇒ docs_surface (record canonical extractor-missing pattern)
 *   - missing_dependency          ⇒ docs_surface (record needed primitive)
 *   - stale_context               ⇒ worker_prompt_surface (add explicit freshness gate hint)
 *   - insufficient_evidence       ⇒ worker_prompt_surface (require explicit evidence_refs)
 *
 * Unsafe lessons are REJECTED (rollout_class=blocked):
 *   - lesson.class = '' (broad narrative)
 *   - lesson.decision != 'admit'
 *
 * Output: {schema_version, plan_id, target_surface, target_paths, rollout_class, verification_needed,
 *           owner_surface, lesson_class, plan_hash, blockers}
 */
final class AtlasSelfConstructionLearningTransferContextUpdatePlan
{
    public const SCHEMA = 'atlas.learning_transfer.context_update_plan.v1';

    public const SURFACE_PACKET_TEMPLATE = 'packet_template_surface';

    public const SURFACE_DOCS = 'docs_surface';

    public const SURFACE_WORKER_PROMPT = 'worker_prompt_surface';

    public const ROLLOUT_BOUNDED = 'bounded_proposed';

    public const ROLLOUT_BLOCKED = 'blocked';

    private const CLASS_TO_SURFACE = [
        'duplicate_capability' => self::SURFACE_PACKET_TEMPLATE,
        'scope_gap' => self::SURFACE_PACKET_TEMPLATE,
        'forbidden_target' => self::SURFACE_DOCS,
        'contradictory_acceptance' => self::SURFACE_DOCS,
        'missing_dependency' => self::SURFACE_DOCS,
        'stale_context' => self::SURFACE_WORKER_PROMPT,
        'insufficient_evidence' => self::SURFACE_WORKER_PROMPT,
    ];

    /**
     * @param  array<string,mixed>  $admittedLesson  {decision:'admit', class:string, ...}
     * @return array<string,mixed>
     */
    public function plan(array $admittedLesson): array
    {
        $decision = (string) ($admittedLesson['decision'] ?? '');
        $class = (string) ($admittedLesson['class'] ?? '');

        $evidenceRefs = is_array($admittedLesson['evidence_refs'] ?? null)
            ? array_values(array_filter(array_map('strval', $admittedLesson['evidence_refs'])))
            : [];
        $ttlSeconds = isset($admittedLesson['ttl_seconds']) ? (int) $admittedLesson['ttl_seconds'] : 0;

        $blockers = [];
        if ($decision !== 'admit') {
            $blockers[] = 'lesson_not_admitted:'.$decision;
        }
        if ($class === '') {
            $blockers[] = 'lesson_class_missing';
        }
        if ((bool) ($admittedLesson['broad_memory_rewrite'] ?? false)) {
            $blockers[] = 'broad_memory_rewrite_rejected';
        }
        if ($evidenceRefs === []) {
            $blockers[] = 'evidence_refs_missing';
        }
        if ($ttlSeconds <= 0) {
            $blockers[] = 'ttl_seconds_missing';
        }

        if ($blockers !== []) {
            return $this->envelope(
                lessonClass: $class,
                targetSurface: '',
                targetPaths: [],
                ownerSurface: '',
                verificationNeeded: [],
                rolloutClass: self::ROLLOUT_BLOCKED,
                blockers: $blockers,
                evidenceRefs: $evidenceRefs,
                ttlSeconds: $ttlSeconds,
            );
        }

        $surface = self::CLASS_TO_SURFACE[$class] ?? null;
        if ($surface === null) {
            return $this->envelope(
                lessonClass: $class,
                targetSurface: '',
                targetPaths: [],
                ownerSurface: '',
                verificationNeeded: [],
                rolloutClass: self::ROLLOUT_BLOCKED,
                blockers: ['unknown_lesson_class:'.$class],
            );
        }

        [$targetPaths, $ownerSurface, $verification] = match ($surface) {
            self::SURFACE_PACKET_TEMPLATE => [
                ['app/Services/Ai/SelfConstruction/PacketTemplates/'.$class.'.md'],
                'packet_templates',
                ['template_lint_green', 'replay_against_recent_packets_green'],
            ],
            self::SURFACE_DOCS => [
                ['docs/self-construction/lessons/'.$class.'.md'],
                'engineering_docs',
                ['docs_lint_green', 'docs_sync_run_post_merge'],
            ],
            self::SURFACE_WORKER_PROMPT => [
                ['app/Services/Ai/SelfConstruction/WorkerPrompts/'.$class.'.md'],
                'worker_prompt_owner',
                ['worker_prompt_dry_run_green'],
            ],
            default => [[], '', []],
        };

        $rollbackHint = match ($surface) {
            self::SURFACE_PACKET_TEMPLATE => 'revert_template_file',
            self::SURFACE_DOCS => 'revert_doc_file',
            self::SURFACE_WORKER_PROMPT => 'revert_worker_prompt_file',
            default => '',
        };

        return $this->envelope(
            lessonClass: $class,
            targetSurface: $surface,
            targetPaths: $targetPaths,
            ownerSurface: $ownerSurface,
            verificationNeeded: $verification,
            rolloutClass: self::ROLLOUT_BOUNDED,
            blockers: [],
            evidenceRefs: $evidenceRefs,
            ttlSeconds: $ttlSeconds,
            rollbackHint: $rollbackHint,
        );
    }

    /**
     * @param  list<string>  $targetPaths
     * @param  list<string>  $verificationNeeded
     * @param  list<string>  $blockers
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function envelope(
        string $lessonClass,
        string $targetSurface,
        array $targetPaths,
        string $ownerSurface,
        array $verificationNeeded,
        string $rolloutClass,
        array $blockers,
        array $evidenceRefs = [],
        int $ttlSeconds = 0,
        string $rollbackHint = '',
    ): array {
        $body = [
            'schema_version' => self::SCHEMA,
            'lesson_class' => $lessonClass,
            'target_surface' => $targetSurface,
            'target_paths' => $targetPaths,
            'owner_surface' => $ownerSurface,
            'verification_needed' => $verificationNeeded,
            'rollout_class' => $rolloutClass,
            'blockers' => $blockers,
            'evidence_refs' => $evidenceRefs,
            'ttl_seconds' => $ttlSeconds,
            'rollback_hint' => $rollbackHint,
        ];
        $body['plan_hash'] = hash('sha256', $this->canonicalJson($body));
        $body['plan_id'] = substr($body['plan_hash'], 0, 16);
        $body['action_hash'] = hash('sha256', $this->canonicalJson([
            'lesson_class' => $lessonClass,
            'target_surface' => $targetSurface,
            'target_paths' => $targetPaths,
        ]));

        return $body;
    }

    private function canonicalJson(mixed $value): string
    {
        return (string) json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->canonicalize($v), $value);
        }
        ksort($value);
        foreach ($value as $k => $v) {
            $value[$k] = $this->canonicalize($v);
        }

        return $value;
    }
}
