<?php
/** VERIFY phase: per-mode source-vs-target diff. Emits @@VERIFY=...@@ (one per mode). */
require __DIR__ . '/_bootstrap.php';
[$SRC, $TGT, $MODE] = mca_boot();
$api = mca_api();
$asv = mca_asv();
$res = ['mode' => $MODE, 'source' => $SRC, 'target' => $TGT];

$sm = $asv->table('merchants')->where('id', $SRC)->first();
$tm = $asv->table('merchants')->where('id', $TGT)->first();

// features
$sf = $api->table('features')->where('entity_id', $SRC)->where('entity_type', 'merchant')->pluck('name')->sort()->values()->all();
$tf = $api->table('features')->where('entity_id', $TGT)->where('entity_type', 'merchant')->pluck('name')->sort()->values()->all();
$res['features'] = ['source' => count($sf), 'target' => count($tf), 'match' => ($sf == $tf)];

// pricing (content-identical, ignoring id/plan_id/plan_name/timestamps)
$ig = array_flip(['id','plan_id','plan_name','created_at','updated_at','deleted_at','audit_id']);
$fp = function ($plan) use ($api, $ig) {
    $s = [];
    foreach ($api->table('pricing')->where('plan_id', $plan)->get() as $r) {
        $a = array_diff_key((array) $r, $ig); ksort($a); $s[] = md5(json_encode($a));
    }
    sort($s); return $s;
};
$sp = $fp($sm->pricing_plan_id); $tp = $fp($tm->pricing_plan_id);
$res['pricing'] = ['source_plan' => $sm->pricing_plan_id, 'target_plan' => $tm->pricing_plan_id,
    'deep_copied' => ($sm->pricing_plan_id != $tm->pricing_plan_id),
    'rules' => [count($sp), count($tp)], 'content_identical' => ($sp == $tp)];

// bank
$sb = $api->table('bank_accounts')->where('merchant_id', $SRC)->first();
$tb = $api->table('bank_accounts')->where('merchant_id', $TGT)->first();
$res['bank'] = ['match' => ($sb && $tb && $sb->ifsc_code == $tb->ifsc_code && $sb->account_number == $tb->account_number)];

// balance presence
$res['balance'] = ['source' => $api->table('balance')->where('merchant_id', $SRC)->count(),
    'target' => $api->table('balance')->where('merchant_id', $TGT)->count()];

// api keys presence
$res['keys'] = ['source' => $api->table('keys')->where('merchant_id', $SRC)->count(),
    'target' => $api->table('keys')->where('merchant_id', $TGT)->count(),
    'has_key_access' => [$sm->has_key_access, $tm->has_key_access]];

// terminals (mode-specific) — count + config fingerprint
try {
    $tsvc = app('terminals_service');
    $norm = fn($t) => json_encode([$t['gateway'] ?? null, $t['gateway_acquirer'] ?? null, $t['upi'] ?? null,
        $t['card'] ?? null, $t['mode'] ?? null, $t['tpv'] ?? null, $t['status'] ?? null]);
    $g = function ($mid) use ($tsvc, $norm) {
        $r = $tsvc->getTerminalsByMerchantId($mid); $l = is_array($r) ? ($r['terminals'] ?? $r) : $r;
        $l = is_array($l) ? $l : []; $f = array_map($norm, $l); sort($f); return [count($l), $f];
    };
    [$sc, $sfp] = $g($SRC); [$tc, $tfp] = $g($TGT);
    $res['terminals'] = ['source' => $sc, 'target' => $tc, 'config_match' => ($sfp == $tfp)];
} catch (\Throwable $e) { $res['terminals'] = 'err:' . $e->getMessage(); }

// settlements config (behavior keys) via twirp
try {
    $base = config("applications.settlements_service.url.$MODE");
    $key  = config("applications.settlements_service.api.$MODE.key");
    $sec  = config("applications.settlements_service.api.$MODE.secret");
    $behavior = ['types','features','schedules','preferences','initiate_types','pos_config','pg_ledger_reverse_shadow_enabled','pg_ledger_ramp_on_hold_enabled'];
    [$c1, $r1] = mca_twirp($base, "$key:$sec", 'rzp.settlements.merchant_config.v1.MerchantConfigService/Get', ['merchant_id' => $SRC]);
    [$c2, $r2] = mca_twirp($base, "$key:$sec", 'rzp.settlements.merchant_config.v1.MerchantConfigService/Get', ['merchant_id' => $TGT]);
    $s = $r1['config'] ?? []; $t = $r2['config'] ?? []; $diff = [];
    foreach ($behavior as $k) if (json_encode($s[$k] ?? null) !== json_encode($t[$k] ?? null)) $diff[] = $k;
    $res['settlements'] = ['source_http' => $c1, 'target_http' => $c2, 'behavior_match' => empty($diff), 'mismatched' => $diff];
} catch (\Throwable $e) { $res['settlements'] = 'err:' . $e->getMessage(); }

mca_out('VERIFY', $res);
