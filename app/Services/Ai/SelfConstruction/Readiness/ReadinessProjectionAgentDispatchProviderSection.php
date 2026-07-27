<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use Closure;

/**
 * Family 5 — Agent Dispatch + Provider (GOD-DEBULK sub-split facade).
 *
 * The full bodies now live in four <=1500-LOC sibling sub-sections under
 * {@see \App\Services\Ai\SelfConstruction\Readiness\DispatchProvider}. This
 * facade keeps every public method with an identical signature so the mother
 * god-class delegators and the direct-construction test contract keep working
 * with zero call-site changes. The A1-SC-0056 dispatch-receipt integrity guard
 * moved verbatim into DispatchProviderPart01SubSection.
 */
final class ReadinessProjectionAgentDispatchProviderSection
{
    private ?AtlasSelfConstructionReadinessService $mother = null;

    private ?DispatchProvider\DispatchProviderPart01SubSection $part01 = null;
    private ?DispatchProvider\DispatchProviderPart02SubSection $part02 = null;
    private ?DispatchProvider\DispatchProviderPart03SubSection $part03 = null;
    private ?DispatchProvider\DispatchProviderPart04SubSection $part04 = null;

    /**
     * @param  Closure(array<string,mixed>): string  $stableHash
     */
    public function __construct(
        private readonly Closure $stableHash,
    ) {}

    public function setMother(AtlasSelfConstructionReadinessService $mother): self
    {
        $this->mother = $mother;

        return $this;
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($this->mother === null) {
            throw new \RuntimeException("ReadinessProjectionAgentDispatchProviderSection mother not bound for {$name}.");
        }

        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }

    private function part01(): DispatchProvider\DispatchProviderPart01SubSection
    {
        return $this->part01 ??= new DispatchProvider\DispatchProviderPart01SubSection($this, $this->stableHash);
    }

    private function part02(): DispatchProvider\DispatchProviderPart02SubSection
    {
        return $this->part02 ??= new DispatchProvider\DispatchProviderPart02SubSection($this, $this->stableHash);
    }

    private function part03(): DispatchProvider\DispatchProviderPart03SubSection
    {
        return $this->part03 ??= new DispatchProvider\DispatchProviderPart03SubSection($this, $this->stableHash);
    }

    private function part04(): DispatchProvider\DispatchProviderPart04SubSection
    {
        return $this->part04 ??= new DispatchProvider\DispatchProviderPart04SubSection($this, $this->stableHash);
    }

    public function agentProviderAdapterInvocationRuntimePolicy(array $options = []): array
    {
        return $this->part01()->agentProviderAdapterInvocationRuntimePolicy($options);
    }

    public function agentProviderProcessSupervisionPolicy(array $options = []): array
    {
        return $this->part01()->agentProviderProcessSupervisionPolicy($options);
    }

    public function agentDispatchPreflight(array $options = []): array
    {
        return $this->part01()->agentDispatchPreflight($options);
    }

    public function agentDispatchReceiptTemplate(array $options = []): array
    {
        return $this->part01()->agentDispatchReceiptTemplate($options);
    }

    public function agentDispatchReceiptValidationPreflight(array $options = []): array
    {
        return $this->part01()->agentDispatchReceiptValidationPreflight($options);
    }

    public function agentDispatchReceiptWrite(array $options = []): array
    {
        return $this->part01()->agentDispatchReceiptWrite($options);
    }

    public function agentDispatchExecutorPreflight(array $options = []): array
    {
        return $this->part02()->agentDispatchExecutorPreflight($options);
    }

    public function agentDispatchExecutorContractTemplate(array $options = []): array
    {
        return $this->part02()->agentDispatchExecutorContractTemplate($options);
    }

    public function agentDispatchExecutorReleasePreflight(array $options = []): array
    {
        return $this->part02()->agentDispatchExecutorReleasePreflight($options);
    }

    public function agentDispatchExecutorReceiptUseWriterContractTemplate(array $options = []): array
    {
        return $this->part02()->agentDispatchExecutorReceiptUseWriterContractTemplate($options);
    }

    public function agentDispatchExecutorReceiptUseWriterPreflight(array $options = []): array
    {
        return $this->part02()->agentDispatchExecutorReceiptUseWriterPreflight($options);
    }

    public function agentDispatchExecutorReceiptUseWriterImplementationPacket(array $options = []): array
    {
        return $this->part02()->agentDispatchExecutorReceiptUseWriterImplementationPacket($options);
    }

    public function agentDispatchExecutorSandboxBindingContractTemplate(array $options = []): array
    {
        return $this->part02()->agentDispatchExecutorSandboxBindingContractTemplate($options);
    }

    public function agentDispatchExecutorSandboxBindingPreflight(array $options = []): array
    {
        return $this->part02()->agentDispatchExecutorSandboxBindingPreflight($options);
    }

