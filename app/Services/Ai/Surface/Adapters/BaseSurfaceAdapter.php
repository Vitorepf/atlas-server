<?php

namespace App\Services\Ai\Surface\Adapters;

use App\Services\Ai\Kernel\Envelope\KernelInput;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;
use App\Services\Ai\Kernel\Surface\SurfaceAttachmentKind;
use App\Services\Ai\Kernel\Surface\SurfaceCapability;
use App\Services\Ai\Kernel\Surface\SurfaceDomainFlowHintKey;
use App\Services\Ai\Kernel\Surface\SurfaceHintKey;
use App\Services\Ai\Kernel\Surface\SurfaceAdapter;
use Throwable;

abstract class BaseSurfaceAdapter implements SurfaceAdapter
{
    protected const SURFACE_ID = '';

    /**
     * @return array<int,string>
     */
    abstract protected function capabilities(): array;

    /**
     * @return array<string,mixed>
     */
    protected function domainFlowHints(): array
    {
        return [];
    }

    public function surfaceId(): string
    {
        return static::SURFACE_ID;
    }

    /**
     * @return array<int,string>
     */
    public function supportedCapabilities(): array
    {
        return array_values(array_unique(array_filter(
            $this->capabilities(),
            fn (mixed $capability): bool => is_string($capability) && trim($capability) !== '',
        )));
    }

