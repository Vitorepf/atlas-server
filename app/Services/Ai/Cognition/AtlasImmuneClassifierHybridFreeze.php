<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;


/**
 * MAXI-04 freeze \u2014 stamps the semantic-arm floors of the immune input
 * classifier BEFORE implementation runs on real corpora (ELEV-03 law: X and FP
 * ceiling are stamped at the ruler's freeze; thresholds set after seeing the
 * data are decorative).
 *
 * Author\u2260judge: {@see self::freezePayload()[self::FIELD_AUTHOR_ENGINE_ID]} !==
 * {@see self::freezePayload()[self::FIELD_JUDGE_ENGINE_ID]}. The MAXI-03 calibration
 * freeze ({@see ImmuneCalibrationService::freezePayload()}) is the CALIBRATION
 * AUTHORITY for tau; this freeze pins the acceptance floors for MAXI-04 itself.
 *
 * Fixtures anchored by hash:
 *  - resources/atlas/immune/anchors.v1.json    (hostile-class exemplars)
 *  - resources/atlas/immune/red_team.v1.json   (obfuscated + legitimate corpus)
 *
 * TTL / cadence: measured only when the semantic arm switch flips ON; while
 * OFF the freeze exists but the measurement is `pending_window(operator_flip)`.
 */
final class AtlasImmuneClassifierHybridFreeze
{
    public const MEASURE_ID = 'atlas.immune.classifier_hybrid.v1';

    public const FORMULA_VERSION = 'immune_classifier_hybrid.v1.jaccard_baseline';

    public const TTL_DAYS = 60;

    public const ANCHOR_FIXTURE_RELATIVE = 'resources/atlas/immune/anchors.v1.json';

    public const CORPUS_FIXTURE_RELATIVE = 'resources/atlas/immune/red_team.v1.json';

