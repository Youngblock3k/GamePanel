<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;

final class AuthController extends Controller
{
    public function loginForm(): void
    {
        $this->view('auth/login', ['title' => 'Sign in', 'layout' => 'guest']);
    }

    public function login(): void
    {
        // Bot check first: a failed captcha never reaches the password check or burns an account's attempts.
        if (!\App\Captcha::verify((string)($_POST['g-recaptcha-response'] ?? ''))) {
            \App\Audit::log('auth.captcha_failed', $this->in('login'), [], null);
            flash('error', 'Please complete the "I\'m not a robot" check.');
            redirect('/login');
        }
        $result = Auth::attempt($this->in('login'), (string)($_POST['password'] ?? ''));
        switch ($result) {
            case 'ok':
                redirect('/');
            case '2fa':
                redirect('/login/2fa');
            case 'locked':
                flash('error', 'Too many attempts. Try again in 15 minutes.');
                break;
            default:
                flash('error', 'Invalid credentials.');
        }
        redirect('/login');
    }

    public function twoFaForm(): void
    {
        if (!Auth::pendingUser()) {
            redirect('/login');
        }
        $this->view('auth/twofa', ['title' => 'Two-factor authentication', 'layout' => 'guest']);
    }

    public function twoFa(): void
    {
        if (!Auth::pendingUser()) {
            redirect('/login');
        }
        if (Auth::verifySecondFactor($this->in('code'))) {
            redirect('/');
        }
        flash('error', Auth::pendingUser() ? 'Invalid code.' : 'Too many failed attempts. Please sign in again.');
        redirect(Auth::pendingUser() ? '/login/2fa' : '/login');
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('/login');
    }
}
