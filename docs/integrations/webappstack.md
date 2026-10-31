# App-linked delivery workspace

This fork adds a WebAppStack-managed Fleetbase SERVICE adapter. Its own MySQL
database, organization, users and sessions remain separate from the App API's
PostgreSQL module schemas. Development changes belong on this fork's `dev` branch.

## Source and identity flow

| Owner | Source |
| --- | --- |
| Server extension | `api/app/Integrations/WebAppStack/` |
| Activation, configuration and routes | `api/app/Providers/WebAppStackServiceProvider.php`, `api/config/webappstack.php` |
| MySQL schema | `api/database/migrations/2026_10_30_210000_create_webappstack_bridge_tables.php` |
| Console login/authenticator/transport | `console/app/{routes,controllers,templates}/auth/webappstack*`, `console/app/authenticators/webappstack.js`, `console/app/services/webappstack.js` |
| Local deployment | `compose.webappstack.yml`, `docker/Dockerfile.webappstack-*`, `scripts/webappstack-local.ps1` |

The console starts a fresh browser transaction with a high-entropy HttpOnly flow
cookie. The server stores its verifier encrypted and sends only state, challenge,
transaction and service item ID to the configured App Manager. App API delegates
the authenticated account/session/item to platform App Services; only an explicit
service-owner/delivery grant authorizes a one-time code. Free-text owner labels,
local ADMIN roles and email matches never establish ownership.

The console callback reads and removes its code fragment before any request. The
fork redeems it with the initiating cookie, verifier and server-only instance
credential. Each API request then revalidates the platform session/grant and local
App principal. Unavailable authority fails closed. Organization/user mappings use
stable App/environment/service/principal subjects, with no password sharing or
email-based account linking. Synthetic `@identity.invalid` identifiers are internal
login keys, not verified customer contact addresses; no email is sent to them.

The browser stores only a noncredential `webappstack-cookie` session marker. Native
Sanctum tokens remain encrypted on the server and are injected into requests only
after current authorization. Core session/bootstrap responses are sanitized before
returning to the browser. Integration cookies require the API and console to share
the same hostname and scheme; cross-site deployment is not currently supported.

## Roles, MFA and unavailable surfaces

OWNER receives the organization's Administrator role, never Fleetbase's global
`admin` user type. MANAGER receives a company-scoped Delivery Viewer role with an
explicit order/driver/vehicle/fleet/entity/place/route read-permission allowlist.
Staff writes use the authorized App Manager. Role changes reconcile membership
and clear a former owner's organization owner reference on downgrade. Every
request still requires the current explicit platform grant.

An enabled authenticator factor uses pinned Fleetbase TwoFactorAuth challenge and
verification methods. No full session exists until verification and a fresh
authority check succeed. Required but unenrolled MFA, or an unconfigured verified
email/SMS factor, blocks entry instead of bypassing policy. Native password login,
organization switching, global administration, IAM writes and API-token issuance
are unavailable through the bridge. Realtime/socket access is denied and no socket
port is published until continuous revocation can be enforced there.

## Table and column ownership

The extension owns every column in these MySQL tables; the migration is the exact
column/type source. Core `companies`, `users`, `company_users`, roles and Sanctum
tokens remain Fleetbase-owned and are referenced by the adapter.

| Table | Columns |
| --- | --- |
| `webappstack_workspaces` | `scope_key`, `organization_key`, `company_uuid`, `created_at`, `updated_at` |
| `webappstack_identities` | `identity_key`, `scope_key`, `principal_kind`, `subject_id`, `user_uuid`, `access_role`, `created_at`, `updated_at` |
| `webappstack_login_transactions` | `id`, `cookie_hash`, `state`, `challenge`, `verifier_ciphertext`, `expires_at`, `consumed_at`, `created_at` |
| `webappstack_sessions` | `cookie_hash`, `platform_session_id`, `identity_key`, `user_uuid`, `status`, `token_ciphertext`, `personal_token_id`, `two_fa_ciphertext`, `expires_at`, `revoked_at`, `created_at` |
| `webappstack_audit` | `id`, `scope_key`, `subject_id`, `action`, `created_at` |

Disabling/revoking access retains organizations and delivery history. Session
expiry and platform revalidation deny continued API use; migration rollback never
drops identity history. Fulfilment's PostgreSQL tables hold only its integration
binding, provider delivery reference and receipt/state data.

## Local operation and recovery

Use PowerShell 7 and a private environment file under `build/.state/webappstack/`.
Never commit it or run a Compose config command that prints interpolated secrets.
Required public fields are App/environment/service UUIDs, platform API/Manager/API/
console origins, API/console loopback ports, exact fork revision, image references
and console config path. The public console JSON contains only `API_HOST` and
`API_NAMESPACE: int/v1`. The API/console hostname and scheme must match.

Required private fields are generated `APP_KEY`, `DB_PASSWORD`,
`MYSQL_ROOT_PASSWORD`, `REDIS_PASSWORD` and `WEBAPPSTACK_INSTANCE_CREDENTIAL`.
Use at least 32 random bytes for each password/instance credential; APP_KEY uses
Laravel's base64 format. `DB_DATABASE` must be `was_fleetbase_` followed by the
first 24 hex characters of SHA-256 of `App:environment:service` UUIDs joined by `:`.

