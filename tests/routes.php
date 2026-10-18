<?php
// Isolated registration test. No server, credentials, filesystem writes or database.
class RouteFixtureApp {
    public array $hooks = [];
    public object $response;
    public object $request;
    public function __construct() {
        $this->response = (object) ['headers' => [], 'status' => 200];
        $this->request = (object) ['method' => 'POST', 'server' => []];
    }
    public function on($event, $handler) { $this->hooks[$event] = $handler; }
    public function param($name, $default = null) { return $default; }
    public function install() { include __DIR__ . '/../bootstrap.php'; }
}
class RouteFixtureDispatcher {
    public array $routes = [];
    public function addEndPoint($path, $methods) { $this->routes[$path] = $methods; }
}
function expect($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$app = new RouteFixtureApp();
$app->install();
$dispatcher = new RouteFixtureDispatcher();
expect(isset($app->hooks['restApi.config']), 'Missing route registration hook');
($app->hooks['restApi.config'])($dispatcher);
expect(count($dispatcher->routes) === 2, 'Expected two routes');
foreach (['authoring', 'upload'] as $name) {
    $methods = $dispatcher->routes['/portfolio/' . $name] ?? [];
    expect(array_keys($methods) === ['POST'], 'Only POST must be registered');
    $app->response->status = 200;
    $response = $methods['POST']([], $app);
    expect($app->response->status === 401, 'Missing key must return 401');
    expect(($response['error'] ?? '') === 'API key header required', 'Handler not included in app context');
}
echo "PASS authoring/upload route registration, app context and missing-key rejection\n";
