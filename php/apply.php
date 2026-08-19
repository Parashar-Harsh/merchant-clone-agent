<?php
/**
 * APPLY phase: converge TGT to SRC for the current MODE. Idempotent.
 * Domains that are truly single-source (merchant/merchant_detail business
 * attrs) are applied on every mode-run harmlessly (same result).
 *
 * Order matters: pricing (deep-copy) -> features -> bank -> balance ->
 * merchant_detail KYC -> business attrs -> settlements -> ledger -> key -> terminals.
 */
require __DIR__ . '/_bootstrap.php';
[$SRC, $TGT, $MODE] = mca_boot();
$now = time();
$api = mca_api();
$asv = mca_asv();
$rep = ['mode' => $MODE];

/* ---------- 1. PRICING (deep-copy plan + assign) ----------
 * NOTE: merchant.pricing_plan_id is a SINGLE ASV field (mode-independent), but the
 * `pricing` rows are per-mode. So the clone plan id must be the SAME across modes,
 * with each mode's rules cloned under it. Deterministic id from TGT guarantees that.
 */
$srcPlan   = $asv->table('merchants')->where('id', $SRC)->value('pricing_plan_id');
$newPlan   = 'CLN' . substr(sha1('mcaclone|' . $TGT), 0, 11);   // stable 14-char id across modes
$cloneName = 'Clone ' . $TGT;
if ($srcPlan) {
    $haveRules = $api->table('pricing')->where('plan_id', $newPlan)->count();
    $n = 0;
    if ($haveRules == 0) {
        foreach ($api->table('pricing')->where('plan_id', $srcPlan)->get() as $r) {
            $row = (array) $r;
            $row['id'] = mca_newid();
            $row['plan_id'] = $newPlan;
            if (array_key_exists('plan_name', $row)) $row['plan_name'] = $cloneName;
            $row['created_at'] = $now; $row['updated_at'] = $now;
            if (array_key_exists('deleted_at', $row)) $row['deleted_at'] = null;
            $api->table('pricing')->insert($row); $n++;
        }
    }
    // assign (idempotent) — same id whichever mode runs
    $asv->table('merchants')->where('id', $TGT)->update(['pricing_plan_id' => $newPlan, 'updated_at' => $now]);
    $rep['pricing'] = ['plan' => $newPlan, 'rules_added' => $n, 'rules_total' => $api->table('pricing')->where('plan_id', $newPlan)->count()];
} else { $rep['pricing'] = 'source_has_no_plan'; }

/* ---------- 2. FEATURES ---------- */
$srcF = $api->table('features')->where('entity_id', $SRC)->where('entity_type', 'merchant')->pluck('name')->all();
$tgtF = $api->table('features')->where('entity_id', $TGT)->where('entity_type', 'merchant')->pluck('name')->all();
$add = [];
foreach ($srcF as $f) {
    if (in_array($f, $tgtF, true)) continue;
    $api->table('features')->insert(['id' => mca_newid(), 'name' => $f, 'entity_id' => $TGT,
        'entity_type' => 'merchant', 'created_at' => $now, 'updated_at' => $now]);
    $add[] = $f;
}
$rep['features'] = ['added' => $add];

/* ---------- 3. BANK ACCOUNT ---------- */
if ($api->table('bank_accounts')->where('merchant_id', $TGT)->count() == 0) {
    $c = 0;
    foreach ($api->table('bank_accounts')->where('merchant_id', $SRC)->get() as $b) {
        $row = (array) $b; $row['id'] = mca_newid(); $row['merchant_id'] = $TGT;
        if (array_key_exists('entity_id', $row)) $row['entity_id'] = $TGT;
        // beneficiary_code is UNIQUE — derive from target MID
        if (array_key_exists('beneficiary_code', $row))
            $row['beneficiary_code'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($TGT, 0, 10)));
        $row['created_at'] = $now; $row['updated_at'] = $now;
        if (array_key_exists('deleted_at', $row)) $row['deleted_at'] = null;
        $api->table('bank_accounts')->insert($row); $c++;
    }
    $rep['bank'] = "copied:$c";
} else { $rep['bank'] = 'already_present'; }