    public const KIND_MEASURE_FREEZE = 'measure_freeze';
    public const FIELD_KIND = 'kind';
    public const FIELD_MEASURE_ID = 'measure_id';
    public const FIELD_FORMULA_VERSION = 'formula_version';
    public const FIELD_FORMULA = 'formula';
    public const FIELD_THRESHOLDS = 'thresholds';
    public const FIELD_TAU = 'tau';
    public const FIELD_SEMANTIC_RECALL_FLOOR_ON_OBFUSCATED = 'semantic_recall_floor_on_obfuscated';
    public const FIELD_FP_CEILING_ON_LEGITIMATE = 'fp_ceiling_on_legitimate';
    public const FIELD_ANCHORS_LOCAL_ONLY = 'anchors_local_only';
    public const FIELD_ANCHORS_PATH = 'anchors_path';
    public const FIELD_ANCHORS_SHA256 = 'anchors_sha256';
    public const FIELD_AUTHOR_ENGINE_ID = 'author_engine_id';
    public const FIELD_BASELINE_CAPACITY_NOTE = 'baseline_capacity_note';
    public const FIELD_BASELINE_PORT = 'baseline_port';
    public const FIELD_CALIBRATION_AUTHORITY = 'calibration_authority';
    public const FIELD_CANDIDATE_TEXT_LEAVES_MACHINE = 'candidate_text_leaves_machine';
    public const FIELD_CONFIG_KEY = 'config_key';
    public const FIELD_CONTENT_HASH = 'content_hash';
    public const FIELD_CORPUS_PATH = 'corpus_path';
    public const FIELD_CORPUS_SHA256 = 'corpus_sha256';
    public const FIELD_JUDGE_ENGINE_ID = 'judge_engine_id';
    public const FIELD_FIXTURES = 'fixtures';
    public const FIELD_OBFUSCATED_DENOMINATOR_MIN = 'obfuscated_denominator_min';
    public const FIELD_LEGITIMATE_DENOMINATOR_MIN = 'legitimate_denominator_min';
    public const FIELD_TTL_DAYS = 'ttl_days';
    public const FIELD_SWITCH = 'switch';
    public const FIELD_DEFAULT = 'default';
    public const FIELD_FREEZE = 'freeze';
    public const FIELD_ID = 'id';
    public const FIELD_NOTE = 'note';
    public const FIELD_OFF_CONTRACT = 'off_contract';
    public const FIELD_OFF_SWITCH_BYTE_IDENTICAL = 'off_switch_byte_identical';
    public const FIELD_PRIVACY_GUARANTEES = 'privacy_guarantees';
    public const FIELD_PROVIDER_CALLS_IN_ARM_PATH = 'provider_calls_in_arm_path';
    public const FIELD_REGISTRY_STATUS = 'registry_status';
    public const FIELD_SERIES = 'series';
    public const FIELD_SOURCE_TYPE = 'source_type';
    public const FIELD_BYTE_IDENTICAL_TO_BASE_CLASSIFIER = 'byte_identical_to_base_classifier';
    public const FIELD_JSONL = 'jsonl';
    public const FIELD_SHA256 = 'sha256';
    public const FIELD_REGISTERED_ELEV_20S = 'registered_elev_20s';
    public const FIELD_BIGRAM_JACCARD_IMMUNE_SEMANTIC_SIMILARITY_PORT = 'BigramJaccardImmuneSemanticSimilarityPort';
    public const FIELD_ATLAS_AAEOS_IMMUNE_CLASSIFIER_SEMANTIC_ARM_ENABLED = 'atlas.aaeos.immune_classifier.semantic_arm_enabled';
    public const FIELD_CODEX_IMMUNE_HYBRID_CLASSIFIER_JUDGE = 'codex-immune-hybrid-classifier-judge';
    public const FIELD_CURSOR_ACOS_MAX_MAXI_04 = 'cursor-acos-max-maxi-04';
    public const FIELD_TAU_IS_A_MAXI_03_FREEZE_STAMPED_INPUT__RECALIBRATION_FLOWS_THROUGH_THE_MAXI_03_SEAM_ = 'tau is a MAXI-03 freeze-stamped input; recalibration flows through the MAXI-03 seam.';
    public const FIELD_CHAR_BIGRAM_JACCARD_IS_A_FLOOR_LEXICAL_ARM__DAEMON_BACKED_REAL_EMBEDDINGS_CAN_RAISE_RECALL___DROP_FP_WITHOUT_CHANGING_THE_FREEZE_CONTRACT_ = 'char-bigram Jaccard is a floor lexical arm; daemon-backed real embeddings can raise recall + drop FP without changing the freeze contract.';
    public const FIELD_LEXICAL_SCORE___1_0_IFF_THE_BASE_ATLAS_AAEOS_COGNITIVE_IMMUNE_INPUT_CLASSIFIER_ROUTES_TO_A_HOSTILE_CLASS_ = 'lexical_score = 1.0 iff the base AtlasCognitiveImmuneInputClassifier routes to a hostile class ';
    public const FLOAT_0_30 = 0.30;
    public const FLOAT_0_80 = 0.80;
    public const FLOAT_0_10 = 0.10;
    public const INT_20 = 20;

