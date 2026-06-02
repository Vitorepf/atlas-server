<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

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
final class AtlasAaeosDocMaturityClassifier
{
    private const SCHEMA_VERSION = 'atlas.aaeos.doc_maturity.v1';

    private const LEVEL_L0 = 'DOC L0';

    private const LEVEL_L1 = 'DOC L1';

    private const LEVEL_L2 = 'DOC L2';

    private const LEVEL_L3 = 'DOC L3';

    private const LEVEL_L4 = 'DOC L4';

    /**
     * Boolean structural parts: present when the input flag is truthy.
     *
     * @var list<string>
     */
    private const BOOLEAN_REQUIREMENTS = ['mother_doc', 'contracts'];

    /**
     * Strength-graded structural parts: satisfied only at 'strong'.
     *
     * @var list<string>
     */
    private const STRENGTH_REQUIREMENTS = ['runbook', 'matrix', 'quality_bar', 'evidence', 'gates'];

    /**
     * The four high signals that, when all 'strong' on top of mother + contracts
     * + strong runbook, certify DOC L4 (line 151).
     *
     * @var list<string>
     */
    private const L4_SIGNALS = ['matrix', 'quality_bar', 'evidence', 'gates'];

    private const STRENGTH_NONE = 'none';

    private const STRENGTH_PARTIAL = 'partial';

    private const STRENGTH_STRONG = 'strong';

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
        $hasMother = $this->boolPart($sections, 'mother_doc');
        $hasContracts = $this->boolPart($sections, 'contracts');
        $runbook = $this->strength($sections, 'runbook');

        $signalStrengths = [];
        foreach (self::L4_SIGNALS as $signal) {
            $signalStrengths[$signal] = $this->strength($sections, $signal);
        }
        $allSignalsStrong = $this->allStrong($signalStrengths);

        if ($hasMother && $hasContracts && $runbook === self::STRENGTH_STRONG && $allSignalsStrong) {
            $level = self::LEVEL_L4;
            $ordinal = 4;
            $rationale = 'DOC L4: mother_doc + contracts + strong runbook + matrix/quality_bar/evidence/gates all strong.';
        } elseif ($hasMother && $hasContracts && $runbook === self::STRENGTH_STRONG) {
            $level = self::LEVEL_L3;
            $ordinal = 3;
            $rationale = 'DOC L3: mother_doc + contracts + strong runbook, but '
                . $this->joinList($this->weakSignals($signalStrengths)) . ' not yet strong.';
        } elseif ($hasMother && $hasContracts) {
            $level = self::LEVEL_L2;
            $ordinal = 2;
            $rationale = 'DOC L2: mother_doc + contracts, runbook incomplete (strength=' . $runbook . ').';
        } elseif ($hasMother) {
            $level = self::LEVEL_L1;
            $ordinal = 1;
            $rationale = 'DOC L1: mother_doc only, contracts absent (fragmentary mother/north-star doc).';
        } else {
            $level = self::LEVEL_L0;
            $ordinal = 0;
            $rationale = 'DOC L0: no mother_doc and no contracts (idea/research/source material without contract).';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'level' => $level,
            'level_ordinal' => $ordinal,
            // Doc maturity never proves runtime: encodes the line 218 failure mode.
            'runtime_ready' => false,
            'satisfied' => $this->satisfiedRequirements($hasMother, $hasContracts, $signalStrengths, $runbook),
            'missing_for_next' => $this->missingForNext($ordinal, $hasMother, $hasContracts, $runbook, $signalStrengths),
            'rationale' => $rationale,
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

        if (! is_string($raw)) {
            return self::STRENGTH_NONE;
        }

        $value = strtolower(trim($raw));

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
            $satisfied[] = 'mother_doc';
        }

        if ($hasContracts) {
            $satisfied[] = 'contracts';
        }

        if ($runbook === self::STRENGTH_STRONG) {
            $satisfied[] = 'runbook';
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
            0 => $hasMother ? [] : ['mother_doc'],
            1 => $hasContracts ? [] : ['contracts'],
            2 => $runbook === self::STRENGTH_STRONG ? [] : ['runbook'],
            3 => $this->weakSignals($signalStrengths),
            default => [],
        };
    }

    /**
     * @param  list<string>  $items
     */
    private function joinList(array $items): string
    {
        return $items === [] ? 'none' : implode('/', $items);
    }
}
