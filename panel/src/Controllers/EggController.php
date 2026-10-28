<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\DB;
use App\EggPresets;
use App\HttpException;

/** Egg templates: browse, install presets, build custom eggs, edit, clone, import/export. */
final class EggController extends Controller
{
    public function index(): void
    {
        $installed = array_column(DB::all('SELECT preset_key FROM eggs WHERE preset_key IS NOT NULL'), 'preset_key');
        $this->view('admin/eggs', [
            'title' => 'Eggs',
            'eggs' => DB::all('SELECT e.*, (SELECT COUNT(*) FROM servers s WHERE s.egg_id = e.id) AS in_use FROM eggs e ORDER BY e.category, e.name'),
            'presets' => array_diff_key(EggPresets::all(), array_flip($installed)),
        ]);
    }

    private function find(array $p): array
    {
        return DB::one('SELECT * FROM eggs WHERE id = ?', [(int)$p['id']]) ?? throw new HttpException('Egg not found', 404);
    }

    /** Validate raw input (form or imported JSON) into a DB row. Returns [row, error]. */
    private function normalise(array $in): array
    {
        $vars = $in['variables'] ?? [];
        if (is_string($vars)) {
            $vars = json_decode($vars === '' ? '[]' : $vars, true);
        }
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 100);
        $image = trim((string)($in['docker_image'] ?? ''));
        $installImage = trim((string)($in['install_image'] ?? ''));
        $imgRe = '#^[a-z0-9][a-z0-9._/\-:@]{0,190}$#i';
        if ($name === '' || !is_array($vars) || !preg_match($imgRe, $image) || ($installImage !== '' && !preg_match($imgRe, $installImage))) {
            return [[], 'Name, a valid docker image and valid variables JSON are required.'];
        }
        if (trim((string)($in['startup'] ?? '')) === '' || strlen((string)$in['startup']) > 1000) {
            return [[], 'A startup command (max 1000 chars) is required.'];
        }
        foreach ($vars as $v) {
            if (!is_array($v) || !preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', (string)($v['key'] ?? '')) || !isset($v['name'])) {
                return [[], 'Each variable needs an UPPER_CASE "key" and a "name".'];
            }
            if (!preg_match('/^[A-Za-z0-9_.:\-]{0,64}$/', (string)($v['default'] ?? ''))) {
                return [[], 'Default for ' . $v['key'] . ' may only use letters, numbers and . _ : -'];
            }
            if (!empty($v['regex']) && @preg_match('/' . str_replace('/', '\/', (string)$v['regex']) . '/', '') === false) {
                return [[], 'Invalid regex for ' . $v['key'] . '.'];
            }
        }
        $cat = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($in['category'] ?? 'custom'))) ?: 'custom';
        return [[
            'name' => $name, 'category' => mb_substr($cat, 0, 40), 'description' => mb_substr((string)($in['description'] ?? ''), 0, 255),
            'docker_image' => $image, 'startup' => trim((string)$in['startup']), 'stop_command' => mb_substr(trim((string)($in['stop_command'] ?? '')), 0, 100),
            'variables' => json_encode(array_values($vars)), 'install_image' => $installImage,
            'install_script' => str_replace("\r", '', (string)($in['install_script'] ?? '')),
        ], null];
    }

    public function create(): void
    {
        [$row, $err] = $this->normalise($_POST);
        if ($err) {
            flash('error', $err);
            redirect('/admin/eggs');
        }
        $id = DB::insert('eggs', $row);
        Audit::log('admin.egg.create', $row['name'], ['id' => $id]);
        flash('ok', 'Egg created.');
        redirect('/admin/eggs/' . $id);
    }

    public function edit(array $p): void
    {
        $egg = $this->find($p);
        $this->view('admin/egg_edit', ['title' => 'Edit egg', 'egg' => $egg, 'inUse' => (int)DB::val('SELECT COUNT(*) FROM servers WHERE egg_id = ?', [$egg['id']])]);
    }

    public function update(array $p): void
    {
        $egg = $this->find($p);
        [$row, $err] = $this->normalise($_POST);
        if ($err) {
            flash('error', $err);
            redirect('/admin/eggs/' . $egg['id']);
        }
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
        DB::q("UPDATE eggs SET $set WHERE id = ?", [...array_values($row), $egg['id']]);
        Audit::log('admin.egg.update', $row['name'], ['id' => $egg['id']]);
        flash('ok', 'Egg saved. Existing servers keep their installed files; startup/variable changes apply on next container rebuild.');
        redirect('/admin/eggs/' . $egg['id']);
    }

    public function clone(array $p): void
    {
        $egg = $this->find($p);
        unset($egg['id']);
        $egg['name'] = mb_substr($egg['name'], 0, 90) . ' (copy)';
        $egg['preset_key'] = null;
        $egg['category'] = 'custom';
        $id = DB::insert('eggs', $egg);
        Audit::log('admin.egg.clone', $egg['name']);
        redirect('/admin/eggs/' . $id);
    }

    public function export(array $p): void
    {
        $egg = $this->find($p);
        $out = array_intersect_key($egg, array_flip(['name', 'category', 'description', 'docker_image', 'startup', 'stop_command', 'install_image', 'install_script']));
        $out['variables'] = json_decode($egg['variables'], true);
        $out['_format'] = 'vorlane-egg/1';
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="egg-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($egg['name'])) . '.json"');
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Convert a Pterodactyl egg (PTDL_v1 / PTDL_v2) into this panel's egg shape. */
    private function fromPterodactyl(array $d): array
    {
        $images = $d['docker_images'] ?? null;
        $image = is_array($images) ? (string)(reset($images) ?: '') : (string)($d['image'] ?? ($d['images'][0] ?? ''));
        $vars = [];
        foreach ((array)($d['variables'] ?? []) as $v) {
            if (!is_array($v) || empty($v['env_variable'])) {
                continue;
            }
            $default = (string)($v['default_value'] ?? '');
            $regex = '';
            if (preg_match('#regex:/(.+)/[a-z]*(?:\||$)#', (string)($v['rules'] ?? ''), $m)) {
                $regex = $m[1];
            }
            $vars[] = [
                'key' => strtoupper((string)$v['env_variable']), 'name' => (string)($v['name'] ?? $v['env_variable']),
                'default' => preg_match('/^[A-Za-z0-9_.:\-]{0,64}$/', $default) ? $default : '',
                'editable' => !empty($v['user_editable']), 'regex' => $regex,
            ];
        }
        $inst = $d['scripts']['installation'] ?? [];
        return [
            'name' => (string)($d['name'] ?? ''), 'category' => 'imported', 'description' => (string)($d['description'] ?? ''),
            'docker_image' => $image, 'startup' => (string)($d['startup'] ?? ''), 'stop_command' => (string)($d['config']['stop'] ?? ''),
            'variables' => $vars, 'install_image' => (string)($inst['container'] ?? ''), 'install_script' => (string)($inst['script'] ?? ''),
        ];
    }

    public function import(): void
    {
        $raw = (string)($_POST['json'] ?? '');
        $data = strlen($raw) <= 400000 ? json_decode($raw, true) : null;
        if (is_array($data) && (isset($data['meta']['version']) || isset($data['docker_images']) || isset($data['scripts']))) {
            $data = $this->fromPterodactyl($data);
        } elseif (!is_array($data) || ($data['_format'] ?? '') !== 'vorlane-egg/1') {
            flash('error', 'Not a valid egg file. Game Panel exports and Pterodactyl eggs (PTDL_v1/v2) are supported.');
            redirect('/admin/eggs');
        }
        [$row, $err] = $this->normalise($data);
        if ($err) {
            flash('error', 'Import failed: ' . $err);
            redirect('/admin/eggs');
        }
        $id = DB::insert('eggs', $row);
        Audit::log('admin.egg.import', $row['name']);
        flash('ok', 'Egg imported. Review the install script before using it - it runs inside a sandboxed container.');
        redirect('/admin/eggs/' . $id);
    }

    public function preset(): void
    {
        $key = $this->in('key');
        $id = $key === 'all' ? $this->installAll() : EggPresets::install($key);
        Audit::log('admin.egg.preset', $key);
        flash($id ? 'ok' : 'error', $id ? 'Preset installed.' : 'Preset is already installed or unknown.');
        redirect('/admin/eggs');
    }

    private function installAll(): int
    {
        $n = 0;
        foreach (array_keys(EggPresets::all()) as $k) {
            $n += EggPresets::install($k) ? 1 : 0;
        }
        return $n;
    }

    public function delete(array $p): void
    {
        $egg = $this->find($p);
        if (DB::val('SELECT COUNT(*) FROM servers WHERE egg_id = ?', [$egg['id']])) {
            flash('error', 'Egg is in use by servers.');
            redirect('/admin/eggs');
        }
        DB::q('DELETE FROM eggs WHERE id = ?', [$egg['id']]);
        Audit::log('admin.egg.delete', $egg['name']);
        redirect('/admin/eggs');
    }
}
