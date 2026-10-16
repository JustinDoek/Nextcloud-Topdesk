# TOPdesk API — research notes (2026-10-13)

Source: https://developers.topdesk.com/ — OpenAPI specs are downloadable, which is the
fastest way to get authoritative detail (the HTML explorer is JS-rendered and unfetchable):

| API | Spec URL |
|---|---|
| Incident 4.2.10 | `https://developers.topdesk.com/swagger/incident_specification_4.2.10.yaml` |
| General 1.2.0 | `https://developers.topdesk.com/swagger/general_specification_1.2.0.yaml` |
| Supporting Files 2.7.12 | `https://developers.topdesk.com/swagger/supporting-files_specification_2.7.12.yaml` |
| OAuth (SaaS) | `https://developers.topdesk.com/swagger/oauth_SaaS.yaml` |

Base URL: `https://<customer>.topdesk.net/tas/api`

## The split that drives the whole design

The Incident API has **two separate endpoint families**, and the spec is explicit about
which principal may use which:

| Endpoint | Operator | Person (SSP user) |
|---|---|---|
| `GET /incidents` | yes (read perm on 1st/2nd line; category/branch/operator filters apply) | **no access** |
| `POST /incidents` | yes, any caller via `callerLookup` | yes, first line for **themself** only |
| `GET /requester/incidents` | **no access** | yes — "Person visibility settings apply" |
| `POST /requester/incidents` | no access | yes, caller filled in automatically |
| `GET /persons` | yes (read perm on persons; branch filters apply) | **no access** |

So "show the user his own tickets" has exactly two shapes:

1. **Operator service account** → `GET /incidents?query=caller.id==<uuid>` — we must resolve
   the person UUID ourselves and we must never forget the filter.
2. **Per-user credential** → `GET /requester/incidents` — TOPdesk enforces "own tickets"
   server-side; no matching logic at all.

## Authentication

Three options, all confirmed in the specs/tutorial:

1. **Application token** (a.k.a. application password) + HTTP Basic.
   `Authorization: Basic base64(<loginName>:<appToken>)`.
   Works on TOPdesk 7.11.005+. Shown once at creation, default expiry 1 year.
   Permission group needs: read `API access > REST API`, write `API access > Use application tokens`.
   Created either on the operator's own card (My settings) or on an API account card
   (that path additionally needs write `Supporting Files > Login data` on the admin,
   and read `Supporting Files > Operator` to see API accounts).

2. **OAuth 2.0 client credentials** — "Application integration" connected application.
   Unattended, fixed permission set. No refresh token.

3. **OAuth 2.0 authorization code** — "User-delegated integration" connected application.
   Acts as a specific TOPdesk user, limited to that user's own permissions, requires the
   user's consent. **access_token 900 s, refresh_token 60 days.**

OAuth endpoints (not under `/tas/api`, they sit on the host root):
- `GET /.well-known/oauth-authorization-server` — RFC 8414 metadata; returns
  `authorization_endpoint`, `token_endpoint`, `grant_types_supported`, `scopes_supported`.
  Discover rather than hardcode: scope names are per-instance.
- `GET /services/oauth/authorize?response_type=code&client_id=&redirect_uri=&state=&scope=`
- `POST /oauth/token` (`application/x-www-form-urlencoded`), client auth via Basic or body.

Clients are registered in TOPdesk at
Modules > Supporting Files > System integrations overview > Connected applications.
Needs the operator permission `Manage connected applications > Manage integration clients`.
Secret is one-time — lost means delete and recreate.

## Listing incidents

`GET /tas/api/incidents`
- `pageStart` (default 0), `pageSize` (default **10**, max 10000)
- `sort=creationDate:desc` — fast sort fields: `callDate, creationDate, modificationDate,
  targetDate, closedDate, id`. `caller`, `priority`, `category`, `request` etc. are **not
  sortable**.
- `query=<FIQL>`
- `fields=a,b,c` — comma separated; omitting it returns all fields and is documented as slow
- `dateFormat=iso8601` — otherwise you get `2020-10-01T14:10:00.000+0000`
- `all=true` — includes partials and archived; default returns only `firstLine` + `secondLine`

Status codes: `200` complete list, `204` nothing matched (**empty body — not an empty array**),
`206` more pages available with an `x-next-page-url` header.

## FIQL

Usable in the query string without URL-escaping. "All fields from the returned resource
should be available, unless otherwise specified by the endpoint documentation."

- `==` equals, `!=` not equal, `==null` / `!=null`
- `=gt= =ge= =lt= =le=` numeric + date only
- `=sw=` starts-with, text/memo only
- `=in=(a,b)` / `=out=(a,b)`
- `;` = AND, `,` = OR, brackets group, AND binds tighter
- Quote with `'` or `"` when the value holds whitespace or a reserved char: `' " ( ) [ ] ; , = ! ~ > <`
- Dates ISO 8601: `yyyy-MM-ddTHH:mm:ssZ`

So: `?query=caller.id==<uuid>&sort=creationDate:desc&pageSize=5&dateFormat=iso8601`
`caller.email==...` should also resolve, but `caller.id` is the indexed path — prefer it.

## Creating an incident

`POST /tas/api/incidents` (operator):
```json
{
  "callerLookup": { "email": "user@example.com" },
  "briefDescription": "Coffee machine is not working",
  "request": "<p>longer text, limited HTML</p>",
  "status": "firstLine",
  "category":    { "name": "Hardware" },
  "subcategory": { "name": "Printer" }
}
```
- `callerLookup` takes **either** `{"id": "<uuid>"}` **or** `{"email": "..."}` — exactly one;
  an empty object is an error. **This means create needs no person lookup of our own.**
- `caller` (the `CallerCreate` object) still exists but using it for a *registered* caller is
  deprecated — it is the unregistered/free-text path. Use `callerLookup`.
- `briefDescription` max **80 chars** — validate client-side, a 400 otherwise.
- `request` accepts only `<i> <em> <b> <strong> <u> <br> <h5> <h6> <img>` (base64, max 450x450).
- Only persons with the **Caller** checkbox set on their card can be used as callers.
- `status` defaults to `firstLine`. `category`/`subcategory`/`callType` take `{id}` or `{name}`,
  never both. A subcategory without a category auto-fills the category unless ambiguous.

`POST /tas/api/requester/incidents` (person): caller is implicit. Settable fields are limited
to `request, briefDescription, callType, category, subcategory, object, location, operatorGroup`,
and only those the "new call form" setting enables.

## Resolving a person

`GET /tas/api/persons?query=email=='<email>';archived==false&pageSize=2&fields=id,dynamicName,email`

- v2 requires `Accept: application/x.topdesk-collection-person-v2+json`, and v2 returns a
  limited default field set unless `fields` is given.
- `pageSize` default 50, max 5000. Without a `query` you get archived persons too.
- Candidate match fields on the person card:
  `email`, `networkLoginName` (max 20 — the AD/network login), `employeeNumber` (max 20),
  `tasLoginName` (the TOPdesk login; reading it needs `Settings > Login Settings` permission),
  `id` (uuid).
- `isCaller` filter exists on `/persons/lookup`; the `Caller` checkbox is what makes a person
  usable as an incident caller.
- `GET /tas/api/persons/current` — who am I, for a person-authenticated call.
- `GET /tas/api/incidents/callers/lookup?name=<prefix>` — operator-side caller picker,
  returns only persons marked as callers.

## Connection test

`GET /tas/api/version` → `{"version": "..."}`, `GET /tas/api/productVersion` → major/minor/patch.
Cheap authenticated round-trip for an admin "Test connection" button.
