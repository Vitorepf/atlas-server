<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Converts a no-gap fix that requires touching a pétreo brain-governance file into an explicit
 * operator-only intervention dossier — instead of the brain re-seeding the same fix as a muscle
 * task packet over and over, wasting leases on work no muscle is authorized to do.
 *
 * Input shape:
 *   { gaps: list<{
 *       target_file?:      string,
 *       target_class?:     string,               // overrides the class name derived from target_file
 *       intended_change?:  string,
 *       required_receipt?: string,                // defaults to 'operator_signed_change_receipt'
 *   }> }
 *
 * A gap escalates only when its resolved class name is one of the forbidden brain-governance
 * classes (AtlasBrainSeedQualityGate, AtlasBrainWorkerPromptCommand, AtlasBrainNextCommand,
 * AtlasBrainAuditCommand, AtlasBrainSummaryCommand). Everything else passes through untouched as
 * an allowed no-gap target. Repeated forbidden gaps against the same file collapse into ONE
 * intervention — the operator needs one dossier per file, not one per rejected attempt.
 *
 * Pure: no I/O, no provider calls, deterministic — callers supply the gap facts.
 */
final class AtlasExternalBrainForbiddenGapEscalationPlan
{
    public const SCHEMA = 'atlas.self_construction.external_brain.forbidden_gap_escalation_plan.v1';

    /** @var list<string> */
    private const FORBIDDEN_CLASSES = [
        'AtlasBrainSeedQualityGate',
        'AtlasBrainWorkerPromptCommand',
        'AtlasBrainNextCommand',
        'AtlasBrainAuditCommand',
        'AtlasBrainSummaryCommand',
    ];

    private const DEFAULT_REQUIRED_RECEIPT = 'operator_signed_change_receipt';

    /**
     * @param  array{gaps?: list<array<string,mixed>>}  $facts
     * @return array<string,mixed>
     */
    public function plan(array $facts): array
    {
        $gaps = is_array($facts['gaps'] ?? null) ? $facts['gaps'] : [];

        $interventions = [];
        $escalatedFiles = [];
        $allowedTargets = [];

        foreach ($gaps as $gap) {
            if (! is_array($gap)) {
                continue;
            }

            $file = trim((string) ($gap['target_file'] ?? ''));
            $class = $this->resolveClassName($file, $gap);

            if (! in_array($class, self::FORBIDDEN_CLASSES, true)) {
                if ($file !== '') {
                    $allowedTargets[] = $file;
                }

                continue;
            }

            if (in_array($file, $escalatedFiles, true)) {
                continue;
            }
            $escalatedFiles[] = $file;

            $interventions[] = [
                'file' => $file,
                'intended_change' => (string) ($gap['intended_change'] ?? ''),
                'required_receipt' => (string) ($gap['required_receipt'] ?? '') !== ''
                    ? (string) $gap['required_receipt']
                    : self::DEFAULT_REQUIRED_RECEIPT,
                'why_muscle_cannot_do_it' => "{$class} is a pétreo brain-governance file; autonomous muscles are forbidden from modifying it without explicit operator authorization.",
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'operator_only_interventions' => $interventions,
            'allowed_no_gap_targets' => array_values(array_unique($allowedTargets)),
        ];
    }

    /**
     * @param  array<string,mixed>  $gap
     */
    private function resolveClassName(string $file, array $gap): string
    {
        $explicit = trim((string) ($gap['target_class'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $basename = basename($file);

        return str_ends_with($basename, '.php') ? substr($basename, 0, -4) : $basename;
    }
}
