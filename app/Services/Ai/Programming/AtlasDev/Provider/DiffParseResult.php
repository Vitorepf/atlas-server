<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Provider;

use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use InvalidArgumentException;

/**
 * Structured outcome of parsing the provider's raw stdout.
 *
 * The output_contract clause in the canonical prompt accepts exactly four
 * provider responses:
 *   - patch (unified diff + changed_files);
 *   - no_patch_needed (boolean + reason);
 *   - blocked (boolean + question);
 *   - invalid (nothing parseable / contract violation).
 *
 * Anything else collapses to MODE_INVALID with descriptive errors so
 * downstream gates can fail honestly.
 */
final class DiffParseResult
{
    public const SCHEMA_VERSION = 'atlas.dev.diff_parse_result.v1';

    public const MODE_PATCH = 'patch';

    public const MODE_NO_PATCH_NEEDED = 'no_patch_needed';

    public const MODE_BLOCKED = 'blocked';

    public const MODE_INVALID = 'invalid';

    public const ALLOWED_MODES = [
        self::MODE_PATCH,
        self::MODE_NO_PATCH_NEEDED,
        self::MODE_BLOCKED,
        self::MODE_INVALID,
    ];

    /**
     * @param  list<string>  $changedFiles
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly string $mode,
        public readonly ?string $diff,
        public readonly array $changedFiles,
        public readonly ?string $reason,
        public readonly ?string $question,
        public readonly array $errors = [],
    ) {
        if (! in_array($this->mode, self::ALLOWED_MODES, true)) {
            throw new InvalidArgumentException(
                'DiffParseResult.mode must be one of ['.implode(',', self::ALLOWED_MODES)."], got '{$this->mode}'."
            );
        }
        AtlasDevStringListNormalizer::requireNonEmptyStrings($this->changedFiles, 'DiffParseResult.changed_files');
        AtlasDevStringListNormalizer::requireNonEmptyStrings($this->errors, 'DiffParseResult.errors');
        if ($this->mode === self::MODE_PATCH && ($this->diff === null || $this->diff === '')) {
            throw new InvalidArgumentException('DiffParseResult: MODE_PATCH requires non-empty diff.');
        }
        if ($this->mode === self::MODE_NO_PATCH_NEEDED && ($this->reason === null || $this->reason === '')) {
            throw new InvalidArgumentException('DiffParseResult: MODE_NO_PATCH_NEEDED requires reason.');
        }
        if ($this->mode === self::MODE_BLOCKED && ($this->question === null || $this->question === '')) {
            throw new InvalidArgumentException('DiffParseResult: MODE_BLOCKED requires question.');
        }
    }

    public function diffHash(): ?string
    {
        if ($this->diff === null || $this->diff === '') {
            return null;
        }

        return hash('sha256', $this->diff);
    }

    public function hasPatch(): bool
    {
        return $this->mode === self::MODE_PATCH;
    }

    public function isNoPatchNeeded(): bool
    {
        return $this->mode === self::MODE_NO_PATCH_NEEDED;
    }

    public function isBlocked(): bool
    {
        return $this->mode === self::MODE_BLOCKED;
    }

    public function isInvalid(): bool
    {
        return $this->mode === self::MODE_INVALID;
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'changed_files' => array_values($this->changedFiles),
            'diff' => $this->diff,
            'diff_hash' => $this->diffHash(),
            'errors' => array_values($this->errors),
            'mode' => $this->mode,
            'question' => $this->question,
            'reason' => $this->reason,
            'schema_version' => self::SCHEMA_VERSION,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return CanonicalJson::canonicalize([
            'changed_files' => array_values($this->changedFiles),
            'diff_hash' => $this->diffHash(),
            'errors' => array_values($this->errors),
            'mode' => $this->mode,
            'question' => $this->question,
            'reason' => $this->reason,
            'schema_version' => self::SCHEMA_VERSION,
        ]);
    }

    public function hash(): string
    {
        return CanonicalHasher::hash($this->toCanonicalArray());
    }

    public static function patch(string $diff, array $changedFiles): self
    {
        return new self(self::MODE_PATCH, $diff, array_values($changedFiles), null, null);
    }

    public static function noPatchNeeded(string $reason): self
    {
        return new self(self::MODE_NO_PATCH_NEEDED, null, [], $reason, null);
    }

    public static function blocked(string $question): self
    {
        return new self(self::MODE_BLOCKED, null, [], null, $question);
    }

    public static function invalid(array $errors): self
    {
        return new self(self::MODE_INVALID, null, [], null, null, array_values($errors));
    }
}
