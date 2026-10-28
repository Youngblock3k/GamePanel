<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\Branding;
use App\Settings;

final class BrandingController extends Controller
{
    public function page(): void
    {
        $this->view('admin/branding', ['title' => 'Branding', 'brandName' => Branding::name(), 'logo' => Branding::logoUrl(), 'theme' => \App\Theme::values()]);
    }

    public function save(): void
    {
        $action = $this->in('action');
        if ($action === 'name') {
            $name = $this->in('brand_name');
            if (!Branding::validName($name)) {
                flash('error', 'Brand name: 1-32 characters (letters, numbers, spaces and . & _ -).');
                redirect('/admin/branding');
            }
            Settings::set('brand_name', $name);
            Audit::log('admin.branding.name', $name);
            flash('ok', 'Brand name updated.');
        } elseif ($action === 'logo') {
            $err = Branding::storeLogo($_FILES['logo'] ?? []);
            if ($err === null) {
                Audit::log('admin.branding.logo');
            }
            flash($err ? 'error' : 'ok', $err ?? 'Logo updated.');
        } elseif ($action === 'remove_logo') {
            Branding::removeLogo();
            Audit::log('admin.branding.logo_removed');
            flash('ok', 'Logo removed.');
        } elseif ($action === 'theme') {
            $preset = $this->in('preset');
            $cl = static fn(string $v): int => max(0, min(32, (int)$v));
            $v = [
                'preset' => isset(\App\Theme::PRESETS[$preset]) ? $preset : 'graphite',
                'bg' => strtolower($this->in('bg')), 'panel' => strtolower($this->in('panel')),
                'text' => strtolower($this->in('text')), 'accent' => strtolower($this->in('accent')),
                'radius' => $cl($this->in('rtl', '10')),
                'corners' => [$cl($this->in('rtl', '10')), $cl($this->in('rtr', '10')), $cl($this->in('rbr', '10')), $cl($this->in('rbl', '10'))],
            ];
            if ($err = \App\Theme::validate($v)) {
                flash('error', $err);
                redirect('/admin/branding');
            }
            $look = $this->in('look');
            Settings::set('ui_look', isset(\App\Theme::LOOKS[$look]) ? $look : 'custom');
            foreach (\App\Theme::OPTIONS as $k => $allowed) {
                $val = $this->in($k);
                if (isset($allowed[$val])) {
                    Settings::set('ui_' . $k, $val);
                }
            }
            Settings::set('ui_caps', $this->in('caps') === '1' ? '1' : '0');
            Settings::set('theme_preset', $v['preset']);
            foreach (['bg', 'panel', 'text', 'accent'] as $k) {
                Settings::set('theme_' . $k, $v[$k]);
            }
            Settings::set('theme_radius', (string)$v['radius']);
            foreach (['tl', 'tr', 'br', 'bl'] as $i => $k) {
                Settings::set('theme_r_' . $k, (string)$v['corners'][$i]);
            }
            Audit::log('admin.branding.theme', $v['preset']);
            flash('ok', 'Look saved.');
        } elseif ($action === 'theme_reset') {
            self::forgetLook();
            Audit::log('admin.branding.theme_reset');
            flash('ok', 'Look reset to the default.');
        } elseif ($action === 'reset') {
            Branding::removeLogo();
            Settings::forget('brand_name');
            self::forgetLook();
            Audit::log('admin.branding.reset');
            flash('ok', 'Branding reset to defaults.');
        }
        redirect('/admin/branding');
    }

    private static function forgetLook(): void
    {
        foreach (['preset', 'bg', 'panel', 'text', 'accent', 'radius', 'r_tl', 'r_tr', 'r_br', 'r_bl'] as $k) {
            Settings::forget('theme_' . $k);
        }
        foreach (['look', 'btn', 'card', 'dens', 'font', 'caps'] as $k) {
            Settings::forget('ui_' . $k);
        }
    }

    public function themeCss(): void
    {
        \App\Theme::serve();
    }

    public function logo(): void
    {
        Branding::serve();
    }
}
