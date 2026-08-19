# Merchant Clone Agent — Implementation Plan

**Goal:** Given a `source_merchant_id` and a freshly activated `target_merchant_id`, converge the target's configuration to the source's — feature flags, DCS configs, terminals, pricing (deep-copied plan), bank account, ledger COA, and methods (≙ checkout preferences) — using only service GET/SET APIs. Environment: **devstack v2 (non-prod)**. Splitz: **out of scope for phase 1** (next phase, confirm-gated).

All API references below were verified against `master` of razorpay/api, dcs, splitz, ledger, terminals (Aug 2026).

---

## ⚠️ MANDATORY: dual-mode (live + test) coverage

Several entities are **mode-specific** — a record can exist in `live` and not `test` (or vice-versa). During the first real clone we missed a `live` UPI terminal because we only queried `test`. **Every domain must be Exported, Applied, and Verified in BOTH modes**, and the convergence report must show per-mode counts so a mode-only gap can never hide.

Rules:
1. The orchestrator runs each adapter twice — `mode=test` and `mode=live` — unless the adapter is explicitly declared mode-agnostic below.
2. `Verify` compares source-vs-target **per mode**; a domain is only "converged" when it matches in *both* modes.
3. The report lists `{domain, mode, source_count, target_count, match}` rows — never a single mode-blind number.
4. Auth/context differs by mode: `app()->instance('rzp.mode', <mode>)`, `Config::set('database.default', <mode>)`, and the service-client URL/creds key (`applications.<svc>.<mode>`) must all be set to the same mode before each call. Feature writes with `should_sync=1` intentionally span both modes — still verify both.

Per-domain mode behavior (verified during first clone):
| Domain | Mode-specific? | Notes |
|---|---|---|
| terminals | **YES** | live and test are separate deployments/DBs — check both (this was the miss) |
| balance | **YES** | primary balance exists per mode (api-beta vs api-beta-test) |
| api keys / has_key_access | **YES** | keys minted per mode via credcase |
| features | mostly test↔live synced via `should_sync=1` | still verify both |
| pricing (plan + rules) | plan is a shared entity | verify assignment in both modes |
| methods | per mode | verify both |
| bank account | per mode | verify both |
| settlements merchant_config | per mode (settlements-base-live vs -test) | check both |
| DCS configs | per mode (dcs-*-live vs -test) | check both |
| ledger COA | per mode (tenant PG, ledger-*-live-pg vs -test-pg) | onboard/verify both |
| merchant + merchant_detail | account-service (ASV) | single source of truth, but confirm |

---

## Architecture

```
merchant-clone-agent (Go CLI)
  cmd/clone/            # CLI entrypoint: clone --source <mid> --target <mid> [--dry-run] [--domains ...]
  internal/orchestrator # phase runner: Export → Transform → Apply → Verify
  internal/adapters/    # one adapter per domain, common interface
      features/  dcsconfig/  pricing/  methods/  bankaccount/  ledger/  terminals/  (splitz/ — phase 2)
  internal/clients/     # api-monolith client (admin+internal auth), dcs client, terminals client, ledger twirp client
  internal/snapshot/    # versioned JSON export artifact + diff engine
  internal/report/      # convergence report (applied / skipped / failed / needs-manual)
```

**Adapter interface:**
```go
type Adapter interface {
    Fetch(ctx, sourceMID) (DomainSnapshot, error)          // GET APIs only
    Transform(snap DomainSnapshot, targetMID) (Plan, error) // id rewrite, blocklist, delta vs target's current state
    Apply(ctx, Plan) (Result, error)                        // SET APIs only, idempotent
    Verify(ctx, targetMID, snap) (Diff, error)              // re-GET and diff
}
```

**Key principle — minimize credential surface:** route every call that the api monolith can serve through the monolith's `internal/` or admin routes (features, pricing, methods, bank account, ledger passthrough, terminals proxy). Direct service clients only where the monolith has no equivalent (DCS KV enumeration, terminals fetch filters if needed).

---

## Domain adapters (verified API map)

