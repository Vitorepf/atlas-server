<?php

namespace App\Services\Semantic;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use App\Support\YesNo;

class AtlasVaultFrontmatterService
{
    public const SYNC_STATUSES = ['draft', 'imported', 'candidate', 'managed', 'archived', 'conflict'];
    public const PRIVACY_CLASSES = ['normal', 'private', 'sensitive', 'secret'];
    public const REDACTION_STATUSES = ['clean', 'redacted', 'blocked', 'needs_review'];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function build(array $input): array
    {
        $atlasId = $this->requiredString($input, 'atlas_id');
        $atlasType = $this->requiredString($input, 'atlas_type');
        $sourceType = $this->string($input['source_type'] ?? null) ?: 'atlas_'.$atlasType;
        $sourceId = $this->string($input['source_id'] ?? null) ?: $atlasId;
        $privacyClass = $this->enum((string) ($input['privacy_class'] ?? 'normal'), self::PRIVACY_CLASSES, 'privacy_class');
        $redactionStatus = $this->enum((string) ($input['redaction_status'] ?? 'clean'), self::REDACTION_STATUSES, 'redaction_status');
        $syncStatus = $this->enum((string) ($input['sync_status'] ?? 'managed'), self::SYNC_STATUSES, 'sync_status');
        $providerSafe = $this->bool($input['provider_safe'] ?? true, 'provider_safe');
        if ($privacyClass === 'secret' && $providerSafe) {
            throw new InvalidArgumentException('Secret AtlasVault notes cannot be provider_safe.');
        }
        if ($providerSafe && in_array($redactionStatus, ['blocked', 'needs_review'], true)) {
            throw new InvalidArgumentException('AtlasVault notes with blocked or needs_review redaction cannot be provider_safe.');
        }
        $updatedAt = $this->timestamp($input['updated_at'] ?? null, 'updated_at');

        return [
            'atlas_id' => $atlasId,
            'atlas_type' => $atlasType,
            'atlas_managed' => $this->bool($input['atlas_managed'] ?? true, 'atlas_managed'),
            'sync_status' => $syncStatus,
            'source' => $this->string($input['source'] ?? null) ?: 'atlas',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'privacy_class' => $privacyClass,
            'provider_safe' => $providerSafe,
            'redaction_status' => $redactionStatus,
            'canonical' => $this->bool($input['canonical'] ?? false, 'canonical'),
            'created_by' => $this->string($input['created_by'] ?? null) ?: 'atlas',
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @return array{frontmatter: array<string,mixed>, body: string, errors: array<int,string>}
     */
    public function parse(string $markdown): array
    {
        if (! preg_match("/^---\r?\n(.*?)\r?\n---\r?\n?(.*)$/s", $markdown, $matches)) {
            return ['frontmatter' => [], 'body' => $markdown, 'errors' => ['missing_frontmatter']];
        }

        try {
            $frontmatter = $this->parseYamlSubset($matches[1]);
            $errors = [];
        } catch (\Throwable $exception) {
            $frontmatter = [];
            $errors = ['frontmatter_parse_failed: '.$exception->getMessage()];
        }

        return [
            'frontmatter' => $frontmatter,
            'body' => ltrim($matches[2]),
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string,mixed>  $frontmatter
     * @return array<int,string>
     */
    public function validateManaged(array $frontmatter): array
    {
        $errors = [];
        foreach ([
            'atlas_id',
            'atlas_type',
            'atlas_managed',
            'sync_status',
            'source',
            'source_type',
            'source_id',
            'privacy_class',
            'provider_safe',
            'redaction_status',
            'canonical',
            'updated_at',
        ] as $field) {
            if (! array_key_exists($field, $frontmatter) || $frontmatter[$field] === null || $frontmatter[$field] === '') {
                $errors[] = "missing_{$field}";
            }
        }

        if (isset($frontmatter['sync_status']) && ! in_array($frontmatter['sync_status'], self::SYNC_STATUSES, true)) {
            $errors[] = 'invalid_sync_status';
        }
        if (isset($frontmatter['privacy_class']) && ! in_array($frontmatter['privacy_class'], self::PRIVACY_CLASSES, true)) {
            $errors[] = 'invalid_privacy_class';
        }
        if (isset($frontmatter['redaction_status']) && ! in_array($frontmatter['redaction_status'], self::REDACTION_STATUSES, true)) {
            $errors[] = 'invalid_redaction_status';
        }
        if (($frontmatter['privacy_class'] ?? null) === 'secret' && ($frontmatter['provider_safe'] ?? null) === true) {
            $errors[] = 'secret_provider_safe';
        }
        if (
            in_array($frontmatter['redaction_status'] ?? null, ['blocked', 'needs_review'], true)
            && ($frontmatter['provider_safe'] ?? null) === true
        ) {
            $errors[] = 'unsafe_redaction_provider_safe';
        }
        if (array_key_exists('updated_at', $frontmatter) && ! $this->validTimestamp($frontmatter['updated_at'])) {
            $errors[] = 'invalid_updated_at';
        }
        foreach (['atlas_managed', 'provider_safe', 'canonical'] as $field) {
            if (array_key_exists($field, $frontmatter) && ! is_bool($frontmatter[$field])) {
                $errors[] = "invalid_{$field}";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $frontmatter
     */
    public function isManaged(array $frontmatter): bool
    {
        return ($frontmatter['atlas_managed'] ?? false) === true;
    }

    /**
     * @param  array<string,mixed>  $frontmatter
     */
    public function syncStatus(array $frontmatter): ?string
    {
        $status = $this->string($frontmatter['sync_status'] ?? null);

        return $status !== '' ? $status : null;
    }

    /**
     * @param  array<string,mixed>  $frontmatter
     */
    public function render(array $frontmatter): string
    {
        return "---\n".$this->dumpYaml($frontmatter)."---\n";
    }

    private function requiredString(array $input, string $key): string
    {
        $value = $this->string($input[$key] ?? null);
        if ($value === '') {
            throw new InvalidArgumentException("Missing required frontmatter field: {$key}");
        }

        return $value;
    }

    private function enum(string $value, array $allowed, string $field): string
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Invalid {$field}: {$value}");
        }

        return $value;
    }

    private function bool(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && in_array($value, [0, 1], true)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        throw new InvalidArgumentException("Invalid boolean {$field}: ".(is_scalar($value) ? (string) $value : gettype($value)));
    }

    private function string(mixed $value): string
    {
        return trim((string) $value);
    }

    private function timestamp(mixed $value, string $field): string
    {
        $timestamp = $this->string($value);
        if ($timestamp === '') {
            return now()->utc()->toIso8601String();
        }
        if (! $this->validTimestamp($timestamp)) {
            throw new InvalidArgumentException("Invalid {$field}: {$timestamp}");
        }

        return $timestamp;
    }

    private function validTimestamp(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $timestamp = trim($value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $timestamp)) {
            return false;
        }

        try {
            new \DateTimeImmutable($timestamp);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function parseYamlSubset(string $yaml): array
    {
        $data = [];
        foreach (preg_split('/\r?\n/', $yaml) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }
            if (! preg_match('/^([A-Za-z0-9_]+):(?:\s*(.*))?$/', $line, $match)) {
                throw new RuntimeException('Unsupported frontmatter line: '.$line);
            }
            $data[$match[1]] = $this->parseScalar($match[2] ?? '');
        }

        return $data;
    }

    private function parseScalar(string $value): mixed
    {
        $value = trim($value);
        if ($value === '' || $value === 'null' || $value === '~') {
            return null;
        }
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }

        return trim($value, "\"'");
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function dumpYaml(array $data): string
    {
        $yaml = '';
        foreach ($data as $key => $value) {
            $yaml .= $key.': '.$this->formatScalar($value)."\n";
        }

        return $yaml;
    }

    private function formatScalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return YesNo::trueFalse($value);
        }
        $string = (string) $value;
        if ($string === '' || preg_match('/[:\[\]#\n]/', $string)) {
            return '"'.str_replace('"', '\"', $string).'"';
        }

        return $string;
    }
}
