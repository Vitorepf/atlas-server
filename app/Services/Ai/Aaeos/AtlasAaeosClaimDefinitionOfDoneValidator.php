<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

/**
 * Pure Definition-of-Done validator for AAEOS completion claims.
 *
 * Computes an evidence-vs-narrative verdict purely from the supplied claim
 * payload, holding zero claim data of its own. A claim is "evidence" only when
 * every contextually-required Definition-of-Done field is present (non-empty
 * after whitespace normalization); otherwise it is "narrative".
 *
 * Conditional rules:
 *  - caveat is required only when documental_state or runtime_state is 'partial'.
 *  - code_command_path is required only when code_command_applicable !== false.
 */
final class AtlasAaeosClaimDefinitionOfDoneValidator
{
    private const SCHEMA_VERSION = 'atlas.aaeos.claim_definition_of_done.v1';

    private const EVALUATED_AGAINST = 'atlas-agentic-engineering-os-implementation-reality.md:244';

    private const FIELD_OWNER_DOC = 'owner_doc';

    private const FIELD_DOCUMENTAL_STATE = 'documental_state';

    private const FIELD_RUNTIME_STATE = 'runtime_state';

    private const FIELD_CODE_COMMAND_PATH = 'code_command_path';

    private const FIELD_PROOF = 'proof';

    private const FIELD_CAVEAT = 'caveat';

    private const STATUS_PRESENT = 'present';

    private const STATUS_MISSING = 'missing';

    private const STATUS_NOT_APPLICABLE = 'not_applicable';

    private const VERDICT_EVIDENCE = 'evidence';

    private const VERDICT_NARRATIVE = 'narrative';

    /**
     * Canonical, ordered list of the six Definition-of-Done field keys.
     *
     * @return list<string>
     */
    private const CANONICAL_FIELDS = [
        self::FIELD_OWNER_DOC,
        self::FIELD_DOCUMENTAL_STATE,
        self::FIELD_RUNTIME_STATE,
        self::FIELD_CODE_COMMAND_PATH,
        self::FIELD_PROOF,
        self::FIELD_CAVEAT,
    ];

    /**
     * Fields that are mandatory regardless of claim context, in canonical order.
     *
     * @return list<string>
     */
    private const UNCONDITIONAL_FIELDS = [
        self::FIELD_OWNER_DOC,
        self::FIELD_DOCUMENTAL_STATE,
        self::FIELD_RUNTIME_STATE,
        self::FIELD_PROOF,
    ];

    /**
     * Canonical ordered mandatory Definition-of-Done field keys.
     *
     * @return list<string>
     */
    public function requiredFields(): array
    {
        return self::UNCONDITIONAL_FIELDS;
    }

    /**
     * Validate a completion claim and compute its evidence-vs-narrative verdict.
     *
     * @param  array<string, mixed>  $claim
     * @return array<string, mixed>
     */
    public function validate(array $claim): array
    {
        $partialClaim = $this->isPartial($claim, self::FIELD_DOCUMENTAL_STATE)
            || $this->isPartial($claim, self::FIELD_RUNTIME_STATE);

        $codePathApplicable = ($claim['code_command_applicable'] ?? null) !== false;

        $presentFields = [];
        $missingFields = [];
        $fieldStatus = [];

        foreach (self::CANONICAL_FIELDS as $field) {
            $present = $this->isPresent($claim, $field);
            $required = $this->isRequired($field, $partialClaim, $codePathApplicable);

            if ($present) {
                $presentFields[] = $field;
                $fieldStatus[$field] = self::STATUS_PRESENT;

                continue;
            }

            if ($required) {
                $missingFields[] = $field;
                $fieldStatus[$field] = self::STATUS_MISSING;

                continue;
            }

            $fieldStatus[$field] = self::STATUS_NOT_APPLICABLE;
        }

        $verdict = $missingFields === [] ? self::VERDICT_EVIDENCE : self::VERDICT_NARRATIVE;
        $passes = $verdict === self::VERDICT_EVIDENCE;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'passes' => $passes,
            'subject' => $this->echoSubject($claim),
            'present_fields' => $presentFields,
            'missing_fields' => $missingFields,
            'field_status' => $fieldStatus,
            'partial_claim' => $partialClaim,
            'reason' => $this->buildReason($verdict, $partialClaim, $missingFields),
            'evaluated_against' => self::EVALUATED_AGAINST,
        ];
    }

    /**
     * A field is required when it is unconditionally mandatory, or when its
     * activating condition holds (caveat on partial claims, code path when applicable).
     */
    private function isRequired(string $field, bool $partialClaim, bool $codePathApplicable): bool
    {
        if ($field === self::FIELD_CAVEAT) {
            return $partialClaim;
        }

        if ($field === self::FIELD_CODE_COMMAND_PATH) {
            return $codePathApplicable;
        }

        return in_array($field, self::UNCONDITIONAL_FIELDS, true);
    }

    /**
     * A field is present when its value normalizes to a non-empty string;
     * whitespace-only and null values are treated as missing.
     *
     * @param  array<string, mixed>  $claim
     */
    private function isPresent(array $claim, string $field): bool
    {
        return $this->normalize($claim[$field] ?? null) !== '';
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function isPartial(array $claim, string $field): bool
    {
        return $this->normalize($claim[$field] ?? null) === 'partial';
    }

    private function normalize(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return strtolower(trim($value));
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function echoSubject(array $claim): string
    {
        $subject = $claim['subject'] ?? null;

        return is_string($subject) ? $subject : '';
    }

    /**
     * @param  list<string>  $missingFields
     */
    private function buildReason(string $verdict, bool $partialClaim, array $missingFields): string
    {
        if ($verdict === self::VERDICT_EVIDENCE) {
            return $partialClaim
                ? 'evidence_complete_partial_state_caveated'
                : 'evidence_complete_all_required_fields_present';
        }

        return 'narrative_missing_required_fields:'.implode(',', $missingFields);
    }
}