/* ---------- 4. BALANCE (per mode) ---------- */
if ($api->table('balance')->where('merchant_id', $TGT)->count() == 0) {
    $c = 0;
    foreach ($api->table('balance')->where('merchant_id', $SRC)->get() as $b) {
        $row = (array) $b; $row['id'] = mca_newid(); $row['merchant_id'] = $TGT;
        $row['created_at'] = $now; $row['updated_at'] = $now;
        $api->table('balance')->insert($row); $c++;
    }
    $rep['balance'] = "created:$c";
} else { $rep['balance'] = 'already_present'; }

/* ---------- 5. MERCHANT_DETAIL KYC + 6. BUSINESS ATTRS (single-source) ---------- */
$sm = (array) $asv->table('merchant_details')->where('merchant_id', $SRC)->first();
$copyMd = ['contact_name','contact_mobile','business_type','business_name','business_dba','business_website',
    'business_registered_address','business_registered_state','business_registered_city','business_registered_pin',
    'business_operation_address','business_operation_state','business_operation_city','business_operation_pin',
    'company_pan_name','business_category','business_subcategory','promoter_pan','promoter_pan_name',
    'bank_account_number','bank_account_name','bank_branch_ifsc','business_pan_url','promoter_pan_url',
    'activation_progress','locked','activation_flow','international_activation_flow','submitted','submitted_at',
    'activation_form_milestone','iec_code','gstin','company_cin','date_of_establishment'];
$mdU = [];
foreach ($copyMd as $f) if (array_key_exists($f, $sm)) $mdU[$f] = $sm[$f];
$mdU['updated_at'] = $now;
$asv->table('merchant_details')->where('merchant_id', $TGT)->update($mdU);

$sMer = (array) $asv->table('merchants')->where('id', $SRC)->first();
$copyMer = ['website','billing_label','whitelisted_domains','product_international','international',
    'activation_source','signup_source','legal_entity_id','category','category2','purpose_code','channel','business_banking'];
$merU = [];
foreach ($copyMer as $f) if (array_key_exists($f, $sMer)) $merU[$f] = $sMer[$f];
$merU['updated_at'] = $now;
$asv->table('merchants')->where('id', $TGT)->update($merU);
$rep['merchant_detail'] = 'copied:' . (count($mdU) - 1);
$rep['business_attrs']  = 'copied:' . (count($merU) - 1);

/* ---------- 7. SETTLEMENTS merchant_config (twirp; create-if-missing then full-replace) ---------- */
try {
    $base = config("applications.settlements_service.url.$MODE");
    $key  = config("applications.settlements_service.api.$MODE.key");
    $sec  = config("applications.settlements_service.api.$MODE.secret");
    $auth = "$key:$sec";
    [$sc, $src] = mca_twirp($base, $auth, 'rzp.settlements.merchant_config.v1.MerchantConfigService/Get', ['merchant_id' => $SRC]);
    // retry once on transient empty/non-200 (Get can flake)
    if ($sc !== 200 || empty($src['config'])) {
        [$sc, $src] = mca_twirp($base, $auth, 'rzp.settlements.merchant_config.v1.MerchantConfigService/Get', ['merchant_id' => $SRC]);
    }
    if ($sc === 200 && !empty($src['config'])) {
        // ensure target config exists
        [$gc, ] = mca_twirp($base, $auth, 'rzp.settlements.merchant_config.v1.MerchantConfigService/Get', ['merchant_id' => $TGT]);
        if ($gc !== 200)
            mca_twirp($base, $auth, 'rzp.settlements.merchant_config.v1.MerchantConfigService/Create',
                ['merchant_id' => $TGT, 'country_code' => ($src['config']['country_code'] ?? 'IN')]);
        // full-replace: send all source keys minus derived/ignored ones
        $cfg = $src['config'];
        foreach (['active','merchant_settlement_currency','cross_border_import_flow','disable_live_config',
                  'cb_import_flow_enabled','preferred_settlement_type'] as $k) unset($cfg[$k]);
        $uc = 0;
        for ($try = 0; $try < 3; $try++) {   // Update flakes with 500 (linked-acct schedule propagation)
            [$uc, ] = mca_twirp($base, $auth, 'rzp.settlements.merchant_config.v1.MerchantConfigService/Update',
                ['merchant_id' => $TGT, 'config' => $cfg]);
            if ($uc === 200) break;
        }
        $rep['settlements'] = "update_http:$uc";
    } else { $rep['settlements'] = "source_get_http:$sc"; }
} catch (\Throwable $e) { $rep['settlements'] = 'err:' . $e->getMessage(); }

