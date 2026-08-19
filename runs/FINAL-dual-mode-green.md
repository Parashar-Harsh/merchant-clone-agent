# Final dual-mode verification — SFaaE4GiJhacCM → TRl7rVHDGreW5m

Run via toolkit: `bin/clone.sh --source SFaaE4GiJhacCM --target TRl7rVHDGreW5m` (modes: test + live)

| Domain | TEST | LIVE |
|---|---|---|
| features | 7/7 match | 8/8 match |
| pricing (deep-copy, plan CLN6403d330cef) | 45/45 identical | 67/67 identical |
| bank account | match | match |
| balance | 1/1 | 1/1 |
| api key / has_key_access | 1/1 | 1/1 |
| terminals | 0/0 match | 1/1 match |
| settlements config | behavior match | behavior match |

Source config genuinely DIFFERS by mode (7 features/45 rules in test vs 8/67 in live) — proof that
single-mode cloning is insufficient. Both modes now converge.

## Bugs the dual-mode toolkit run exposed (and fixed)
1. pricing_plan_id is a SINGLE mode-independent ASV field, but rules are per-mode. First cut generated
   a different clone-plan id per mode → live run overwrote test's assignment. Fixed: deterministic
   shared plan id (CLN+sha1(TGT)) with per-mode rules cloned under it.
2. settlements Update flakes with HTTP 500 (linked-account schedule propagation). Fixed: retry x3.

## Re-run on a FRESH target (proves fixes from scratch) — TRoXKK5lCXU0mA
`bin/clone.sh --source SFaaE4GiJhacCM --mint`  → both modes green:
- pricing: SAME plan CLNbfb14cf426b in test (45 rules) AND live (67 rules), content_identical both  [fix #1 ✓]
- settlements: update_http:200 both modes, behavior_match  [fix #2 ✓]
- terminals: live 1/1 config_match (created TRoZ7tdsFCSiSC)  [fix #3 ✓]
- features 7/7 (test) 8/8 (live), bank/balance/keys match

## Additional bugs found+fixed this pass
3. apply.php terminals: `json_decode()` threw when terminals-service returns currency/type as arrays
   (not JSON strings). Fixed with `mca_asarray()` coercion helper in _bootstrap.php.
4. ensure-pod.sh: `mapfile` is bash 4+ (macOS ships 3.2) → replaced with portable while-read.
5. ensure-pod.sh: stale Completed/Error pod blocked re-create → now force-deletes before recreate.
