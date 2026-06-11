---
id: venture-019eb3ce-7871-70be-b853-5a9f92293055-company-canonical
title: Company Canonical Doc
status: draft
type: venture-company-canonical
human_name: Company Canonical Doc
canonical_name: venture-019eb3ce-7871-70be-b853-5a9f92293055-company-canonical
technical_name: VentureCompanyCanonicalDoc
cartography_type: module
canonical_source: docs/ventures/019eb3ce-7871-70be-b853-5a9f92293055/company-canonical.md
graph_parent: atlas-venture-foundry-operating-system
graph_id: venture-019eb3ce-7871-70be-b853-5a9f92293055-company-canonical
graph_world: atlas
graph_layer: module
graph_kind: venture-doc
graph_status: active
graph_source: repo
summary: 'Generated company-canonical doc for venture 019eb3ce-7871-70be-b853-5a9f92293055, synthesized from comprehension findings and repository structure.'
tags:
  - venture-foundry
  - comprehension
  - generated
  - company-canonical
owner: atlas-venture-foundry
doc_schema: atlas_canonical_module_doc.v1
---
# Company Canonical Doc

> Generated company-canonical doc for venture `019eb3ce-7871-70be-b853-5a9f92293055` from comprehension run `f90cf3f1-cc33-4dfa-9225-286200dd31a5`. Deterministic synthesis of recorded findings + repo structure — cite or omit.

## Overview

_No manifest description found._

### Architecture

Repository roots:
- `nivor-front-end`
- `blackink-app`
- `nivor-back-end`

## Business Rules

Top recorded business rules (by confidence):

- **Preço de 0.01 USD** (confidence: 0.90) — `nivor-back-end/app/Console/Commands/DiagnoseSubscriptions.php`:28
  - evidence: `$diffLabel = abs($diff) > 0.01 ? " *** DIFF: R\${$diff}" : ' OK';`
- **Preço de 0.01 BRL** (confidence: 0.90) — `nivor-back-end/app/Console/Commands/DiagnoseSubscriptions.php`:46
  - evidence: `if (abs($priceDiffTotal) > 0.01) {`
- **Plano 'week' custa 4.33 USD** (confidence: 0.90) — `nivor-back-end/app/Console/Commands/DiagnoseSubscriptions.php`:136
  - evidence: `'week' => $amount * 4.33 / $intervalCount,`
- **Preço de 4.33** (confidence: 0.90) — `nivor-back-end/app/Console/Commands/DiagnoseSubscriptions.php`:169
  - evidence: `WHEN 'week' THEN subscription_items.unit_amount * 4.33 / subscription_items.interval_count`
- **Plano 'yearly' custa 0.85 USD** (confidence: 0.90) — `nivor-back-end/app/Models/Plan.php`:107
  - evidence: `'yearly' => $this->price_yearly ?? ($this->price * 12 * 0.85),`
- **Período de trial de 8 dias (trialdays)** (confidence: 0.90) — `nivor-back-end/app/Http/Controllers/SubscribeController.php`:381
  - evidence: `$checkoutSession = $checkoutSession->trialDays(8);`
- **Período de trial de 8 dias (trialdays)** (confidence: 0.90) — `nivor-back-end/app/Services/UserService.php`:347
  - evidence: `$builder = $builder->trialDays(8);`
- **Período de trial de 8 dias (trialdays)** (confidence: 0.90) — `nivor-back-end/app/Services/UserService.php`:367
  - evidence: `$builder = $builder->trialDays(8);`
- **Preço de 0.05 USD** (confidence: 0.70) — `nivor-back-end/app/Models/CompetitorProfile.php`:590
  - evidence: `if ($change > 0.05) {`
- **Preço de 0.25 USD** (confidence: 0.70) — `nivor-back-end/app/Models/CompetitorProfile.php`:531
  - evidence: `if ($score >= 0.25) {`
- **Preço de 0.05 USD** (confidence: 0.70) — `nivor-back-end/app/Models/CompetitionAlert.php`:448
  - evidence: `if ($absChange >= 0.05) {`
- **Preço de 0.65 USD** (confidence: 0.70) — `nivor-back-end/app/Models/CampaignCorrelation.php`:180
  - evidence: `return $this->sample_size >= 30 && $this->confidence_level >= 0.65;`

## Problems

### Critical

- **Google API key hardcoded in source** (severity: critical, confidence: 0.95) — `blackink-app/google-services.json`:18
  - evidence: `"current_key": "AIzaSyBMpeFlUd9TLa8i1vIunYA4ASDHQJigZnU"`
