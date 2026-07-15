<?php

namespace App\Services\Ai\Rivals\Support;

use App\Services\Ai\Rivals\Core\FailureClass;
use App\Services\Ai\Rivals\Core\ModelRegistry;
use App\Services\Ai\Rivals\Core\NativeExecutionManifest;
use App\Services\Ai\Rivals\Core\NativeExecutionReceipt;

/** Binds external-suite receipts to the solver runtime that actually executed. */
final class RuntimeProofAttacher
{
    /**
     * @param  array<string, mixed>  $receipt
     * @param  array<string, mixed>  $binding
     * @return array<string, mixed>
     */
    public function attach(array $receipt, array $binding, string $runId, string $resultPath): array
    {
        $model = (new ModelRegistry)->get((string) ($binding['model_id'] ?? '')) ?? [];
        if (($model['provider'] ?? null) !== 'hermes'
            || ! is_file(RunPaths::nativeManifestPath($runId))) {
            return $receipt;
        }
        $entry = collect(NativeExecutionManifest::load($runId)->entries())->first(
            fn (array $candidate): bool => $candidate['expected_result_path'] === $resultPath,
        );
        $nativeReceipt = collect(NativeExecutionReceipt::loadAll($runId))->first(
            fn (NativeExecutionReceipt $candidate): bool => $candidate->data['expected_result_path'] === $resultPath,
        );
        $metadata = (array) ($receipt['metadata'] ?? []);
        $runtime = (string) ($binding['runtime'] ?? '');
        if ($runtime === 'bare') {
            $tokensIn = (int) ($receipt['tokens_in'] ?? 0);
            $tokensOut = (int) ($receipt['tokens_out'] ?? 0);
            $providerBinding = $nativeReceipt instanceof NativeExecutionReceipt
                ? (array) ($nativeReceipt->data['provider_binding'] ?? [])
                : [];
            $real = is_array($entry)
                && $nativeReceipt instanceof NativeExecutionReceipt
                && $nativeReceipt->data['status'] === 'success'
                && ($nativeReceipt->data['runner']['mode'] ?? null) === 'execute'
                && ($providerBinding['provider'] ?? null) === 'verboo'
                && ($providerBinding['model_id'] ?? null) === ($binding['model_id'] ?? null)
                && ($providerBinding['atlas_cli_model'] ?? null) === ($binding['cli_model'] ?? null)
                && ($providerBinding['native_model'] ?? null) === ($binding['native_model'] ?? null)
                && ($providerBinding['base_url_sha256'] ?? null) === hash(
                    'sha256',
                    \App\Services\Ai\Rivals\Core\VerbooEnvironment::BASE_URL,
                )
                && ($tokensIn + $tokensOut) > 0;
            $metadata['direct_provider'] = [
                'schema_version' => 'atlas.rivals2.direct_provider_proof.v1',
                'real_provider' => $real,
                'provider' => 'verboo',
                'model' => (string) ($binding['cli_model'] ?? ''),
                'native_model' => (string) ($binding['native_model'] ?? ''),
                'execution_id' => $entry['execution_id'] ?? null,
                'command_hash' => $nativeReceipt?->data['command_hash'] ?? null,
                'result_sha256' => $nativeReceipt?->data['result_sha256'] ?? null,
                'usage' => [
                    'input_tokens' => $tokensIn,
                    'output_tokens' => $tokensOut,
                    'cost_usd' => (float) ($receipt['cost_usd'] ?? 0),
                    'present' => ($tokensIn + $tokensOut) > 0,
                ],
                'reason' => $real ? null : 'native_provider_execution_not_proven',
            ];
        } elseif ($runtime === 'atlas_dev') {
            $proof = is_array(data_get($receipt, 'metadata.runtime_bridge'))
                ? data_get($receipt, 'metadata.runtime_bridge')
                : (is_array($entry)
                    ? $this->atlasProof((string) data_get($entry, 'normalization.scratch_dir'))
                    : null);
            // TOKEN NÃO É PROVA DE QUE O ATLAS RODOU — é telemetria.
            //
            // Isto exigia `usage.present === true`, e o efeito era apagar o braço
            // inteiro: os caminhos de run BLOQUEADO do Atlas Dev gravam
            // `tokens_in => null` cravado (PipelineRunExecutor:4116 e :4259,
            // KernelRunExecutor:118 — todos `completionState: 'blocked'`). Como
            // bloqueado é exatamente o que acontece quando o Atlas ERRA ou RECUSA
            // a tarefa, toda derrota do Atlas caía aqui, virava
            // environment_failure e sumia do denominador. O braço só conseguia
            // registrar acerto: 100% por construção.
            //
            // A assimetria é o que denuncia: o braço bare é `hermes -z`, que
            // reporta usage e passa sempre. Ou seja, o portão reprovava só o lado
            // que ele deveria medir.
            //
            // E ele nunca protegeu contra a fraude real: quando a coluna "com
            // Atlas" rodava `hermes -z` disfarçado, o usage vinha presente e o
            // portão aprovava alegremente. Não pega fraude; só apaga derrota.
            //
            // A prova de runtime que vale é a de baixo, e ela é derivada do que o
            // `atlas:cli:dev --json` de fato devolveu: provider hermes_cli, o
            // modelo pedido, e uma chamada de provider que aconteceu. O usage
            // segue gravado como dado — com `present: false` quando faltar, que é
            // telemetria ausente, não execução ausente.
            $valid = is_array($proof)
                && ($proof['status'] ?? null) === 'passed'
                && ($proof['real_provider'] ?? false) === true
                && ($proof['provider'] ?? null) === 'hermes_cli'
                && ($proof['model'] ?? null) === ($binding['cli_model'] ?? null)
                && data_get($proof, 'fair_mode.single_provider') === true
                && data_get($proof, 'fair_mode.decide_disabled') === true
                && data_get($proof, 'fair_mode.fallback_disabled') === true;
            $metadata['runtime_bridge'] = $valid
                ? $proof
                : [
                    'schema_version' => 'atlas.rivals2.atlas_dev_bridge_receipt.v1',
                    'status' => 'failed',
                    'real_provider' => false,
                    'model' => (string) ($binding['cli_model'] ?? ''),
                    'reason' => 'atlas_dev_runtime_proof_missing_or_invalid',
                ];
            EventStream::append($runId, $valid ? 'bridge_proof_attached' : 'bridge_proof_missing', [
                'case_id' => $receipt['case_id'] ?? null,
                'arm_id' => $receipt['arm_id'] ?? null,
                'repetition' => $receipt['repetition'] ?? null,
                'runtime' => 'atlas_dev',
                'reason' => $valid ? null : 'atlas_dev_runtime_proof_missing_or_invalid',
            ]);
            if (! $valid) {
                // Missing bridge is an environment/runtime proof failure — not model stupidity.
                $receipt['failure_class'] = FailureClass::ENVIRONMENT;
                if (($receipt['status'] ?? null) === 'success') {
                    $receipt['status'] = 'error';
                }
            }
        }
        $receipt['metadata'] = $metadata;

        return $receipt;
    }

    /** @return array<string, mixed>|null */
    private function atlasProof(string $scratch): ?array
    {
        if ($scratch === '' || ! is_dir($scratch)) {
            return null;
        }
        $direct = $scratch.'/.rivals_atlas_dev_bridge.json';
        if (is_file($direct)) {
            $proof = json_decode((string) file_get_contents($direct), true);

            return is_array($proof) ? $proof : null;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($scratch, \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === '.rivals_atlas_dev_bridge.json') {
                $proof = json_decode((string) file_get_contents($file->getPathname()), true);

                return is_array($proof) ? $proof : null;
            }
        }

        return null;
    }
}