    public function agentDispatchExecutorSandboxBindingImplementationPacket(array $options = []): array
    {
        return $this->part02()->agentDispatchExecutorSandboxBindingImplementationPacket($options);
    }

    public function agentDispatchExecutorProviderStartDriverContractTemplate(array $options = []): array
    {
        return $this->part02()->agentDispatchExecutorProviderStartDriverContractTemplate($options);
    }

    public function agentDispatchExecutorProviderStartDriverPreflight(array $options = []): array
    {
        return $this->part02()->agentDispatchExecutorProviderStartDriverPreflight($options);
    }

    public function agentDispatchExecutorProviderStartDriverImplementationPacket(array $options = []): array
    {
        return $this->part02()->agentDispatchExecutorProviderStartDriverImplementationPacket($options);
    }

    public function agentProviderAdapterRegistryContractTemplate(array $options = []): array
    {
        return $this->part03()->agentProviderAdapterRegistryContractTemplate($options);
    }

    public function agentProviderAdapterRegistryPreflight(array $options = []): array
    {
        return $this->part03()->agentProviderAdapterRegistryPreflight($options);
    }

    public function agentProviderAdapterRegistryImplementationPacket(array $options = []): array
    {
        return $this->part03()->agentProviderAdapterRegistryImplementationPacket($options);
    }

    public function agentProviderAdapterExecutionGuardContractTemplate(array $options = []): array
    {
        return $this->part03()->agentProviderAdapterExecutionGuardContractTemplate($options);
    }

    public function agentProviderAdapterExecutionGuardPreflight(array $options = []): array
    {
        return $this->part03()->agentProviderAdapterExecutionGuardPreflight($options);
    }

    public function agentProviderAdapterExecutionGuardImplementationPacket(array $options = []): array
    {
        return $this->part03()->agentProviderAdapterExecutionGuardImplementationPacket($options);
    }

    public function agentDispatchExecutorAdapterInvocationBoundaryContractTemplate(array $options = []): array
    {
        return $this->part03()->agentDispatchExecutorAdapterInvocationBoundaryContractTemplate($options);
    }

    public function agentDispatchExecutorAdapterInvocationBoundaryPreflight(array $options = []): array
    {
        return $this->part03()->agentDispatchExecutorAdapterInvocationBoundaryPreflight($options);
    }

    public function agentDispatchExecutorAdapterInvocationBoundaryImplementationPacket(array $options = []): array
    {
        return $this->part03()->agentDispatchExecutorAdapterInvocationBoundaryImplementationPacket($options);
    }

    public function agentDispatchExecutorReleaseAuthorizationTemplate(array $options = []): array
    {
        return $this->part03()->agentDispatchExecutorReleaseAuthorizationTemplate($options);
    }

    public function agentDispatchExecutorReleaseAuthorizationReceiptDraft(array $options = []): array
    {
        return $this->part03()->agentDispatchExecutorReleaseAuthorizationReceiptDraft($options);
    }

    public function agentDispatchExecutorReleaseAuthorizationSignatureRequest(array $options = []): array
    {
        return $this->part03()->agentDispatchExecutorReleaseAuthorizationSignatureRequest($options);
    }

    public function agentDispatchExecutorReleaseAuthorizationPostSignatureRunbook(array $options = []): array
    {
        return $this->part03()->agentDispatchExecutorReleaseAuthorizationPostSignatureRunbook($options);
    }

    public function agentDispatchExecutorReleaseAuthorizationSignedReceiptTemplate(array $options = []): array
    {
        return $this->part03()->agentDispatchExecutorReleaseAuthorizationSignedReceiptTemplate($options);
    }

    public function agentDispatchExecutorReleaseAuthorizationSignedReceiptPreflight(array $options = []): array
    {
        return $this->part03()->agentDispatchExecutorReleaseAuthorizationSignedReceiptPreflight($options);
    }

    public function agentDispatchExecutorReleaseAuthorizationPersistenceTemplate(array $options = []): array
    {
        return $this->part04()->agentDispatchExecutorReleaseAuthorizationPersistenceTemplate($options);
    }

    public function agentDispatchExecutorReleaseAuthorizationPersistencePreflight(array $options = []): array
    {
        return $this->part04()->agentDispatchExecutorReleaseAuthorizationPersistencePreflight($options);
    }

    public function agentDispatchExecutorReleaseAuthorizationPersistenceWriterContractTemplate(array $options = []): array
    {
        return $this->part04()->agentDispatchExecutorReleaseAuthorizationPersistenceWriterContractTemplate($options);
    }

    public function agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPreflight(array $options = []): array
    {
        return $this->part04()->agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPreflight($options);
    }

    public function agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPacket(array $options = []): array
    {
        return $this->part04()->agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPacket($options);
    }

    public function agentDispatchExecutorReleaseAuthorizationPersistenceStatus(array $options = []): array
    {
        return $this->part04()->agentDispatchExecutorReleaseAuthorizationPersistenceStatus($options);
    }
}
