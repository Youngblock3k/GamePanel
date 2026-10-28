<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\DB;
use App\HttpException;
use App\Roles;

final class RoleController extends Controller
{
    public function index(): void
    {
        $this->view('admin/roles', ['title' => 'Roles', 'roles' => Roles::all()]);
    }

    public function form(array $p = []): void
    {
        Roles::ensure();
        $role = null;
        if (isset($p['id'])) {
            $role = DB::one('SELECT * FROM roles WHERE id = ?', [(int)$p['id']]);
            if (!$role) {
                throw new HttpException('Role not found', 404);
            }
            $role['perms'] = json_decode((string)$role['perms'], true) ?: [];
        }
        $this->view('admin/role_form', ['title' => $role ? 'Edit role' : 'New role', 'role' => $role]);
    }

    private function read(): array
    {
        $perms = array_values(array_intersect(array_keys(Roles::PERMS), array_filter((array)($_POST['perms'] ?? []), 'is_string')));
        foreach ($perms as $k) {
            if (str_contains($k, '.') && !in_array($base = explode('.', $k)[0], $perms, true)) {
                $perms[] = $base;
            }
        }
        $color = strtolower($this->in('color'));
        return [mb_substr($this->in('name'), 0, 60), mb_substr($this->in('description'), 0, 191), array_values(array_unique($perms)), Roles::validColor($color) ? $color : Roles::COLORS[8]];
    }

    public function create(): void
    {
        Roles::ensure();
        [$name, $desc, $perms, $color] = $this->read();
        if (!preg_match('/^[A-Za-z0-9 _.\-]{2,60}$/', $name) || DB::one('SELECT id FROM roles WHERE name = ?', [$name])) {
            flash('error', 'Role name: 2-60 letters, numbers or spaces, and not used yet.');
            redirect('/admin/roles/new');
        }
        $id = DB::insert('roles', ['name' => $name, 'description' => $desc, 'perms' => json_encode($perms), 'color' => $color, 'created_at' => time()]);
        Audit::log('admin.role.create', $name, ['id' => $id, 'perms' => $perms]);
        flash('ok', 'Role created.');
        redirect('/admin/roles');
    }

    public function update(array $p): void
    {
        Roles::ensure();
        $id = (int)$p['id'];
        [$name, $desc, $perms, $color] = $this->read();
        if (!DB::one('SELECT id FROM roles WHERE id = ?', [$id])) {
            throw new HttpException('Role not found', 404);
        }
        if (!preg_match('/^[A-Za-z0-9 _.\-]{2,60}$/', $name) || DB::one('SELECT id FROM roles WHERE name = ? AND id <> ?', [$name, $id])) {
            flash('error', 'Role name: 2-60 letters, numbers or spaces, and not used yet.');
            redirect('/admin/roles/' . $id);
        }
        DB::q('UPDATE roles SET name = ?, description = ?, perms = ?, color = ? WHERE id = ?', [$name, $desc, json_encode($perms), $color, $id]);
        Audit::log('admin.role.update', $name, ['perms' => $perms]);
        flash('ok', 'Role saved. It applies to its members straight away.');
        redirect('/admin/roles');
    }

    public function delete(array $p): void
    {
        Roles::ensure();
        if (!$this->requirePassword()) {
            redirect('/admin/roles');
        }
        $id = (int)$p['id'];
        DB::q('UPDATE users SET role_id = NULL WHERE role_id = ?', [$id]);
        DB::q('DELETE FROM roles WHERE id = ?', [$id]);
        Audit::log('admin.role.delete', (string)$id);
        flash('ok', 'Role deleted. Its members are now ordinary users.');
        redirect('/admin/roles');
    }
}
