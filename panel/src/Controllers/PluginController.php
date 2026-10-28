<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\DaemonClient;
use App\DaemonException;
use App\PluginSources;
use App\ServerService;

/** Browse Modrinth / Spigot / CurseForge and drop the chosen plugin into the server's plugins folder. */
final class PluginController extends Controller
{
    private function mc(array $s): string
    {
        $v = (string)((json_decode($s['env'], true) ?: [])['MC_VERSION'] ?? '');
        return preg_match('/^(1|2\d)\.\d{1,2}(\.\d{1,2})?$/', $v) ? $v : '';
    }

    public function page(array $p): void
    {
        $s = $this->server($p);
        $this->view('servers/plugins', ['title' => $s['name'] . ' – Plugins', 's' => $s, 'tab' => 'plugins', 'mc' => $this->mc($s), 'curse' => PluginSources::curseKey() !== '']);
    }

    public function search(array $p): void
    {
        $s = $this->server($p);
        if (!\App\Security::hit('plug:' . $this->user()['id'], 60, 60)) {
            $this->json(['error' => 'Slow down'], 429);
        }
        $mc = $this->in('mc') === 'any' ? '' : $this->mc($s);
        try {
            $this->json(['results' => PluginSources::search($this->in('source', 'modrinth'), mb_substr($this->in('q'), 0, 60), $mc)]);
        } catch (\RuntimeException $e) {
            $this->json(['error' => $e->getMessage()], 502);
        }
    }

    public function install(array $p): void
    {
        $s = $this->server($p);
        if ($s['status'] !== 'active') {
            $this->json(['error' => 'Server is ' . $s['status'] . '.'], 409);
        }
        $mc = $this->in('mc') === 'any' ? '' : $this->mc($s);
        try {
            $r = PluginSources::resolve($this->in('source'), $this->in('id'), $mc);
        } catch (\RuntimeException $e) {
            $this->json(['error' => $e->getMessage()], 502);
        }
        $node = ServerService::node($s);
        try {
            try {
                DaemonClient::ok($node, 'POST', '/api/servers/' . $s['uuid'] . '/files/mkdir', ['path' => '/plugins']);
            } catch (DaemonException) {
                // folder probably exists already; the download below reports real problems
            }
            DaemonClient::ok($node, 'POST', '/api/servers/' . $s['uuid'] . '/files/pull', ['path' => '/plugins/' . $r['file'], 'url' => $r['url'], 'disk' => (int)$s['disk']], 300);
            Audit::log('server.plugin.install', $s['uuid'], ['file' => $r['file']]);
            $this->json(['ok' => true, 'file' => $r['file']]);
        } catch (DaemonException $e) {
            $this->json(['error' => $e->getMessage()], 502);
        }
    }
}