- **Private key block hardcoded in source** (severity: critical, confidence: 0.95) — `blackink-app/google-key.json`:5
  - evidence: `"private_key": "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQCoTV8O8x/V5f9w\nl/2ss/5mRhPfwf591UkI82Anrrw+2fITK6TlA4Nf1hlbrL8tudGeFKKOzGctyDUz\n7MlxVL5KlMEKUHLpI2UYGBYh5UwYFsGHwm0Pe3FsLiQ5Oo4OVpla5QacWkP4nzvy\nE8bfEnloV8g5W0BGryGO23zHUXlQzEBpFXNY/5ZK0SxbrNIdT4gtC0ly9fq5zbUW\nNdqE2Km6VNkfRiQywjqu3vx92NzGZhlQtu4EXttF6zrFJf/K2p/3sJQTUjI7y7tt\n1Vkl1GAwiRRNsOvSyQj7LZsw43rWEqWiSpEkQ6ljZuJQhxknf3/6PQ6cCvmu/nqr\nDryFz/AVAgMBAAECggEAN/h7K3qZVMZHfAdf+qZlbVfS1jAq1WgwAMUHbksDGZfb\nlJqIHQ1dDGskRcOeVLOeTYcpRHofui8B2oHdwE3hduYfiLGWdYgq36dq/NzHwJ8Y\nv3BeWq6/2q1BqLKbeZM9LuhJmYe/YRh7lBcVpSv8qkG/OavqJVeqvlqqFZM32DF7\nC12fdrvJ5+2OKJYUHPdJISYMa2W8M95PIpndo0KuKmtg8+R++0NwD2wCbD33wLDH\nAl09jMvczdckrvtXIrDxldVo/5xtq60Z3ETqtxwwYfTTGg5vf4ZBPlsGL3VID/7s\nvnMfpDN5fsPCS75cr2S29D9xllzxx`
- **Hardcoded credential literal hardcoded in source** (severity: critical, confidence: 0.95) — `blackink-app/src/config/services.config.js`:5
  - evidence: `client_secret: 'ohDJoTqzq24DiBKGFxnHPKWUZB0E1sv5X1S1FKss',`

### All problems (by severity)

- **Google API key hardcoded in source** (severity: critical, confidence: 0.95) — `blackink-app/google-services.json`:18
  - evidence: `"current_key": "AIzaSyBMpeFlUd9TLa8i1vIunYA4ASDHQJigZnU"`
- **Private key block hardcoded in source** (severity: critical, confidence: 0.95) — `blackink-app/google-key.json`:5
  - evidence: `"private_key": "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQCoTV8O8x/V5f9w\nl/2ss/5mRhPfwf591UkI82Anrrw+2fITK6TlA4Nf1hlbrL8tudGeFKKOzGctyDUz\n7MlxVL5KlMEKUHLpI2UYGBYh5UwYFsGHwm0Pe3FsLiQ5Oo4OVpla5QacWkP4nzvy\nE8bfEnloV8g5W0BGryGO23zHUXlQzEBpFXNY/5ZK0SxbrNIdT4gtC0ly9fq5zbUW\nNdqE2Km6VNkfRiQywjqu3vx92NzGZhlQtu4EXttF6zrFJf/K2p/3sJQTUjI7y7tt\n1Vkl1GAwiRRNsOvSyQj7LZsw43rWEqWiSpEkQ6ljZuJQhxknf3/6PQ6cCvmu/nqr\nDryFz/AVAgMBAAECggEAN/h7K3qZVMZHfAdf+qZlbVfS1jAq1WgwAMUHbksDGZfb\nlJqIHQ1dDGskRcOeVLOeTYcpRHofui8B2oHdwE3hduYfiLGWdYgq36dq/NzHwJ8Y\nv3BeWq6/2q1BqLKbeZM9LuhJmYe/YRh7lBcVpSv8qkG/OavqJVeqvlqqFZM32DF7\nC12fdrvJ5+2OKJYUHPdJISYMa2W8M95PIpndo0KuKmtg8+R++0NwD2wCbD33wLDH\nAl09jMvczdckrvtXIrDxldVo/5xtq60Z3ETqtxwwYfTTGg5vf4ZBPlsGL3VID/7s\nvnMfpDN5fsPCS75cr2S29D9xllzxx`
- **Hardcoded credential literal hardcoded in source** (severity: critical, confidence: 0.95) — `blackink-app/src/config/services.config.js`:5
  - evidence: `client_secret: 'ohDJoTqzq24DiBKGFxnHPKWUZB0E1sv5X1S1FKss',`