Build the API from the locked fork base and both affected images with the same
committed fork revision label. Application, queue and scheduler use that one API
image. `webappstack:prepare` migrates only the exact isolated database and initializes
missing permission definitions; it never creates an insecure default administrator.

```powershell
./scripts/webappstack-local.ps1 -Action Start -EnvFile /absolute/private/service.env
./scripts/webappstack-local.ps1 -Action Status -EnvFile /absolute/private/service.env
./scripts/webappstack-local.ps1 -Action Stop -EnvFile /absolute/private/service.env
```

All containers explicitly use `restart: "no"`; all published ports bind loopback.
Stop retains volumes and documents. Start verifies immutable image labels, exact
scope, nonce/HMAC readiness, extension migrations and a real same-revision queue
probe. Its HMAC signs nonce, App/environment/service, extension version, fork
revision, migrations/authorization/queue flags and disabled socket state. The
receipt under `build/.state/webappstack/<service>/receipt.json` records
owned containers, images and origins without secret values. Retain the private
environment file, image versions and volumes for recovery; never substitute a
different App's database or delete volumes to repair startup.

## Verification status

Source review on 30 October 2026 corrected item-bound redirect parameters,
least-privilege staff access, same-site cookie policy and MFA revocation races.
Source clearance does not establish working SSO. Record focused PHP/console,
MySQL migration and real browser/platform handoff evidence here before claiming
owner login, MFA, two-App isolation or revocation verified.

Compatibility sources: core-api 1.6.74 at `ac97b5620b260660ae4e6fd5ea0e64025629b0c9`
and FleetOps 0.6.75 at `811aa711a68833dfd8390d8ddac582d4ed75f3c6`, pinned by
`api/composer.lock`. The owned console router source is `console/router.map.js`;
the extension build regenerates `console/app/router.js`.

## Durable local first startup

MySQL uses a five-minute health start period before its existing bounded failure
checks. The standalone launcher waits at most ten minutes for database/cache
readiness. This accommodates the observed persistent-volume initialization without
disabling fsync or automatic restart protection. App, worker and signed readiness
checks keep their separate bounds.

The platform managed adapter journals detached named migration one-offs and retains
their exit evidence. Schema observation timeouts preserve ongoing DDL and require
reattaching the same immutable target; do not stop an active schema migration or
reset its retained database. The original legacy recovery run retained its
original image/commit independently of subsequent fork dev commits.

The cold-start source change was introduced in dev commit
f18213372ed799a370ad7efac2620eae021dfbd6. The original retained WebAppStack
review was pinned to310688b9c1fe6a8f104cb478500acdb920771d8c; advancing
dev does not mutate its detached source, immutable image or prepared configuration.
Adopting newer source requires a fresh prepared deployment after recovery.

New managed plans freeze the server-selected migration observation budget
(APP_LOCAL_FLEETBASE_MIGRATION_WAIT_MINUTES, integer1..360, default360minutes).
Legacy prepared specs retain their45-minute budget. These limits stop observation,
never active DDL, and make no completion-time promise. The platform reports fixed
database/schema/runtime/readiness stages without percentages or ETA.

## PHP-safe browser cookies and observed handoff

Bridge cookie names use `fleetbase_was_v1_<scope-hash>_<purpose>`. The hash includes
App, environment and service identity; flow and session purposes remain distinct.
The fixed version prefix also avoids collisions with PHP's normalized aliases of
older dotted names. Cookie values remain opaque, HttpOnly and same-site; all PKCE,
flow expiry/consumption, session authority and MFA checks remain enforced.

On 31 October 2026, real browser consent reached the callback but returned the
missing-browser-flow response. The correctly scoped 64-character cookie existed
in the browser. An actual PHP HTTP fixture proved that dotted names were parsed
with underscores, so the server's dotted-name lookup could not find the cookie.
Programmatically constructed Request cookie arrays had bypassed this parsing step.
The PHP-safe fix is code revision `2c4ebc7547137827b33113444f56351b75f1f0cf`.
Focused scope/purpose/parser checks and a real HTTP mixed-old/new-cookie fixture
passed for both flow and session names. Do not attribute this failure to platform
201 responses: the client already accepts successful 2xx responses.

Managed deployment at diagnostic revision
`4593485b02c87680dba87f6d38b40a6e1ec14028` reached READY with signed queue/revision
proof and retained native data. At 04:30 SAST the new immutable `2c4ebc7` deployment
was being prepared; real browser login on that cookie fix was still pending.
A pushed code revision or this documentation does not prove its live activation.

At 04:42 SAST, the cookie-fix code pin `2c4ebc7547137827b33113444f56351b75f1f0cf`
was READY through the real platform managed deployment path with retained data
and signed queue/revision proof. A fresh real owner browser flow then passed:
completion, bridge session and `/int/v1/users/me` returned HTTP200, and the console
displayed the App delivery workspace and demo administrator without a second
password. This establishes the real Deploy-to-owner-workspace path. Keep focused
native MFA/replay/isolation fixture proof separate from this owner browser result;
additional real revocation or role tests require their own recorded evidence.
Subsequent documentation-only dev commits do not change the active `2c4ebc7` pin.
