<?php
/**
 * Shared bootstrap for all merchant-clone-agent phase scripts.
 * Run inside the in-cluster api pod via: php artisan tinker _bootstrap-consumer.php
 * Reads SRC_MID / TGT_MID / MODE from the environment.
 *
 * Responsibilities:
 *   - bind request mode context (rzp.mode + default DB connection)
 *   - point every external service client at its IN-CLUSTER dev service
 *     (config bakes prod URLs that this pod can't reach)
 *   - expose small helpers used by export/apply/verify
 */

if (!function_exists('mca_boot')) {

    function mca_mode(): string { return getenv('MODE') ?: 'test'; }
    function mca_src(): string  { return getenv('SRC_MID') ?: ''; }
    function mca_tgt(): string  { return getenv('TGT_MID') ?: ''; }

    // api DB connection for the current mode (features/pricing/bank/balance live here)
    function mca_api($mode = null) { return \DB::connection($mode ?: mca_mode()); }
    // account-service (ASV) writer — merchant + merchant_detail (mode-independent)
    function mca_asv() { return \DB::connection('account_service_writer'); }

    function mca_newid(): string {
        $a = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $s = ""; for ($i=0;$i<14;$i++) $s .= $a[random_int(0,61)]; return $s;
    }

    // emit a machine-readable marker line the bash layer greps for
    function mca_out(string $key, $val): void {
        echo "\n@@$key=" . json_encode($val) . "@@\n";
    }

    /** Point all service clients at in-cluster dev services for the given mode. */
    function mca_override_urls(string $mode): void {
        \Config::set('applications.splitz.url', 'http://splitz-base.splitz.svc.cluster.local/');
        \Config::set("applications.settlements_service.url.$mode",
            "http://settlements-base-$mode.settlements.svc.cluster.local");
        \Config::set("applications.ledger.url.pg.$mode",
            "http://ledger-base-$mode-pg.ledger.svc.cluster.local/");
        \Config::set("applications.ledger.url.$mode",
            "http://ledger-base-$mode.ledger.svc.cluster.local/");
        \Config::set("applications.terminals_service.$mode.url",
            "http://terminals-base-$mode.terminals.svc.cluster.local/");
        \Config::set('services.credcase.host',
            'http://credcase-base.credcase.svc.cluster.local');
        // some service classes cache the App facade under their own namespace in tinker
        if (!class_exists('RZP\\Services\\Dcs\\Features\\App', false)) {
            @class_alias(\Illuminate\Support\Facades\App::class, 'RZP\\Services\\Dcs\\Features\\App');
        }
    }

    /** Standard per-run boot: mode binding + url overrides. Returns [src, tgt, mode]. */
    function mca_boot(): array {
        $mode = mca_mode();
        app()->instance('rzp.mode', $mode);
        \Config::set('database.default', $mode);
        mca_override_urls($mode);
        return [mca_src(), mca_tgt(), $mode];
    }

    /** Load a merchant entity through the repo (handles ASV routing). */
    function mca_merchant(string $mid) {
        return app('repo')->merchant->findOrFailPublic($mid);
    }

    /** twirp POST helper (used for settlements). */
    function mca_twirp(string $base, string $auth, string $path, array $body): array {
        $ch = curl_init("$base/twirp/$path");
        curl_setopt_array($ch, [
            CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_USERPWD => $auth,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
            CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_TIMEOUT => 25,
        ]);
        $r = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return [$c, json_decode($r, true)];
    }
}
