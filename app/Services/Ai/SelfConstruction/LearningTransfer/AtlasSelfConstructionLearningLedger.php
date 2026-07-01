<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use RuntimeException;

/**
 * Append-only JSONL ledger of admitted lessons. Idempotent on lesson_hash; kernel
 * JsonlReceiptStore (flock LOCK_EX) on every write; never overwrites prior rows; validates
 * required fields before append.
 *
 * Default path: storage/atlas/governance/learning-ledger.jsonl (production). Tests pass a
 * custom path through the constructor.
 */
final class AtlasSelfConstructionLearningLedger
{
    public const SCHEMA = 'atlas.learning_transfer.learning_ledger.v1';

    public const REQUIRED_FIELDS = [
        'lesson_id',
        'class',
        'decision',
        'observation_ts',
        'reasons',
        'evidence_refs',
    ];

    public const CLASS_POISON_PATTERN = 'poison_pattern';

    public const CLASS_SUCCESS_PATTERN = 'success_pattern';

    /** A poison_pattern lesson must name what future failure it prevents and how to repair it. */
    private const POISON_PATTERN_REQUIRED_FIELDS = [
        'give_back_root',
        'prevented_future_failure',
        'repair_strategy',
    ];

    /** A success_pattern lesson must name the reusable design path, not just that it worked once. */
    private const SUCCESS_PATTERN_REQUIRED_FIELDS = [
        'task_family',
        'green_commit_ref',
        'reusable_design_path',
    ];

    /** Case-insensitive substrings that make an evidence_refs entry a placeholder, not real proof. */
    private const PLACEHOLDER_EVIDENCE_NEEDLES = ['todo', 'fake', 'synthetic', 'example', 'tbd'];

    public function __construct(private readonly ?string $ledgerPath = null) {}

    /**
     * @param  array<string,mixed>  $lesson
     * @return array<string,mixed>
     */
    public function append(array $lesson): array
    {
        $this->validate($lesson);
        $lessonHash = $this->lessonHash($lesson);
        $row = [
            'schema_version' => self::SCHEMA,
            'lesson_hash' => $lessonHash,
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'lesson' => $this->sortRecursive($lesson),
        ];
        ksort($row, SORT_STRING);

        // Idempotency check runs INSIDE the store's write lock; null return aborts the append.
        $written = (new JsonlReceiptStore($this->path()))->appendWith(
            fn (?string $lastLine): ?array => $this->findByHash($lessonHash) !== null ? null : $row,
        );

        return [
            'schema_version' => self::SCHEMA,
            'status' => $written === null ? 'already_recorded' : 'recorded',
            'lesson_hash' => $lessonHash,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return (new JsonlReceiptStore($this->path()))->replay();
    }

    public function path(): string
    {
        if ($this->ledgerPath !== null && $this->ledgerPath !== '') {
            return $this->ledgerPath;
        }
        if (function_exists('storage_path')) {
            return storage_path('atlas/governance/learning-ledger.jsonl');
        }

        return sys_get_temp_dir().'/atlas-learning-ledger.jsonl';
    }

    /**
     * @param  array<string,mixed>  $lesson
     */
    public function lessonHash(array $lesson): string
    {
        return hash('sha256', (string) json_encode($this->sortRecursive($lesson), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $lesson
     */
    private function validate(array $lesson): void
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $lesson)) {
                throw new RuntimeException('learning_ledger_missing_field:'.$field);
            }
        }
        if ((string) $lesson['lesson_id'] === '') {
            throw new RuntimeException('learning_ledger_empty_lesson_id');
        }
        if ((string) $lesson['class'] === '') {
            throw new RuntimeException('learning_ledger_empty_class');
        }
        if ((string) $lesson['decision'] === '') {
            throw new RuntimeException('learning_ledger_empty_decision');
        }
        if ((string) $lesson['observation_ts'] === '') {
            throw new RuntimeException('learning_ledger_empty_observation_ts');
        }
        if (! is_array($lesson['reasons'])) {
            throw new RuntimeException('learning_ledger_reasons_not_array');
        }
        if (! is_array($lesson['evidence_refs'])) {
            throw new RuntimeException('learning_ledger_evidence_refs_not_array');
        }
        if ($lesson['evidence_refs'] === []) {
            throw new RuntimeException('learning_ledger_evidence_refs_empty');
        }
        foreach ($lesson['evidence_refs'] as $ref) {
            $refValue = strtolower(trim((string) $ref));
            if ($refValue === '') {
                throw new RuntimeException('learning_ledger_evidence_refs_empty_entry');
            }
            foreach (self::PLACEHOLDER_EVIDENCE_NEEDLES as $needle) {
                if (str_contains($refValue, $needle)) {
                    throw new RuntimeException('learning_ledger_evidence_refs_placeholder:'.$needle);
                }
            }
        }
        foreach (['raw_prompt', 'provider_trace', 'conversation_text', 'secret'] as $forbidden) {
            if (array_key_exists($forbidden, $lesson)) {
                throw new RuntimeException('learning_ledger_forbidden_field:'.$forbidden);
            }
        }

        $class = (string) $lesson['class'];
        if ($class === self::CLASS_POISON_PATTERN) {
            $this->requireNonEmptyFields($lesson, self::POISON_PATTERN_REQUIRED_FIELDS, 'poison_pattern');
        }
        if ($class === self::CLASS_SUCCESS_PATTERN) {
            $this->requireNonEmptyFields($lesson, self::SUCCESS_PATTERN_REQUIRED_FIELDS, 'success_pattern');
        }
    }

    /**
     * @param  array<string,mixed>  $lesson
     * @param  list<string>  $fields
     */
    private function requireNonEmptyFields(array $lesson, array $fields, string $classLabel): void
    {
        foreach ($fields as $field) {
            if (trim((string) ($lesson[$field] ?? '')) === '') {
                throw new RuntimeException("learning_ledger_{$classLabel}_missing_field:{$field}");
            }
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findByHash(string $hash): ?array
    {
        foreach ($this->all() as $row) {
            if ((string) ($row['lesson_hash'] ?? '') === $hash) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $v): mixed => $this->sortRecursive($v), $value);
        }
        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->sortRecursive($v);
        }

        return $out;
    }
}
