<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Loops de readiness-check repetidos byte a byte em 11 *ReadinessService de dominio
 * (medicao jscpd 05/07): tabela existe / model existe / service resolve no container.
 * Extraidos na limpeza para que o formato do check evolua UMA vez.
 */
trait BuildsReadinessChecks
{
    /**
     * @param  list<string>  $tables
     * @return list<array{name:string,status:string,detail:string}>
     */
    private function tableChecks(array $tables): array
    {
        $checks = [];
        foreach ($tables as $table) {
            $exists = DatabaseTableAvailability::has($table);
            $checks[] = [
                'name' => "table:{$table}",
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'exists' : 'missing - run php artisan migrate',
            ];
        }

        return $checks;
    }

    /**
     * @param  list<class-string>  $models
     * @return list<array{name:string,status:string,detail:string}>
     */
    private function modelChecks(array $models): array
    {
        $checks = [];
        foreach ($models as $model) {
            $exists = class_exists($model);
            $checks[] = [
                'name' => 'model:'.class_basename($model),
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'class exists' : "missing class [{$model}]",
            ];
        }

        return $checks;
    }

    /**
     * @param  list<class-string>  $services
     * @return list<array{name:string,status:string,detail:string}>
     */
    private function serviceChecks(Container $container, array $services): array
    {
        $checks = [];
        foreach ($services as $service) {
            $resolvable = false;
            $detail = 'not resolvable';
            try {
                $resolved = $container->make($service);
                $resolvable = $resolved instanceof $service;
                $detail = $resolvable ? 'resolved' : 'not an instance';
            } catch (Throwable $e) {
                $detail = 'exception: '.$e->getMessage();
            }
            $checks[] = [
                'name' => 'service:'.class_basename($service),
                'status' => $resolvable ? 'passed' : 'failed',
                'detail' => $detail,
            ];
        }

        return $checks;
    }
}
