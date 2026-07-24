<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\HubDelegators;

/**
 * Compact section forwarders (full-pass density). Section: releaseWriterSection().
 */
trait ReleaseWriterSectionDelegators
{
    public function workSplitter(array $options = []): array
    {
        return $this->releaseWriterSection()->workSplitter($options);
    }

    public function codexIntegrationReport(array $options = []): array
    {
        return $this->releaseWriterSection()->codexIntegrationReport($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickWriterPreflight(array $options = []): array
    {
        return $this->releaseWriterSection()->agentAutomaticDispatchSchedulerOneShotTickWriterPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickReleaseTemplate(array $options = []): array
    {
        return $this->releaseWriterSection()->agentAutomaticDispatchSchedulerOneShotTickReleaseTemplate($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptDraft(array $options = []): array
    {
        return $this->releaseWriterSection()->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptDraft($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceContract(array $options = []): array
    {
        return $this->releaseWriterSection()->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceContract($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriterPreflight(array $options = []): array
    {
        return $this->releaseWriterSection()->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriterPreflight($options);
    }

    public function agentAutomaticDispatchSchedulerOneShotTickMutatingWriterContract(array $options = []): array
    {
        return $this->releaseWriterSection()->agentAutomaticDispatchSchedulerOneShotTickMutatingWriterContract($options);
    }

}
