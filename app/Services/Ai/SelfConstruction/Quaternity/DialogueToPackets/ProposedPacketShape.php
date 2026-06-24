<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets;

/**
 * The candidate packet SKELETON emitted by the proposer — a structured shape, not a final task. The dialogue
 * surface persists this under storage/atlas/maestro/dialogue/proposed/<ulid>.json so the operator can review
 * before any enqueue happens. NO auto-enqueue, ever.
 *
 * Fields mirror the existing atlas:task packet contract (task_packet_id, objective, allowed_files, scope_in,
 * acceptance_criteria, required_evidence, depends_on, wave). The objective MUST anchor on a real symbol or
 * file present in the {@see CortexGroundingSnapshot} the proposer consumed.
 */
final class ProposedPacketShape
{
    /**
     * @param  list<string>  $allowedFiles         seed list (operator may add/remove during review)
     * @param  list<string>  $scopeIn              mirror of allowedFiles (atlas:task contract)
     * @param  list<string>  $acceptanceCriteria   seeds derived from intent verbs
     * @param  list<string>  $dependsOn            empty in this skeleton; operator wires later
     */
    public function __construct(
        public readonly string $taskPacketId,
        public readonly string $objective,
        public readonly array $allowedFiles,
        public readonly array $scopeIn,
        public readonly array $acceptanceCriteria,
        public readonly string $requiredEvidence,
        public readonly array $dependsOn,
        public readonly string $wave,
        public readonly string $anchorSymbol,
        public readonly string $anchorFile,
    ) {
    }

    /**
     * Canonical-shape array — keys in atlas:task packet order, lists alphabetically deterministic. The same
     * inputs to the proposer always yield the same toArray() byte-for-byte.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'acceptance_criteria' => $this->acceptanceCriteria,
            'allowed_files' => $this->allowedFiles,
            'anchor_file' => $this->anchorFile,
            'anchor_symbol' => $this->anchorSymbol,
            'depends_on' => $this->dependsOn,
            'objective' => $this->objective,
            'required_evidence' => $this->requiredEvidence,
            'scope_in' => $this->scopeIn,
            'task_packet_id' => $this->taskPacketId,
            'wave' => $this->wave,
        ];
    }

    public function canonicalJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
