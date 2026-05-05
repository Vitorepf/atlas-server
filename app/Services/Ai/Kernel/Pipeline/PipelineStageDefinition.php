<?php

namespace App\Services\Ai\Kernel\Pipeline;

final readonly class PipelineStageDefinition
{
    /**
     * @param  array<int,string>  $reads
     * @param  array<int,string>  $writes
     */
    public function __construct(
        public KernelPipelineStage $stage,
        public int $order,
        public array $reads,
        public array $writes,
        public string $description,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage->value,
            'order' => $this->order,
            'reads' => $this->reads,
            'writes' => $this->writes,
            'description' => $this->description,
        ];
    }
}