### 1. Feature flags (verified deep-trace)
- **Fetch:** `GET features/{mid}` (admin, `VIEW_MERCHANT_FEATURES`) → `{assigned_features: [...], all_features: [...]}` — `assigned_features` is DB ∪ DCS merged & deduped. Internal variant: `GET internal/features/{entityId}`.
- **Apply:** `POST features/assign` (admin, `MANAGE_BULK_FEATURE_MAPPING`) body `{name: [names], entity_type: merchant, entity_ids: [target], should_sync: '1'}`. Behavior verified in `Feature\Service::multiAssignFeature`:
  - Synchronous, per-(feature,mid) try/catch; response always HTTP 200 `{successful:{name:[mids]}, failed:{name:[mids]}}` — **agent MUST parse `failed`**, never trust status code.
  - In prod/beta ALL feature writes route via DCS proxy V2 transparently (both DB- and DCS-mapped names) — no separate DCS feature handling needed on our side.
  - `should_sync=1` writes BOTH test+live modes and skips already-assigned dup errors → idempotent re-runs for free.
  - Server enforces its own blocklist (LEDGER_FEATURES, PAYOUT_SERVICE features, payout idempotency features) and per-feature side-effect hooks (jobs, webhooks, terminal adds) — another reason API-first beats DB writes.
- **Blocklist (config-driven):** seed = admin-dashboard modal's EXCLUDED_FEATURES (`ledger_journal_writes/reads`, `ledger_reverse_shadow`, `pg_ledger_journal_writes`, `no_settlement_service`, `headless`, `ivr`, `dcc`, `block_settlements`, `bharath_qr`, `fund_account_validation`, `es_ondemand_restricted`) + `no_doc_onboarding` + hidden features; review with team.
- Delta-apply: skip names already present on target. Note `features/remove` fires the admin workflow engine — avoid removals in v1; clone is additive.

### 2. DCS configs (non-feature KV)
- **Fetch:** `POST /v1/kv/entities` with key pattern per namespace: `rzp/pg/merchant/{mid}/*`, `rzp/x/merchant/{mid}/*`, `rzp/capital/merchant/{mid}/*` (paginated).
- **Transform:** rewrite `{mid}` in key; drop keys owned by feature proxy (already handled by adapter 1) and keys in `$newDcsConfigurationServiceMapping` (write via owning service or flag needs-manual).
- **Apply:** `POST /v1/kv/put` with `auditLog{changeBy: "merchant-clone-agent", changeReason: "clone from <source>"}`.

### 3. Pricing — DEEP COPY (decision c)
- **Fetch:** `GET merchants/{source}/pricing` → plan; `GET pricing/{plan_id}` → all rules.
- **Apply:**
  1. `POST pricing` body `{plan_name: "<source_plan_name>_clone_<target_mid_suffix>", rules: [...]}` — strip read-only fields (id, plan_id, created_at, org defaults) from each rule; keep plan_name alphanumeric (validator constraint).
  2. `POST merchants/{target}/pricing` (or `POST internal/merchants/{target}/pricing`) body `{pricing_plan_id: <new_plan_id>}`.
- Note: plan creation is dual-writing to charge-collections service behind a router — use the monolith route and let it handle that.
- Idempotency: search existing plans by the derived clone name before creating.
- **Assignment validation chain (verified):** plan must belong to target's org; every enabled method (except cod) must have a payment rule; if target is NOT dynamic fee-bearer, EVERY rule's `fee_bearer` must equal the target's → deep-copy must carry `fee_bearer` verbatim and target's fee_bearer must match source's (pre-flight check in Transform). Amex enabled ⇒ Amex network rule required.
- **Workflow interception:** if the org has a maker-checker workflow on `EDIT_MERCHANT_PRICING`, assignment returns a workflow object instead of applying — agent must detect this response shape and report `needs-approval` instead of `applied`.

### 4. Methods (≙ checkout_method_preferences)
- `checkout_method_preferences` verified NOT to exist as a stored config; checkout `GET /preferences` derives from the `methods` row.
- **Fetch:** `GET merchant/methods/all/{source}` (internal).
- **Apply:** `PUT merchants/{target}/methods` (admin, `EDIT_MERCHANT_METHODS`) or `PATCH internal/merchants/{target}/methods`; strip identifiers/timestamps.
- **Ordering:** MUST run after pricing (method enablement validates pricing rules exist for each method).

