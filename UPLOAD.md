# Image upload

The upload endpoint is part of this Cockpit addon and is registered by root `bootstrap.php` at `POST /api/portfolio/upload`. Do not copy it into Cockpit's separate `config/api/` directory.

Requirements: PHP Fileinfo and image-inspection support; PHP/web-server upload limits at least 10 MiB (`post_max_size` greater than `upload_max_filesize`). In Assets, create exactly one root folder named `projects`. Grant the dedicated API key role the `assets/upload` permission only; never grant it to the public role.

From the frontend repository root:

```sh
npm run cockpit -- upload /path/to/image.png .cockpit/new-image.asset.json
```

Output must be new. One PNG/JPEG/WebP is accepted, up to 10 MiB and 20 MP. The file is placed into the fixed root `projects` folder, and the CLI saves the native asset reference including `_id`, `path`, and `folder`. Identical images may be reused. Arbitrary folders, remote URLs, replacement and deletion are not supported.

Attach it via the guarded media-only workflow in `README.md`; never resave a full local project JSON. A 500/timeout may occur after storage—inspect Assets/API before retrying. Missing key returns401; insufficient role returns403; invalid images return415. Route tests live in `tests/routes.php` and `tests/routes.test.mjs`.
