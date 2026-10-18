<?php
// Install as Cockpit config/api/portfolio/upload.post.php. Never expose publicly.
$fail = function (int $status, string $message) {
    $this->response->status = $status;
    return ['error' => $message];
};
$stage = 'request';
try {
if ($this->request->method !== 'POST') {
    return $fail(405, 'POST required');
}
// Cockpit validates the API token before dispatching this file. Require the header
// explicitly; refuse query/body overrides and the public token. Custom role keys
// legitimately have user=anonymous, so authorize their role, not their username.
$key = $this->request->server['HTTP_API_KEY'] ?? '';
if (!is_string($key) || !trim($key) || $key === 'public' || $this->param('api_key', null) !== null) {
    return $fail(401, 'API key header required');
}
$stage = 'authorization';
$role = $this->helper('auth')->getUser('role');
if (!$role || !$this->helper('acl')->isAllowed('assets/upload', $role)) {
    return $fail(403, 'Asset upload permission required');
}
$stage = 'multipart-validation';
$file = $this->request->files['file'] ?? null;
if (!is_array($file) || !isset($file['name'], $file['tmp_name'], $file['error']) || is_array($file['name'])) {
    return $fail(400, 'Send one file in the file multipart field');
}
if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    return $fail(400, 'Upload failed');
}
$size = filesize($file['tmp_name']);
if ($size === false || $size < 1 || $size > 10 * 1024 * 1024) {
    return $fail(413, 'Maximum image size is 10 MiB');
}
$nativeLimit = $this->retrieve('assets/max_upload_size', 0);
if (is_numeric($nativeLimit) && $nativeLimit > 0 && $size >= $nativeLimit) {
    return $fail(413, 'Image exceeds configured Cockpit asset size limit');
}
$types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
$extension = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
$stage = 'image-validation';
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
$image = @getimagesize($file['tmp_name']);
if (!isset($types[$extension]) || $mime !== $types[$extension] || !$image || ($image['mime'] ?? '') !== $mime) {
    return $fail(415, 'Only valid PNG, JPEG or WebP images are accepted');
}
if ($image[0] * $image[1] > 20000000) {
    return $fail(413, 'Maximum image resolution is 20 megapixels');
}
// Cockpit folders are virtual records: use the ID, NOT the string /projects.
$stage = 'folder-lookup';
$folders = $this->module('assets')->folders(['filter' => ['name' => 'projects', '_p' => '']]);
if (count($folders) !== 1 || empty($folders[0]['_id'])) {
    return $fail(409, 'Create exactly one root projects folder in Assets first');
}
    $stage = 'duplicate-lookup';
    // A previous request may have stored the asset before its response failed.
    // Reuse byte-identical images within projects rather than uploading duplicates.
    $existing = $this->module('assets')->assets([
        'filter' => ['folder' => $folders[0]['_id'], '_hash' => md5_file($file['tmp_name'])],
        'sort' => ['_created' => 1], 'limit' => 1
    ]);
    if (isset($existing[0]['_id'], $existing[0]['path'])) {
        return ['asset' => $existing[0], 'reused' => true];
    }
    $upload = [];
    foreach (['name', 'tmp_name', 'error', 'size', 'type'] as $field) {
        $upload[$field] = [$file[$field] ?? ($field === 'type' ? $mime : $size)];
    }
    $stage = 'asset-storage';
    $result = $this->module('assets')->upload($upload, ['folder' => $folders[0]['_id']]);
    $asset = $result['assets'][0] ?? null;
    // Native Cockpit assets have title/altText, not necessarily a name field.
    if (!$asset || empty($asset['_id']) || empty($asset['path'])) {
        // Return only counts/keys, never raw importer data or uploaded paths.
        $counts = [];
        foreach (['uploaded', 'failed', 'assets'] as $key) {
            $counts[] = $key . ' ' . (isset($result[$key]) && is_array($result[$key]) ? count($result[$key]) : 0);
        }
        return $fail(500, 'Cockpit import returned ' . implode(', ', $counts));
    }
    return ['asset' => $asset];
} catch (Throwable $error) {
    // Only fixed stage names leave the server; never exception messages or secrets.
    error_log('Portfolio upload failure at stage ' . $stage);
    return $fail(500, 'Asset upload failed at stage ' . $stage);
}