- **DB::raw with string concatenation** (severity: high, confidence: 0.80) — `nivor-back-end/app/Services/BusinessMetricsService.php`:239
  - evidence: `DB::raw('COALESCE(SUM(' . $this->monthlyRevenueExpr() . '), 0) as revenue')`
- **DB::raw with string concatenation** (severity: high, confidence: 0.80) — `nivor-back-end/app/Services/BusinessMetricsService.php`:1209
  - evidence: `DB::raw('COALESCE(SUM(' . $this->monthlyRevenueExpr() . '), 0) as revenue')`
- **DB::raw with string concatenation** (severity: high, confidence: 0.80) — `nivor-back-end/app/Services/BusinessMetricsService.php`:1235
  - evidence: `DB::raw('COALESCE(SUM(' . $this->monthlyRevenueExpr() . '), 0) as mrr_generated')`
- **DB::raw with string concatenation** (severity: high, confidence: 0.80) — `nivor-back-end/app/Services/Goals/GoalBreakdownService.php`:103
  - evidence: `DB::raw("COALESCE(ts." . $safeColumn . ", 'Não identificado') as dimension_value"),`
- **DB::raw with string concatenation** (severity: high, confidence: 0.80) — `nivor-back-end/app/Services/Intelligence/IntelligenceMetricsService.php`:208
  - evidence: `? ['value' => DB::raw('value + ' . $item['value']), 'updated_at' => now()]`
- **DB::raw with string concatenation** (severity: high, confidence: 0.80) — `nivor-back-end/app/Services/PerformanceService.php`:1649
  - evidence: `DB::raw("DATE_TRUNC('".$truncFunc."', created_at) as period"),`
- **DB::raw with string concatenation** (severity: high, confidence: 0.80) — `nivor-back-end/app/Services/PerformanceService.php`:1655
  - evidence: `->groupBy(DB::raw("DATE_TRUNC('".$truncFunc."', created_at)"))`
- **Shell/command execution** (severity: high, confidence: 0.80) — `nivor-back-end/app/Http/Controllers/HealthCheckController.php`:246
  - evidence: `exec('supervisorctl status', $output, $returnVar);`
- **Shell/command execution** (severity: high, confidence: 0.80) — `nivor-back-end/app/Http/Controllers/HealthDashboardController.php`:442
  - evidence: `exec('supervisorctl status', $processes, $returnVar);`
- **Shell/command execution** (severity: high, confidence: 0.80) — `nivor-back-end/tests/Feature/StubObfuscationTest.php`:49
  - evidence: `$output = shell_exec('php -l '.escapeshellarg($file).' 2>&1');`
- **DB::raw with string concatenation** (severity: high, confidence: 0.80) — `nivor-back-end/app/Services/BusinessMetricsService.php`:272
  - evidence: `DB::raw('COALESCE(SUM(' . $this->monthlyRevenueExpr() . '), 0) as revenue')`
- **DB::raw with string concatenation** (severity: high, confidence: 0.80) — `nivor-back-end/app/Services/BusinessMetricsService.php`:570
  - evidence: `->sum(DB::raw('(' . $this->monthlyRevenueExpr() . ')'));`
- **DB::raw with string concatenation** (severity: high, confidence: 0.80) — `nivor-back-end/app/Services/BusinessMetricsService.php`:604
  - evidence: `DB::raw('COALESCE(SUM(' . $this->monthlyRevenueExpr() . '), 0) as revenue')`
- **DB::raw with string concatenation** (severity: high, confidence: 0.80) — `nivor-back-end/app/Services/BusinessMetricsService.php`:636
  - evidence: `DB::raw('COALESCE(SUM(' . $this->monthlyRevenueExpr() . '), 0) as revenue')`
- **DB::raw with string concatenation** (severity: high, confidence: 0.80) — `nivor-back-end/app/Services/BusinessMetricsService.php`:790
  - evidence: `DB::raw('COALESCE(SUM(' . $this->monthlyRevenueExpr() . '), 0) as revenue')`
- **DB::raw with string concatenation** (severity: high, confidence: 0.80) — `nivor-back-end/app/Services/BusinessMetricsService.php`:822
  - evidence: `DB::raw('COALESCE(SUM(' . $this->monthlyRevenueExpr() . '), 0) as revenue')`
- **DB::raw with string concatenation** (severity: high, confidence: 0.80) — `nivor-back-end/app/Services/BusinessMetricsService.php`:1121
  - evidence: `DB::raw('AVG(' . $this->monthlyRevenueExpr() . ') as avg_price'),`

## Audience & Usage

Recorded audience / usage signals (tiers, locales, integrations):

