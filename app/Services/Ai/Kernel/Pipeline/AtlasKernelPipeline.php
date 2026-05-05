<?php

namespace App\Services\Ai\Kernel\Pipeline;

interface AtlasKernelPipeline
{
    /**
     * @return array<int,PipelineStageDefinition>
     */
    public function stages(): array;

    /**
     * @return array<string,mixed>
     */
    public function plan(PipelineInput $input): array;

    public function execute(PipelineInput $input): PipelineExecutionResult;

    /**
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>,stage_count:int,stage_order:array<int,string>,slot_flow_valid:bool,provider_execution_allowed:bool,runtime_execution_allowed:bool}
     */
    public function complianceReport(): array;
}
