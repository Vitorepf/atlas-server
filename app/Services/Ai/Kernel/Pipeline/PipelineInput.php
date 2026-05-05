<?php

namespace App\Services\Ai\Kernel\Pipeline;

final readonly class PipelineInput
{
    /**
     * @param  array<string,mixed>  $hints
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public string $primaryText,
        public string $inputType = 'text',
        public string $surfaceId = 'kernel_pipeline_scaffold',
        public string $tenantId = 'atlas-single-tenant',
        public string $operatorId = 'unknown',
        public string $locale = 'pt-BR',
        public array $hints = [],
        public array $metadata = [],
        public bool $dryRun = true,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            primaryText: self::string($payload['primary_text'] ?? $payload['text'] ?? ''),
            inputType: self::string($payload['input_type'] ?? $payload['primary_type'] ?? 'text') ?: 'text',
            surfaceId: self::string($payload['surface_id'] ?? 'kernel_pipeline_scaffold') ?: 'kernel_pipeline_scaffold',
            tenantId: self::string($payload['tenant_id'] ?? 'atlas-single-tenant') ?: 'atlas-single-tenant',
            operatorId: self::string($payload['operator_id'] ?? 'unknown') ?: 'unknown',
            locale: self::string($payload['locale'] ?? 'pt-BR') ?: 'pt-BR',
            hints: is_array($payload['hints'] ?? null) ? $payload['hints'] : [],
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            dryRun: self::bool($payload['dry_run'] ?? true),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toAuditArray(): array
    {
        return $this->auditPayload() + [
            'input_fingerprint' => $this->auditFingerprint(),
        ];
    }

    public function auditFingerprint(): string
    {
        return hash('sha256', json_encode($this->auditPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string,mixed>
     */
    private function auditPayload(): array
    {
        return [
            'primary_text_hash' => hash('sha256', $this->primaryText),
            'input_type' => $this->inputType,
            'surface_id' => $this->surfaceId,
            'tenant_id' => $this->tenantId,
            'operator_id' => $this->operatorId,
            'locale' => $this->locale,
            'safe_hints' => $this->safeHints(),
            'hints_hash' => $this->hashArray($this->hints),
            'metadata_keys' => $this->metadataKeys(),
            'dry_run' => $this->dryRun,
        ];
    }

    private static function string(mixed $value): string
    {
        if (! is_scalar($value) && ! $value instanceof \Stringable) {
            return '';
        }

        return trim((string) $value);
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['false', '0', 'no', 'off'], true)) {
                return false;
            }

            if (in_array($normalized, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
        }

        return (bool) $value;
    }

    /**
     * @return array<string,scalar|null>
     */
    private function safeHints(): array
    {
        $safe = [];
        $allowed = array_flip([
            'domain',
            'domain_id',
            'flow',
            'flow_id',
            'mode',
            'task',
            'surface_id',
            'runtime',
            'autonomy',
        ]);

        foreach ($this->hints as $key => $value) {
            if (! isset($allowed[(string) $key]) || (! is_scalar($value) && $value !== null)) {
                continue;
            }

            $safe[(string) $key] = $value;
        }

        ksort($safe);

        return $safe;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashArray(array $payload): string
    {
        return hash('sha256', json_encode($this->normalizeForHash($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<int,string>
     */
    private function metadataKeys(): array
    {
        $keys = array_map('strval', array_keys($this->metadata));
        sort($keys);

        return $keys;
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn (mixed $item): mixed => $this->normalizeForHash($item), $value);
            }

            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[(string) $key] = $this->normalizeForHash($item);
            }

            ksort($normalized);

            return $normalized;
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return 'unsupported:'.get_debug_type($value);
    }
}
