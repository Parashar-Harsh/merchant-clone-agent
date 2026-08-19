<?php
/**
 * MINT phase: create + activate a fresh target merchant via the real signup flow.
 * Emits the new MID as @@CREATED_MID=...@@.
 * The Core::create tail dispatches a welcome email to SQS which 403s in this pod
 * (no SQS IAM) — harmless: the merchant is saved well before that step.
 */
require __DIR__ . '/_bootstrap.php';
[$SRC, $TGT, $MODE] = mca_boot();  // mint always runs in test unless MODE overridden

$suffix = substr(md5((string) random_int(0, PHP_INT_MAX)), 0, 8);
$email  = "clone.target+$suffix@razorpay.com";
$name   = getenv('TGT_NAME') ?: 'CLONE Target';
$org    = getenv('TGT_ORG')  ?: '100000razorpay';

try {
    $input   = ['name' => $name, 'email' => $email, 'org_id' => $org];
    $mdInput = ['token_data' => null, 'contact_name' => $name, 'contact_email' => $email];
    $meta    = [\RZP\Models\Merchant\Entity::SKIP_EMAIL_UNIQUENESS_CHECK => true];
    (new \RZP\Models\Merchant\Core())->create($input, $mdInput, $meta);
} catch (\Throwable $e) {
    // swallow the SQS/email tail failure; verify the merchant landed below
    if (strpos($e->getMessage(), 'SendMessage') === false
        && strpos($e->getMessage(), 'sqs') === false) {
        mca_out('MINT_ERR', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        return;
    }
}

$row = mca_asv()->table('merchants')->where('email', $email)->orderBy('created_at', 'desc')->first();
if (!$row) { mca_out('MINT_ERR', 'merchant row not found after create'); return; }

// activate (mirrors activation flow's core state, without external side-effects)
$now = time();
mca_asv()->table('merchants')->where('id', $row->id)
    ->update(['activated' => 1, 'activated_at' => $now, 'live' => 1, 'updated_at' => $now]);
mca_asv()->table('merchant_details')->where('merchant_id', $row->id)
    ->update(['activation_status' => 'activated']);

mca_out('CREATED_MID', ['mid' => $row->id, 'email' => $email]);