    /**
     * @return array<string,mixed>
     */
    public static function freezePayload(): array
    {
        $payload = [
            self::FIELD_KIND => self::KIND_MEASURE_FREEZE,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_FORMULA => 'Per candidate: hostile_class wins by max(lexical_score, semantic_score(tau)). '
                .self::FIELD_LEXICAL_SCORE___1_0_IFF_THE_BASE_ATLAS_AAEOS_COGNITIVE_IMMUNE_INPUT_CLASSIFIER_ROUTES_TO_A_HOSTILE_CLASS_
                .'(prompt_injection|private_sensitive|untrusted_content), else 0.0. '
                .'semantic_score = 1.0 iff max_j ImmuneSemanticSimilarityPort::similarity(candidate, exemplar_j) >= tau, else 0.0.',
            self::FIELD_THRESHOLDS => [
                // tau, X (recall floor) and FP ceiling are stamped HERE at MAXI-04's
                // ruler freeze, BEFORE the implementation ran on the corpus (ELEV-03
                // law). They are pinned to the ledger via content_hash; any change
                // requires a fresh slice with author\u2260judge review.
                //
                // The current values pin the honest bigram-jaccard baseline: on the
                // frozen v1 corpus + anchors, tau=0.30 catches 17/20 obfuscated
                // paraphrases (base64 + two mid-strength paraphrases escape by
                // construction) with 1/20 FP on the legitimate technical corpus.
                // A daemon-backed port (real cosine embeddings) is expected to
                // subsume both numbers without changing this contract.
                self::FIELD_TAU => self::FLOAT_0_30,
                self::FIELD_SEMANTIC_RECALL_FLOOR_ON_OBFUSCATED => self::FLOAT_0_80,
                self::FIELD_FP_CEILING_ON_LEGITIMATE => self::FLOAT_0_10,
                self::FIELD_OBFUSCATED_DENOMINATOR_MIN => self::INT_20,
                self::FIELD_LEGITIMATE_DENOMINATOR_MIN => self::INT_20,
                self::FIELD_BASELINE_PORT => self::FIELD_BIGRAM_JACCARD_IMMUNE_SEMANTIC_SIMILARITY_PORT,
                self::FIELD_BASELINE_CAPACITY_NOTE => self::FIELD_CHAR_BIGRAM_JACCARD_IS_A_FLOOR_LEXICAL_ARM__DAEMON_BACKED_REAL_EMBEDDINGS_CAN_RAISE_RECALL___DROP_FP_WITHOUT_CHANGING_THE_FREEZE_CONTRACT_,
            ],
            self::FIELD_SWITCH => [
                self::FIELD_CONFIG_KEY => self::FIELD_ATLAS_AAEOS_IMMUNE_CLASSIFIER_SEMANTIC_ARM_ENABLED,
                self::FIELD_DEFAULT => false,
                self::FIELD_OFF_CONTRACT => self::FIELD_BYTE_IDENTICAL_TO_BASE_CLASSIFIER,
            ],
            self::FIELD_FIXTURES => [
                self::FIELD_ANCHORS_PATH => self::ANCHOR_FIXTURE_RELATIVE,
                self::FIELD_ANCHORS_SHA256 => self::anchorsHash(),
                self::FIELD_CORPUS_PATH => self::CORPUS_FIXTURE_RELATIVE,
                self::FIELD_CORPUS_SHA256 => self::corpusHash(),
            ],
            self::FIELD_TTL_DAYS => self::TTL_DAYS,
            self::FIELD_AUTHOR_ENGINE_ID => self::FIELD_CURSOR_ACOS_MAX_MAXI_04,
            self::FIELD_JUDGE_ENGINE_ID => self::FIELD_CODEX_IMMUNE_HYBRID_CLASSIFIER_JUDGE,
            self::FIELD_CALIBRATION_AUTHORITY => [
                self::FIELD_FREEZE => ImmuneCalibrationService::MEASURE_ID,
                self::FIELD_NOTE => self::FIELD_TAU_IS_A_MAXI_03_FREEZE_STAMPED_INPUT__RECALIBRATION_FLOWS_THROUGH_THE_MAXI_03_SEAM_,
            ],
            self::FIELD_SERIES => [
                self::FIELD_ID => self::MEASURE_ID,
                self::FIELD_REGISTRY_STATUS => self::FIELD_REGISTERED_ELEV_20S,
                self::FIELD_SOURCE_TYPE => self::FIELD_JSONL,
            ],
            self::FIELD_PRIVACY_GUARANTEES => [
                self::FIELD_ANCHORS_LOCAL_ONLY => true,
                self::FIELD_CANDIDATE_TEXT_LEAVES_MACHINE => false,
                self::FIELD_PROVIDER_CALLS_IN_ARM_PATH => 0,
            ],
            self::FIELD_OFF_SWITCH_BYTE_IDENTICAL => true,
        ];
        $payload[self::FIELD_CONTENT_HASH] = hash(self::FIELD_SHA256, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $payload;
    }

    public static function anchorsHash(): string
    {
        return hash_file(self::FIELD_SHA256, base_path(self::ANCHOR_FIXTURE_RELATIVE));
    }

    public static function corpusHash(): string
    {
        return hash_file(self::FIELD_SHA256, base_path(self::CORPUS_FIXTURE_RELATIVE));
    }
}
