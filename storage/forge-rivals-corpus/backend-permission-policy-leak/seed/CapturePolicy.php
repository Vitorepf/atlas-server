<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Policy de visualização de Capture.
 *
 * BUG (seed): `view()` devolve true sempre que existe user — não compara
 * tenant_id. Auditoria registrou um operador do tenant A vendo captura do
 * tenant B; o fix exige deny-first com comparação explícita de tenant.
 *
 * Contract local (test fixture): user e capture são arrays simples com
 * chave `tenant_id` (string). Em produção isso vem de Eloquent, mas o
 * caso mede o princípio (deny-first), não o framework.
 *
 * @phpstan-type UserData array{tenant_id?:?string, ...}
 * @phpstan-type CaptureData array{tenant_id?:?string, ...}
 */
final class CapturePolicy
{
    /**
     * @param  UserData|null  $user
     * @param  CaptureData|null  $capture
     */
    public function view(?array $user, ?array $capture): bool
    {
        // BUG: aceita qualquer user autenticado, ignora tenant_id.
        return is_array($user) && is_array($capture);
    }
}
