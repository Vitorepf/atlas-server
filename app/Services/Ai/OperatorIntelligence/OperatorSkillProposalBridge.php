<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\OperatorPatternDetection;
use App\Models\OperatorSkillProposal;
use App\Services\Ai\OperatorIntelligence\Support\SkillScaffoldTextSupport;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Bridge #2 — Self-Construction. A detected recurring pattern → Atlas AUTO-BUILDS a skill
 * for it. A skill is a SKILL.md (markdown instructions/automation), so "building" it means
 * generating a real, valid skill file — grounded in the pattern, tool-less by default — and
 * staging it in a SANDBOX. Governance, exactly as the operator asked (sandbox, never-merge,
 * Sunday review):
 *
 *   • SANDBOX — the generated SKILL.md is written to a staging dir, NEVER the live vault;
 *   • NEVER-MERGE — the ONLY writer of the live vault is the explicit, --confirm operator
 *     promoter (AtlasOperatorSkillCommand); this bridge never touches it;
 *   • GROUNDED — the scaffold is deterministic (SkillScaffoldGenerator), built from the
 *     pattern's own fields + cited evidence, with empty allowed-tools (grants no powers);
 *   • PRIVACY GATE — a sensitive/secret pattern is never turned into a skill;
 *   • IDEMPOTENT — one staged skill per pattern.
 */
final class OperatorSkillProposalBridge
{
    private const STAGING_DIR = 'atlas/operator-skills-staging';

    public function __construct(private readonly SkillScaffoldGenerator $generator) {}

    public function propose(OperatorPatternDetection $detection): ?OperatorSkillProposal
    {
        if ($detection->proposed_skill_task_id !== null || $detection->status === OperatorPatternDetection::STATUS_DISMISSED) {
            return null;
        }
        if (! in_array($detection->proposal_target, ['skill', 'both'], true)) {
            return null;
        }
        if ($detection->privacy_class !== 'normal') {
            return null; // never build a skill from a sensitive/secret recurrence
        }

        try {
            $existing = OperatorSkillProposal::query()
                ->where('operator_id', $detection->operator_id)
                ->where('pattern_id', $detection->pattern_id)
                ->first();
            if ($existing instanceof OperatorSkillProposal) {
                return $existing;
            }

            $scaffold = $this->generator->generate($detection);
            // Fail-closed: a staged skill MUST be tool-less + untrusted + proposed. If the
            // scaffold ever fails that (e.g. a future escaping regression injecting frontmatter
            // keys), refuse to stage it rather than trust downstream parser leniency.
            if (! SkillScaffoldTextSupport::scaffoldIsSafe($scaffold['markdown'])) {
                return null;
            }
            $stagingPath = storage_path(self::STAGING_DIR.'/'.$scaffold['slug'].'/SKILL.md');
            File::ensureDirectoryExists(dirname($stagingPath));
            File::put($stagingPath, $scaffold['markdown']); // SANDBOX — never the live vault

            $proposal = OperatorSkillProposal::query()->create([
                'operator_id' => (string) $detection->operator_id,
                'pattern_id' => (string) $detection->pattern_id,
                'detection_id' => (string) $detection->id,
                'slug' => $scaffold['slug'],
                'title' => $scaffold['title'],
                'description' => $scaffold['description'],
                'status' => OperatorSkillProposal::STATUS_STAGED,
                'staging_path' => $stagingPath,
                'confidence' => (float) $detection->confidence,
                'privacy_class' => (string) $detection->privacy_class,
                'evidence' => $detection->evidence,
            ]);

            $detection->forceFill([
                'status' => OperatorPatternDetection::STATUS_PROPOSED,
                'proposed_skill_task_id' => (string) $proposal->id,
            ])->save();

            return $proposal;
        } catch (Throwable) {
            return null;
        }
    }

}

