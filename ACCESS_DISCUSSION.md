# GK Application Permissions — Discussion

Notes on how permissions currently work in the `gk_application_respond` module and how to handle them. Based on a review of the codebase (controllers, ECK access handler, field_permissions config, role configs).

---

## Part 1 — How permissions actually gate today (3 independent layers)

**1. Route gate — `ApplicationRespondController::checkAccess()`** (`web/modules/custom/gk_application_respond/src/Controller/ApplicationRespondController.php:62`)
The only working gate. `respond()` throws 403 unless the user:
- is admin (`administer nodes` / `bypass node access`), or
- has `manage gk applications` (**not defined anywhere**) → dead branch, or
- is listed in `field_app_auditor_user` on the application, or
- has `view gk applications jury` (**not defined anywhere**) → dead branch, or
- is listed in `field_est_admin_user` on the linked establishment.

**2. Entity gate — ECK's `EckEntityAccessControlHandler`** (`web/modules/contrib/eck/src/EckEntityAccessControlHandler.php:75`)
`$entity->access('update')` in `CriterionResponseFormController.php:101` requires `edit own/any criterion_response_ent entities`. All responses are authored by whoever ran the setup batch (`uid` = programme admin), so "own" maps to the *batch creator*, not the domain owner.

**3. Field gate — field_permissions**
Only these fields are `custom` mode: `field_res_compliance_status`, `field_res_note_auditor`, `field_res_note_jury`, `field_res_note_natop`, `field_res_criterion_appl`, `field_res_criterion_active`. All other editable fields (`field_res_answer`, uploads, `field_res_note_establishment`) are **public** — anyone with entity-edit access gets them.

## Why it's confusing (it's actually broken)

- The two permission strings in `checkAccess()` (`manage gk applications`, `view gk applications jury`) exist **nowhere** — no `.permissions.yml`, no role config. `hasPermission()` returns `FALSE` for undefined permissions, so green_key and jury members can never pass that branch.
- `est_admin`, `auditor`, `certification_body` have **zero direct permissions**; they only inherit `authenticated` (`edit own criterion_response_ent entities`, view-only field perms). Since "own" = setup batch author, only that person (plus admins via `bypass eck entity access`) can actually edit anything. The respond page is effectively unusable by every non-admin role.
- The "own" semantics in both ECK and field_permissions are **author (`uid`)-based**, but the domain access is **relationship-based** (est_admin ↔ establishment, auditor ↔ application). These never align because the responses' `uid` is always the setup runner.

## How it should be handled

Split responsibility into two clear rules:

**Access (can you reach/open this application's forms) = relationship-based, one shared service.**
- Create a small `GkApplicationAccess` service that computes view/edit per application from the application's own fields, keyed off defined permissions. Fix the two undefined permissions by defining real ones in `gk_application_respond.permissions.yml` (e.g. `manage gk applications`, `view gk applications` read-only for jury).
- Use the same service in both routes. Critically, `gk_application_respond.criterion_form` currently only checks `_permission: access content` — it must call the same access check, otherwise the AJAX form route is open to any authenticated user.

**Editing (which fields can a role change) = field_permissions, granted at role level, not author-level.**
- field_permissions' custom mode supports `edit field_X` (any) — nobody has those today, only the useless `edit own` variants. Grant each role the "any" permissions for the fields they own:
  - `est_admin`: `field_res_answer`, uploads, performance data, `field_res_note_establishment`
  - `green_key`: `field_res_note_natop` (+ `field_res_criterion_appl` for applicability changes)
  - `auditor`: `field_res_compliance_status`, `field_res_note_auditor`
  - `certification_body`: `field_res_note_jury`
- Move the currently-public fields (answer/uploads/notes) into `custom` mode too, otherwise once entity edit is granted, everyone (e.g. auditors) could also overwrite the est_admin's answers.
- Stop relying on `$entity->access('update')` in `CriterionResponseFormController` as the edit gate — let the route's access service + field_permissions be the gates (the read-only fallback at `CriterionResponseFormController.php:104` can stay for jury).

This is the standard, config-driven pattern and needs no custom `hook_entity_access` logic. All changes are: 1 permissions.yml + 1 small service + route access refactor + role/field config exports.

---

## Part 2 — The three access approaches compared

### The core problem recap

Today the access decision is split across three places that disagree:
- `ApplicationRespondController::checkAccess()` — application-level, relationship-based (works in intent, but uses two undefined permissions)
- `$entity->access('update')` in `CriterionResponseFormController.php:101` — entity-level, author-based (`uid` = batch creator), blocks everyone
- field_permissions — per-field, but "own" variants only granted, so also author-based

The question is: **where should the single authoritative "may this user edit this response" decision live?**

### Option 1 — Shared access service (recommended)

Create one service, e.g. `GkApplicationAccess`, with methods like:
```
canView(application, account) -> bool
canEditField(application, account, field_name) -> bool   // or rely on field_permissions
```
Both routes call it. `respond()` uses it instead of the inline `checkAccess()`. The AJAX form route (`criterion_form`) uses it too, so the same logic gates page and form.

**How it works:** You pass the *application node* (already upcast on both routes), read `field_est_admin_user` / `field_app_auditor_user`, check defined permissions (`manage gk applications`, `view gk applications` for jury), and return a boolean. The `criterion_response` entity's own author/uid never matters.

**Pros:**
- Single source of truth; page + AJAX form can't disagree
- The relationship logic already exists in `checkAccess()` — you mostly *move* it, not rewrite it
- No Drupal internals fighting you; plain dependency injection, easy to unit test
- Field-level separation stays 100% in field_permissions (config-driven), which the form already relies on

**Cons:**
- You must remember to call it on *every* future route that exposes response data (someone could add a new route and forget)
- The `$entity->access('update')` branch becomes redundant — you either bypass it or must keep both in sync
- The security contract lives in custom code, not in Drupal's permission system

### Option 2 — Keep controller `checkAccess()`, just fix it

Minimal change: define the missing permissions (`manage gk applications`, `view gk applications jury`) in a `.permissions.yml`, and add the same `checkAccess()` call at the top of `CriterionResponseFormController::loadForm()`.

**How it works:** You keep the existing inline method, but make the two routes share it (probably by extracting it into a trait or static call). Same logic as today, just *defined* and *applied to both entry points*.

**Pros:**
- Smallest diff; lowest risk
- No new abstractions

**Cons:**
- Access logic stays buried in a controller, hard to reuse from views/blocks/other modules later
- Same duplication risk as Option 1 (each new route must remember to call it), but without the benefit of a testable service
- The author-vs-relationship confusion in the entity gate is still there unless you also drop the `$entity->access('update')` check

### Option 3 — Custom entity access handler for `criterion_response_ent`

Register a replacement for `EckEntityAccessControlHandler` via `hook_entity_type_alter()` in `gk_application_respond.module` (the entity type is already altered there for the form class). Your handler's `checkAccess()` would resolve "may edit" by walking the relationship: `response → field_res_application → field_app_establishment → field_est_admin_user`, or `field_app_auditor_user`, based on the user.

**How it works:** Drupal's entity access system becomes the gate — `$entity->access('update')` (the check already at `CriterionResponseFormController.php:101`) now returns TRUE for the right people, and field_permissions continues to do field-level separation. Any other consumer (Views, ECK admin UI, REST) automatically gets the correct answer.

**Pros:**
- Most "correct" Drupal way: entity access is enforced everywhere automatically, not just on your two routes
- Solves the author/ownership mismatch at the root — `access('update')` means what you *want* it to mean
- No redundant gates; the existing `$entity->access('update')` check becomes meaningful rather than always-false

**Cons:**
- Most complex to implement and to keep correct (revision handling, `createAccess`, caching with `AccessResult`, bundle checks)
- ECK's handler also handles revision/revert permissions you'd need to replicate
- Risk: you might *weaken* access for the ECK admin UI if you make `access()` more permissive than the current handler — that's exactly what you want for the respond page, but you must be deliberate about it
- Harder to reason about than a service (entity access runs implicitly all over the system)

### Practical overlap

All three require the **same field_permissions work** (grant `edit field_X` "any" permissions per role, move public fields to custom mode). The choice is purely *where the "can edit" decision for the response entity lives*.

The recommendation remains **Option 1** (or Option 2 if you want minimal risk now): it directly mirrors the existing `checkAccess()` intent, is testable, and you can later layer Option 3 on top if you find other code paths (views, REST) need the same rule.

---

## Part 3 — Suggested plan ahead (Option 3)

Two later requirements settled the choice on **Option 3** (custom entity access handler):

1. **Post-submission restrict/permit toggle** for the establishment owner — a *state-dependent edit restriction* that must hold everywhere (respond page, Views, any future route), not just on the two custom routes.
2. **Custom Views presenting response data** — Views do not call a controller access service; they enforce Drupal's *entity access handler* on the base entity. Only an entity-level handler makes views correct without per-view access hacks.

(Views are consumed by **internal roles only** — programme admin, auditor, certification body — who already have `view any criterion_response_ent entities`. So the handler only needs relationship + state logic for `edit`; `view` stays on existing permissions and views are gated by a permission-based access plugin.)

### Workflow/state note

Application state is handled by core content moderation via the `application_review` workflow. The state lives in the built-in `moderation_state` base field on the `application_container` node — **no custom `field_app_state` field** (that PROJECT.md row is stale). The handler reads `$application->get('moderation_state')->value` to enforce the est_admin draft-only rule.

### Steps

1. **Define real permissions** (`gk_application_respond.permissions.yml`)
   The code already references `manage gk applications` and `view gk applications jury`, but neither is defined anywhere — `hasPermission()` always returns FALSE for them. Define them here (`manage gk applications` for green_key; `view gk applications` read-only for jury/certification body) and grant them in the role configs.

2. **Custom entity access handler** — the core of the plan
   New class `GkCriterionResponseAccessControlHandler extends EckEntityAccessControlHandler`, registered via `setAccessClass()` in `hook_entity_type_alter()` (ordering is already safe — the existing form-class override there proves `gk_application_respond` runs after `eck`). Override `checkAccess()`:
   - **bypass**: `bypass eck entity access` / `administer nodes` / `bypass node access` → allow
   - **view**: delegate to parent (authenticated already has `view any criterion_response_ent entities`; views are gated separately)
   - **edit/update**: walk `field_res_application`, then per role:
     - `manage gk applications` → allow
     - in `field_app_auditor_user` → allow
     - in establishment's `field_est_admin_user` → **allow only when `moderation_state` == `draft`** (the restrict/permit toggle; the existing read-only fallback in `CriterionResponseFormController.php:104` renders automatically after submission)
     - else deny
   - **revision ops** (`view revision`, `revert`, `delete revision`): delegate to parent to preserve ECK admin behavior
   - **cacheability**: `->cachePerPermissions()` + `->cachePerUser()` + `->addCacheableDependency($application)` — mandatory, or the draft/submitted answer gets cached wrong

3. **Harden the AJAX route** (defense in depth)
   `gk_application_respond.criterion_form` currently has only `_permission: access content`. Add `_custom_access` running the same application-level check as the respond page, so the form endpoint can't be hit for applications the user can't open.

4. **field_permissions — finish the field separation**
   - Move currently-public editable fields to `permission_type: custom`: `field_res_answer`, `field_res_uploads_files`, `field_res_uploads_photos`, `field_res_note_establishment` (+ `field_res_performance_data` when it's added)
   - Grant the **non-"own"** ("any") variants per role:
     - est_admin: create/edit/view on answer, uploads, performance, note_establishment
     - auditor: edit `field_res_compliance_status`, `field_res_note_auditor`
     - green_key: edit `field_res_note_natop` (+ `field_res_criterion_appl` if they control applicability)
     - certification_body: edit `field_res_note_jury`
     - all internal roles + jury: **view** on all fields (needed so views and the read-only view render fully)
   - Existing custom fields keep custom mode; swap the useless `edit own …` grants for the "any" ones

5. **Views**
   - Access plugin: permission-based (`manage gk applications` / `view gk applications`) → internal roles only
   - Field visibility flows automatically from field_permissions
   - Optional: scope auditor/programme views by application relationship

6. **Controller cleanup**
   Fix `ApplicationRespondController::checkAccess()` to use the now-defined permissions (its relationship logic stays — it gates the page shell); optionally extract to a shared service reused by the AJAX route's `_custom_access`.

7. **Export** all role + field config changes to `config/sync`.

### What this buys you
- est_admin post-submission lockout enforced by the entity handler → true everywhere
- Views correct with zero per-view access hacks
- `$entity->access('update')` in the form controller becomes meaningful (not always-false)
- field_permissions handles *which fields*; the handler handles *which applications* + *which state*

### Cost / risk
- Handler must correctly handle revision ops + cacheable metadata (the tricky parts)
- Relationship-walking per entity is N+1 in large listings — fine at 139 criteria/application, but worth a query-alter optimization if thousands of rows are listed later
- Who may move states between draft/submitted/etc. is enforced separately by content moderation transition permissions (`use application_review transition <name>`), complementing the handler's draft-only field-edit rule