- **Mercado/idioma en (Anglófono (en))** (confidence: 0.90) — `blackink-app/src/i18n/locales/en.json`:1
  - evidence: `{`
- **Mercado/idioma de (Alemão (de))** (confidence: 0.90) — `blackink-app/src/i18n/locales/de.json`:1
  - evidence: `{`
- **Mercado/idioma es (Hispanófono (es))** (confidence: 0.90) — `blackink-app/src/i18n/locales/es.json`:1
  - evidence: `{`
- **Mercado/idioma fr (Francófono (fr))** (confidence: 0.90) — `blackink-app/src/i18n/locales/fr.json`:1
  - evidence: `{`
- **Mercado/idioma pt-BR (Brasil/Portugal (pt))** (confidence: 0.90) — `blackink-app/src/i18n/locales/pt-BR.json`:1
  - evidence: `{`
- **Mercado/idioma ru (ru)** (confidence: 0.90) — `blackink-app/src/i18n/locales/ru.json`:1
  - evidence: `{`
- **Mercado/idioma zh-CN (zh-CN)** (confidence: 0.90) — `blackink-app/src/i18n/locales/zh-CN.json`:1
  - evidence: `{`
- **Mercado/idioma en-GB (Anglófono (en))** (confidence: 0.90) — `blackink-app/src/i18n/locales/en-GB.json`:1
  - evidence: `{`
- **Entidade de domínio: exemplars** (confidence: 0.85) — `nivor-back-end/.claude/exemplars/EXEMPLAR_Migration.php`:71
  - evidence: `Schema::create('exemplars', function (Blueprint $table) {`
- **Entidade de domínio: google_customer_pixels** (confidence: 0.85) — `nivor-back-end/database/migrations/2025_09_11_205223_create_google_customer_pixels.php`:13
  - evidence: `Schema::create('google_customer_pixels', function (Blueprint $table) {`
- **Entidade de domínio: google_campaigns_metrics** (confidence: 0.85) — `nivor-back-end/database/migrations/2025_09_09_235636_create_google_campaigns_metrics.php`:13
  - evidence: `Schema::create('google_campaigns_metrics', function (Blueprint $table) {`
- **Entidade de domínio: google_campaigns** (confidence: 0.85) — `nivor-back-end/database/migrations/2025_09_09_235336_create_google_campaigns.php`:13
  - evidence: `Schema::create('google_campaigns', function (Blueprint $table) {`
- **Entidade de domínio: google_customers** (confidence: 0.85) — `nivor-back-end/database/migrations/2025_09_09_235305_create_google_customers.php`:14
  - evidence: `Schema::create('google_customers', function (Blueprint $table) {`
- **Entidade de domínio: google_accounts** (confidence: 0.85) — `nivor-back-end/database/migrations/2025_09_08_161502_create_google_accounts.php`:13
  - evidence: `Schema::create('google_accounts', function (Blueprint $table) {`
- **Entidade de domínio: campaign_credentials** (confidence: 0.85) — `nivor-back-end/database/migrations/2025_08_31_143145_create_campaign_credentials.php`:13
  - evidence: `Schema::create('campaign_credentials', function (Blueprint $table) {`
- **Entidade de domínio: module_permissions** (confidence: 0.85) — `nivor-back-end/database/migrations/2025_08_28_200219_create_module_permissions.php`:13
  - evidence: `Schema::create('module_permissions', function (Blueprint $table) {`

## Improvements

Highest-leverage improvements:

- **Move 3 hardcoded secrets to vault/env** (confidence: 0.95, leverage: 5.00) — `blackink-app/google-key.json`:5
  - evidence: `"private_key": "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQCoTV8O8x/V5f9w\nl/2ss/5mRhPfwf591UkI82Anrrw+2fITK6TlA4Nf1hlbrL8tudGeFKKOzGctyDUz\n7MlxVL5KlMEKUHLpI2UYGBYh5UwYFsGHwm0Pe3FsLiQ5Oo4OVpla5QacWkP4nzvy\nE8bfEnloV8g5W0BGryGO23zHUXlQzEBpFXNY/5ZK0SxbrNIdT4gtC0ly9fq5zbUW\nNdqE2Km6VNkfRiQywjqu3vx92NzGZhlQtu4EXttF6zrFJf/K2p/3sJQTUjI7y7tt\n1Vkl1GAwiRRNsOvSyQj7LZsw43rWEqWiSpEkQ6ljZuJQhxknf3/6PQ6cCvmu/nqr\nDryFz/AVAgMBAAECggEAN/h7K3qZVMZHfAdf+qZlbVfS1jAq1WgwAMUHbksDGZfb\nlJqIHQ1dDGskRcOeVLOeTYcpRHofui8B2oHdwE3hduYfiLGWdYgq36dq/NzHwJ8Y\nv3BeWq6/2q1BqLKbeZM9LuhJmYe/YRh7lBcVpSv8qkG/OavqJVeqvlqqFZM32DF7\nC12fdrvJ5+2OKJYUHPdJISYMa2W8M95PIpndo0KuKmtg8+R++0NwD2wCbD33wLDH\nAl09jMvczdckrvtXIrDxldVo/5xtq60Z3ETqtxwwYfTTGg5vf4ZBPlsGL3VID/7s\nvnMfpDN5fsPCS75cr2S29D9xllzxx`
