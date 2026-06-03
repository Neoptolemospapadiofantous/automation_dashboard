# Voiceflow Project API (mirror)

Local mirror of the **Project API** reference pages, captured for offline use.

Source index: <https://docs.voiceflow.com/api-reference/project-api/overview>
Base URL for all endpoints below: `https://realtime-api.voiceflow.com`

## Files

| File                         | Method | Path                                                                                 | Purpose                                           |
| ---------------------------- | ------ | ------------------------------------------------------------------------------------ | ------------------------------------------------- |
| `overview.md`                | —      | —                                                                                    | Overview of the Project API surface.              |
| `list-environments.md`       | GET    | `/v1alpha1/project/{projectID}/environments`                                         | List all environments for a project.              |
| `get-environment.md`         | GET    | `/v1alpha1/project/{projectID}/environment/{idOrAlias}`                              | Fetch a single environment.                       |
| `create-environment.md`      | POST   | `/v1alpha1/project/{projectID}/environment`                                          | **Clone** an environment from an existing one.    |
| `delete-environment.md`      | DELETE | `/v1alpha1/project/{projectID}/environment/{environmentID}`                          | Delete an environment (ID only, no alias).        |
| `publish-environment.md`     | POST   | `/v1alpha1/project/{projectID}/environment/{idOrAlias}/publish`                      | Cut a release from the draft version.             |
| `export-environment-json.md` | GET    | `/v1alpha1/project/{projectID}/environment/{alias}/export-json?version=draft|published` | Download the assistant as one large JSON dump.    |
| `get-traffic-split.md`       | GET    | `/v1alpha1/project/{projectID}/environment/traffic`                                  | Read the current traffic distribution.            |
| `update-traffic-split.md`    | PATCH  | `/v1alpha1/project/{projectID}/environment/traffic`                                  | Replace the traffic distribution (must sum 100).  |

All endpoints authenticate with a workspace API key passed in the `authorization` header.

---

## Managed-SaaS-tier verdict (re: programmatic project provisioning)

**Short answer: not possible with the public API as of this capture.** If we want to ship a "managed Voiceflow tier" where signing up a new customer auto-creates a fresh Voiceflow project for them, we cannot do it without talking to Voiceflow sales.

Specifically:

1. **No project-create endpoint exists in the public API.** The Project API only manages *environments inside an existing project*. The `llms.txt` index, the overview page, the OpenAPI spec at `/specs-prettified/temp/project-api.json` (which only lists the legacy `GET /versions/{versionID}/export`), and the discovered `environmentpublicapi` route family all confirm this. There is no `POST /project`, `POST /v1/project`, or similar.

2. **`POST /environment` clones — it does not create from scratch.** The request body has a `cloneFromEnvironmentID` field (optional, defaults to the project's main environment), and the page explicitly states "The endpoint creates environments by cloning existing ones rather than from scratch." So even if we manually pre-create a "template" project, we can only fan out *additional environments* inside that one project — we cannot mint separate, isolated projects per customer.

3. **Auth level is just a workspace API key.** The `authorization` header takes the standard Voiceflow API key — there is no documented enterprise/organization/admin scope that unlocks project-creation. So lack of a higher tier of key isn't what's blocking us; the endpoint simply doesn't exist in the public surface.

4. **No rate limits are documented** for any Project API endpoint. (The Evaluations `queue` endpoint is the only one in the three surfaces we mirrored that documents a 429.)

**Implication for a managed tier:** the realistic options are (a) ask Voiceflow sales whether there's a private/partner API for project provisioning; (b) build on top of a single shared project, partitioning customers by environment + alias and using the traffic-split API — but this still maxes out at one assistant per project, and per-environment isolation is weaker than per-project; or (c) drop the auto-provision goal and have an operator manually create projects in the Voiceflow dashboard before onboarding completes.

---

## Failed / missing pages

None — all 8 endpoint pages plus the overview returned 200. (The slug under `llms.txt` is just `project-api/overview`; the per-endpoint reference pages live under `environmentpublicapi/*`, which had to be discovered from the sidebar of the rendered overview page.)