### 5. Bank account
- **Fetch:** `GET internal/merchants/{source}/bank_account`.
- **Apply:** admin `PUT bank_accounts/{id}` on target's existing account, or create path. Fields: beneficiary_name, account_number, ifsc_code, account_type, beneficiary contact fields.
- Devstack = non-prod so copying account numbers is fine; keep a `--bank-account=copy|skip|override` flag for future prod-hardening.

### 6. Ledger COA
- **Fetch:** `AccountAPI/FetchMerchantAccounts` for both source and target (twirp `rzp.ledger.account.v1.AccountAPI`, header `ledger-tenant: PG`, `idempotency-key`).
- **Apply (delta only):** target already has base COA from activation (`pg_merchant_onboarding`). For source-extras: fire matching onboarding events via monolith admin passthrough `POST ledger_service/create_accounts_on_event` (events like `pg_cb_ica_merchant_onboarding`, `pg_merchant_credit_onboarding`) or `AccountAPI/Create` for one-off accounts with `parent_account_id` remapped.

### 7. Terminals
- **Fetch:** `GET /v1/merchants/{source}/terminals` (terminals svc) or monolith proxy `GET merchants/{id}/terminals`.
- **Transform:** partition by ownership:
  - **Shared/bank terminals** (owner `100000Razorpay` / submerchant-attached): plan = attach target as submerchant.
  - **Direct terminals**: plan = re-create with source's shape (gateway, methods flags, mode, currency, category, network_category, type, procurer) and source's gateway MID/TID/secrets — acceptable in devstack; add `--terminal-secrets=copy|placeholder` flag.
  - **Dedupe** against terminals the activation flow already auto-created for target (match on gateway+method).
- **Apply:** shared → `POST /v3/terminals/{tid}/merchants/{target}`; direct → `POST /v3/merchants/{target}/terminals/validatev3` (preflight) then `POST /v3/merchants/{target}/terminals/v3`. Monolith proxy equivalents exist (`terminal_add_merchant`, `merchant_create_terminal_v3`) if we stay monolith-only.

### 8. Settlements merchant_config (added after prior-art trace)
- **Backend:** monolith admin routes `settlements/merchant_config/get|create|update` (SettlementController → twirp `rzp.settlements.merchant_config.v1.MerchantConfigService` on razorpay/settlements). Permissions: `VIEW_ALL_ENTITY` (get), `SETTLEMENT_SERVICE_MERCHANT_CONFIG_EDIT` (update), `SETTLEMENT_BULK_UPDATE` (create).
- **Update is FULL-REPLACE, not patch** — all non-ignored top-level keys required. Pattern: Get source → Get target → merge (schedules/types/preferences/features from source) → strip `active` (derived at read time from api global config, never written) → Update target.
- Target may have NO config yet (Get = NotFound; no lazy create): call `settlements/merchant_config/create` first (starts from country defaults) or the migration route (`settlements/service/migration`, which also assigns `new_settlement_service` feature).
- Server-side guards on update: per-MID distributed lock, settlement-type-change blocked if recent pending executions, aggregate-parent validation, daily-settlement schedule-hour consistency. Side effect: schedule changes propagate to linked-account children.
- Schedule IDs are shared entities in the settlements service — safe to reference the source's ids directly.

### 9. Splitz — PHASE 2 (decision b)
- Design stub only: `SegmentAPI/Lookup` per segment for membership discovery, `SegmentAPI/Update {id, element_ids:[target]}` to append; every write behind an explicit `--confirm-splitz` gate because segments are globally shared state. Not built in phase 1.

---

## Apply ordering (dependency-driven)

```
1. pricing (deep-copy plan + assign)     — must precede methods
2. methods                                — validates against pricing
3. features (DB + DCS via monolith)
4. DCS KV configs
5. bank account
6. ledger COA delta
7. terminals (shared attach → direct create)
8. settlements merchant_config (create-if-missing → full-replace update)
[phase 2: 9. splitz, confirm-gated]
```

