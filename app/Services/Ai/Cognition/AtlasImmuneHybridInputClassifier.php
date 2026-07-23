<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCognitiveImmuneInputClassifier;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * MAXI-04 \u2014 the hybrid classifier that wraps the base
 * {@see AtlasCognitiveImmuneInputClassifier} with an OPTIONAL semantic arm
 * gated by `atlas.aaeos.immune_classifier.semantic_arm_enabled` (default OFF).
 *
 * Contract:
 *  - Switch OFF (default): {@see classifyHybrid()} returns the base classifier
 *    output ADDING only a byte-inert `hybrid_arm` block (semantic_arm.enabled
 *    = false, source = 'off'); the base fields are untouched. Callers that
 *    ignore the block get byte-identical behaviour.
 *  - Switch ON: the semantic arm runs. For each hostile class the arm looks
 *    up its anchor exemplars in the frozen anchors fixture, computes
 *    max_j similarity(candidate, exemplar_j) via the injected
 *    {@see ImmuneSemanticSimilarityPort}, and if the max >= tau the class wins
 *    by the rule `hostile_class wins by max(lexical, semantic)`. The base
 *    classifier is invoked first (lexical arm), and its verdict wins unless
 *    the semantic arm names a MORE-severe hostile class \u2014 order of severity
 *    is prompt_injection > private_sensitive > untrusted_content (matching the
 *    base classifier's own routeClass precedence). tau comes from the freeze.
 *  - The port MAY return null (backend unavailable); when it does, the arm
 *    reports `semantic_arm.source = 'unavailable'` and never fabricates a
 *    similarity.
 *
 * Privacy: neither the candidate nor the anchor text ever leaves the machine
 * (delegated to the port implementation, which is local by construction).
 */
final class AtlasImmuneHybridInputClassifier
{
    /** @var list<string> Severity order (highest first). */
    public const HOSTILE_SEVERITY = ['prompt_injection', 'private_sensitive', 'untrusted_content'];

    public const SEMANTIC_ARM_ENABLED_CONFIG_KEY = 'atlas.aaeos.immune_classifier.semantic_arm_enabled';

    public const DEFAULT_SEMANTIC_ARM_ENABLED = false;

    public const SOURCE_UNAVAILABLE = 'unavailable';

    public const SOURCE_JACCARD_BASELINE = 'jaccard_baseline';

    public const FIELD_ENABLED = 'enabled';
    public const FIELD_INPUT_CLASS = 'input_class';
    public const FIELD_WINNER_SOURCE = 'winner_source';
    public const FIELD_REF = 'ref';
    public const FIELD_MATCHED_SIGNALS = 'matched_signals';
    public const FIELD_IMMUNE_SIGNATURE = 'immune_signature';
    public const FIELD_HYBRID_ARM = 'hybrid_arm';
    public const FIELD_HOSTILE_CLASS_CANDIDATE = 'hostile_class_candidate';
    public const FIELD_STATUS = 'status';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_SOURCE = 'source';
    public const FIELD_TAU = 'tau';
    public const FIELD_MAX_SIMILARITY = 'max_similarity';
    public const FIELD_LEXICAL_HOSTILE_CLASS = 'lexical_hostile_class';
    public const FIELD_OVERRIDE_APPLIED = 'override_applied';
    public const FIELD_MATCHED = 'matched';
    public const FIELD_ENFORCE_APPLIED = 'enforce_applied';
    public const FIELD_SIGNATURE = 'signature';
    public const FIELD_ORIGIN_REF = 'origin_ref';
    public const FIELD_HIT_COUNT_AFTER = 'hit_count_after';
    public const FIELD_REASON = 'reason';
    public const FIELD_DEFAULT_DESTINATION = 'default_destination';
    public const FIELD_EMBEDDING_ALLOWED = 'embedding_allowed';
    public const FIELD_MEMORY_ELIGIBLE = 'memory_eligible';
    public const FIELD_HOSTILE_CLASS = 'hostile_class';
    public const FIELD_SCORES_BY_CLASS = 'scores_by_class';
    public const FIELD_UNTRUSTED_CONTENT = 'untrusted_content';
    public const FIELD_THRESHOLDS = 'thresholds';
    public const FIELD_CLASSES = 'classes';
    public const FIELD_MODE = 'mode';
    public const FIELD_PRIVATE_SENSITIVE = 'private_sensitive';
    public const FIELD_PROMPT_INJECTION = 'prompt_injection';
    public const FIELD_BLOCKED_EPHEMERAL_EVIDENCE = 'blocked_ephemeral_evidence';
    public const FIELD_OFF = 'off';
    public const FIELD_AGREEMENT = 'agreement';
    public const FIELD_CITED_DATA_NOT_INSTRUCTION = 'cited_data_not_instruction';
    public const FIELD_LEXICAL = 'lexical';
    public const FIELD_REDACT_MINIMIZE = 'redact_minimize';
    public const FIELD_KNOWN_POISON_SIGNATURE = 'known_poison_signature';
    public const FIELD_SEMANTIC_ARM_HIT = 'semantic_arm_hit';
    public const FIELD_SEMANTIC = 'semantic';
    public const FIELD_SEMANTIC_ARM_SIMILARITY_ = 'semantic_arm_similarity_';

    private readonly AtlasCognitiveImmuneInputClassifier $base;

    private readonly ImmuneSemanticSimilarityPort $port;

    private readonly ImmuneSignatureStore $signatureStore;

    /** @var array<string,list<string>>|null */
    private ?array $anchors = null;

    private readonly float $tau;

    private readonly bool $enabled;

    /**
     * @param  array<string,list<string>>|null  $anchors  Optional injected anchor map
     *         {class => [exemplar, ...]}. When null, loaded lazily from the frozen
     *         anchors fixture.
     */
    public function __construct(
        ?AtlasCognitiveImmuneInputClassifier $base = null,
        ?ImmuneSemanticSimilarityPort $port = null,
        ?array $anchors = null,
        ?float $tau = null,
        ?bool $enabled = null,
        ?ImmuneSignatureStore $signatureStore = null,
    ) {
        $this->base = $base ?? new AtlasCognitiveImmuneInputClassifier;
        $this->port = $port ?? new BigramJaccardImmuneSemanticSimilarityPort;
        $this->signatureStore = $signatureStore ?? new ImmuneSignatureStore;
        $this->anchors = $anchors;
        $freeze = AtlasImmuneClassifierHybridFreeze::freezePayload();
        $this->tau = $tau ?? (AiValueNormalizer::finiteFloatOrNull($freeze[self::FIELD_THRESHOLDS][self::FIELD_TAU] ?? null) ?? 0.62);
        $this->enabled = $enabled ?? (AiValueNormalizer::boolOrNull(config(self::SEMANTIC_ARM_ENABLED_CONFIG_KEY, self::DEFAULT_SEMANTIC_ARM_ENABLED)) ?? self::DEFAULT_SEMANTIC_ARM_ENABLED);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    public function classifyHybrid(string $text, array $metadata = []): array
    {
        $signatureBlock = [
            self::FIELD_SCHEMA_VERSION => AtlasImmuneSignatureFreeze::MEASURE_ID,
            self::FIELD_MODE => $this->signatureStore->mode(),
            self::FIELD_MATCHED => false,
            self::FIELD_REF => null,
            self::FIELD_ENFORCE_APPLIED => false,
        ];

        $knownSignature = $this->signatureStore->consult($text, $metadata);
        if ($knownSignature !== null) {
            $signatureBlock[self::FIELD_MATCHED] = true;
            $signatureBlock[self::FIELD_REF] = $knownSignature[self::FIELD_REF];
            $signatureBlock[self::FIELD_SIGNATURE] = $knownSignature[self::FIELD_SIGNATURE];
            $signatureBlock[self::FIELD_ORIGIN_REF] = $knownSignature[self::FIELD_ORIGIN_REF];
            $signatureBlock[self::FIELD_HIT_COUNT_AFTER] = $knownSignature[self::FIELD_HIT_COUNT_AFTER];
        }

        if ($knownSignature !== null && $this->signatureStore->enforceEnabled()) {
            $hostileClass = AiValueNormalizer::trimmedScalarStringOrNull($knownSignature[self::FIELD_HOSTILE_CLASS] ?? null) ?? '';
            $baseResult = $this->base->classify($text, $metadata);
            $baseResult[self::FIELD_INPUT_CLASS] = $hostileClass;
            $baseResult[self::FIELD_REASON] = 'known_poison_signature:'.(AiValueNormalizer::trimmedScalarStringOrNull($knownSignature[self::FIELD_REF] ?? null) ?? '');
            $baseResult[self::FIELD_MATCHED_SIGNALS] = $this->augmentSignals(
                $baseResult[self::FIELD_MATCHED_SIGNALS],
                self::FIELD_KNOWN_POISON_SIGNATURE,
            );
            $baseResult[self::FIELD_MEMORY_ELIGIBLE] = false;
            $baseResult[self::FIELD_EMBEDDING_ALLOWED] = false;
            $baseResult[self::FIELD_DEFAULT_DESTINATION] = self::hostileDestination($hostileClass);
            $signatureBlock[self::FIELD_ENFORCE_APPLIED] = true;
            $baseResult[self::FIELD_IMMUNE_SIGNATURE] = $signatureBlock;
            $baseResult[self::FIELD_HYBRID_ARM] = $this->offHybridArm($baseResult);

            return $baseResult;
        }

        $baseResult = $this->base->classify($text, $metadata);

        $armBlock = [
            self::FIELD_SCHEMA_VERSION => AtlasImmuneClassifierHybridFreeze::MEASURE_ID,
            self::FIELD_ENABLED => $this->enabled,
            self::FIELD_SOURCE => self::FIELD_OFF,
            self::FIELD_TAU => $this->tau,
            self::FIELD_MAX_SIMILARITY => null,
            self::FIELD_HOSTILE_CLASS_CANDIDATE => null,
            self::FIELD_LEXICAL_HOSTILE_CLASS => in_array($baseResult[self::FIELD_INPUT_CLASS], self::HOSTILE_SEVERITY, true)
                ? (AiValueNormalizer::trimmedScalarStringOrNull($baseResult[self::FIELD_INPUT_CLASS] ?? null) ?? '')
                : null,
            self::FIELD_WINNER_SOURCE => self::FIELD_LEXICAL,
            self::FIELD_OVERRIDE_APPLIED => false,
        ];

        if (! $this->enabled) {
            $baseResult[self::FIELD_HYBRID_ARM] = $armBlock;
            $baseResult[self::FIELD_IMMUNE_SIGNATURE] = $signatureBlock;

            return $baseResult;
        }

        $scores = $this->computeSemanticScores($text);
        $armBlock[self::FIELD_SOURCE] = $scores === null ? self::SOURCE_UNAVAILABLE : self::SOURCE_JACCARD_BASELINE;
        if ($scores === null) {
            $baseResult[self::FIELD_HYBRID_ARM] = $armBlock;
            $baseResult[self::FIELD_IMMUNE_SIGNATURE] = $signatureBlock;

            return $baseResult;
        }

        $bestClass = null;
        $bestScore = 0.0;
        foreach ($scores as $class => $score) {
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestClass = $class;
            }
        }
        $armBlock[self::FIELD_MAX_SIMILARITY] = round($bestScore, 6);
        $armBlock[self::FIELD_SCORES_BY_CLASS] = array_map(static fn (float $v): float => round($v, 6), $scores);

        if ($bestClass !== null && $bestScore >= $this->tau) {
            $armBlock[self::FIELD_HOSTILE_CLASS_CANDIDATE] = $bestClass;
        }

        $lexicalHostile = $armBlock[self::FIELD_LEXICAL_HOSTILE_CLASS];
        $semanticHostile = $armBlock[self::FIELD_HOSTILE_CLASS_CANDIDATE];

        $winner = $this->maxSeverity($lexicalHostile, $semanticHostile);
        if ($winner !== null && $winner !== $lexicalHostile) {
            $armBlock[self::FIELD_WINNER_SOURCE] = self::FIELD_SEMANTIC;
            $armBlock[self::FIELD_OVERRIDE_APPLIED] = true;
            $baseResult[self::FIELD_INPUT_CLASS] = $winner;
            $baseResult[self::FIELD_REASON] = self::FIELD_SEMANTIC_ARM_SIMILARITY_.number_format($bestScore, 3);
            $baseResult[self::FIELD_MATCHED_SIGNALS] = $this->augmentSignals($baseResult[self::FIELD_MATCHED_SIGNALS], self::FIELD_SEMANTIC_ARM_HIT);
            $baseResult[self::FIELD_MEMORY_ELIGIBLE] = false;
            $baseResult[self::FIELD_EMBEDDING_ALLOWED] = false;
            $baseResult[self::FIELD_DEFAULT_DESTINATION] = self::hostileDestination($winner);
        } elseif ($winner !== null && $winner === $lexicalHostile) {
            $armBlock[self::FIELD_WINNER_SOURCE] = $semanticHostile === $lexicalHostile ? self::FIELD_AGREEMENT : self::FIELD_LEXICAL;
        }

        $baseResult[self::FIELD_HYBRID_ARM] = $armBlock;
        $baseResult[self::FIELD_IMMUNE_SIGNATURE] = $signatureBlock;

        return $baseResult;
    }

    /**
     * @param  array<string,mixed>  $baseResult
     * @return array<string,mixed>
     */
    private function offHybridArm(array $baseResult): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => AtlasImmuneClassifierHybridFreeze::MEASURE_ID,
            self::FIELD_ENABLED => false,
            self::FIELD_SOURCE => self::FIELD_OFF,
            self::FIELD_TAU => $this->tau,
            self::FIELD_MAX_SIMILARITY => null,
            self::FIELD_HOSTILE_CLASS_CANDIDATE => null,
            self::FIELD_LEXICAL_HOSTILE_CLASS => in_array($baseResult[self::FIELD_INPUT_CLASS], self::HOSTILE_SEVERITY, true)
                ? (AiValueNormalizer::trimmedScalarStringOrNull($baseResult[self::FIELD_INPUT_CLASS] ?? null) ?? '')
                : null,
            self::FIELD_WINNER_SOURCE => self::FIELD_IMMUNE_SIGNATURE,
            self::FIELD_OVERRIDE_APPLIED => true,
        ];
    }

    /**
     * @return array<string,float>|null null iff the port reported unavailability
     */
    private function computeSemanticScores(string $text): ?array
    {
        $out = [];
        $sawResult = false;
        foreach ($this->anchors() as $class => $exemplars) {
            $best = 0.0;
            foreach ($exemplars as $exemplar) {
                $score = $this->port->similarity($text, $exemplar);
                if ($score === null) {
                    continue;
                }
                $sawResult = true;
                if ($score > $best) {
                    $best = $score;
                }
            }
            $out[$class] = $best;
        }

        return $sawResult ? $out : null;
    }

    private function maxSeverity(?string $a, ?string $b): ?string
    {
        foreach (self::HOSTILE_SEVERITY as $class) {
            if ($a === $class || $b === $class) {
                return $class;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $signals
     * @return list<string>
     */
    private function augmentSignals(array $signals, string $signal): array
    {
        $signals[] = $signal;
        sort($signals);

        return array_values(array_unique($signals));
    }

    private static function hostileDestination(string $class): string
    {
        return match ($class) {
            self::FIELD_PROMPT_INJECTION => self::FIELD_BLOCKED_EPHEMERAL_EVIDENCE,
            self::FIELD_PRIVATE_SENSITIVE => self::FIELD_REDACT_MINIMIZE,
            self::FIELD_UNTRUSTED_CONTENT => self::FIELD_CITED_DATA_NOT_INSTRUCTION,
            default => self::FIELD_BLOCKED_EPHEMERAL_EVIDENCE,
        };
    }

    /**
     * @return array<string,list<string>>
     */
    private function anchors(): array
    {
        if ($this->anchors !== null) {
            return $this->anchors;
        }

        $path = base_path(AtlasImmuneClassifierHybridFreeze::ANCHOR_FIXTURE_RELATIVE);
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return $this->anchors = [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->anchors = [];
        }
        $classes = AiValueNormalizer::arrayOrEmpty($decoded[self::FIELD_CLASSES] ?? null);
        $out = [];
        foreach ($classes as $class => $exemplars) {
            $class = AiValueNormalizer::trimmedStringOrNull(is_string($class) ? $class : null);
            if ($class === null || ! is_array($exemplars)) {
                continue;
            }
            $list = [];
            foreach ($exemplars as $exemplar) {
                $exemplar = AiValueNormalizer::trimmedStringOrNull($exemplar);
                if ($exemplar !== null) {
                    $list[] = $exemplar;
                }
            }
            if ($list !== []) {
                $out[$class] = $list;
            }
        }

        return $this->anchors = $out;
    }
}
