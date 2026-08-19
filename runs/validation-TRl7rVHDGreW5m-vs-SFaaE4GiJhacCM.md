# Dashboard-parity validation: TRl7rVHDGreW5m (target) vs SFaaE4GiJhacCM (source)

Compared full admin data layer (every column of merchants + merchant_details + related entities).

## merchants entity: 66/76 fields identical
Remaining differences (all expected):
- IDENTITY (must differ): id, name, email, invoice_code, transaction_report_email, activated_at, created_at, updated_at, audit_id
- INTENDED: pricing_plan_id (deep-copied → m0Wp3hLKbahCV4)
- FIXED this pass: legal_entity_id, whitelisted_domains, website, billing_label, product_international, activation_source, signup_source, has_key_access(=1)

## merchant_details entity: 127/132 fields identical
Fixed this pass (35 KYC/business fields copied): contact_name, contact_mobile, business_type/name/dba/website, registered+operation address/state/city/pin, company_pan_name, business_category/subcategory, promoter_pan(+name), bank_account_number/name, bank_branch_ifsc, business_pan_url, promoter_pan_url, activation_progress(85), locked(1), activation_flow(greylist), international_activation_flow, submitted(1), submitted_at, activation_form_milestone(L2), iec_code
Remaining differences (all identity): merchant_id, contact_email, created_at, updated_at, audit_id

## Related entities
- features: 7/7 identical
- pricing: deep-copied 45 rules, content-identical, new plan m0Wp3hLKbahCV4
- bank_accounts: cloned (IFSC RZPB0000000, acct ...2592)
- balance: created primary INR balance (test + live) — was MISSING before
- api key: generated rzp_test_TRlyX8IYodi5Oy (via credcase, cluster override); has_key_access=1
- settlements config: all settlement-behavior keys cloned
- ledger COA: onboarded pg_merchant_onboarding (acct TRldx2hwBh4Yo2)
- methods: no explicit config on source; category/attrs aligned → defaults match
- DCS: source empty (no-op); terminals: source 0 (no-op)

## Honest caveats
- API key value differs (new key minted for target — you can't share one key across merchants).
- Uploaded-doc URLs (business_pan_url/promoter_pan_url) copied as-is → point to source's files (dev only).
- transaction_report_email/contact_email kept target's (email uniqueness).

## CORRECTION: terminals (found via dashboard, live mode)
Earlier "0 terminals" was wrong — I only checked TEST mode. Source has 1 LIVE terminal.
- source terminal: TLh3YyRe2NVYdF (gateway upi_rzpapb, acquirer apb_rzp, UPI, cc_on_upi+credit_line_on_upi, mode 3, tpv 2, activated)
- CLONED → target terminal TRm9VrPKm3sknA with identical config; config_match=true (1↔1).
- Terminal-specific IDs necessarily differ: id, gateway_merchant_id, vpa (target vpa=clonetargetl7rvhd.rzp@rxairtel) — VPA/MID are unique per merchant.
- Lesson: terminals are MODE-SPECIFIC; must check both live and test.
