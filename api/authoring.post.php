<?php
// Install as config/api/portfolio/authoring.post.php after the addon.
$fail = function (int $status, string $message) {
    $this->response->status = $status;
    return ['error' => $message];
};
$this->response->headers['Cache-Control'] = 'no-store';
$service = $GLOBALS['portfolio_authoring_v1'] ?? null;
if (!$service) return $fail(503, 'Authoring addon required');
$key = $this->request->server['HTTP_API_KEY'] ?? '';
if (!is_string($key) || !trim($key) || $key === 'public' || $this->param('api_key', null) !== null) return $fail(401, 'API key header required');
$role = $this->helper('auth')->getUser('role');
$allowed = fn ($permission) => $role && $this->helper('acl')->isAllowed('content/projects/' . $permission, $role);
// A public/read-only role must never gain draft/history access.
if (!$allowed('read') || !$allowed('update')) return $fail(403, 'Authoring permissions required');
try {
    $raw = file_get_contents('php://input', false, null, 0, 2097153);
    if ($raw === false || strlen($raw) > 2097152) return $fail(413, 'Request too large');
    $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
} catch (Throwable $error) { return $fail(400, 'Valid JSON required'); }
if (!is_array($body)) return $fail(400, 'Object required');
$action = $body['action'] ?? '';
if (!in_array($action, ['check', 'get', 'history', 'create', 'set-media', 'edit-text', 'publish', 'unpublish'], true)) return $fail(400, 'Unknown action');
$model = $this->module('content')->model('projects');
if (!$model || $model['type'] !== 'collection') return $fail(503, 'Projects collection required');
$collection = 'content/collections/projects';
$hash = $service['revision'];
$snapshot = fn ($item) => ['item' => $item, 'revision' => $hash($item)];
if ($action === 'check') return ['ok' => true, 'version' => 1];
try {
    if (in_array($action, ['get', 'history'], true)) {
        $slug = $body['slug'] ?? '';
        if (!is_string($slug) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) return $fail(400, 'Valid slug required');
        ($service['acquire'])();
        try {
            $items = $this->dataStorage->find($collection, ['filter' => ['slug' => $slug], 'limit' => 2])->toArray();
            if (count($items) !== 1) return $fail(count($items) ? 409 : 404, 'Project must be unique');
            $item = $items[0];
            if ($action === 'get') return $snapshot($item);
            $history = $this->dataStorage->find('portfolio/authoring-history', [
                'filter' => ['itemId' => $item['_id']], 'sort' => ['createdAt' => -1], 'limit' => 20
            ])->toArray();
            return ['history' => $history];
        } finally { ($service['release'])(); }
    }
    $textFields = ['title', 'summary', 'content', 'tags', 'links', 'date'];
    $mediaFields = ['cover', 'gallery'];
    if ($action === 'create') {
        if (!$allowed('create')) return $fail(403, 'Create permission required');
        $patch = $body['data'] ?? null;
        $fields = array_merge($textFields, $mediaFields, ['slug', 'featured']);
    } elseif (in_array($action, ['publish', 'unpublish'], true)) {
        if (!$allowed('publish')) return $fail(403, 'Publication permission required');
        if (isset($body['patch']) || isset($body['data'])) return $fail(400, 'Publication is separate');
        $patch = ['_state' => $action === 'publish' ? 1 : 0];
        $fields = ['_state'];
    } else {
        $patch = $body['patch'] ?? null;
        $fields = $action === 'set-media' ? $mediaFields : $textFields;
    }
    if (!is_array($patch) || !$patch || array_diff(array_keys($patch), $fields)) return $fail(400, 'Disallowed or empty fields');
    foreach (['title', 'summary', 'content', 'date', 'slug'] as $field) {
        if (array_key_exists($field, $patch) && !is_string($patch[$field])) return $fail(400, 'Text fields must be strings');
    }
    if (isset($patch['title']) && !trim($patch['title'])) return $fail(400, 'Title required');
    if (isset($patch['tags']) && (!is_array($patch['tags']) || !array_is_list($patch['tags']) || count(array_filter($patch['tags'], 'is_string')) !== count($patch['tags']))) return $fail(400, 'Tags must be a list of strings');
    if (isset($patch['links'])) {
        if (!is_array($patch['links']) || array_diff(array_keys($patch['links']), ['github', 'website'])) return $fail(400, 'Invalid links');
        foreach ($patch['links'] as $link) {
            if (!is_string($link) || !filter_var($link, FILTER_VALIDATE_URL) || !in_array(parse_url($link, PHP_URL_SCHEME), ['http', 'https'], true)) return $fail(400, 'HTTP links required');
        }
    }
    if (isset($patch['featured']) && !is_bool($patch['featured'])) return $fail(400, 'Featured must be Boolean');
    // Hydrate native assets from storage; never accept remote URLs or fabricated paths.
    $asset = function ($reference) {
        if (!is_array($reference) || !isset($reference['_id']) || !is_string($reference['_id'])) throw new InvalidArgumentException('Native asset required');
        $native = $this->dataStorage->findOne('assets', ['_id' => $reference['_id']]);
        if (!$native || empty($native['path'])) throw new InvalidArgumentException('Asset not found');
        foreach (['alt', 'caption'] as $field) {
            if (isset($reference[$field])) {
                if (!is_string($reference[$field])) throw new InvalidArgumentException('Invalid asset text');
                $native[$field] = $reference[$field];
            }
        }
        return $native;
    };
    if (array_key_exists('cover', $patch) && $patch['cover'] !== null) $patch['cover'] = $asset($patch['cover']);
    if (array_key_exists('gallery', $patch)) {
        if (!is_array($patch['gallery']) || !array_is_list($patch['gallery']) || count($patch['gallery']) > 100) return $fail(400, 'Gallery must be a bounded list');
        $patch['gallery'] = array_map($asset, $patch['gallery']);
    }
    if ($action === 'create') {
        if (empty($patch['title']) || empty($patch['slug']) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $patch['slug'])) return $fail(400, 'Title and slug required');
        $patch['_state'] = 0;
        $GLOBALS['portfolio_authoring_context'] = ['action' => 'create'];
        $data = $patch;
    } else {
        $id = $body['id'] ?? null;
        $revision = $body['revision'] ?? null;
        if (!is_string($id) || !$id || !is_string($revision) || !preg_match('/^[a-f0-9]{64}$/', $revision)) return $fail(400, 'Snapshot ID and revision required');
        $GLOBALS['portfolio_authoring_context'] = ['action' => $action, 'revision' => $revision, 'patch' => $patch];
        $data = array_merge(['_id' => $id], $patch);
    }
    // The addon acquires the shared editor lock, compares revision, records history,
    // and rebases only this patch immediately before the database write.
    $saved = $this->module('content')->saveItem('projects', $data, ['user' => $this->helper('auth')->getUser()]);
    if (!$saved) throw new RuntimeException('Save failed');
    return $snapshot($saved);
} catch (InvalidArgumentException $error) {
    return $fail(400, 'Invalid field value or asset');
} catch (Throwable $error) {
    return $fail($error->getCode() === 409 ? 409 : 500, $error->getCode() === 409 ? 'Revision conflict or duplicate slug' : 'Authoring failed; inspect CMS before retrying');
} finally {
    unset($GLOBALS['portfolio_authoring_context']);
    ($service['release'])();
}
