<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendProviderInstructionPacketService;
use Illuminate\Console\Command;

class AtlasFrontendProviderInstructionPacketCommand extends Command
{
    protected $signature = 'atlas:frontend:provider-packet
        {--task= : Frontend task or intent}
        {--workspace= : Local company/product frontend repository path}
        {--frontend-app= : Optional frontend app subdirectory inside the selected repository, e.g. apps/web}
        {--surface=programming.frontend : Surface/profile requesting frontend work}
        {--provider=provider_neutral : Provider receiving the instruction packet}
        {--acceptance : Acceptance criteria exists}
        {--asset-context : Asset provenance or placeholder policy exists}
        {--company-profile-ready : Company design profile is ready}
        {--prototype : Prototype/discovery mode requested}
        {--live : Live visual iteration mode requested}
        {--test-plan : Test plan exists}
        {--visual-quality-plan : Visual quality plan exists}
        {--evidence-plan : Evidence plan exists}
        {--senior-design-review : Senior design review is available}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless provider packet is ready}';

    protected $description = 'Compile a provider-safe Atlas Frontend execution instruction packet.';

    public function handle(AtlasFrontendProviderInstructionPacketService $packet): int
    {
        $payload = $packet->compile([
            'task' => (string) ($this->option('task') ?: ''),
            'workspace' => (string) ($this->option('workspace') ?: ''),
            'frontend_app' => (string) ($this->option('frontend-app') ?: ''),
            'surface' => (string) ($this->option('surface') ?: 'programming.frontend'),
            'provider' => (string) ($this->option('provider') ?: 'provider_neutral'),
            'acceptance_criteria' => (bool) $this->option('acceptance'),
            'asset_context' => (bool) $this->option('asset-context'),
            'company_profile_ready' => (bool) $this->option('company-profile-ready'),
            'prototype' => (bool) $this->option('prototype'),
            'live' => (bool) $this->option('live'),
            'test_plan' => (bool) $this->option('test-plan'),
            'visual_quality_plan' => (bool) $this->option('visual-quality-plan'),
            'evidence_plan' => (bool) $this->option('evidence-plan'),
            'senior_design_review' => (bool) $this->option('senior-design-review'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Provider Packet: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
