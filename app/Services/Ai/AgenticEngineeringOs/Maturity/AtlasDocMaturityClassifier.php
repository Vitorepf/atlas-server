<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Pure DOC L0..L4 maturity classifier for the AAEOS Implementation Reality
 * contract (atlas-agentic-engineering-os-implementation-reality.md:147-155).
 *
 * It deterministically derives the doc-maturity level from a presence/strength
 * map of the documented structural parts (mother doc, contracts, runbook,
 * matrix, quality bar, evidence, gates) and ALWAYS reports runtime_ready=false:
 * doc maturity is never runtime proof (line 218 / risk "IA trata DOC L4 como
 * runtime completo"). It pairs with — and never duplicates — the
 * implementation-state classifier.
 */
final class AtlasDocMaturityClassifier
{
    public const FIELD_LEVEL_ORDINAL = 'level_ordinal';
    public const FIELD_MISSING_FOR_NEXT = 'missing_for_next';
    public const SCHEMA_VERSION = 'atlas.aaeos.doc_maturity.v1';

    public const LEVEL_L0 = 'DOC L0';

    public const LEVEL_L1 = 'DOC L1';

    public const LEVEL_L2 = 'DOC L2';

    public const LEVEL_L3 = 'DOC L3';

    public const LEVEL_L4 = 'DOC L4';

    /**
     * Boolean structural parts: present when the input flag is truthy.
     *
     * @var list<string>
     */
    public const BOOLEAN_REQUIREMENTS = ['mother_doc', 'contracts'];

    /**
     * Strength-graded structural parts: satisfied only at 'strong'.
     *
     * @var list<string>
     */
    public const STRENGTH_REQUIREMENTS = ['runbook', 'matrix', 'quality_bar', 'evidence', 'gates'];

    /**
     * The four high signals that, when all 'strong' on top of mother + contracts
     * + strong runbook, certify DOC L4 (line 151).
     *
     * @var list<string>
     */
    public const L4_SIGNALS = ['matrix', 'quality_bar', 'evidence', 'gates'];

    public const STRENGTH_NONE = 'none';

    public const STRENGTH_PARTIAL = 'partial';

    public const STRENGTH_STRONG = 'strong';
    public const FIELD_CONTRACTS = 'contracts';
    public const FIELD_LEVEL = 'level';
    public const FIELD_MOTHER_DOC = 'mother_doc';
    public const FIELD_RATIONALE = 'rationale';
    public const FIELD_RUNBOOK = 'runbook';
    public const FIELD_RUNTIME_READY = 'runtime_ready';
    public const FIELD_SATISFIED = 'satisfied';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_DOC_L0__NO_MOTHER_DOC_AND_NO_CONTRACTS__IDEA_RESEARCH_SOURCE_MATERIAL_WITHOUT_CONTRACT__ = 'DOC L0: no mother_doc and no contracts (idea/research/source material without contract).';
    public const FIELD_DOC_L1__MOTHER_DOC_ONLY__CONTRACTS_ABSENT__FRAGMENTARY_MOTHER_NORTH_STAR_DOC__ = 'DOC L1: mother_doc only, contracts absent (fragmentary mother/north-star doc).';
    public const FIELD_DOC_L3__MOTHER_DOC___CONTRACTS___STRONG_RUNBOOK__BUT_ = 'DOC L3: mother_doc + contracts + strong runbook, but ';
    public const FIELD_DOC_L4__MOTHER_DOC___CONTRACTS___STRONG_RUNBOOK___MATRIX_QUALITY_BAR_EVIDENCE_GATES_ALL_STRONG_ = 'DOC L4: mother_doc + contracts + strong runbook + matrix/quality_bar/evidence/gates all strong.';

    /** @var list<string> */
    public const LEVELS = [
        self::LEVEL_L0,
        self::LEVEL_L1,
        self::LEVEL_L2,
        self::LEVEL_L3,
        self::LEVEL_L4,
    ];

    /**
     * @param  array<string,mixed>  $sections
     * @return array{
     *     schema_version: string,
     *     level: string,
     *     level_ordinal: int,
     *     runtime_ready: bool,
     *     satisfied: list<string>,
     *     missing_for_next: list<string>,
     *     rationale: string
     * }
     */
    public function classify(array $sections): array
    {
        $hasMother = $this->boolPart($sections, self::FIELD_MOTHER_DOC);
        $hasContracts = $this->boolPart($sections, self::FIELD_CONTRACTS);
        $runbook = $this->strength($sections, self::FIELD_RUNBOOK);

        $signalStrengths = [];
        foreach (self::L4_SIGNALS as $signal) {
            $signalStrengths[$signal] = $this->strength($sections, $signal);
        }
        $allSignalsStrong = $this->allStrong($signalStrengths);

        if ($hasMother && $hasContracts && $runbook === self::STRENGTH_STRONG && $allSignalsStrong) {
            $level = self::LEVEL_L4;
            $ordinal = 4;
            $rationale = self::FIELD_DOC_L4__MOTHER_DOC___CONTRACTS___STRONG_RUNBOOK___MATRIX_QUALITY_BAR_EVIDENCE_GATES_ALL_STRONG_;
        } elseif ($hasMother && $hasContracts && $runbook === self::STRENGTH_STRONG) {
            $level = self::LEVEL_L3;
            $ordinal = 3;
            $rationale = self::FIELD_DOC_L3__MOTHER_DOC___CONTRACTS___STRONG_RUNBOOK__BUT_
                . $this->joinList($this->weakSignals($signalStrengths)) . ' not yet strong.';
        } elseif ($hasMother && $hasContracts) {
            $level = self::LEVEL_L2;
            $ordinal = 2;
            $rationale = 'DOC L2: mother_doc + contracts, runbook incomplete (strength=' . $runbook . ').';
        } elseif ($hasMother) {
            $level = self::LEVEL_L1;
            $ordinal = 1;
            $rationale = self::FIELD_DOC_L1__MOTHER_DOC_ONLY__CONTRACTS_ABSENT__FRAGMENTARY_MOTHER_NORTH_STAR_DOC__;
        } else {
            $level = self::LEVEL_L0;
            $ordinal = 0;
            $rationale = self::FIELD_DOC_L0__NO_MOTHER_DOC_AND_NO_CONTRACTS__IDEA_RESEARCH_SOURCE_MATERIAL_WITHOUT_CONTRACT__;
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_LEVEL => $level,
            self::FIELD_LEVEL_ORDINAL => $ordinal,
            // Doc maturity never proves runtime: encodes the line 218 failure mode.
            self::FIELD_RUNTIME_READY => false,
            self::FIELD_SATISFIED => $this->satisfiedRequirements($hasMother, $hasContracts, $signalStrengths, $runbook),
            self::FIELD_MISSING_FOR_NEXT => $this->missingForNext($ordinal, $hasMother, $hasContracts, $runbook, $signalStrengths),
            self::FIELD_RATIONALE => $rationale,
        ];
    }

    /**
     * @param  array<string,mixed>  $sections
     */
    private function boolPart(array $sections, string $key): bool
    {
        return ($sections[$key] ?? false) === true;
    }

    /**
     * Normalize a strength flag case-insensitively to none|partial|strong.
     * Missing keys and unknown values default to 'none' (absent).
     *
     * @param  array<string,mixed>  $sections
     */
    private function strength(array $sections, string $key): string
    {
        $raw = $sections[$key] ?? self::STRENGTH_NONE;
        $value = AiValueNormalizer::trimmedStringOrNull($raw);
        if ($value === null) {
            return self::STRENGTH_NONE;
        }

        $value = AiValueNormalizer::lowerTrimmedString($value);

        if ($value === self::STRENGTH_STRONG) {
            return self::STRENGTH_STRONG;
        }

        if ($value === self::STRENGTH_PARTIAL) {
            return self::STRENGTH_PARTIAL;
        }

        return self::STRENGTH_NONE;
    }

    /**
     * @param  array<string,string>  $signalStrengths
     */
    private function allStrong(array $signalStrengths): bool
    {
        foreach ($signalStrengths as $strength) {
            if ($strength !== self::STRENGTH_STRONG) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,string>  $signalStrengths
     * @return list<string>
     */
    private function weakSignals(array $signalStrengths): array
    {
        $weak = [];
        foreach (self::L4_SIGNALS as $signal) {
            if (($signalStrengths[$signal] ?? self::STRENGTH_NONE) !== self::STRENGTH_STRONG) {
                $weak[] = $signal;
            }
        }

        return $weak;
    }

    /**
     * @param  array<string,string>  $signalStrengths
     * @return list<string>
     */
    private function satisfiedRequirements(bool $hasMother, bool $hasContracts, array $signalStrengths, string $runbook): array
    {
        $satisfied = [];

        if ($hasMother) {
            $satisfied[] = self::FIELD_MOTHER_DOC;
        }

        if ($hasContracts) {
            $satisfied[] = self::FIELD_CONTRACTS;
        }

        if ($runbook === self::STRENGTH_STRONG) {
            $satisfied[] = self::FIELD_RUNBOOK;
        }

        foreach (self::L4_SIGNALS as $signal) {
            if (($signalStrengths[$signal] ?? self::STRENGTH_NONE) === self::STRENGTH_STRONG) {
                $satisfied[] = $signal;
            }
        }

        return $satisfied;
    }

    /**
     * @param  array<string,string>  $signalStrengths
     * @return list<string>
     */
    private function missingForNext(int $ordinal, bool $hasMother, bool $hasContracts, string $runbook, array $signalStrengths): array
    {
        return match ($ordinal) {
            0 => $hasMother ? [] : [self::FIELD_MOTHER_DOC],
            1 => $hasContracts ? [] : [self::FIELD_CONTRACTS],
            2 => $runbook === self::STRENGTH_STRONG ? [] : [self::FIELD_RUNBOOK],
            3 => $this->weakSignals($signalStrengths),
            default => [],
        };
    }

    /**
     * @param  list<string>  $items
     */
    private function joinList(array $items): string
    {
        return $items === [] ? self::STRENGTH_NONE : implode('/', $items);
    }
}
