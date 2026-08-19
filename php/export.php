<?php
/** EXPORT phase: dump the source merchant's config for the current MODE. */
require __DIR__ . '/_bootstrap.php';
[$SRC, $TGT, $MODE] = mca_boot();

$snap = ['mode' => $MODE, 'source' => $SRC];

// merchant + merchant_detail (ASV, mode-independent — captured every run for convenience)
$m  = mca_asv()->table('merchants')->where('id', $SRC)->first();
$md = mca_asv()->table('merchant_details')->where('merchant_id', $SRC)->first();
$snap['merchant']        = $m ? (array) $m : null;
$snap['merchant_detail'] = $md ? (array) $md : null;

// features (api DB, per mode)
$snap['features'] = mca_api()->table('features')
    ->where('entity_id', $SRC)->where('entity_type', 'merchant')
    ->pluck('name')->sort()->values()->all();

// pricing plan id + rule count (plan is a shared entity)
$planId = $m->pricing_plan_id ?? null;
$snap['pricing_plan_id'] = $planId;
$snap['pricing_rules']   = $planId ? mca_api()->table('pricing')->where('plan_id', $planId)->count() : 0;

// bank accounts (per mode)
$snap['bank_accounts'] = array_map(
    fn($b) => ['type' => $b->type, 'ifsc' => $b->ifsc_code ?? null, 'acc_last4' => substr($b->account_number ?? '', -4)],
    mca_api()->table('bank_accounts')->where('merchant_id', $SRC)->get()->all()
);

// balance (per mode)
try {
    $snap['balances'] = mca_api()->table('balance')->where('merchant_id', $SRC)
        ->get(['type', 'currency', 'balance'])->map(fn($r) => (array) $r)->all();
} catch (\Throwable $e) { $snap['balances'] = "err:" . $e->getMessage(); }

// terminals (terminals service, per mode) — THE mode-specific gotcha
try {
    $tsvc = app('terminals_service');
    $r = $tsvc->getTerminalsByMerchantId($SRC);
    $list = is_array($r) ? ($r['terminals'] ?? $r) : $r;
    $snap['terminals'] = array_map(fn($t) => [
        'id' => $t['id'] ?? null, 'gateway' => $t['gateway'] ?? null,
        'acquirer' => $t['gateway_acquirer'] ?? null, 'upi' => $t['upi'] ?? null,
        'card' => $t['card'] ?? null, 'status' => $t['status'] ?? null,
    ], is_array($list) ? $list : []);
} catch (\Throwable $e) { $snap['terminals'] = "err:" . $e->getMessage(); }

// settlements merchant_config (per mode) — read via twirp
try {
    $base = config("applications.settlements_service.url.$MODE");
    $key  = config("applications.settlements_service.api.$MODE.key");
    $sec  = config("applications.settlements_service.api.$MODE.secret");
    [$c, $resp] = mca_twirp($base, "$key:$sec",
        'rzp.settlements.merchant_config.v1.MerchantConfigService/Get', ['merchant_id' => $SRC]);
    $snap['settlements_config'] = ($c === 200) ? array_keys($resp['config'] ?? []) : "http:$c";
} catch (\Throwable $e) { $snap['settlements_config'] = "err:" . $e->getMessage(); }

mca_out('SNAP', $snap);
