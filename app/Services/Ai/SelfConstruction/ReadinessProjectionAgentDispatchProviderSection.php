<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use Closure;

/**
 * AGENT DISPATCH + AGENT PROVIDER projection section, extracted from the
 * god-class {@see AtlasSelfConstructionReadinessService}.
 *
 * Owns every public agentDispatch* (31) and agentProvider* (8) method.
 * The runtime service delegates each method to this collaborator through
 * thin byte-identical delegators. The collaborator also holds the
 * dependency on the `stableHash` closure the original kept in the runtime
 * service.
 */
final class ReadinessProjectionAgentDispatchProviderSection
{
    /**
     * @param  Closure(array<string,mixed>): string  $stableHash
     */
    public function __construct(
        private readonly Closure $stableHash,
    ) {}

public function agentProviderAdapterInvocationRuntimePolicy(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentProviderAdapterInvocationRuntimePolicy($options);
    }


public function agentProviderProcessSupervisionPolicy(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentProviderProcessSupervisionPolicy($options);
    }


public function agentDispatchPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchPreflight($options);
    }


public function agentDispatchReceiptTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchReceiptTemplate($options);
    }


public function agentDispatchReceiptValidationPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchReceiptValidationPreflight($options);
    }


public function agentDispatchReceiptWrite(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchReceiptWrite($options);
    }


public function agentDispatchExecutorPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorPreflight($options);
    }


public function agentDispatchExecutorContractTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorContractTemplate($options);
    }


public function agentDispatchExecutorReleasePreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleasePreflight($options);
    }


public function agentDispatchExecutorReceiptUseWriterContractTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReceiptUseWriterContractTemplate($options);
    }


public function agentDispatchExecutorReceiptUseWriterPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReceiptUseWriterPreflight($options);
    }


public function agentDispatchExecutorReceiptUseWriterImplementationPacket(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReceiptUseWriterImplementationPacket($options);
    }


public function agentDispatchExecutorSandboxBindingContractTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorSandboxBindingContractTemplate($options);
    }


public function agentDispatchExecutorSandboxBindingPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorSandboxBindingPreflight($options);
    }


public function agentDispatchExecutorSandboxBindingImplementationPacket(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorSandboxBindingImplementationPacket($options);
    }


public function agentDispatchExecutorProviderStartDriverContractTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorProviderStartDriverContractTemplate($options);
    }


public function agentDispatchExecutorProviderStartDriverPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorProviderStartDriverPreflight($options);
    }


public function agentDispatchExecutorProviderStartDriverImplementationPacket(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorProviderStartDriverImplementationPacket($options);
    }


public function agentProviderAdapterRegistryContractTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentProviderAdapterRegistryContractTemplate($options);
    }


public function agentProviderAdapterRegistryPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentProviderAdapterRegistryPreflight($options);
    }


public function agentProviderAdapterRegistryImplementationPacket(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentProviderAdapterRegistryImplementationPacket($options);
    }


public function agentProviderAdapterExecutionGuardContractTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentProviderAdapterExecutionGuardContractTemplate($options);
    }


public function agentProviderAdapterExecutionGuardPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentProviderAdapterExecutionGuardPreflight($options);
    }


public function agentProviderAdapterExecutionGuardImplementationPacket(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentProviderAdapterExecutionGuardImplementationPacket($options);
    }


public function agentDispatchExecutorAdapterInvocationBoundaryContractTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorAdapterInvocationBoundaryContractTemplate($options);
    }


public function agentDispatchExecutorAdapterInvocationBoundaryPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorAdapterInvocationBoundaryPreflight($options);
    }


public function agentDispatchExecutorAdapterInvocationBoundaryImplementationPacket(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorAdapterInvocationBoundaryImplementationPacket($options);
    }


public function agentDispatchExecutorReleaseAuthorizationTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationTemplate($options);
    }


public function agentDispatchExecutorReleaseAuthorizationReceiptDraft(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationReceiptDraft($options);
    }


public function agentDispatchExecutorReleaseAuthorizationSignatureRequest(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationSignatureRequest($options);
    }


public function agentDispatchExecutorReleaseAuthorizationPostSignatureRunbook(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationPostSignatureRunbook($options);
    }


public function agentDispatchExecutorReleaseAuthorizationSignedReceiptTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationSignedReceiptTemplate($options);
    }


public function agentDispatchExecutorReleaseAuthorizationSignedReceiptPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationSignedReceiptPreflight($options);
    }


public function agentDispatchExecutorReleaseAuthorizationPersistenceTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationPersistenceTemplate($options);
    }


public function agentDispatchExecutorReleaseAuthorizationPersistencePreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationPersistencePreflight($options);
    }


public function agentDispatchExecutorReleaseAuthorizationPersistenceWriterContractTemplate(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationPersistenceWriterContractTemplate($options);
    }


public function agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPreflight(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPreflight($options);
    }


public function agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPacket(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationPersistenceWriterImplementationPacket($options);
    }


public function agentDispatchExecutorReleaseAuthorizationPersistenceStatus(array $options = []): array
    {
        return $this->agentDispatchProviderSection()->agentDispatchExecutorReleaseAuthorizationPersistenceStatus($options);
    }

}