/* ---------- 8. LEDGER COA (onboard target with same PG event) ---------- */
try {
    $merchant = mca_merchant($TGT);
    $resp = (new \RZP\Models\Merchant\Balance\Ledger\Core())
        ->createPGLedgerAccount($merchant, $MODE, 0, [], 0, [], 'pg_merchant_onboarding');
    $rep['ledger'] = is_array($resp) ? ('ok:' . ($resp[0] ?? '?')) : 'ok';
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    $rep['ledger'] = (stripos($msg, 'already') !== false) ? 'already_onboarded' : ('err:' . $msg);
}

/* ---------- 9. API KEY (mint if source has key access; sets has_key_access) ---------- */
try {
    if (($sMer['has_key_access'] ?? 0) == 1) {
        if ($api->table('keys')->where('merchant_id', $TGT)->count() == 0) {
            $merchant = mca_merchant($TGT);
            $key = (new \RZP\Models\Key\Core())->createFirstKey($merchant, $MODE);
            $kid = is_object($key) ? (method_exists($key, 'getPublicKey') ? $key->getPublicKey() : $key->getId()) : 'ok';
            $rep['api_key'] = "minted:$kid";
        } else { $rep['api_key'] = 'already_present'; }
        $asv->table('merchants')->where('id', $TGT)->update(['has_key_access' => 1, 'updated_at' => $now]);
    } else { $rep['api_key'] = 'source_no_key_access'; }
} catch (\Throwable $e) { $rep['api_key'] = 'err:' . $e->getMessage(); }

/* ---------- 10. TERMINALS (mode-specific; recreate direct, new UPI ids) ---------- */
try {
    $tsvc = app('terminals_service');
    $r = $tsvc->getTerminalsByMerchantId($SRC);
    $sList = is_array($r) ? ($r['terminals'] ?? $r) : $r;
    $r2 = $tsvc->getTerminalsByMerchantId($TGT);
    $tList = is_array($r2) ? ($r2['terminals'] ?? $r2) : $r2;
    $tGateways = array_map(fn($t) => $t['gateway'] ?? '', is_array($tList) ? $tList : []);
    $created = [];
    foreach ((is_array($sList) ? $sList : []) as $t) {
        if (in_array($t['gateway'] ?? '', $tGateways, true)) continue; // dedupe by gateway
        $input = [
            'gateway' => $t['gateway'], 'gateway_acquirer' => $t['gateway_acquirer'] ?? '',
            'procurer' => $t['procurer'] ?? 'razorpay',
            'category' => $t['category'] ?? null, 'mode' => $t['mode'] ?? 3, 'tpv' => $t['tpv'] ?? 0,
            'enabled' => true, 'status' => $t['status'] ?? 'activated',
            'currency' => json_decode($t['currency'] ?? '["INR"]', true) ?: ['INR'],
            'type' => json_decode($t['type'] ?? '[]', true) ?: [],
        ];
        foreach (['card','upi','netbanking','emi','cardless_emi','paylater','app','bank_transfer',
                  'cc_on_upi','credit_line_on_upi','wallet_on_upi'] as $mflag)
            if (!empty($t[$mflag])) $input[$mflag] = true;
        // UPI terminals require unique gateway_merchant_id + gateway_merchant_id2 + vpa
        if (!empty($t['upi'])) {
            $vpa = 'clonetarget' . strtolower(substr($TGT, 2, 6)) . '.rzp@rxairtel';
            $input['gateway_merchant_id'] = mca_newid();
            $input['gateway_merchant_id2'] = $vpa;
            $input['vpa'] = $vpa;
        } else {
            if (!empty($t['gateway_merchant_id'])) $input['gateway_merchant_id'] = $t['gateway_merchant_id'];
            if (!empty($t['gateway_terminal_id'])) $input['gateway_terminal_id'] = $t['gateway_terminal_id'];
        }
        $path = sprintf('v3/merchants/%s/terminals/v3', $TGT);
        $resp = $tsvc->proxyTerminalService($input, 'POST', $path, ['timeout' => 30, 'connect_timeout' => 10]);
        $created[] = ($resp['id'] ?? $t['gateway']);
    }
    $rep['terminals'] = ['source' => count(is_array($sList) ? $sList : []), 'created' => $created];
} catch (\Throwable $e) { $rep['terminals'] = 'err:' . $e->getMessage(); }

mca_out('APPLY', $rep);
