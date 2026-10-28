# Game Panel

A game-server control panel in PHP: web panel + node daemon + Docker. Every game server runs in its own locked-down container.

```
Browser ──HTTPS──▶ panel/ (PHP, DB, UI) ──HMAC-signed HTTPS──▶ daemon/ (on each node) ──docker.sock──▶ one container per server
```

© Game Panel. All rights reserved.

## Install on Ubuntu 22.04 / 24.04

Run as root from the project folder. Both commands are idempotent.

**Panel** (nginx + php-fpm + SQLite, TLS via Let's Encrypt, ufw, fail2ban):
```bash
sudo bash install/ubuntu.sh panel --domain panel.example.com --email you@example.com
```
The admin password is printed once at the end. Optional env vars before the command: `VL_HOLDER`, `VL_RECAPTCHA_SITE`, `VL_RECAPTCHA_SECRET`.

**Node** (Docker, hardened `daemon.json`, php-fpm pool as an unprivileged user, TLS on :8443, container firewall):
1. In the panel: *Locations → add*, *Nodes → add*. Copy the secret shown **once**.
2. On the node machine:
```bash
sudo bash install/ubuntu.sh daemon --fqdn node1.example.com --email you@example.com \
     --secret <secret> --panel-ip <panel public IP>
```
Without `--email` a self-signed certificate is created; the script tells you how to pin it in the panel (`daemon_ca`).

3. Panel → *Nodes → your node → Allocations* (IP + ports), *Eggs* (presets are pre-installed), *Servers → Create*.

> Dedicate node machines to this. Docker-group membership is root-equivalent on that host.

## Why servers can't "break out"
Each server is its own container created by the daemon with: non-root uid, **all capabilities dropped**, `no-new-privileges`, **read-only root filesystem** (only `/home/container` and a small `/tmp` are writable), memory+swap/CPU/PID limits, private IPC, `init`, log caps, default seccomp + AppArmor profiles, no privileged mode, only its own data folder mounted.
Network: a dedicated bridge with **inter-container traffic disabled**, and the `vorlane-firewall` service which blocks containers from reaching the node itself, the LAN, any private range and cloud-metadata (169.254.0.0/16) — players can still connect and containers still reach the internet.
Installs (egg install scripts) run in a separate sandboxed container with the same restrictions. File access is path-jailed (no `..`, no symlinks). "Pull URL" downloads are SSRF-hardened (https/443 only, public IPv4 only, pinned DNS, size cap).

## Account & panel security
Argon2id + password policy · login rate-limit/lockout · **Google reCAPTCHA v2 on login** (set keys in `config.php`) · TOTP 2FA with hashed recovery codes (**mandatory for admins**) · revocable DB sessions, `__Host-` SameSite=Strict cookies · CSRF + Origin checks · strict CSP (no inline JS/CSS; relaxed only on `/login` when reCAPTCHA is on) · password re-entry for sensitive actions · full audit log · Bearer API keys (hashed, IP allow-list) · panel↔node HMAC with replay protection.

## Eggs
Presets: **Node.js, Python, Minecraft (Paper), Java, Rust, Counter-Strike 2, Hytale**. In *Admin → Eggs* you can edit them, **clone**, **export/import JSON**, or **build your own** (image, install image/script, startup, stop command, variables with regex validation).
Presets use community image names (`ghcr.io/ptero-eggs/...`, `ghcr.io/pterodactyl/yolks`) — verify tags before production. Notes: Rust needs 8 GB+ RAM; CS2 ~60 GB disk and a GSLT token; **Hytale** needs Java 25 and you must supply `HytaleServer.jar` + `Assets.zip` yourself (Files → *Pull URL*) and run `/auth login device` in the console — launch flags are community-documented, so check `--help`.

## UI protection & building (for the owner)
The look and the copyright notice are compiled and signed:
- Sources live in `panel/resources/{css,js}`; `php bin/build.php` bundles them into content-hashed files in `public/assets`.
- `build/seal.json` lists the SHA-256 of every view, resource, built asset and the brand module, signed with an **Ed25519 private key** (`.seal/seal.key` — **move it offline, never ship it**). The panel verifies it on every request and refuses to run (HTTP 503) if anything was changed.
- After you change any UI file: `php bin/build.php --key=../.seal/seal.key`.
- Honest limit: this is deterrence, not DRM. Anyone with the PHP source can remove the check from `App.php`; use a licence/legal notice and ship only what customers need.

## Not included yet
Sub-users, backups, schedules, websocket console (2 s polling), file upload from the browser (use *Pull URL*), 2FA QR code (manual key), e-mail reset, WebAuthn.

## Accounts, passwords and 2FA

- **Sign in** with username or e-mail (case-insensitive).
- **Forgot password**: `/forgot` e-mails a one-hour, single-use link. Configure SMTP (`mail_*`, `app_url`) in `panel/config/config.php`; without SMTP the message is written to `panel/storage/outbox/`.
- **Locked out?** `php bin/reset-password.php <user|email> [--password=...] [--disable-2fa]` (run on the server from `panel/`).
- **2FA**: Account > Security shows a QR code (generated locally, no external service), the manual key, and one-time recovery codes.
- Installer env vars are `VL_*` (e.g. `VL_URL`, `VL_MAIL_HOST`, `VL_ADMIN_USER`).
