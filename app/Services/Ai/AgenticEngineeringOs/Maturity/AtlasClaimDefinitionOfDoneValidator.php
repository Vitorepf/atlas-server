<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Services\Ai\Support\AiValueNormalizer;
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
final class AtlasClaimDefinitionOfDoneValidator
{
    public const SCHEMA_VERSION = 'atlas.aaeos.claim_definition_of_done.v1';

    public const EVALUATED_AGAINST = 'atlas-agentic-engineering-os-implementation-reality.md:244';

    public const STATE_PARTIAL = 'partial';

    public const FIELD_OWNER_DOC = 'owner_doc';

    public const FIELD_DOCUMENTAL_STATE = 'documental_state';

    public const FIELD_RUNTIME_STATE = 'runtime_state';

    public const FIELD_CODE_COMMAND_PATH = 'code_command_path';

    public const FIELD_PROOF = 'proof';

    public const FIELD_CAVEAT = 'caveat';

    public const FIELD_MISSING_FIELDS = 'missing_fields';
    public const FIELD_SUBJECT = 'subject';
    public const FIELD_CODE_COMMAND_APPLICABLE = 'code_command_applicable';
    public const FIELD_VERDICT = 'verdict';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_EVALUATED_AGAINST = 'evaluated_against';
    public const FIELD_FIELD_STATUS = 'field_status';
    public const FIELD_PARTIAL_CLAIM = 'partial_claim';
    public const FIELD_PASSES = 'passes';
    public const FIELD_PRESENT_FIELDS = 'present_fields';
    public const FIELD_REASON = 'reason';
    public const FIELD_EVIDENCE_COMPLETE_PARTIAL_STATE_CAVEATED = 'evidence_complete_partial_state_caveated';
    public const FIELD_EVIDENCE_COMPLETE_ALL_REQUIRED_FIELDS_PRESENT = 'evidence_complete_all_required_fields_present';

    public const STATUS_PRESENT = 'present';

    public const STATUS_MISSING = 'missing';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const VERDICT_EVIDENCE = 'evidence';

    public const VERDICT_NARRATIVE = 'narrative';

    /**
     * Canonical, ordered list of the six Definition-of-Done field keys.
     *
     * @var list<string>
     */
    public const CANONICAL_FIELDS = [
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
     * @var list<string>
     */
    public const UNCONDITIONAL_FIELDS = [
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

        $codePathApplicable = ($claim[self::FIELD_CODE_COMMAND_APPLICABLE] ?? null) !== false;

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
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_VERDICT => $verdict,
            self::FIELD_PASSES => $passes,
            self::FIELD_SUBJECT => $this->echoSubject($claim),
            self::FIELD_PRESENT_FIELDS => $presentFields,
            self::FIELD_MISSING_FIELDS => $missingFields,
            self::FIELD_FIELD_STATUS => $fieldStatus,
            self::FIELD_PARTIAL_CLAIM => $partialClaim,
            self::FIELD_REASON => $this->buildReason($verdict, $partialClaim, $missingFields),
            self::FIELD_EVALUATED_AGAINST => self::EVALUATED_AGAINST,
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
        return $this->normalize($claim[$field] ?? null) === self::STATE_PARTIAL;
    }

    private function normalize(mixed $value): string
    {
        $normalized = AiValueNormalizer::trimmedStringOrNull($value);

        return $normalized === null ? '' : AiValueNormalizer::lowerTrimmedString($normalized);
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function echoSubject(array $claim): string
    {
        return AiValueNormalizer::trimmedStringOrNull($claim[self::FIELD_SUBJECT] ?? null) ?? '';
    }

    /**
     * @param  list<string>  $missingFields
     */
    private function buildReason(string $verdict, bool $partialClaim, array $missingFields): string
    {
        if ($verdict === self::VERDICT_EVIDENCE) {
            return $partialClaim
                ? self::FIELD_EVIDENCE_COMPLETE_PARTIAL_STATE_CAVEATED
                : self::FIELD_EVIDENCE_COMPLETE_ALL_REQUIRED_FIELDS_PRESENT;
        }

        return 'narrative_missing_required_fields:'.implode(',', $missingFields);
    }
}
