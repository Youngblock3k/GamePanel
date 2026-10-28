<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\DaemonClient;
use App\DaemonException;
use App\DB;
use App\Security;
use App\ServerService;

/** Bearer-token API: Authorization: Bearer gp_<identifier>.<secret> */
final class ApiController extends Controller
{
    private array $apiUser = [];

    private function authenticate(): void
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer gp_([a-f0-9]{16})\.([a-f0-9]{48})$/', $h, $m)) {
            $this->json(['error' => 'Unauthorized'], 401);
        }
        if (!Security::hit('api:ip:' . Security::ip(), 120, 60)) {
            $this->json(['error' => 'Rate limited'], 429);
        }
        $key = DB::one('SELECT * FROM api_keys WHERE identifier = ?', [$m[1]]);
        if (!$key || !hash_equals($key['token_hash'], hash('sha256', $m[2]))) {
            $this->json(['error' => 'Unauthorized'], 401);
        }
        if ($key['allowed_ips'] && !in_array(Security::ip(), explode(',', $key['allowed_ips']), true)) {
            $this->json(['error' => 'IP not allowed'], 403);
        }
        $u = DB::one('SELECT * FROM users WHERE id = ? AND disabled = 0', [$key['user_id']]);
        if (!$u) {
            $this->json(['error' => 'Unauthorized'], 401);
        }
        DB::q('UPDATE api_keys SET last_used = ? WHERE id = ?', [time(), $key['id']]);
        $this->apiUser = $u;
    }

    public function servers(): void
    {
        $this->authenticate();
        $rows = DB::all('SELECT uuid, name, status, memory, cpu, disk FROM servers WHERE owner_id = ? ORDER BY name', [$this->apiUser['id']]);
        $this->json(['data' => $rows]);
    }

    public function show(array $p): void
    {
        $this->authenticate();
        $s = ServerService::find($p['uuid'], $this->apiUser);
        $out = ['uuid' => $s['uuid'], 'name' => $s['name'], 'status' => $s['status'], 'address' => $s['fqdn'] . ':' . $s['a_port']];
        try {
            $out['resources'] = DaemonClient::ok(ServerService::node($s), 'GET', '/api/servers/' . $s['uuid'] . '/stats?disk=' . (int)$s['disk'], null, 12);
        } catch (DaemonException) {
            $out['resources'] = null;
        }
        $this->json($out);
    }

    public function power(array $p): void
    {
        $this->authenticate();
        $s = ServerService::find($p['uuid'], $this->apiUser);
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        $action = is_array($body) ? ($body['action'] ?? '') : '';
        if ($s['status'] !== 'active' || !in_array($action, ['start', 'stop', 'restart', 'kill'], true)) {
            $this->json(['error' => 'Invalid request'], 422);
        }
        try {
            DaemonClient::ok(ServerService::node($s), 'POST', '/api/servers/' . $s['uuid'] . '/power', ['action' => $action, 'disk' => (int)$s['disk']], 60);
        } catch (DaemonException $e) {
            $this->json(['error' => $e->getMessage()], 502);
        }
        Audit::log('api.server.power.' . $action, $s['uuid'], [], (int)$this->apiUser['id']);
        $this->json(['ok' => true]);
    }
}
