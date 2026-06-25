<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\LearningTransfer;

/**
 * Pure classifier — bins give_back / blocked-task facts into REUSABLE failure classes.
 *
 * Classes (FACTS only; no narrative invention):
 *   - duplicate_capability        : the work asked for already exists / would re-implement an existing organ.
 *   - scope_gap                   : allowed_files insufficient to deliver acceptance (missing files outside allow-list).
 *   - forbidden_target            : target sits in a frozen/forbidden core path.
 *   - contradictory_acceptance    : acceptance criteria mutually exclusive against the existing fixture (e.g.
 *                                    the "missing acceptance_contract" packet that breaks 9 sibling tests).
 *   - missing_dependency          : a needed extractor / migration / collaborator does not yet exist.
 *   - stale_context               : context-pack / code-index / docs out of date for this lane.
 *   - insufficient_evidence       : verification was inconclusive (amber); not enough proof to act.
 *
 * Output: {schema_version, class, packet_id, allowed_files, blocking_facts, evidence_refs}
 * Class = 'unknown' when no signal lights. NO narrative invention — every field on the output comes
 * from the input.
 */
final class AtlasSelfConstructionLearningTransferGiveBackClassifier
{
    public const SCHEMA = 'atlas.learning_transfer.give_back_class.v1';

    public const CLASS_DUPLICATE_CAPABILITY = 'duplicate_capability';

    public const CLASS_SCOPE_GAP = 'scope_gap';

    public const CLASS_FORBIDDEN_TARGET = 'forbidden_target';

    public const CLASS_CONTRADICTORY_ACCEPTANCE = 'contradictory_acceptance';

    public const CLASS_MISSING_DEPENDENCY = 'missing_dependency';

    public const CLASS_STALE_CONTEXT = 'stale_context';

    public const CLASS_INSUFFICIENT_EVIDENCE = 'insufficient_evidence';

    public const CLASS_UNKNOWN = 'unknown';

    /**
     * @param  array<string,mixed>  $giveBackFact  the give_back payload as recorded by the worker
     * @return array<string,mixed>
     */
    public function classify(array $giveBackFact): array
    {
        $reason = strtolower((string) ($giveBackFact['reason'] ?? ''));
        $blockingFacts = array_values((array) ($giveBackFact['blocking_facts'] ?? []));
        $evidenceRefs = array_values((array) ($giveBackFact['evidence_refs'] ?? []));
        $packetId = (string) ($giveBackFact['task_packet_id'] ?? '');
        $allowedFiles = array_values((array) ($giveBackFact['allowed_files'] ?? []));

        $class = $this->resolveClass($reason, $giveBackFact);

        return [
            'schema_version' => self::SCHEMA,
            'class' => $class,
            'packet_id' => $packetId,
            'allowed_files' => $allowedFiles,
            'blocking_facts' => $blockingFacts,
            'evidence_refs' => $evidenceRefs,
        ];
    }

    /**
     * @param  array<string,mixed>  $fact
     */
    private function resolveClass(string $reason, array $fact): string
    {
        // Reason-string match takes precedence over signal heuristics so the classifier is deterministic.
        $rules = [
            self::CLASS_DUPLICATE_CAPABILITY => ['duplicate_capability', 'already_exists', 'reimplements_existing'],
            self::CLASS_SCOPE_GAP => ['scope_gap', 'allowed_files_insufficient', 'requires_files_outside_scope'],
            self::CLASS_FORBIDDEN_TARGET => ['forbidden_target', 'pétreo', 'petreo', 'forbidden_core'],
            self::CLASS_CONTRADICTORY_ACCEPTANCE => ['contradictory_acceptance', 'breaks_sibling_tests', 'acceptance_contradicts_fixture'],
            self::CLASS_MISSING_DEPENDENCY => ['missing_extractor', 'missing_dependency', 'missing_migration', 'missing_collaborator'],
            self::CLASS_STALE_CONTEXT => ['stale_context', 'context_pack_stale', 'code_index_stale', 'docs_stale'],
            self::CLASS_INSUFFICIENT_EVIDENCE => ['insufficient_evidence', 'verification_amber'],
        ];
        foreach ($rules as $class => $needles) {
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($reason, $needle)) {
                    return $class;
                }
            }
        }

        // Structural signals (when reason isn't named).
        if (! empty($fact['target_is_forbidden_core'])) {
            return self::CLASS_FORBIDDEN_TARGET;
        }
        if (! empty($fact['allowed_files_insufficient'])) {
            return self::CLASS_SCOPE_GAP;
        }
        if (! empty($fact['missing_dependency_path'])) {
            return self::CLASS_MISSING_DEPENDENCY;
        }

        return self::CLASS_UNKNOWN;
    }
}
