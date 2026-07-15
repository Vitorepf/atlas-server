<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringFileReviewDecision;
use App\Models\AtlasEngineeringPatchArtifact;
use App\Models\AtlasEngineeringRun;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Persists the current, reviewable decision for every file in a captured patch.
 *
 * The patch artifact is immutable review evidence. Decisions bind to that exact
 * patch and its diff hash, so a UI cannot approve an arbitrary path or silently
 * carry a decision to a later capture. The decision table is the current state;
 * the run operator-action ledger records aggregate operator intent separately.
 */
final class EngineeringRunFileReviewService
{
    public const ACTION_ACCEPT = 'accept';

    public const ACTION_REJECT = 'reject';

    /**
     * @param  array{actor?:mixed,note?:mixed}  $options
     */
    public function decide(
        AtlasEngineeringRun $run,
        AtlasEngineeringPatchArtifact $patch,
        string $filePath,
        string $action,
        array $options = [],
    ): AtlasEngineeringFileReviewDecision {
        $this->requireTable();
        $action = $this->action($action);
        $filePath = $this->filePath($filePath);

        return DB::transaction(function () use ($run, $patch, $filePath, $action, $options): AtlasEngineeringFileReviewDecision {
            $run = AtlasEngineeringRun::query()->lockForUpdate()->findOrFail($run->id);
            $patch = AtlasEngineeringPatchArtifact::query()
                ->where('engineering_run_id', $run->id)
                ->lockForUpdate()
                ->findOrFail($patch->id);

            if (! in_array($filePath, $this->filesFor($patch), true)) {
                throw new InvalidArgumentException('file_path is not part of the bound patch artifact.');
            }

            $actor = $this->actor($options['actor'] ?? null);
            $note = $this->note($options['note'] ?? null);
            $decision = AtlasEngineeringFileReviewDecision::query()
                ->where('patch_artifact_id', $patch->id)
                ->where('file_path', $filePath)
                ->lockForUpdate()
                ->first();

            $values = [
                'engineering_run_id' => $run->id,
                'patch_diff_hash' => $patch->diff_hash,
                'action' => $action,
                'actor' => $actor,
                'note' => $note,
                'decided_at' => now(),
            ];

            if ($decision) {
                $decision->forceFill($values)->save();

                return $decision->refresh();
            }

            return AtlasEngineeringFileReviewDecision::query()->create($values + [
                'patch_artifact_id' => $patch->id,
                'file_path' => $filePath,
            ]);
        });
    }

    /**
     * Replaces each captured file's current review state with accept. The caller
     * is still responsible for the terminal run-level acceptance and its receipt.
     *
     * @return list<AtlasEngineeringFileReviewDecision>
     */
    public function acceptAll(AtlasEngineeringRun $run, array $options = []): array
    {
        $this->requireTable();

        return DB::transaction(function () use ($run, $options): array {
            $run = AtlasEngineeringRun::query()->lockForUpdate()->findOrFail($run->id);
            $patches = AtlasEngineeringPatchArtifact::query()
                ->where('engineering_run_id', $run->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $decisions = [];

            foreach ($patches as $patch) {
                foreach ($this->filesFor($patch) as $filePath) {
                    $decisions[] = $this->decide($run, $patch, $filePath, self::ACTION_ACCEPT, $options);
                }
            }

            return $decisions;
        });
    }

    /** @return list<string> */
    private function filesFor(AtlasEngineeringPatchArtifact $patch): array
    {
        return collect(array_merge(
            (array) ($patch->changed_files_json ?? []),
            (array) ($patch->created_files_json ?? []),
            (array) ($patch->deleted_files_json ?? []),
        ))
            ->filter(static fn (mixed $path): bool => is_string($path) && trim($path) !== '')
            ->map(static fn (string $path): string => trim($path))
            ->unique()
            ->values()
            ->all();
    }

    private function requireTable(): void
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_file_review_decisions')) {
            throw new RuntimeException('File review storage is not available. Apply the Atlas engineering review migration first.');
        }
    }

    private function action(string $action): string
    {
        $action = trim($action);
        if (! in_array($action, [self::ACTION_ACCEPT, self::ACTION_REJECT], true)) {
            throw new InvalidArgumentException('action must be accept or reject.');
        }

        return $action;
    }

    private function filePath(string $filePath): string
    {
        $filePath = trim($filePath);
        if ($filePath === '' || mb_strlen($filePath) > 1024) {
            throw new InvalidArgumentException('file_path must be a non-empty path no longer than 1024 characters.');
        }

        return $filePath;
    }

    private function actor(mixed $actor): string
    {
        $actor = is_string($actor) ? trim($actor) : '';

        return $actor !== '' ? mb_substr($actor, 0, 120) : 'operator';
    }

    private function note(mixed $note): ?string
    {
        $note = is_string($note) ? trim($note) : '';
        if (mb_strlen($note) > 2000) {
            throw new InvalidArgumentException('note may not exceed 2000 characters.');
        }

        return $note !== '' ? $note : null;
    }
}
