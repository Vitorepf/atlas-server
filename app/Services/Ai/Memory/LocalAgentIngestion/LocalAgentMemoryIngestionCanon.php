<?php

namespace App\Services\Ai\Memory\LocalAgentIngestion;

/**
 * Canonical enums for `atlas.ai.local_agent_ingestion.*.v1`.
 *
 * The pipeline produces 3 persisted entity types (run, source, candidate)
 * plus a synthetic receipt. All schemas live behind the `atlas.ai.` prefix
 * to align with the Compounding/Memory schema family.
 *
 * Threat-model invariants encoded here:
 *  - secret content NEVER appears in any persisted column;
 *  - source rows store hashes + alias-relative path + redaction summary;
 *  - candidates only reference sources, they don't duplicate raw text;
 *  - promotion is intentionally OUT OF SCOPE — the pipeline emits
 *    quarantined candidates only. A future review path can promote them.
 */
final class LocalAgentMemoryIngestionCanon
{
    public const RUN_SCHEMA_VERSION = 'atlas.ai.local_agent_ingestion.run.v1';

    public const SOURCE_SCHEMA_VERSION = 'atlas.ai.local_agent_ingestion.source.v1';

    public const CANDIDATE_SCHEMA_VERSION = 'atlas.ai.local_agent_ingestion.candidate.v1';

    public const RECEIPT_SCHEMA_VERSION = 'atlas.ai.local_agent_ingestion.receipt.v1';

    public const RUN_STATUS_RUNNING = 'running';

    public const RUN_STATUS_COMPLETED = 'completed';

    public const RUN_STATUS_FAILED = 'failed';

    public const RUN_STATUS_DRY_RUN = 'dry_run';

    /** @var array<int,string> */
    public const RUN_STATUSES = [
        self::RUN_STATUS_RUNNING,
        self::RUN_STATUS_COMPLETED,
        self::RUN_STATUS_FAILED,
        self::RUN_STATUS_DRY_RUN,
    ];

    public const SOURCE_STATUS_INGESTED = 'ingested';

    public const SOURCE_STATUS_SKIPPED = 'skipped';

    public const SOURCE_STATUS_QUARANTINED = 'quarantined';

    public const SOURCE_STATUS_DUPLICATE = 'duplicate';

    /** @var array<int,string> */
    public const SOURCE_STATUSES = [
        self::SOURCE_STATUS_INGESTED,
        self::SOURCE_STATUS_SKIPPED,
        self::SOURCE_STATUS_QUARANTINED,
        self::SOURCE_STATUS_DUPLICATE,
    ];

    public const CLASS_GOAL_PROMPT = 'goal_prompt';

    public const CLASS_IMPLEMENTATION_PLAN = 'implementation_plan';

    public const CLASS_ERROR_TRACE = 'error_trace';

    public const CLASS_SUCCESSFUL_FIX = 'successful_fix';

    public const CLASS_OPERATOR_PREFERENCE = 'operator_preference';

    public const CLASS_TOOL_RECIPE = 'tool_recipe';

    public const CLASS_SENSITIVE_SECRET = 'sensitive_secret';

    public const CLASS_STALE_CONTEXT = 'stale_context';

    public const CLASS_UNTRUSTED_OUTPUT = 'untrusted_output';

    public const CLASS_UNCLASSIFIED = 'unclassified';

    /** @var array<int,string> */
    public const SOURCE_CLASSES = [
        self::CLASS_GOAL_PROMPT,
        self::CLASS_IMPLEMENTATION_PLAN,
        self::CLASS_ERROR_TRACE,
        self::CLASS_SUCCESSFUL_FIX,
        self::CLASS_OPERATOR_PREFERENCE,
        self::CLASS_TOOL_RECIPE,
        self::CLASS_SENSITIVE_SECRET,
        self::CLASS_STALE_CONTEXT,
        self::CLASS_UNTRUSTED_OUTPUT,
        self::CLASS_UNCLASSIFIED,
    ];

    public const SKIP_SIZE_EXCEEDED = 'size_exceeded';

    public const SKIP_BINARY_DETECTED = 'binary_detected';

    public const SKIP_EXTENSION_NOT_ALLOWED = 'extension_not_allowed';

    public const SKIP_DENYLIST_PATTERN = 'denylist_pattern';

    public const SKIP_PATH_OUTSIDE_ROOT = 'path_outside_root';

    public const SKIP_UNREADABLE = 'unreadable';

    public const SKIP_DUPLICATE = 'duplicate';

    public const SKIP_SECRET_BLOCKED = 'secret_blocked';

    public const SKIP_DISCOVERY_TRUNCATED = 'discovery_truncated';

    /** @var array<int,string> */
    public const SKIP_REASONS = [
        self::SKIP_SIZE_EXCEEDED,
        self::SKIP_BINARY_DETECTED,
        self::SKIP_EXTENSION_NOT_ALLOWED,
        self::SKIP_DENYLIST_PATTERN,
        self::SKIP_PATH_OUTSIDE_ROOT,
        self::SKIP_UNREADABLE,
        self::SKIP_DUPLICATE,
        self::SKIP_SECRET_BLOCKED,
        self::SKIP_DISCOVERY_TRUNCATED,
    ];

    public const CANDIDATE_STATUS_QUARANTINED = 'quarantined';

    public const CANDIDATE_STATUS_REJECTED = 'rejected';

    public const CANDIDATE_STATUS_PROMOTED = 'promoted';

    /** @var array<int,string> */
    public const CANDIDATE_STATUSES = [
        self::CANDIDATE_STATUS_QUARANTINED,
        self::CANDIDATE_STATUS_REJECTED,
        self::CANDIDATE_STATUS_PROMOTED,
    ];

    /**
     * Source classes that always block memory promotion regardless of
     * downstream review — implements the canon's "secret never becomes memory"
     * rule.
     *
     * @return array<int,string>
     */
    public static function promotionBlockedClasses(): array
    {
        return [self::CLASS_SENSITIVE_SECRET];
    }
}