    /**
     * @return array<string,mixed>
     */
    public function supportedDomainFlowHints(): array
    {
        $hints = $this->domainFlowHints();
        $hints[SurfaceDomainFlowHintKey::SURFACE_ID] = $this->surfaceId();
        $hints[SurfaceDomainFlowHintKey::ACCEPTS_EXPLICIT_DOMAIN_FLOW_SELECTION] = in_array(
            SurfaceCapability::DOMAIN_FLOW_SELECTION,
            $this->supportedCapabilities(),
            true,
        );

        return $hints;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function normalizeInput(array $payload): KernelInput
    {
        return KernelInput::fromArray([
            'primary_type' => $this->primaryType($payload),
            'primary_text' => $this->primaryText($payload),
            'attachments' => $this->attachments($payload),
            'hints' => $this->hints($payload),
            'locale' => $this->locale($payload),
        ]);
    }

    /**
     * @param  array<string,mixed>  $output
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function renderOutput(array $output, array $context = []): array
    {
        return app(KernelSloProbe::class)->measure('output.render', function () use ($output, $context): array {
            return $this->renderOutputUnmeasured($output, $context);
        }, [
            'tenant_id' => $this->string($context['tenant_id'] ?? data_get($output, 'metadata.tenant_id') ?? 'default') ?: 'default',
            'operator_id' => $this->string($context['operator_id'] ?? data_get($output, 'metadata.operator_id') ?? 'system') ?: 'system',
            'envelope_id' => $this->string($context['envelope_id'] ?? data_get($output, 'metadata.envelope_id') ?? 'surface_output_'.$this->surfaceId()) ?: 'surface_output_'.$this->surfaceId(),
            'receipt_id' => $this->string($context['receipt_id'] ?? data_get($output, 'metadata.receipt_id') ?? null) ?: null,
            'trace_id' => $this->string($context['trace_id'] ?? data_get($output, 'metadata.trace_id') ?? null) ?: null,
            'correlation_id' => $this->string($context['correlation_id'] ?? $context['trace_id'] ?? data_get($output, 'metadata.trace_id') ?? 'surface_output_'.$this->surfaceId()) ?: 'surface_output_'.$this->surfaceId(),
            'domain' => $this->string($context['domain'] ?? data_get($output, 'metadata.domain') ?? null) ?: null,
            'flow' => $this->string($context['flow'] ?? data_get($output, 'metadata.flow') ?? null) ?: null,
            'surface_id' => $this->surfaceId(),
            'provider' => $this->string($context['provider'] ?? data_get($output, 'metadata.provider') ?? null) ?: null,
            'model' => $this->string($context['model'] ?? data_get($output, 'metadata.model') ?? null) ?: null,
        ]);
    }

    /**
     * @param  array<string,mixed>  $output
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    protected function renderOutputUnmeasured(array $output, array $context = []): array
    {
        return [
            'schema_version' => 1,
            'surface_id' => $this->surfaceId(),
            'status' => $this->string($output['status'] ?? 'pending') ?: 'pending',
            'text' => $this->string($output['text'] ?? $output['primary_text'] ?? $output['response_text'] ?? ''),
            'artifacts' => is_array($output['artifacts'] ?? null) ? $output['artifacts'] : [],
            'metadata' => [
                'surface_capabilities' => $this->supportedCapabilities(),
                'kernel_metadata' => is_array($output['metadata'] ?? null) ? $output['metadata'] : [],
                'context' => $context,
            ],
        ];
    }

    /**
     * @return array{ok:bool,errors:array<int,string>}
     */
    public function complianceReport(): array
    {
        $errors = [];

        if ($this->surfaceId() === '') {
            $errors[] = 'surface_id_empty';
        }

        if ($this->supportedCapabilities() === []) {
            $errors[] = 'supported_capabilities_empty';
        }

        foreach ($this->supportedCapabilities() as $capability) {
            if (! SurfaceCapability::isKnown($capability)) {
                $errors[] = "unsupported_capability:{$capability}";
            }
        }

        $domainFlowHints = $this->supportedDomainFlowHints();
        array_push($errors, ...$this->domainFlowHintErrors($domainFlowHints));

        try {
            $input = $this->normalizeInput([
                'text' => 'compliance probe',
                'locale' => 'pt-BR',
                'hints' => ['probe' => true],
            ]);

            if ($input->primaryText !== 'compliance probe') {
                $errors[] = 'normalize_input_text_not_preserved';
            }
        } catch (Throwable $exception) {
            $errors[] = 'normalize_input_failed:'.$exception->getMessage();
        }

        $rendered = $this->renderOutput(['status' => 'ok', 'text' => 'done']);
        if (($rendered['surface_id'] ?? null) !== $this->surfaceId()) {
            $errors[] = 'render_output_surface_id_mismatch';
        }

        if (($rendered['schema_version'] ?? null) !== 1) {
            $errors[] = 'render_output_schema_version_missing';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function primaryType(array $payload): string
    {
        $explicit = $this->string($payload['primary_type'] ?? $payload['type'] ?? null);
        if ($explicit !== '') {
            return $explicit;
        }

        return $this->primaryText($payload) !== '' ? 'text' : 'attachment';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function primaryText(array $payload): string
    {
        foreach (['text', 'primary_text', 'input_text', 'prompt', 'message', 'query', 'input', 'operator_input'] as $key) {
            $value = $this->string($payload[$key] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    protected function attachments(array $payload): array
    {
        $attachments = [];

        foreach ($this->arrayList($payload['attachments'] ?? null) as $attachment) {
            $attachments[] = $this->surfaceAttachment($attachment, SurfaceAttachmentKind::ATTACHMENT, 'attachments');
        }

        foreach (['image_attachments', 'images'] as $key) {
            foreach ($this->arrayList($payload[$key] ?? null) as $attachment) {
                $attachments[] = $this->surfaceAttachment($attachment, SurfaceAttachmentKind::IMAGE, $key);
            }
        }

        foreach (['file_attachments', 'files'] as $key) {
            foreach ($this->arrayList($payload[$key] ?? null) as $attachment) {
                $attachments[] = $this->surfaceAttachment($attachment, SurfaceAttachmentKind::FILE, $key);
            }
        }

        return $attachments;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function hints(array $payload): array
    {
        $hints = is_array($payload['hints'] ?? null) ? $payload['hints'] : [];
        $hints[SurfaceHintKey::SURFACE_ID] = $this->surfaceId();
        $hints[SurfaceHintKey::SURFACE_CAPABILITIES] = $this->supportedCapabilities();

        foreach ($this->hintKeys() as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
                $hints[$key] = $payload[$key];
            }
        }

        return $hints;
    }

    /**
     * @return array<int,string>
     */
    protected function hintKeys(): array
    {
        return [
            SurfaceHintKey::AGENT_SLUG,
            SurfaceHintKey::APP_SURFACE,
            SurfaceHintKey::ATLAS_MODE,
            SurfaceHintKey::ATLAS_WORKFLOW_MODE,
            SurfaceHintKey::CURRENT_MODE,
            SurfaceHintKey::DOMAIN_CATALOG_SELECTION,
            SurfaceHintKey::DOMAIN_ID,
            SurfaceHintKey::EXECUTOR,
            SurfaceHintKey::FLOW_ID,
            SurfaceHintKey::MODE,
            SurfaceHintKey::PRODUCT_DOMAIN,
            SurfaceHintKey::PROVIDER,
            SurfaceHintKey::ROUTING_DOMAIN,
            SurfaceHintKey::ROUTING_TASK,
            SurfaceHintKey::SOURCE,
            SurfaceHintKey::SOURCE_TYPE,
            SurfaceHintKey::TASK,
            SurfaceHintKey::THREAD_ID,
            SurfaceHintKey::WORKSPACE,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function locale(array $payload): string
    {
        return $this->string($payload['locale'] ?? data_get($payload, 'operator.locale') ?? 'pt-BR') ?: 'pt-BR';
    }

    protected function string(mixed $value): string
    {
        return trim(is_scalar($value) ? (string) $value : '');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function arrayList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => $item)
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $attachment
     * @return array<string,mixed>
     */
    private function surfaceAttachment(array $attachment, string $kind, string $source): array
    {
        return [
            ...$attachment,
            'surface_attachment_kind' => $kind,
            'surface_attachment_source' => $source,
        ];
    }

    /**
     * @param  array<string,mixed>  $hints
     * @return array<int,string>
     */
    private function domainFlowHintErrors(array $hints): array
    {
        $errors = [];

        foreach (array_keys($hints) as $key) {
            if (! is_string($key) || ! SurfaceDomainFlowHintKey::isKnown($key)) {
                $errors[] = "domain_flow_hints_unknown_key:{$key}";
            }
        }

        if (($hints[SurfaceDomainFlowHintKey::SURFACE_ID] ?? null) !== $this->surfaceId()) {
            $errors[] = 'domain_flow_hints_surface_id_mismatch';
        }

        $acceptsExplicit = $hints[SurfaceDomainFlowHintKey::ACCEPTS_EXPLICIT_DOMAIN_FLOW_SELECTION] ?? null;
        if (! is_bool($acceptsExplicit)) {
            $errors[] = 'domain_flow_hints_accepts_explicit_not_bool';
        }

        if (in_array(SurfaceCapability::DOMAIN_FLOW_SELECTION, $this->supportedCapabilities(), true)
            && $acceptsExplicit !== true
        ) {
            $errors[] = 'domain_flow_selection_capability_without_hint';
        }

        foreach ([SurfaceDomainFlowHintKey::DEFAULT_DOMAIN_ID, SurfaceDomainFlowHintKey::DEFAULT_FLOW_ID] as $key) {
            if (array_key_exists($key, $hints) && ! $this->nonEmptyString($hints[$key])) {
                $errors[] = "domain_flow_hints_invalid_{$key}";
            }
        }

        foreach ([SurfaceDomainFlowHintKey::SUPPORTED_DOMAIN_IDS, SurfaceDomainFlowHintKey::SUPPORTED_FLOW_IDS] as $key) {
            if (array_key_exists($key, $hints) && ! $this->stringList($hints[$key])) {
                $errors[] = "domain_flow_hints_invalid_{$key}";
            }
        }

        if (array_key_exists(SurfaceDomainFlowHintKey::PREFER_DEFAULT_FLOW, $hints)
            && ! is_bool($hints[SurfaceDomainFlowHintKey::PREFER_DEFAULT_FLOW])
        ) {
            $errors[] = 'domain_flow_hints_prefer_default_flow_not_bool';
        }

        if (array_key_exists(SurfaceDomainFlowHintKey::ACCEPTS_CATALOG_DOMAIN_FLOW_SELECTION, $hints)
            && ! is_bool($hints[SurfaceDomainFlowHintKey::ACCEPTS_CATALOG_DOMAIN_FLOW_SELECTION])
        ) {
            $errors[] = 'domain_flow_hints_accepts_catalog_not_bool';
        }

        $taskFlowMap = $hints[SurfaceDomainFlowHintKey::TASK_FLOW_MAP] ?? null;
        if (array_key_exists(SurfaceDomainFlowHintKey::TASK_FLOW_MAP, $hints)) {
            if (! is_array($taskFlowMap) || $taskFlowMap === []) {
                $errors[] = 'domain_flow_hints_invalid_task_flow_map';
            } else {
                foreach ($taskFlowMap as $task => $flowId) {
                    if (! $this->nonEmptyString($task) || ! $this->nonEmptyString($flowId)) {
                        $errors[] = 'domain_flow_hints_invalid_task_flow_map_entry';
                        break;
                    }
                }
            }
        }

        $supportedDomainIds = $this->stringList($hints[SurfaceDomainFlowHintKey::SUPPORTED_DOMAIN_IDS] ?? null)
            ? $hints[SurfaceDomainFlowHintKey::SUPPORTED_DOMAIN_IDS]
            : [];
        $supportedFlowIds = $this->stringList($hints[SurfaceDomainFlowHintKey::SUPPORTED_FLOW_IDS] ?? null)
            ? $hints[SurfaceDomainFlowHintKey::SUPPORTED_FLOW_IDS]
            : [];

        $defaultDomainId = $hints[SurfaceDomainFlowHintKey::DEFAULT_DOMAIN_ID] ?? null;
        if ($this->nonEmptyString($defaultDomainId) && $supportedDomainIds !== [] && ! in_array($defaultDomainId, $supportedDomainIds, true)) {
            $errors[] = 'domain_flow_hints_default_domain_not_supported';
        }

        $defaultFlowId = $hints[SurfaceDomainFlowHintKey::DEFAULT_FLOW_ID] ?? null;
        if ($this->nonEmptyString($defaultFlowId) && $supportedFlowIds !== [] && ! in_array($defaultFlowId, $supportedFlowIds, true)) {
            $errors[] = 'domain_flow_hints_default_flow_not_supported';
        }

        if (is_array($taskFlowMap) && $supportedFlowIds !== []) {
            foreach ($taskFlowMap as $flowId) {
                if ($this->nonEmptyString($flowId) && ! in_array($flowId, $supportedFlowIds, true)) {
                    $errors[] = 'domain_flow_hints_task_flow_not_supported';
                    break;
                }
            }
        }

        return array_values(array_unique($errors));
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function stringList(mixed $value): bool
    {
        return is_array($value)
            && $value !== []
            && array_is_list($value)
            && collect($value)->every(fn (mixed $item): bool => $this->nonEmptyString($item));
    }
}
