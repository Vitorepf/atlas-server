<?php

namespace App\Services\Ai\Kernel\Provider;

final readonly class AgentBehaviorContract
{
    public const CONTRACT_ID = 'atlas-ai.agent-behavior.v1';

    /**
     * @return array<int,string>
     */
    public function principles(): array
    {
        return [
            'Assumption Management',
            'Simplicity Bias',
            'Surgical Diff Discipline',
            'Verifiable Goal Loop',
        ];
    }

    public function text(): string
    {
        return implode("\n", [
            'Atlas AI Agent Behavior Contract v1',
            '- Assumption Management: separate evidence, inference, assumptions, and uncertainty when risk is meaningful.',
            '- Simplicity Bias: prefer the smallest change that satisfies the current objective and local patterns.',
            '- Surgical Diff Discipline: every changed line must tie back to the request, AP, bug, or verification.',
            '- Verifiable Goal Loop: state success criteria, run verification when feasible, and report residual risk.',
        ]);
    }

    public function contentHash(): string
    {
        return hash('sha256', $this->text());
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'contract_id' => self::CONTRACT_ID,
            'content_hash' => $this->contentHash(),
            'principles' => $this->principles(),
            'source' => 'docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md',
        ];
    }
}
