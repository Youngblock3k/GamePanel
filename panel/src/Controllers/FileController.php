<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\DaemonClient;
use App\DaemonException;
use App\Security;
use App\ServerService;

/** Panel-side proxy for the daemon's file API. Path jailing is enforced again by the daemon. */
final class FileController extends Controller
{
    public function page(array $p): void
    {
        $s = $this->server($p);
        $this->view('servers/files', ['title' => $s['name'] . ' – Files', 's' => $s, 'tab' => 'files']);
    }

    private function path(string $key = 'path'): string
    {
        $v = $this->in($key);
        if (strlen($v) > 1024 || preg_match('/[\x00-\x1f]/', $v)) {
            $this->json(['error' => 'Invalid path'], 422);
        }
        return $v;
    }

    private function proxy(array $s, string $method, string $op, array $args, int $timeout = 20): never
    {
        if ($s['status'] === 'suspended') {
            $this->json(['error' => 'Server is suspended'], 403);
        }
        if (!Security::hit('files:' . $this->user()['id'], 300, 60)) {
            $this->json(['error' => 'Slow down'], 429);
        }
        try {
            $url = '/api/servers/' . $s['uuid'] . '/files/' . $op;
            if ($method === 'GET') {
                $r = DaemonClient::call(ServerService::node($s), 'GET', $url . '?' . http_build_query($args));
            } else {
                $r = DaemonClient::call(ServerService::node($s), $method, $url, $args, $timeout);
            }
            if ($method !== 'GET') {
                Audit::log('server.file.' . $op, $s['uuid'], ['path' => $args['path'] ?? '']);
            }
            $this->json($r['data'] ?? ['error' => 'Bad daemon response'], $r['status']);
        } catch (DaemonException $e) {
            $this->json(['error' => $e->getMessage()], 502);
        }
    }

    public function list(array $p): void
    {
        $this->proxy($this->server($p), 'GET', 'list', ['path' => $this->path()]);
    }

    public function read(array $p): void
    {
        $this->proxy($this->server($p), 'GET', 'read', ['path' => $this->path()]);
    }

    public function write(array $p): void
    {
        $this->proxy($this->server($p), 'POST', 'write', ['path' => $this->path(), 'content' => (string)($_POST['content'] ?? '')]);
    }

    public function delete(array $p): void
    {
        $this->proxy($this->server($p), 'POST', 'delete', ['path' => $this->path()]);
    }

    public function pull(array $p): void
    {
        $s = $this->server($p);
        $url = $this->in('url');
        if (!preg_match('#^https://[^\s]{4,1000}$#', $url)) {
            $this->json(['error' => 'Enter a full https:// URL'], 422);
        }
        $this->proxy($s, 'POST', 'pull', ['path' => $this->path(), 'url' => $url, 'disk' => (int)$s['disk']], 3700);
    }

    public function mkdir(array $p): void
    {
        $this->proxy($this->server($p), 'POST', 'mkdir', ['path' => $this->path()]);
    }

    public function rename(array $p): void
    {
        $this->proxy($this->server($p), 'POST', 'rename', ['path' => $this->path(), 'to' => $this->path('to')]);
    }
}
