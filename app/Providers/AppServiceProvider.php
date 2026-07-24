<?php

namespace App\Providers;

use App\Console\Commands\AtlasTaskMaestroCostCommand;
use App\Console\Commands\AtlasTaskMaestroRetryCommand;
use App\Models\AtlasMemoryEntry;
use App\Observers\AtlasMemoryRecallCacheObserver;
use App\Services\Ai\Learning\Harness\AtlasHarnessSurface;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Domain DI peels: MemoryInfrastructure, RuntimeSeams, Obra, Stewardship,
        // Organism, Vox, Swarm, Patamar4, Mission, Compression, CrossDomain,
        // ProviderManagerWiring, MaestroPriority (full-pass).

        $this->registerLoopSentinels();
        // Maestro/AAEL peeled to AtlasMaestroPriorityServiceProvider (full-pass).
        // Cortex Council lens wiring: o condicional legado (`cortex.council.lenses`) foi
        // superseded pelo registry sempre-bound e pré-populado com as 5 lentes (gate upstream
        // em config('atlas.cortex.council.enabled')). O re-singleton() cru do legado REBINDAVA
        // o registry VAZIO sempre que a chave legada listasse uma lente — removido.
    }

    /**
     * WAVE-19 SENTINEL WIRING — fail-CLOSED, byte-identical when OFF. When the flag
     * `atlas.loop.sentinels.wave19_enabled` is false (default) NOTHING is bound, so the pétreo floor is
     * preserved exactly. When ON, the four wave-19 sentinels + the wiring canary are bound as singletons so
     * the cron / keepalive / any caller can resolve them from the container.
     */
    private function registerLoopSentinels(): void
    {
        if (! (bool) config('atlas.loop.sentinels.wave19_enabled', false)) {
            return; // OFF ⇒ zero bindings, byte-identical no-op
        }

        // HONEST residual: wave19_enabled ON currently has no live sentinel class
        // registrations here (historical bindings removed with ACDE-dead loop).
        // When/if live wave-19 sentinel classes return, rebind them as singletons
        // under this flag. Until then ON is a no-op (same as OFF for DI), not a
        // silent "sentinels active" claim.
    }


    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        AtlasMemoryEntry::observe(AtlasMemoryRecallCacheObserver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                AtlasTaskMaestroCostCommand::class,
                AtlasTaskMaestroRetryCommand::class,
            ]);
        }

        // AP-819 Obra B — overlay da Harness Surface: reaplica overrides de
        // harness_config APROVADOS (allowlist+bounds revalidados a cada boot;
        // entrada inválida é ignorada). Fail-open: erro aqui nunca derruba o boot.
        try {
            app(AtlasHarnessSurface::class)->bootOverlay();
        } catch (\Throwable) {
            // o config base do .env segue valendo.
        }
    }

}
