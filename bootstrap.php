<?php
// Install the entire PortfolioAuthoring directory under Cockpit addons/.
// All project writes through Content::saveItem (including the editor) participate.
$app = $this;
$canonical = function ($value) use (&$canonical) {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value);
    foreach ($value as $key => $child) $value[$key] = $canonical($child);
    return $value;
};
$revision = function (array $item) use ($canonical) {
    return hash('sha256', json_encode($canonical($item), JSON_THROW_ON_ERROR));
};
$lock = null;
$historyId = null;
$acquire = function () use ($app, &$lock) {
    if ($lock !== null) throw new RuntimeException('Nested project write refused', 409);
    $dir = $app->path('#storage:') . '/.portfolio-authoring-locks';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Lock unavailable');
    $lock = fopen($dir . '/projects.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Lock unavailable');
};
$release = function () use (&$lock, &$historyId) {
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    $lock = null;
    $historyId = null;
};
register_shutdown_function($release);
$GLOBALS['portfolio_authoring_v1'] = ['revision' => $revision, 'acquire' => $acquire, 'release' => $release];

$app->on('content.item.save.before.projects', function (&$item, $isUpdate, $collection) use ($app, $revision, $acquire, $release, &$historyId) {
    $acquire();
    try {
        if ($collection !== 'content/collections/projects') throw new RuntimeException('Unsupported projects model');
        $current = isset($item['_id']) ? $app->dataStorage->findOne($collection, ['_id' => $item['_id']]) : null;
        $context = $GLOBALS['portfolio_authoring_context'] ?? null;
        if ($context) {
            if ($context['action'] !== 'create') {
                if (!$current || !hash_equals($revision($current), $context['revision'])) throw new RuntimeException('Revision conflict', 409);
                // saveItem merged its own snapshot before this hook. Rebase ONLY
                // the explicit patch onto the current record under the shared lock.
                $item = array_merge($current, $context['patch'], [
                    '_modified' => $item['_modified'], '_mby' => $item['_mby'] ?? null
                ]);
            } elseif ($current) {
                throw new RuntimeException('Create cannot update', 409);
            }
        }
        if (isset($item['slug'])) {
            $duplicate = $app->dataStorage->findOne($collection, ['slug' => $item['slug']]);
            if ($duplicate && ($duplicate['_id'] !== ($item['_id'] ?? null))) throw new RuntimeException('Duplicate slug', 409);
        }
        // Private storage collection, not a publicly served file or content model.
        // Persist the complete pre-change item BEFORE allowing the content write.
        $historyId = bin2hex(random_bytes(16));
        $entry = [
            '_id' => $historyId, 'itemId' => $current['_id'] ?? ($item['_id'] ?? null),
            'slug' => $item['slug'] ?? '', 'createdAt' => time(),
            'action' => $context['action'] ?? 'cockpit-editor-or-other-api',
            'before' => $current, 'beforeRevision' => $current ? $revision($current) : null,
            'status' => 'attempt'
        ];
        $app->dataStorage->save('portfolio/authoring-history', $entry);
        if (!$app->dataStorage->findOne('portfolio/authoring-history', ['_id' => $historyId])) throw new RuntimeException('History unavailable');
    } catch (Throwable $error) {
        $release();
        throw $error;
    }
});
$app->on('content.item.save.projects', function ($item) use ($app, $revision, $release, &$historyId) {
    try {
        if (!$historyId) throw new RuntimeException('Missing authoring history');
        $entry = $app->dataStorage->findOne('portfolio/authoring-history', ['_id' => $historyId]);
        $entry['itemId'] = $item['_id'];
        $entry['afterRevision'] = $revision($item);
        $entry['status'] = 'committed';
        $app->dataStorage->save('portfolio/authoring-history', $entry);
    } finally { $release(); }
});
