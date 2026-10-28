<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\DB;
use App\DbHosts;
use App\HttpException;
use App\Security;

final class DatabaseController extends Controller
{
    // ---- Database hosts -------------------------------------------------

    public function hosts(): void
    {
        DbHosts::ensure();
        $this->view('admin/databases', [
            'title' => 'Databases',
            'hosts' => DB::all('SELECT h.*, (SELECT COUNT(*) FROM server_dbs d WHERE d.host_id = h.id) AS dbs FROM db_hosts h ORDER BY h.name'),
            'driver' => in_array('mysql', \PDO::getAvailableDrivers(), true),
        ]);
    }

    public function hostNew(): void
    {
        DbHosts::ensure();
        $this->view('admin/db_host_new', ['title' => 'New database host']);
    }

    public function hostCreate(): void
    {
        DbHosts::ensure();
        if (!$this->requirePassword()) {
            redirect('/admin/databases/new');
        }
        $name = $this->in('name');
        $host = $this->in('host');
        $port = (int)$this->in('port', '3306');
        $user = $this->in('admin_user');
        $pass = (string)($_POST['admin_pass'] ?? '');
        if ($name === '' || strlen($name) > 100 || !preg_match('/^[A-Za-z0-9.\-]{1,191}$/', $host) || $port < 1 || $port > 65535 || $user === '' || strlen($user) > 100) {
            flash('error', 'Check the name, host, port and user.');
            redirect('/admin/databases/new');
        }
        try {
            DbHosts::connect($host, $port, $user, $pass);
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/admin/databases/new');
        }
        DB::insert('db_hosts', ['name' => $name, 'host' => $host, 'port' => $port, 'admin_user' => $user, 'admin_pass' => Security::encrypt($pass),
            'max_dbs' => max(0, (int)$this->in('max_dbs', '0')), 'created_at' => time()]);
        Audit::log('admin.dbhost.create', $name);
        flash('ok', 'Database host added.');
        redirect('/admin/databases');
    }

    public function hostDelete(array $p): void
    {
        DbHosts::ensure();
        if (!$this->requirePassword()) {
            redirect('/admin/databases');
        }
        $id = (int)$p['id'];
        if ((int)DB::val('SELECT COUNT(*) FROM server_dbs WHERE host_id = ?', [$id]) > 0) {
            flash('error', 'Delete the databases on this host first.');
            redirect('/admin/databases');
        }
        DB::q('DELETE FROM db_hosts WHERE id = ?', [$id]);
        Audit::log('admin.dbhost.delete', (string)$id);
        flash('ok', 'Database host removed.');
        redirect('/admin/databases');
    }

    // ---- One server -----------------------------------------------------

