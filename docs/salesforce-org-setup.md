# Salesforce org setup (Client Credentials)

The Salesforce-side work needed before `csatf/laravel-salesforce-connector` can
connect. Follow it top to bottom.

We use the **OAuth 2.0 Client Credentials** flow: no certificate (nothing to
renew) and purely server-to-server (no callback, so it works from any environment
without exposing the app).

> Trade-off to accept: Client Credentials runs every call as **one integration
> user**, so there is no per-end-user attribution. That is the right model for
> app↔Salesforce data access.

Most first-time failures are org configuration, not application code. If
something fails, check [Gotchas](#gotchas) before debugging the app.

---

## Part 1 — Create the connected app

Creation is under **App Manager**, not "Manage Connected Apps".

1. Setup → **App Manager** → **New Connected App** (or, in newer orgs, **External
   Client Apps Manager → New External Client App** — both work).
2. **Enable OAuth.** Callback URL: a placeholder is fine, Client Credentials
   doesn't use it.
3. **OAuth scope:** add **Manage user data via APIs (api)**.
4. **Enable Client Credentials Flow** (ECA: Policies tab → "OAuth Flows and
   External Client App Enhancements"; Connected App: OAuth settings).
5. **Save**, then **wait 2–10 minutes** to propagate.

## Part 2 — Create the integration user

Setup → **Users → New User**.

- **User License:** `Salesforce Integration`
- **Profile:** `Salesforce API Only System Integrations`

## Part 3 — Set the Run As user

App → **Manage / Edit Policies** → Client Credentials Flow → **Run As** = the
integration user from Part 2.

## Part 4 — Assign the Permission Set License **first**

Setup → the user → **Permission Set License Assignments** → enable
**`Salesforce API Integration`**.

Do this **before** assigning any permission set. Skipping the order causes
`The user license doesn't allow the permission: Read/View All <Object>` when you
later assign one. It is a sequencing problem, not a platform limit.

## Part 5 — Grant access (permission set + FLS)

Create permission set(s), assign them to the integration user, and grant **Object
Read + field-level (FLS) Read** on exactly the objects and fields the app queries.
Least privilege.

- A **license-bound** permission set hides some objects (e.g. standard objects
  like `Contact`). Put those on a **`License = None`** permission set instead.
  Both get assigned to the user.
- To traverse a relationship in SOQL (`A__r.B__c`) you need Read on the **lookup
  field** too, not just the target object.
- **`describe` and `FIELDS(ALL)` are FLS-filtered for this user** — they hide
  fields you haven't granted, so you cannot use them to discover what to grant.
  Get field API names from **Object Manager** as an admin, then grant Read.
- **Record visibility** is separate: Object Read exposes the object, but seeing
  records the user doesn't own depends on org **sharing / OWD**. The Integration
  license cannot grant `View All` on standard objects.

## Part 6 — Collect the credentials

- **Consumer Key / Secret:** Connected App → **Manage Consumer Details**; ECA →
  **Settings → OAuth Settings**.
- **My Domain URL:** Setup → My Domain → *Current My Domain URL*.

Set them in the app's environment:

```dotenv
SF_AUTH_METHOD=ClientCredentials
SF_CONSUMER_KEY=
SF_CONSUMER_SECRET=
SF_LOGIN_URL=https://your-org.my.salesforce.com
SF_API_VERSION=62.0
SF_TOKEN_STORAGE=cache
SF_VERIFY_SSL=true
```

After editing env on a deployed host, redeploy or `php artisan config:clear` so
the values take effect — especially if the app caches config.

---

## Part 7 — Validate, in this order

```sh
# 1. Auth works (needs no object permissions):
php artisan tinker --execute="Forrest::authenticate(); echo json_encode(Forrest::versions());"
#    A JSON list of API versions = success.

# 2. A real read (needs the FLS from Part 5):
php artisan tinker --execute="Forrest::authenticate(); echo json_encode(Forrest::query('SELECT Id FROM Contact LIMIT 1'));"
```

Or, using the package, both at once:

```sh
php artisan tinker --execute="dump(app(\Csatf\LaravelSalesforceConnector\Health\SalesforceHealth::class)->check());"
```

Do **not** validate with `Forrest::limits()` — see the gotchas table.

---

## Gotchas

Each row here cost real time on the first integration.

| Symptom | Cause | Fix |
|---|---|---|
| `invalid_grant: request not supported on this domain` | `SF_LOGIN_URL` is `login.salesforce.com` / `test.salesforce.com` | Use the org's **My Domain** URL |
| `invalid_client_id: client identifier invalid` | Wrong or empty key, or wrong domain | Set `SF_CONSUMER_KEY`/`SECRET`; confirm My Domain |
| `SalesforceException null` on a resource call | `SF_API_VERSION` is blank | Pin it, then `config:clear` + `cache:clear` |
| `403 API_DISABLED_FOR_ORG` on `Forrest::limits()` | `/limits` is unavailable to the minimal license | Use `versions()` or a real query as a health check |
| `…license doesn't allow the permission: Read/View All <Object>` | Permission Set License not assigned first | Assign **Salesforce API Integration** PSL, *then* the permission set |
| `INVALID_FIELD: No such column 'X'` | FLS not granted — `describe` hides it | Grant Read FLS; get the API name from Object Manager |
| Token errors mid-run | Cached token expired or flushed | The package re-authenticates and retries once automatically |
| Query returns far fewer rows than expected | Response capped at 2000 with `done: false` | Use `SoqlClient::fetchAll()`, which follows `nextRecordsUrl` |
| `OFFSET` errors on deep pages | SOQL rejects OFFSET above 2000 | `SoqlQuery::offset()` clamps; check `exceedsOffsetCeiling()` |
| A filter appears to match everything | Unescaped `%` in a `LIKE` value | Use `Soql::escapeLike()` or `FilterCompiler` |

---

## Environment and rollout notes

- **One org per environment.** Each app environment (local / UAT / production)
  points its `SF_*` vars at its own Salesforce org. `SF_LOGIN_URL` is always that
  org's My Domain URL.
- **No certificate to rotate** — the reason for Client Credentials over JWT.
- The consumer secret is a credential: it belongs in the environment, never in
  the repository.
- If the app caches HTTP responses, they can outlive a deploy. Flush relevant
  caches on deploy so code changes aren't masked by stale cached data.
