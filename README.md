<div align="center">

<img src="docs/banner.svg" alt="Game Panel" width="100%">

<br>

![PHP](https://img.shields.io/badge/PHP-8.3-4f5b93?style=for-the-badge&logo=php&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-per--server%20containers-2496ed?style=for-the-badge&logo=docker&logoColor=white)
![Ubuntu](https://img.shields.io/badge/Ubuntu-22.04%20%7C%2024.04-e95420?style=for-the-badge&logo=ubuntu&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-4fd1a1?style=for-the-badge)

**A game-server control panel written in PHP.**
One web panel, one small daemon per machine, and a hardened Docker container for every server.

[Install](#-install) · [Features](#-features) · [How it works](#-how-it-works) · [API](#-api-reference) · [Eggs](#-eggs) · [Security](#-security-model) · [Development](#-local-development)

</div>

---

## ✦ Features

<table>
<tr>
<td width="50%" valign="top">

### Running servers
- Console with live stats (CPU, memory, disk, network)
- Start, stop, restart and kill, with graceful stop commands
- File manager with editor, rename, folders and *Pull URL*
- Per-server memory, CPU, disk and **OOM-killer** switch
- Auto or custom ports, next free port picked for you
- Address shown is the **node's own host**, with a copy button

</td>
<td width="50%" valign="top">

### Plugins and versions
- **Plugin browser** for Modrinth, Spigot and CurseForge
- One click install into `/plugins`
- Minecraft versions checked against the real published lists
  (Paper, Purpur, Fabric, Vanilla) **before** a server is made
- Egg picker with Nest and Egg selects

</td>
</tr>
<tr>
<td valign="top">

### Teams and access
- **Roles** with 25 granular permissions and custom colours
- **Sub-users** per server with their own permissions
- Per-server activity feed and a full audit log
- Bearer-token API keys with optional IP allow-list

</td>
<td valign="top">

### Administration
- Nodes, locations, allocations, maintenance mode
- Monitoring with live donuts and detected hardware
- Database hosts with per-server databases
- Branding, three looks and per-user themes
- Egg editor, clone, export and import (Pterodactyl JSON too)

</td>
</tr>
</table>

---

## ✦ How it works

<div align="center">
<img src="docs/architecture.svg" alt="Browser to panel to daemon to containers" width="100%">
</div>

| Part | Folder | Job |
|---|---|---|
| **Panel** | [`panel/`](panel) | Accounts, roles, the web UI, the database and the public API. Never touches Docker. |
| **Daemon** | [`daemon/`](daemon) | Runs on every node. Receives signed requests, talks to Docker, serves files. |
| **Installer** | [`install/`](install) | `ubuntu.sh` sets up either side, TLS, firewall and the container network. |

The panel and each daemon share a secret created when you add the node. Every request between them is signed (see [Daemon API](#daemon-api)).

---

## ✦ Install

> Ubuntu 22.04 or 24.04, run as root, from the project folder. Both commands can be run again safely.

### 1 · The panel

```bash
sudo bash install/ubuntu.sh panel --domain panel.example.com --email admin@example.com
```

nginx, php-fpm, SQLite, a Let's Encrypt certificate, `ufw` and `fail2ban` are set up. A daemon is installed on the same machine and registered as the **localhost** node (add `--no-daemon` to skip that). The administrator password is printed once at the end.

Optional environment variables before the command: `VL_HOLDER`, `VL_RECAPTCHA_SITE`, `VL_RECAPTCHA_SECRET`, `VL_URL`, `VL_ADMIN_USER`, `VL_MAIL_HOST`.

### 2 · More nodes

1. In the panel add a **Location**, then a **Node**. The secret is shown **once**.
2. On the node machine:

```bash
sudo bash install/ubuntu.sh daemon --fqdn node1.example.com --email admin@example.com \
     --secret <secret from the panel> --panel-ip <panel public IP>
```

Without `--email` a self-signed certificate is created and the script explains how to pin it (`daemon_ca`).

3. Open **Nodes → your node → Allocations**, add IP and ports, then **Servers → New server**.

> Give nodes their own machines. Membership of the `docker` group is root-equivalent on that host.

---

## ✦ Eggs

An egg describes one game: image, install script, startup command, stop command and variables (with optional regex and dropdown options).

| Preset | Notes |
|---|---|
| **Minecraft: Java** | Paper, Purpur, Fabric or Vanilla. Version is verified; `server.jar` is downloaded for the version you type; `server.properties` is written for you. |
| **Node.js** · **Python** · **Java** | Generic runtimes. |
| **Rust** | Needs 8 GB RAM or more. |
| **Counter-Strike 2** | About 60 GB disk and a GSLT token. |
| **Hytale** | You supply `HytaleServer.jar` and `Assets.zip` yourself. |

Under **Admin → Eggs** you can edit, clone, export or import eggs, including Pterodactyl egg files. Preset image tags come from community registries, so check them before production.

---

## ✦ Roles and sub-users

Roles give a non-administrator access to parts of the admin area. Every permission has a *view* level and optional *action* levels:

```
servers            View servers
├─ servers.create      Create servers
├─ servers.suspend     Suspend / unsuspend
├─ servers.delete      Delete
└─ servers.databases   Manage server databases
nodes → create · edit · secret · delete        users → create · manage
locations → edit      eggs → edit      databases → manage      settings / branding → edit
```

Permissions are enforced on the server, not only hidden in the UI. Ticking an action ticks its *view* permission too.

**Sub-users** are per server: `console`, `power`, `files`, `plugins`, `settings`. Only the owner and administrators manage them.

---

## ✦ API reference

Three layers, from outside in.

### Application API

Bearer tokens, no cookies, no CSRF. Create a key under **Account → API keys**. The token looks like `gp_<16 hex>.<48 hex>`, is shown once and stored hashed. Limit: 120 requests per minute per IP.

```http
Authorization: Bearer gp_0123456789abcdef.0123456789abcdef0123456789abcdef0123456789abcdef
```

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/servers` | Servers you own. |
| `GET` | `/api/v1/servers/{uuid}` | One server with address and live resources. |
| `POST` | `/api/v1/servers/{uuid}/power` | Body `{"action":"start\|stop\|restart\|kill"}`. |

```bash
curl -s https://panel.example.com/api/v1/servers \
  -H "Authorization: Bearer $TOKEN"
```
```json
{ "data": [ { "uuid": "7b81694e-…", "name": "Survival", "status": "active", "memory": 2048, "cpu": 100, "disk": 5000 } ] }
```

```bash
curl -s -X POST https://panel.example.com/api/v1/servers/$UUID/power \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"action":"restart"}'
```
```json
{ "ok": true }
```

| Status | Meaning |
|---|---|
| `401` | Missing or wrong token. |
| `403` | Source IP not on the key's allow-list. |
| `422` | Server not active, or invalid action. |
| `429` | Rate limited. |
| `502` | The node could not be reached. |

### Panel endpoints

Used by the web UI. They need a signed-in session; `POST` routes also need the CSRF token (`X-CSRF-Token`).

<details>
<summary><b>Server</b> — <code>/server/{uuid}</code></summary>

| Method | Path | Needs | Purpose |
|---|---|---|---|
| `GET` | `/server/{uuid}` | console | Console page |
| `GET` | `/server/{uuid}/state` | console | State and resource usage (JSON) |
| `GET` | `/server/{uuid}/logs` | console | Recent console output |
| `POST` | `/server/{uuid}/power` | power | `action=start\|stop\|restart\|kill` |
| `POST` | `/server/{uuid}/command` | console | `command=<text>` |
| `GET` | `/server/{uuid}/activity` | console | Activity feed |
| `GET` `POST` | `/server/{uuid}/settings` | settings | Name and startup variables |
| `GET` | `/server/{uuid}/files` | files | File manager page |
| `GET` | `/server/{uuid}/files/list?path=` | files | Directory listing |
| `GET` | `/server/{uuid}/files/read?path=` | files | File content (2 MB limit) |
| `POST` | `/server/{uuid}/files/write` | files | `path`, `content` |
| `POST` | `/server/{uuid}/files/mkdir` · `delete` · `rename` | files | Folder and file operations |
| `POST` | `/server/{uuid}/files/pull` | files | `path`, `url` — download an https file |
| `GET` | `/server/{uuid}/plugins` | plugins | Plugin browser page |
| `GET` | `/server/{uuid}/plugins/search?source=&q=` | plugins | `source` is `modrinth`, `spigot` or `curseforge` |
| `POST` | `/server/{uuid}/plugins/install` | plugins | `source`, `id` |
| `GET` `POST` | `/server/{uuid}/subusers` | owner | List or add (`who`, `perms[]`) |
| `POST` | `/server/{uuid}/subusers/{id}/remove` | owner | Remove access |

</details>

<details>
<summary><b>Account</b> — <code>/account</code></summary>

| Method | Path | Purpose |
|---|---|---|
| `GET` `POST` | `/login` · `/login/2fa` | Sign in |
| `POST` | `/logout` | Sign out |
| `GET` `POST` | `/forgot` · `/reset/{token}` | Password reset |
| `POST` | `/account/password` | Change password |
| `POST` | `/account/2fa/start` · `enable` · `disable` · `recovery` | Two-factor |
| `POST` | `/account/sessions/revoke` | Sign out other devices |
| `POST` | `/account/avatar` · `/account/theme` | Profile picture and theme |
| `GET` `POST` | `/account/api` · `/account/api/delete` | API keys |

</details>

<details>
<summary><b>Administration</b> — <code>/admin</code> (needs the matching role permission)</summary>

| Area | Routes |
|---|---|
| Servers | `GET /admin/servers` · `/new` · `/check-version?type=&v=` · `POST /admin/servers` · `POST /admin/servers/action` |
| Nodes | `/admin/nodes` · `/{id}` · `POST …/allocations` · `…/maintenance` · `…/update` · `…/rotate` · `…/delete` · `POST /admin/nodes/local` |
| Locations | `/admin/locations` · `POST` create / delete |
| Eggs | `/admin/eggs` · `/{id}` · `/{id}/export` · `POST /import` · `/preset` · `/{id}/clone` · `/{id}/delete` |
| Users | `/admin/users` · `POST /admin/users` · `POST /admin/users/action` |
| Roles | `/admin/roles` · `/{id}` · `POST /{id}/delete` |
| Databases | `/admin/databases` · `POST /hosts` · `POST /admin/servers/{id}/databases` (+ `rotate`, `delete`) |
| Panel | `/admin/monitoring` · `/admin/audit` · `/admin/settings` · `/admin/branding` · `/admin/theme` |

`GET /admin/servers/check-version?type=paper&v=1.21.11` returns `{"ok":true}` or `{"ok":false,"error":"…"}`.

</details>

### Daemon API

Runs on each node, port `8443` by default. Not meant for people: only the panel calls it.

**Signing.** Every request carries three headers:

| Header | Value |
|---|---|
| `X-GP-Ts` | Unix time in seconds. Rejected when more than 30 s off. |
| `X-GP-Nonce` | 24 random hex characters. Each one is accepted once. |
| `X-GP-Sig` | `HMAC-SHA256(secret, ts \n nonce \n METHOD \n path \n sha256(body))` in hex. |

```php
$sig = hash_hmac('sha256', implode("\n", [$ts, $nonce, 'POST', $path, hash('sha256', $json)]), $secret);
```

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/system` | Version, Docker, disk, load, running count, hardware |
| `POST` | `/api/servers` | Create a server and run its install in the background |
| `GET` | `/api/servers/{uuid}` | Container state and install status |
| `PUT` | `/api/servers/{uuid}` | Recreate the container after changes |
| `DELETE` | `/api/servers/{uuid}` | Remove container and data |
| `GET` | `/api/servers/{uuid}/stats` | CPU, memory, network, disk |
| `POST` | `/api/servers/{uuid}/power` | `start`, `stop`, `restart`, `kill` |
| `GET` | `/api/servers/{uuid}/logs` | Console output |
| `POST` | `/api/servers/{uuid}/command` | Send a console command |
| `GET` `POST` | `/api/servers/{uuid}/files/{list,read,write,pull,mkdir,delete,rename}` | Jailed file operations |

---

## ✦ Security model

<table>
<tr>
<td width="50%" valign="top">

**Around every server**
- Non-root user, **all capabilities dropped**
- `no-new-privileges`, default seccomp and AppArmor
- **Read-only root filesystem**; only the server folder and a small `/tmp` are writable
- Memory, swap, CPU and PID limits; private IPC
- Dedicated bridge network with container-to-container traffic off
- `vorlane-firewall` stops containers reaching the host, the LAN, private ranges and cloud metadata
- Installs run in their own sandboxed container
- File paths are jailed: no `..`, no symlinks
- *Pull URL* is SSRF-hardened: https on 443, public IPv4 only, pinned DNS, size cap

</td>
<td width="50%" valign="top">

**Around the panel**
- Argon2id and a password policy; login lockout
- reCAPTCHA v2 on login (optional)
- TOTP two-factor with hashed recovery codes, **required for admins**
- Revocable database sessions; `__Host-` `SameSite=Strict` cookies
- CSRF and Origin checks
- Strict CSP: no inline scripts or styles
- Password re-entry for sensitive actions
- Hashed API keys with IP allow-list
- Signed panel-to-node calls with replay protection
- Full audit log

</td>
</tr>
</table>

---

## ✦ Local development

You do not need Docker to try the panel. On Windows or macOS run the panel and the local daemon, which starts servers as plain processes:

```bash
php -S 127.0.0.1:8080 -t panel/public panel/public/index.php   # the panel
php -S 127.0.0.1:8443 daemon/dev/mock.php                      # the local daemon
```

Add a node named `localhost` pointing at `127.0.0.1:8443` over `http` (the panel offers a one-click **Add localhost** button). The local daemon really downloads the Minecraft jar, starts `java`, streams the console and accepts commands. It needs Java on `PATH` and gives **no isolation and no resource limits**: use the real daemon on Ubuntu for that.

If HTTPS calls to plugin sites fail on Windows with a certificate error, start PHP with `-d curl.cainfo=<path to a CA bundle>`.

---

## ✦ Project layout

```
panel/
├─ src/            App, Router, controllers, Roles, Theme, PluginSources, …
├─ views/          admin/ · servers/ · account/ · layouts/ · partials/
├─ resources/      css/ and js/ sources
├─ public/         index.php and built assets
├─ database/       schema.sql
└─ bin/            install, build, add-node, add-dbhost, reset-password
daemon/
├─ public/index.php   signed HTTP API
├─ src/               Docker, Server, Files
└─ dev/               local no-Docker daemon
install/              ubuntu.sh and the container firewall
docs/                 README artwork
```

### Configuration

`panel/config/config.php` (created by the installer, never committed) holds `app_url`, `db`, the reCAPTCHA keys, `mail_*` for password-reset e-mail, `trusted_proxies` and `app_key`. Without SMTP, reset mails are written to `panel/storage/outbox/`. A locked-out admin can run `php bin/reset-password.php <user> [--password=…] [--disable-2fa]`.

### Build and integrity seal

Sources in `panel/resources` are bundled into content-hashed files by:

```bash
php bin/build.php --key=../.seal/seal.key
```

`build/seal.json` lists the SHA-256 of every view, resource and built asset and is signed with an Ed25519 key. The panel checks it on every request and answers `503` if something changed. The private key (a base64 Ed25519 secret key) is **not** in this repository, and the matching public key is compiled into `src/Brand/Seal.php`. To build your own copy, generate a key pair with libsodium, replace the public key in `Seal.php`, then run the build above with your private key. Keep that key offline.

---

## ✦ Not included yet

Backups, schedules, a websocket console (the console polls every 2 seconds), browser file uploads (use *Pull URL*) and WebAuthn.

---

## ✦ License

Released under the [MIT License](LICENSE).