    private function srv(array $p): array
    {
        $s = DB::one('SELECT s.*, u.username AS owner_name, n.name AS node_name, e.name AS egg_name, a.ip AS a_ip, a.port AS a_port, n.fqdn
            FROM servers s JOIN users u ON u.id = s.owner_id JOIN nodes n ON n.id = s.node_id JOIN eggs e ON e.id = s.egg_id JOIN allocations a ON a.id = s.allocation_id WHERE s.id = ?', [(int)$p['id']]);
        if (!$s) {
            throw new HttpException('Server not found', 404);
        }
        return $s;
    }

    public function serverPage(array $p): void
    {
        DbHosts::ensure();
        $s = $this->srv($p);
        $new = $_SESSION['new_db'] ?? null;
        unset($_SESSION['new_db']);
        $this->view('admin/server', [
            'title' => $s['name'], 's' => $s, 'newDb' => $new,
            'dbs' => DB::all('SELECT d.*, h.name AS host_name, h.host, h.port FROM server_dbs d JOIN db_hosts h ON h.id = d.host_id WHERE d.server_id = ? ORDER BY d.id', [$s['id']]),
            'hosts' => DB::all('SELECT h.*, (SELECT COUNT(*) FROM server_dbs d WHERE d.host_id = h.id) AS dbs FROM db_hosts h ORDER BY h.name'),
        ]);
    }

    public function create(array $p): void
    {
        DbHosts::ensure();
        $s = $this->srv($p);
        $back = '/admin/servers/' . (int)$s['id'];
        $host = DB::one('SELECT * FROM db_hosts WHERE id = ?', [(int)$this->in('host_id')]);
        $label = strtolower($this->in('label'));
        $remote = $this->in('remote', '%');
        if (!$host) {
            flash('error', 'Choose a database host (add one under Databases first).');
            redirect($back);
        }
        if (!preg_match('/^[a-z0-9_]{1,24}$/', $label)) {
            flash('error', 'Database name: 1-24 lowercase letters, numbers or underscores.');
            redirect($back);
        }
        if (!DbHosts::validRemote($remote)) {
            flash('error', 'Allowed from must be % (anywhere) or one IP address.');
            redirect($back);
        }
        if ((int)$host['max_dbs'] > 0 && (int)DB::val('SELECT COUNT(*) FROM server_dbs WHERE host_id = ?', [$host['id']]) >= (int)$host['max_dbs']) {
            flash('error', 'That database host is full.');
            redirect($back);
        }
        try {
            $r = DbHosts::createFor($host, (int)$s['id'], $label, $remote);
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect($back);
        }
        $id = DB::insert('server_dbs', ['server_id' => $s['id'], 'host_id' => $host['id'], 'db_name' => $r['db_name'], 'db_user' => $r['db_user'],
            'db_pass' => Security::encrypt($r['db_pass']), 'remote' => $remote, 'created_at' => time()]);
        $_SESSION['new_db'] = $id;
        Audit::log('admin.db.create', $r['db_name'], ['server' => $s['uuid']]);
        flash('ok', 'Database created.');
        redirect($back);
    }

    private function row(array $p, array $s): array
    {
        $d = DB::one('SELECT d.*, h.host AS h_host, h.port AS h_port, h.admin_user, h.admin_pass FROM server_dbs d JOIN db_hosts h ON h.id = d.host_id WHERE d.id = ? AND d.server_id = ?', [(int)$p['db'], $s['id']]);
        if (!$d) {
            throw new HttpException('Database not found', 404);
        }
        $d['host'] = $d['h_host'];
        $d['port'] = $d['h_port'];
        return $d;
    }

    public function rotate(array $p): void
    {
        $s = $this->srv($p);
        $back = '/admin/servers/' . (int)$s['id'];
        if (!$this->requirePassword()) {
            redirect($back);
        }
        $d = $this->row($p, $s);
        try {
            $pass = DbHosts::rotate($d, $d);
        } catch (\Throwable $e) {
            flash('error', $e instanceof \RuntimeException ? $e->getMessage() : 'The database host refused the change.');
            redirect($back);
        }
        DB::q('UPDATE server_dbs SET db_pass = ? WHERE id = ?', [Security::encrypt($pass), $d['id']]);
        $_SESSION['new_db'] = (int)$d['id'];
        Audit::log('admin.db.rotate', $d['db_name']);
        flash('ok', 'Password changed.');
        redirect($back);
    }

    public function delete(array $p): void
    {
        $s = $this->srv($p);
        $back = '/admin/servers/' . (int)$s['id'];
        if (!$this->requirePassword()) {
            redirect($back);
        }
        $d = $this->row($p, $s);
        try {
            DbHosts::drop($d, $d);
        } catch (\Throwable $e) {
            if ($this->in('force') !== '1') {
                flash('error', ($e instanceof \RuntimeException ? $e->getMessage() : 'The database host refused the change.') . ' Tick "Forget it anyway" to remove only the record.');
                redirect($back);
            }
        }
        DB::q('DELETE FROM server_dbs WHERE id = ?', [$d['id']]);
        Audit::log('admin.db.delete', $d['db_name']);
        flash('ok', 'Database deleted.');
        redirect($back);
    }
}
