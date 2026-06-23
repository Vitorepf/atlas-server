<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The VSL asset must store every part of the dissection in its OWN field — not buried inside a
 * generic json blob — because this data feeds new-test creation, page analysis and the decision
 * engine. Adds the missing first-class fields: the LEAD (the opening, the single most important
 * copy element), the HOOK, the ANGLE, the named TRICK/mechanism, the consolidated METRICS, and the
 * separated PERSUASION DEVICES (authority/government/conspiracy/urgency) so they never again
 * contaminate the angle/mechanism.
 */
return new class extends Migration
{
    private const COLUMNS = ['lead', 'hook', 'angle', 'trick', 'metrics', 'persuasion_devices'];

    public function up(): void
    {
        if (! Schema::hasTable('ai_marketing_vsl_assets')) {
            return;
        }

        Schema::table('ai_marketing_vsl_assets', function (Blueprint $t): void {
            if (! Schema::hasColumn('ai_marketing_vsl_assets', 'lead')) {
                $t->json('lead')->nullable();                 // {type, format, opening_text, why, structure[]}
            }
            if (! Schema::hasColumn('ai_marketing_vsl_assets', 'hook')) {
                $t->text('hook')->nullable();                 // the exact opening hook (first 5-15s)
            }
            if (! Schema::hasColumn('ai_marketing_vsl_assets', 'angle')) {
                $t->text('angle')->nullable();                // the REAL angle (mechanism/trick), not the device
            }
            if (! Schema::hasColumn('ai_marketing_vsl_assets', 'trick')) {
                $t->text('trick')->nullable();                // the named trick/solution ("at-home retatrutide protocol")
            }
            if (! Schema::hasColumn('ai_marketing_vsl_assets', 'metrics')) {
                $t->json('metrics')->nullable();              // {price, guarantee_days, result_claims[], scarcity[], ...}
            }
            if (! Schema::hasColumn('ai_marketing_vsl_assets', 'persuasion_devices')) {
                $t->json('persuasion_devices')->nullable();   // {authority, conspiracy, urgency, scarcity, social_proof}
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_marketing_vsl_assets')) {
            return;
        }

        Schema::table('ai_marketing_vsl_assets', function (Blueprint $t): void {
            foreach (self::COLUMNS as $c) {
                if (Schema::hasColumn('ai_marketing_vsl_assets', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
