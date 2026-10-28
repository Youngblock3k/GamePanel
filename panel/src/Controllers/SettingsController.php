<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\Auth;
use App\Settings;

final class SettingsController extends Controller
{
    public function page(): void
    {
        $this->view('admin/settings', ['title' => 'Settings', 'mode' => Auth::twoFaMode(), 'geo' => Settings::get('geo_lookup', '0') === '1', 'avatars' => Settings::get('avatar_uploads', '1') === '1', 'curse' => Settings::get('curseforge_key') !== '']);
    }

    public function save(): void
    {
        $mode = $this->in('require_2fa');
        if (!in_array($mode, ['0', '1', '2'], true)) {
            flash('error', 'Invalid option.');
            redirect('/admin/settings');
        }
        Settings::set('require_2fa', $mode);
        Settings::set('avatar_uploads', $this->in('avatar_uploads') === '1' ? '1' : '0');
        Settings::set('geo_lookup', $this->in('geo_lookup') === '1' ? '1' : '0');
        $k = trim((string)($_POST['curseforge_key'] ?? ''));
        if ($k !== '') {
            if (!preg_match('/^[!-~]{10,200}$/', $k)) {
                flash('error', 'That does not look like a CurseForge API key.');
                redirect('/admin/settings');
            }
            Settings::set('curseforge_key', $k);
        } elseif ($this->in('curseforge_clear') === '1') {
            Settings::forget('curseforge_key');
        }
        Audit::log('admin.settings.require_2fa', $mode);
        flash('ok', 'Settings saved.');
        redirect('/admin/settings');
    }
}
