# Safe Cockpit authoring

**Cockpit is the source of truth.** Local project JSON under `content/projects/` is historical/import material, not a copy to overwrite the CMS with. The old `save` and `find` commands are disabled. Earlier full-project saves overwrote user text during a media import; do not repeat that workflow.

## Install once

See [AUTHORING.md](AUTHORING.md). The new CLI requires both the PortfolioAuthoring addon and the authenticated authoring API route. Installation/live compatibility verification are pending; no production content was changed while preparing this workflow. The existing [asset upload endpoint](UPLOAD.md) stays separate.

## Everyday workflow

The CLI internally loads `.env.local`; exported variables take precedence. Use `COCKPIT_URL=https://cockpit.manuelselch.de` and a dedicated `COCKPIT_WRITE_API_KEY`, never a `NEXT_PUBLIC_` write key. No keys are printed. Node 22.18+ recommended.

```sh
mkdir -p .cockpit
npm run cockpit -- check
npm run cockpit -- get business-to-go .cockpit/business-v1.json
```

`get` includes drafts and writes a **new** private snapshot with the CMS item and an opaque revision. `.cockpit/` is ignored by Git; files are created with mode 0600. Do not commit draft snapshots/history. It refuses to overwrite existing files.

### Change only text

Create a patch containing only the text fields you intend to change:

```json
{"summary":"An updated project summary."}
```

```sh
npm run cockpit -- edit-text .cockpit/business-v1.json .cockpit/text-patch.json
```

Allowed fields: `title`, `summary`, `content`, `tags`, `links`, `date`. Omit everything not deliberately changed. HTML remains trusted owner-authored content. Slug/featured changes can be made in Cockpit's editor; they are not part of the text command.

### Change only media

Upload images using `upload` as before, then create a media-only patch using native asset IDs:

```json
{
  "cover":{"_id":"NATIVE_ASSET_ID","alt":"A meaningful description"},
  "gallery":[{"_id":"NATIVE_ASSET_ID","alt":"A meaningful description","caption":"Optional caption"}]
}
```

```sh
npm run cockpit -- set-media .cockpit/business-v1.json .cockpit/media-patch.json
```

Only `cover`/`gallery` are accepted. The server resolves native asset metadata; it does not trust client paths or fetch remote images. `cover:null` clears the cover and `gallery:[]` clears the gallery. Sending a gallery replaces that gallery list; preserve intended existing entries. **Neither media nor text edits change publication state.**

### Conflicts and concurrent editing

Each edit supplies the revision from its original snapshot. If the CMS changed at all, the server returns 409 and nothing from that request is saved. Fetch a new snapshot into a new file, review changes and recreate the small patch. There is no automatic retry, merge or force-overwrite flag. Fetch another snapshot after every successful edit.

The addon hooks editor saves as well as agent saves: the revision comparison and write occur under a shared server lock, and a complete pre-change record is retained in private storage. **Cockpit's existing browser form does not submit this revision token.** A stale already-open form can still overwrite later agent edits if the human saves it afterwards. Until the editor is enhanced, finish/save/close the form before an agent edit, and reload it before saving after an agent edit. The addon cannot infer intent from a stale full editor payload.

### Create and publish separately

```sh
npm run cockpit -- create .cockpit/new-project.json
npm run cockpit -- get my-project .cockpit/my-project-v1.json
npm run cockpit -- publish .cockpit/my-project-v1.json
# Or explicitly unpublish using another fresh snapshot:
npm run cockpit -- unpublish .cockpit/my-project-v2.json
```

`create` accepts a new project with title/slug but no `_id`, `_state` or revision, always creates a draft, and rejects duplicate slugs. `publish`/`unpublish` accept only a snapshot; publishing cannot silently accompany text/media changes. If a write times out, fetch current state and inspect history before retrying: a response error does not prove the save failed.

### Inspect history

```sh
npm run cockpit -- history business-to-go .cockpit/business-history.json
```

Returns the latest 20 records, including the full `before` snapshot. A history entry marked `attempt` may correspond to an interrupted/failed save; inspect current state before deciding what to restore. Restore selected fields through a fresh guarded patch or manually through the editor, never blindly replay a whole old record. History starts only when the addon is installed; it cannot recover earlier overwritten text. Back up Cockpit storage/configuration independently.

## Published-project verification

```sh
TEST_BASE_URL=http://127.0.0.1:3016 npm run verify:project -- pi-chat
```

This is read-only and sends no key. It compares the published CMS entry against local expected JSON, checks native assets and browser rendering/navigation. If someone edited content in Cockpit, the historical local JSON may intentionally differ; review/export the current CMS content before using strict fidelity verification. A mismatch does not authorize restoring the old file.

Never access Cockpit through Docker. Unsupported server installation/configuration is performed by the user.
