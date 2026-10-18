# Install guarded authoring and history

This is a prepared server-side integration, **not yet installed or live-verified**. No production writes were made during its development. Cockpit's standard content GET endpoints exclude drafts; this dedicated authenticated endpoint reads them without exposing them publicly.

## Files to install

Copy these into the **active Cockpit installation** using your usual deployment process; do not modify core files:

```text
Repository                                              Cockpit installation
-------------------------------------------------------------------------
docs/cockpit/addons/PortfolioAuthoring/bootstrap.php      addons/PortfolioAuthoring/bootstrap.php
docs/cockpit/api/portfolio/authoring.post.php              config/api/portfolio/authoring.post.php
```

Use your actual configured addons/config paths if customized. Install both before use. Reload Cockpit/PHP if OPcache retains old files. Keep the existing `portfolio/upload` route installed for images.

Requirements: PHP 8.1+ with JSON, random_bytes and flock; writable storage; a projects **collection** model; Cockpit Content save hooks and dataStorage interfaces. Interfaces were checked against upstream source, not yet this installed version. No local PHP executable was available: JS tests and PHP syntax parsing were performed, while runtime tests are pending.

Grant the dedicated API role `content/projects/read` and `content/projects/update`; creation also needs `content/projects/create`, publication needs `content/projects/publish`. Asset uploads separately need `assets/upload`. Never give the public role authoring/update permissions. Cockpit validates the token before file dispatch; the route additionally requires a non-public header token and rejects query/body API-key overrides.

## How the safety mechanism works

- `get` returns the complete stored item plus SHA-256 revision over canonicalized full record data. Same-second edits are detected; this does not rely on timestamp resolution.
- Updates accept only explicit field groups and an expected revision. They do not submit the old snapshot's other fields.
- The addon acquires a shared project-write lock at `content.item.save.before.projects`, after Cockpit's initial read and before storage save. It rereads the stored record, compares the expected revision and rebases only the allowed patch onto that record. Editor saves through Content::saveItem use this same lock, so a UI commit occurring before the agent's guarded write is detected.
- A full pre-change snapshot is stored in the private dataStorage collection `portfolio/authoring-history` before each allowed save. A history-storage failure aborts the save. Successful saves mark the entry committed; interrupted attempts remain identifiable.
- The lock is released after the write or on request shutdown. No draft/history data is written as a publicly served JSON file. The route's responses use Cache-Control no-store.
- Publishing is its own permission-checked, revision-guarded action. Other edits retain `_state`.

### Boundaries

- **Stale Cockpit browser forms are not revision-aware.** Reload the form before saving after agent work; a server addon cannot infer which fields the user intended to change in a stale full-form submission. Adding browser-side revision tokens is a separate change. Do not claim full two-way editor conflict prevention.
- This locking scheme targets this single-host homelab. All PHP workers must use the same storage/lock filesystem. Multiple hosts, writes directly to the database, asset-reference background jobs, model migrations or other paths bypassing Content::saveItem are outside its concurrency contract. Do not edit content simultaneously through bypass paths.
- This is tailored to the existing projects schema, not a general computed-field/plugin framework. Validate additional Content save hooks/computed fields before adopting them: the guarded patch rebase can interact with computed-field transformations.
- Advisory file locks are not a database transaction covering the history and content write. A crash can leave an `attempt` history entry, or a successful write with an error response. Always inspect current state/history before retrying an ambiguous result.
- Uploaded files are still public; private draft items do not imply private assets.
- History grows without automatic pruning; include it in storage backups and monitor size. Never expose the history collection through another generic public API.
- Old full-record JSON files and save receipts remain for traceability, not as authoritative editable copies. The CLI refuses `save` and `find`; no unsafe fallback is implemented.

## Verification after installation

First, on a machine with PHP, from a checkout:

```sh
php -l docs/cockpit/addons/PortfolioAuthoring/bootstrap.php
php -l docs/cockpit/api/portfolio/authoring.post.php
php tests/cockpit-authoring-hooks.php
```

The isolated fixture tests editor/agent history, stale-revision rejection, preservation of human text/publication during media changes, revision stability and refusal when history storage fails. It does not connect to production.

Then perform bounded API checks:

1. No key, invalid key and a public/read-only role must not read drafts/history or write anything.
2. `npm run cockpit -- check` should succeed with the dedicated role.
3. `get` an existing draft and confirm its text matches the editor, without modifying it.
4. On a disposable draft, fetch snapshot A, make/save a text change in Cockpit, then attempt a media edit using A: expect 409 and human text unchanged.
5. Fetch B and apply media: verify the human text and `_state` unchanged, native references resolved and history has the recoverable before record.
6. Verify media patches containing text/state fields are rejected, and publish/unpublish require permission plus fresh revisions.
7. Confirm the draft and its history remain inaccessible anonymously.

Do not call the server integration verified until these checks pass. If compatibility fails, leave CLI writes blocked; never fall back to the original full-project save endpoint.