---

## Devstack execution plan (grounded 2026-08-20, per devstack-harsh-session-handoff.md)

**Environment facts (verified):**
- Devstack v2 fully set up: `devstackctl` v0.5.10, label `harsh`, cluster `dev-serve` (kubectl context working), backend `https://idp-api.dev.razorpay.in`, TTL profile 5h. Resources UI: https://develop.dev.razorpay.in/resources/label/harsh
- **All required services run as BASE pods in dev-serve** (verified via kubectl 2026-08-20): `api` (monolith), `dcs`, `splitz-base`, `ledger` (l-ac live/test), `settlements` (workers), `terminals-base-live`, `payment-methods-base`, `pg-router`. **Zero deployments needed** — the agent only CALLS services; it targets base, not the `harsh` label. (The `harsh`-label terminals deploy has a 5h TTL — don't depend on it; use terminals-base.)
- Base URL pattern: `https://<service>.dev.razorpay.in` (confirmed for api, argo, e2e-test-orchestrator, idp-api). VPN required.
- Agent runs LOCALLY (Mac → VPN → dev-serve base services). No Harbor image, no devpod template, no flows.yaml involvement for v1. (Deferred: deploying the agent itself as a devstack service.)

### Stage 0 — Access & test data (~½–1 day, the only stage with dependencies on others)
1. **Base URL sheet**: one curl healthcheck per service (`api`, `dcs`, `settlements`, `ledger`, `terminals`); where hostname guess fails, `kubectl --context dev-serve get ingress -n <ns>` for the exact host. Record in `configs/devstack.yaml`.
2. **Auth decision (investigate first, then pick one)**:
   - Path A (preferred): api-monolith **internal-app basic auth** — mine `environment/.env.defaults` / dev credstash for an internal app already whitelisted for our routes (admin_dashboard identity is whitelisted for settlements merchant_config; check features/pricing route whitelists).
   - Path B: dev **admin token** (admin user on dev admin-dashboard) with permissions: MANAGE_BULK_FEATURE_MAPPING, VIEW_MERCHANT_FEATURES, EDIT_MERCHANT_PRICING, CREATE_PRICING_PLAN, MERCHANT_PRICING_PLANS, EDIT_MERCHANT_METHODS, VIEW_TERMINAL, ASSIGN_MERCHANT_TERMINAL, TERMINAL_MANAGE_MERCHANT, LEDGER_CLIENT_ACTIONS, SETTLEMENT_SERVICE_MERCHANT_CONFIG_EDIT, VIEW_ALL_ENTITY, SETTLEMENT_BULK_UPDATE.
   - Nail down **mode selection** (test vs live) for the chosen path — replicate how admin-dashboard's `live/` prefix maps to the api call (dashboard proxy headers vs direct admin API host + mode header). Deliverable: a working curl for ONE read (features) and ONE write (feature assign on a scratch merchant) in both modes.
   - DCS creds for `/v1/kv/*` (only non-monolith dependency; from dcs dev config).
3. **Source merchant**: pick/enrich one dev merchant with: >5 features (mix DB+DCS), a custom multi-rule pricing plan, non-default methods, ≥1 direct + ≥1 shared terminal, a settlements config, ≥1 DCS KV key. Script the enrichment so we can recreate it.
4. **Target-minting recipe**: repeatable script to create + activate a merchant on dev (candidate: reuse the CBE devstack E2E onboarding suite via workflow_dispatch, else direct API sequence: create merchant → seed merchant_detail → L2 submit → internal activation_status=activated).
5. Repo `razorpay/merchant-clone-agent` created (Go), CI lint+test.
6. (Parallel, non-blocking) Sync with Aegis team (Daya/Raj) on the Merchant Cloner component overlap.

### Stage 1 — Scaffold (day 1)
Go CLI (`clone export|plan|apply|verify`, `--source`, `--target`, `--domains`, `--dry-run`, `--mode test|live`), config profiles (devstack.yaml: base URLs + creds via env vars), typed clients: monolith (admin/internal), DCS KV. Snapshot schema (versioned JSON), report writer, HTTP-fixture test harness.

### Stage 2 — Export adapters (days 2–3)
8 `Fetch()` impls against devstack base: features, DCS KV, pricing (plan+rules), methods, bank account, ledger accounts (via monolith passthrough), terminals, settlements config. **Milestone:** `clone export --source <mid>` emits a complete snapshot from dev.

### Stage 3 — Transform + dry-run (day 4)
Blocklists (seeded from modal EXCLUDED_FEATURES), fee-bearer preflight (source vs target), terminal partition/dedupe (vs target's auto-created terminals), settlements full-replace merge with `active` stripped, DCS key rewrite, delta-vs-target for every domain. **Milestone:** `clone plan` prints reviewable apply-plan; zero writes.

### Stage 4 — Apply adapters (days 5–7)
In order: pricing deep-copy (create plan → assign; detect workflow-object response → `needs-approval`) → methods → features (`features/assign`, `should_sync=1`, parse `{successful,failed}`) → DCS KV puts (with auditLog) → bank account → ledger CreateOnEvent delta → terminals (submerchant attach → validatev3+create) → settlements (create-if-missing → full-replace update). Idempotent re-runs; per-domain selector. **Milestone:** full clone on scratch merchants, re-run converges to no-op.

### Stage 5 — Verify + E2E on devstack (days 8–9)
`Verify()` re-GET + diff per domain **in both live and test modes** (see mandatory dual-mode section); convergence report artifact with per-mode rows. E2E script: mint target (Stage-0 recipe) → activate → `clone apply` → assert: features diff empty (modulo blocklist), pricing rules deep-equal (new plan_id), methods row equal, checkout `GET /preferences` parity, terminals equivalent, settlements schedules equal. Wire as `make e2e-devstack`. **Milestone: demo — "give me a MID, get its twin".**

### Stage 6 — Hardening (day 10)
Retries/backoff on 5xx, partial-failure resume (apply only unconverged domains), audit artifact per run, README/runbook (incl. new-joiner setup: VPN, creds, one command).

### Stage 7 (next phase)
Splitz adapter (`SegmentAPI/Lookup` → `Update`), gated behind `--confirm-splitz` (shared dev state). Optional: MCP-tool wrapper; optional: deploy agent as a devstack service (Dockerfile → Harbor → devpod template → catalog entry, per devstack service-onboarding-v2 flow).

### Devstack-specific risks
- **Shared base state**: base pods share dev DBs with every other dev — additive-only writes, dedicated scratch MIDs, never bulk operations, no feature REMOVALS (also avoids the admin-workflow engine on deletes).
- **Mode duality (critical)**: entities are mode-specific — the agent MUST export/apply/verify every domain in BOTH live and test (see the mandatory dual-mode section). A single-mode check already caused a missed live UPI terminal. Default `--mode` = `both`; never trust a single-mode count.
- **dcs namespace shows per-label admin-server pods** (`omni2`, `shopeepay`) — confirm which DCS instance is the base/shared one during Stage 0 URL discovery.
- **TTL**: nothing the agent depends on lives under the `harsh` label; if we later co-deploy fixtures, remember 5h TTL.
- flows.yaml hygiene (stray `upi-switch`/`e2e-test-orchestrator` entries) is irrelevant to v1 — only matters if we deploy something; clean up before any future `devstackctl deploy` off the main flows file.

---

## Risks / notes
- Monolith internal routes require the calling app to be registered in the api's internal-app auth config — verify early in Phase 0 which existing app credentials devstack already provisions (may reuse e.g. dashboard/admin creds).
- DCS `kv/entities` pattern semantics (wildcard support per namespace) need one exploratory call in Phase 2 before finalizing the enumeration strategy.
- Terminals dual-write legacy: monolith still has its own terminals model; prefer terminals-service (or monolith proxy routes, which hit the service) as source of truth.
- Pricing deep-copy: rule validator requires `payment_method` per rule except refund/optimizer features — carry rules verbatim minus read-only fields.