- **Eliminate likely N+1 query in DiagnoseSubscriptions.php** (confidence: 0.60, leverage: 2.00) — `nivor-back-end/app/Console/Commands/DiagnoseSubscriptions.php`:37
  - evidence: `})                         ->count();                     usleep(50000);`
- **Eliminate likely N+1 query in HandleStripeWebhooks.php** (confidence: 0.60, leverage: 2.00) — `nivor-back-end/app/Listeners/HandleStripeWebhooks.php`:625
  - evidence: `DB::table('subscription_items')                 ->where('subscription_id', $localSub->id)                 ->where('stripe_price', $price['id'])`
- **Eliminate likely N+1 query in HandleStripeWebhooks.php** (confidence: 0.60, leverage: 2.00) — `nivor-back-end/app/Listeners/HandleStripeWebhooks.php`:626
  - evidence: `->where('subscription_id', $localSub->id)                 ->where('stripe_price', $price['id'])                 ->update([`
- **Eliminate likely N+1 query in ImportCampaignMetricsCommand.php** (confidence: 0.60, leverage: 2.00) — `nivor-back-end/app/Console/Commands/ImportCampaignMetricsCommand.php`:89
  - evidence: `$this->info("Job despachado para usuário {$userId} com {$userCampaigns->count()} campanhas.");         }`
- **Eliminate likely N+1 query in DiagnoseSubscriptions.php** (confidence: 0.60, leverage: 2.00) — `nivor-back-end/app/Console/Commands/DiagnoseSubscriptions.php`:31
  - evidence: `$priceDiffTotal += $diff * DB::table('subscriptions')                         ->where('stripe_price', $plan->stripe_price_id_monthly)                         ->whereIn('stripe_status', ['active', 'past_due'])`
- **Eliminate likely N+1 query in ImportCampaignMetricsCommand.php** (confidence: 0.60, leverage: 2.00) — `nivor-back-end/app/Console/Commands/ImportCampaignMetricsCommand.php`:106
  - evidence: `$this->info("  Usuário {$userId}: {$userCampaigns->count()} campanhas em {$customerGroups->count()} customers");`
- **Eliminate likely N+1 query in ImportCampaignMetricsCommand.php** (confidence: 0.60, leverage: 2.00) — `nivor-back-end/app/Console/Commands/ImportCampaignMetricsCommand.php`:112
  - evidence: `$customerGroups->map(function ($campaigns, $customerId) {                         return [$customerId, $campaigns->count()];                     })->toArray()`
- **Eliminate likely N+1 query in ImportSearchTermsCommand.php** (confidence: 0.60, leverage: 2.00) — `nivor-back-end/app/Console/Commands/ImportSearchTermsCommand.php`:141
  - evidence: `$this->info("Jobs despachados para usuário {$userId} com {$userCampaigns->count()} campanhas.");         }`
- **Eliminate likely N+1 query in ImportSearchTermsCommand.php** (confidence: 0.60, leverage: 2.00) — `nivor-back-end/app/Console/Commands/ImportSearchTermsCommand.php`:158
  - evidence: `$this->info("  Usuário {$userId}: {$userCampaigns->count()} campanhas em {$customerGroups->count()} customers");`
- **Eliminate likely N+1 query in ImportSearchTermsCommand.php** (confidence: 0.60, leverage: 2.00) — `nivor-back-end/app/Console/Commands/ImportSearchTermsCommand.php`:164
  - evidence: `$customerGroups->map(function ($campaigns, $customerId) {                         return [$customerId, $campaigns->count()];                     })->toArray()`
- **Eliminate likely N+1 query in ProcessRetroactiveCommissionsJob.php** (confidence: 0.60, leverage: 2.00) — `nivor-back-end/app/Jobs/ProcessRetroactiveCommissionsJob.php`:247
  - evidence: `$existing = PartnerCommission::lockForUpdate()                     ->where('stripe_invoice_id', $invoice['id'])                     ->first();`
