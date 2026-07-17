<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Aaeos\AtlasAaeosCognitiveImmuneInputClassifier;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * MAXI-04 \u2014 the hybrid classifier that wraps the base
 * {@see AtlasAaeosCognitiveImmuneInputClassifier} with an OPTIONAL semantic arm
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

    private readonly AtlasAaeosCognitiveImmuneInputClassifier $base;

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
        ?AtlasAaeosCognitiveImmuneInputClassifier $base = null,
        ?ImmuneSemanticSimilarityPort $port = null,
        ?array $anchors = null,
        ?float $tau = null,
        ?bool $enabled = null,
        ?ImmuneSignatureStore $signatureStore = null,
    ) {
        $this->base = $base ?? new AtlasAaeosCognitiveImmuneInputClassifier;
        $this->port = $port ?? new BigramJaccardImmuneSemanticSimilarityPort;
        $this->signatureStore = $signatureStore ?? new ImmuneSignatureStore;
        $this->anchors = $anchors;
        $freeze = AtlasImmuneClassifierHybridFreeze::freezePayload();
        $this->tau = $tau ?? (AiValueNormalizer::finiteFloatOrNull($freeze['thresholds']['tau'] ?? null) ?? 0.62);
        $this->enabled = $enabled ?? (AiValueNormalizer::boolOrNull(config(self::SEMANTIC_ARM_ENABLED_CONFIG_KEY, self::DEFAULT_SEMANTIC_ARM_ENABLED)) ?? self::DEFAULT_SEMANTIC_ARM_ENABLED);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    public function classifyHybrid(string $text, array $metadata = []): array
    {
        $signatureBlock = [
            'schema_version' => AtlasImmuneSignatureFreeze::MEASURE_ID,
            'mode' => $this->signatureStore->mode(),
            'matched' => false,
            self::FIELD_REF => null,
            'enforce_applied' => false,
        ];

        $knownSignature = $this->signatureStore->consult($text, $metadata);
        if ($knownSignature !== null) {
            $signatureBlock['matched'] = true;
            $signatureBlock[self::FIELD_REF] = $knownSignature[self::FIELD_REF];
            $signatureBlock['signature'] = $knownSignature['signature'];
            $signatureBlock['origin_ref'] = $knownSignature['origin_ref'];
            $signatureBlock['hit_count_after'] = $knownSignature['hit_count_after'];
        }

        if ($knownSignature !== null && $this->signatureStore->enforceEnabled()) {
            $hostileClass = AiValueNormalizer::trimmedScalarStringOrNull($knownSignature['hostile_class'] ?? null) ?? '';
            $baseResult = $this->base->classify($text, $metadata);
            $baseResult[self::FIELD_INPUT_CLASS] = $hostileClass;
            $baseResult['reason'] = 'known_poison_signature:'.(AiValueNormalizer::trimmedScalarStringOrNull($knownSignature[self::FIELD_REF] ?? null) ?? '');
            $baseResult[self::FIELD_MATCHED_SIGNALS] = $this->augmentSignals(
                $baseResult[self::FIELD_MATCHED_SIGNALS],
                'known_poison_signature',
            );
            $baseResult['memory_eligible'] = false;
            $baseResult['embedding_allowed'] = false;
            $baseResult['default_destination'] = self::hostileDestination($hostileClass);
            $signatureBlock['enforce_applied'] = true;
            $baseResult[self::FIELD_IMMUNE_SIGNATURE] = $signatureBlock;
            $baseResult[self::FIELD_HYBRID_ARM] = $this->offHybridArm($baseResult);

            return $baseResult;
        }

        $baseResult = $this->base->classify($text, $metadata);

        $armBlock = [
            'schema_version' => AtlasImmuneClassifierHybridFreeze::MEASURE_ID,
            self::FIELD_ENABLED => $this->enabled,
            'source' => 'off',
            'tau' => $this->tau,
            'max_similarity' => null,
            self::FIELD_HOSTILE_CLASS_CANDIDATE => null,
            'lexical_hostile_class' => in_array($baseResult[self::FIELD_INPUT_CLASS], self::HOSTILE_SEVERITY, true)
                ? (AiValueNormalizer::trimmedScalarStringOrNull($baseResult[self::FIELD_INPUT_CLASS] ?? null) ?? '')
                : null,
            self::FIELD_WINNER_SOURCE => 'lexical',
            'override_applied' => false,
        ];

        if (! $this->enabled) {
            $baseResult[self::FIELD_HYBRID_ARM] = $armBlock;
            $baseResult[self::FIELD_IMMUNE_SIGNATURE] = $signatureBlock;

            return $baseResult;
        }

        $scores = $this->computeSemanticScores($text);
        $armBlock['source'] = $scores === null ? self::SOURCE_UNAVAILABLE : self::SOURCE_JACCARD_BASELINE;
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
        $armBlock['max_similarity'] = round($bestScore, 6);
        $armBlock['scores_by_class'] = array_map(static fn (float $v): float => round($v, 6), $scores);

        if ($bestClass !== null && $bestScore >= $this->tau) {
            $armBlock[self::FIELD_HOSTILE_CLASS_CANDIDATE] = $bestClass;
        }

        $lexicalHostile = $armBlock['lexical_hostile_class'];
        $semanticHostile = $armBlock[self::FIELD_HOSTILE_CLASS_CANDIDATE];

        $winner = $this->maxSeverity($lexicalHostile, $semanticHostile);
        if ($winner !== null && $winner !== $lexicalHostile) {
            $armBlock[self::FIELD_WINNER_SOURCE] = 'semantic';
            $armBlock['override_applied'] = true;
            $baseResult[self::FIELD_INPUT_CLASS] = $winner;
            $baseResult['reason'] = 'semantic_arm_similarity_'.number_format($bestScore, 3);
            $baseResult[self::FIELD_MATCHED_SIGNALS] = $this->augmentSignals($baseResult[self::FIELD_MATCHED_SIGNALS], 'semantic_arm_hit');
            $baseResult['memory_eligible'] = false;
            $baseResult['embedding_allowed'] = false;
            $baseResult['default_destination'] = self::hostileDestination($winner);
        } elseif ($winner !== null && $winner === $lexicalHostile) {
            $armBlock[self::FIELD_WINNER_SOURCE] = $semanticHostile === $lexicalHostile ? 'agreement' : 'lexical';
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
            'schema_version' => AtlasImmuneClassifierHybridFreeze::MEASURE_ID,
            self::FIELD_ENABLED => false,
            'source' => 'off',
            'tau' => $this->tau,
            'max_similarity' => null,
            self::FIELD_HOSTILE_CLASS_CANDIDATE => null,
            'lexical_hostile_class' => in_array($baseResult[self::FIELD_INPUT_CLASS], self::HOSTILE_SEVERITY, true)
                ? (AiValueNormalizer::trimmedScalarStringOrNull($baseResult[self::FIELD_INPUT_CLASS] ?? null) ?? '')
                : null,
            self::FIELD_WINNER_SOURCE => 'immune_signature',
            'override_applied' => true,
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
            'prompt_injection' => 'blocked_ephemeral_evidence',
            'private_sensitive' => 'redact_minimize',
            'untrusted_content' => 'cited_data_not_instruction',
            default => 'blocked_ephemeral_evidence',
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
        $classes = AiValueNormalizer::arrayOrEmpty($decoded['classes'] ?? null);
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
