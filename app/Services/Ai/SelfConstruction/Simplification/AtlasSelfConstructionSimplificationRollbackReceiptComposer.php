<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Composes a rollback receipt for a governed simplification wave, binding
 * before/after targets, removed and replacement symbols, proof refs, and
 * rollback steps. reversible is false whenever before-state hash,
 * replacement symbol data, or proof references are missing — an incomplete
 * receipt cannot honestly claim it can be undone.
 */
final class AtlasSelfConstructionSimplificationRollbackReceiptComposer
{
    private const SCHEMA = 'atlas.self_construction.simplification_rollback_receipt.v1';

    private const DESTRUCTIVE_ACTIONS = ['merge', 'delete', 'extract'];

    /**
     * Fail-closed pre-image sentinel: a destructive merge/delete/extract
     * action with no pre_image_refs cannot honestly be rolled back, so it is
     * blocked before any receipt is composed. Complete input gets a
     * deterministic rollback_receipt_hash over pre_image_refs, touched_files,
     * and replay_gates, so any change to what was touched or what gates ran
     * changes the hash.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function checkRollbackPreimage(array $input): array
    {
        $actionType = (string) ($input['action_type'] ?? '');
        $preImageRefs = array_values((array) ($input['pre_image_refs'] ?? []));
        $touchedFiles = array_values((array) ($input['touched_files'] ?? []));
        $replayGates = array_values((array) ($input['replay_gates'] ?? []));
        $restoreSteps = array_values((array) ($input['restore_steps'] ?? []));

        $reasons = [];
        if (in_array($actionType, self::DESTRUCTIVE_ACTIONS, true) && $preImageRefs === []) {
            $reasons[] = 'pre_image_refs_missing';
        }

        if ($reasons !== []) {
            return [
                'schema_version' => self::SCHEMA,
                'blocked' => true,
                'reasons' => $reasons,
                'rollback_receipt_hash' => null,
                'restore_steps' => [],
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'blocked' => false,
            'reasons' => [],
            'rollback_receipt_hash' => $this->preimageReceiptHash($preImageRefs, $touchedFiles, $replayGates),
            'restore_steps' => $restoreSteps,
        ];
    }

    /**
     * @param  list<string>  $preImageRefs
     * @param  list<string>  $touchedFiles
     * @param  list<string>  $replayGates
     */
    private function preimageReceiptHash(array $preImageRefs, array $touchedFiles, array $replayGates): string
    {
        sort($preImageRefs);
        sort($touchedFiles);
        sort($replayGates);

        return hash('sha256', (string) json_encode([
            'pre_image_refs' => $preImageRefs,
            'touched_files' => $touchedFiles,
            'replay_gates' => $replayGates,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string,mixed>  $wave
     * @return array<string,mixed>
     */
    public function compose(array $wave): array
    {
        $beforeHash = trim((string) ($wave['before_hash'] ?? ''));
        $replacementSymbols = array_values((array) ($wave['replacement_symbols'] ?? []));
        $proofRefs = array_values((array) ($wave['proof_refs'] ?? []));

        $blockers = [];
        if ($beforeHash === '') {
            $blockers[] = 'before_hash_missing';
        }
        if ($replacementSymbols === []) {
            $blockers[] = 'replacement_symbols_missing';
        }
        if ($proofRefs === []) {
            $blockers[] = 'proof_refs_missing';
        }

        return [
            'schema_version' => self::SCHEMA,
            'wave_id' => (string) ($wave['wave_id'] ?? ''),
            'before_targets' => array_values((array) ($wave['before_targets'] ?? [])),
            'after_targets' => array_values((array) ($wave['after_targets'] ?? [])),
            'removed_symbols' => array_values((array) ($wave['removed_symbols'] ?? [])),
            'replacement_symbols' => $replacementSymbols,
            'proof_refs' => $proofRefs,
            'rollback_steps' => array_values((array) ($wave['rollback_steps'] ?? [])),
            'blockers' => $blockers,
            'reversible' => $blockers === [],
        ];
    }
}
