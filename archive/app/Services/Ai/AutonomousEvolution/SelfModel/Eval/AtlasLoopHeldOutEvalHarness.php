<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfModel\Eval;

final class AtlasLoopHeldOutEvalHarness
{
    /**
     * @param  list<array{example_id:int|string,expected:mixed}>  $heldOut
     * @param  list<array{example_id:int|string,produced:mixed}>  $candidateOutputs
     * @return array{schema:string,evaluated:int,passed:int,failed:int,pass_rate:float,regressions:list<int|string>}
     */
    public function evaluate(array $heldOut, array $candidateOutputs): array
    {
        $outputsByExampleId = $this->outputsByExampleId($candidateOutputs);
        $regressions = [];
        $passed = 0;

        foreach ($heldOut as $example) {
            $exampleId = $example['example_id'];
            $key = $this->exampleKey($exampleId);

            if (
                array_key_exists($key, $outputsByExampleId)
                && $outputsByExampleId[$key] === ($example['expected'] ?? null)
            ) {
                $passed++;

                continue;
            }

            $regressions[] = $exampleId;
        }

        $evaluated = count($heldOut);
        $failed = count($regressions);

        return [
            'schema' => 'atlas.loop.self_model.held_out_eval.v1',
            'evaluated' => $evaluated,
            'passed' => $passed,
            'failed' => $failed,
            'pass_rate' => $evaluated === 0 ? 0.0 : (float) ($passed / $evaluated),
            'regressions' => $regressions,
        ];
    }

    /**
     * @param  list<array{example_id:int|string,produced:mixed}>  $candidateOutputs
     * @return array<string,mixed>
     */
    private function outputsByExampleId(array $candidateOutputs): array
    {
        $outputs = [];

        foreach ($candidateOutputs as $output) {
            $key = $this->exampleKey($output['example_id']);

            if (! array_key_exists($key, $outputs)) {
                $outputs[$key] = $output['produced'] ?? null;
            }
        }

        return $outputs;
    }

    private function exampleKey(int|string $exampleId): string
    {
        return (string) $exampleId;
    }
}
