#!/usr/bin/env bash
# Game Panel installer for Ubuntu 22.04 / 24.04 (run as root).
#
#   Panel : sudo bash install/ubuntu.sh panel  --domain panel.example.com --email you@example.com [--admin-user admin --admin-email you@example.com] [--no-daemon]
#           (the panel install also sets up the daemon on the same machine and registers it as node "local"; pass --no-daemon to skip)
#   Node  : sudo bash install/ubuntu.sh daemon --fqdn node1.example.com --secret <secret from panel> --panel-ip 203.0.113.10 [--email you@example.com]
#
# Omit --domain/--email on the panel for plain HTTP (testing only). Omit --email on a node for a self-signed certificate.
set -euo pipefail

die() { echo "ERROR: $*" >&2; exit 1; }
info() { echo -e "\n\033[1;36m==> $*\033[0m"; }

[ "$(id -u)" -eq 0 ] || die "run as root (sudo)"
. /etc/os-release
[ "${ID:-}" = "ubuntu" ] || die "this installer supports Ubuntu only"

ROLE="${1:-}"; shift || true
[ "$ROLE" = "panel" ] || [ "$ROLE" = "daemon" ] || die "usage: ubuntu.sh <panel|daemon> [options]   (panel also installs a local daemon unless --no-daemon is given)"

NO_DAEMON=0; NO_DB=0; LOCAL=0; DOMAIN=""; EMAIL=""; FQDN=""; SECRET=""; PANEL_IP=""; ADMIN_USER="admin"; ADMIN_EMAIL=""
while [ $# -gt 0 ]; do
  case "$1" in
    --domain) DOMAIN="$2"; shift 2;;
    --email) EMAIL="$2"; shift 2;;
    --fqdn) FQDN="$2"; shift 2;;
    --secret) SECRET="$2"; shift 2;;
    --panel-ip) PANEL_IP="$2"; shift 2;;
    --admin-user) ADMIN_USER="$2"; shift 2;;
    --admin-email) ADMIN_EMAIL="$2"; shift 2;;
    --no-daemon) NO_DAEMON=1; shift;;
    --no-database) NO_DB=1; shift;;
    --local) LOCAL=1; FQDN="127.0.0.1"; PANEL_IP="127.0.0.1"; shift;;
    *) die "unknown option $1";;
  esac
done

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export DEBIAN_FRONTEND=noninteractive

php_version() { php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'; }

base_packages() {
  info "Installing packages (PHP from the ondrej PPA so it tracks upstream security fixes)"
  apt-get update -y
  apt-get install -y software-properties-common ca-certificates
  add-apt-repository -y ppa:ondrej/php
  apt-get update -y
  PHPV="8.3"
  apt-get install -y nginx "php${PHPV}-fpm" "php${PHPV}-cli" "php${PHPV}-curl" "php${PHPV}-mbstring" "php${PHPV}-sqlite3" "php${PHPV}-mysql" "php${PHPV}-gd"     openssl rsync curl ufw fail2ban unattended-upgrades "$@"
  update-alternatives --set php "/usr/bin/php${PHPV}" || true
  apt-get -y upgrade
  # php2shell (CVE-2026-109618 / CVE-2026-109619): refuse to run on anything older than the fixed releases.
  php -r 'exit(version_compare(PHP_VERSION, ["8.2"=>"8.2.35","8.3"=>"8.3.36","8.4"=>"8.4.27","8.5"=>"8.5.12"][PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION] ?? "99", ">=") ? 0 : 1);'     || die "PHP $(php -r 'echo PHP_VERSION;') is vulnerable to CVE-2026-109618/109619. Run: apt update && apt upgrade, then retry."
  php -m | grep -qi sodium || die "PHP sodium extension missing"
  systemctl enable --now "php${PHPV}-fpm" nginx
  harden_host
}

# Kernel + fail2ban hardening shared by panel and node (SYN-flood cookies, spoofing filters, brute-force bans).
harden_host() {
  info "Kernel and brute-force hardening"
  cat > /etc/sysctl.d/99-gamepanel.conf <<'EOF'
net.ipv4.tcp_syncookies = 1
net.ipv4.tcp_max_syn_backlog = 4096
net.ipv4.tcp_synack_retries = 2
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.all.send_redirects = 0
net.ipv4.icmp_echo_ignore_broadcasts = 1
net.ipv4.icmp_ignore_bogus_error_responses = 1
EOF
  sysctl --system >/dev/null 2>&1 || true
  mkdir -p /etc/fail2ban/jail.d
  cat > /etc/fail2ban/jail.d/gamepanel.local <<'EOF'
[sshd]
enabled = true
maxretry = 5
findtime = 10m
bantime = 1h

[nginx-limit-req]
enabled = true
port = http,https
logpath = /var/log/nginx/error.log
maxretry = 8
findtime = 1m
bantime = 1h

[nginx-botsearch]
enabled = true
port = http,https
logpath = /var/log/nginx/access.log
maxretry = 5
bantime = 1d
EOF
  systemctl enable --now fail2ban >/dev/null 2>&1 || true
  systemctl restart fail2ban >/dev/null 2>&1 || true
}

# Shared php-fpm hardening: mitigates CVE-2026-109619 (upload progress) and limits what a PHP RCE could do.
fpm_pool() { # name user socket extra-lines
  cat > "/etc/php/${PHPV}/fpm/pool.d/$1.conf" <<POOL
[$1]
user = $2
group = $2
listen = $3
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 20
pm.process_idle_timeout = 30s
env[PATH] = /usr/local/bin:/usr/bin:/bin
php_admin_value[expose_php] = off
php_admin_value[session.upload_progress.enabled] = 0
php_admin_value[allow_url_fopen] = off
php_admin_value[allow_url_include] = off
$4
POOL
}

# ----------------------------------------------------------------------------------------------
install_panel() {
  base_packages certbot python3-certbot-nginx
  local APP=/var/www/vorlane

  info "Copying panel to $APP"
  install -d -m 755 "$APP"
  rsync -a --delete --exclude config --exclude storage --exclude '*.bak' "$SRC/panel/" "$APP/panel/"
  install -d -m 750 "$APP/panel/config" "$APP/panel/storage"

  [ -f "$APP/panel/build/seal.json" ] || die "build/seal.json missing: this is not an official signed release"

  local ADMIN_PASS
  ADMIN_PASS="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 20)Aa1"
  [ -n "$ADMIN_EMAIL" ] || ADMIN_EMAIL="${EMAIL:-admin@localhost.invalid}"

  if [ ! -f "$APP/panel/config/config.php" ]; then
    info "Running the panel installer"
    (cd "$APP/panel" && VL_DB=sqlite VL_NAME="Game Panel" VL_HOLDER="${VL_HOLDER:-Game Panel}" \
       VL_RECAPTCHA_SITE="${VL_RECAPTCHA_SITE:-}" VL_RECAPTCHA_SECRET="${VL_RECAPTCHA_SECRET:-}" \
       VL_URL="${DOMAIN:+https://$DOMAIN}" VL_MAIL_HOST="${VL_MAIL_HOST:-}" VL_MAIL_USER="${VL_MAIL_USER:-}" VL_MAIL_PASS="${VL_MAIL_PASS:-}" VL_MAIL_FROM="${VL_MAIL_FROM:-no-reply@${DOMAIN:-localhost}}" VL_ADMIN_USER="$ADMIN_USER" VL_ADMIN_EMAIL="$ADMIN_EMAIL" VL_ADMIN_PASS="$ADMIN_PASS" php bin/install.php </dev/null)
    SHOW_PASS=1
  else
    info "config.php exists - keeping existing install (panel files updated)"
    SHOW_PASS=0
  fi

  # Code is read-only to the web user; only storage/ is writable.
  chown -R root:www-data "$APP"
  find "$APP" -type d -exec chmod 750 {} +
  find "$APP" -type f -exec chmod 640 {} +
  chown -R www-data:www-data "$APP/panel/storage"; chmod -R u+rwX,g-rwx,o-rwx "$APP/panel/storage"
  chmod 640 "$APP/panel/config/config.php"
  install -d -o www-data -g www-data -m 700 "$APP/panel/storage/sessions"
  chmod 755 "$APP" "$APP/panel" "$APP/panel/public" "$APP/panel/public/assets"
  chmod 644 "$APP"/panel/public/assets/* "$APP/panel/public/index.php"

  info "Dedicated, locked-down php-fpm pool for the panel"
  rm -f "/etc/php/${PHPV}/fpm/pool.d/www.conf"
  fpm_pool vorlane-panel www-data /run/php/vl-panel.sock "php_admin_value[open_basedir] = $APP/panel/:/tmp/
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,show_source,highlight_file
php_admin_value[upload_max_filesize] = 1M
php_admin_value[post_max_size] = 2M
php_admin_value[session.use_strict_mode] = 1
php_admin_value[session.save_path] = $APP/panel/storage/sessions"
  systemctl restart "php${PHPV}-fpm"

  info "Configuring nginx"
  cat > /etc/nginx/conf.d/vorlane-limits.conf <<'NGX'
limit_req_zone $binary_remote_addr zone=vl_login:10m rate=20r/m;
limit_req_zone $binary_remote_addr zone=vl_api:10m rate=120r/m;
limit_req_zone $binary_remote_addr zone=vl_all:10m rate=20r/s;
limit_conn_zone $binary_remote_addr zone=vl_conn:10m;
limit_req_status 429;
limit_conn_status 429;
NGX
  cat > /etc/nginx/snippets/vorlane-php.conf <<NGX
include fastcgi_params;
fastcgi_param SCRIPT_FILENAME $APP/panel/public/index.php;
fastcgi_param SCRIPT_NAME /index.php;
fastcgi_pass unix:/run/php/vl-panel.sock;
fastcgi_read_timeout 60s;
NGX
  cat > /etc/nginx/sites-available/vorlane <<NGX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN:-_};
    root $APP/panel/public;
    server_tokens off;
    client_max_body_size 2m;
    client_body_timeout 10s;
    client_header_timeout 10s;
    send_timeout 15s;
    keepalive_timeout 15s;
    reset_timedout_connection on;
    limit_conn vl_conn 40;
    limit_req zone=vl_all burst=80 nodelay;

    location /assets/ { expires 30d; add_header Cache-Control "public, immutable"; try_files \$uri =404; }
    location = /login { limit_req zone=vl_login burst=10 nodelay; include snippets/vorlane-php.conf; }
    location = /login/2fa { limit_req zone=vl_login burst=10 nodelay; include snippets/vorlane-php.conf; }
    location /api/ { limit_req zone=vl_api burst=40 nodelay; include snippets/vorlane-php.conf; }
    location ~ /\.(?!well-known) { deny all; }
    location ~ \.php\$ { return 404; }
    location / { include snippets/vorlane-php.conf; }
}
NGX
  ln -sf /etc/nginx/sites-available/vorlane /etc/nginx/sites-enabled/vorlane
  rm -f /etc/nginx/sites-enabled/default
  nginx -t && systemctl reload nginx

  if [ -n "$DOMAIN" ] && [ -n "$EMAIL" ]; then
    info "Requesting a Let's Encrypt certificate for $DOMAIN"
    certbot --nginx -d "$DOMAIN" -m "$EMAIL" --agree-tos --no-eff-email --redirect --non-interactive
  else
    echo "WARNING: no --domain/--email given -> serving plain HTTP. Sessions/2FA/passwords are NOT protected. Use only for testing."
  fi

  base_firewall 80/tcp 443/tcp

  echo
  echo "================ Game Panel panel installed ================"
  if [ -n "$DOMAIN" ] && [ -n "$EMAIL" ]; then echo " URL        : https://$DOMAIN"; else echo " URL        : http://${DOMAIN:-<server-ip>}  (no TLS!)"; fi
  if [ "${SHOW_PASS:-0}" = "1" ]; then
    echo " Admin user : $ADMIN_USER"
    echo " Admin pass : $ADMIN_PASS   <-- shown once; change it and enable 2FA (required for admins)"
  fi
  echo " Next       : Locations -> Nodes -> (install a node) -> Allocations -> Eggs -> Servers"
  echo "============================================================"
}

# ----------------------------------------------------------------------------------------------
base_firewall() {
  info "Configuring firewall (ufw)"
  ufw allow OpenSSH >/dev/null
  for p in "$@"; do ufw allow "$p" >/dev/null; done
  ufw --force enable >/dev/null
}

install_daemon() {
  [ "$LOCAL" = "1" ] && [ -z "$FQDN" ] && FQDN=127.0.0.1
  [ -n "$FQDN" ] || die "--fqdn is required for a node"
  [ ${#SECRET} -ge 32 ] || die "--secret is required (copy the secret shown once when you created the node in the panel)"
  base_packages docker.io iptables certbot

  info "Creating service user and directories"
  id gamepanel >/dev/null 2>&1 || useradd -r -u 988 -m -d /var/lib/gamepanel -s /usr/sbin/nologin gamepanel
  usermod -aG docker gamepanel
  install -d -o gamepanel -g gamepanel -m 750 /var/lib/gamepanel /var/lib/gamepanel/servers /var/lib/gamepanel/state

  info "Hardening Docker"
  [ -f /etc/docker/daemon.json ] && cp -n /etc/docker/daemon.json /etc/docker/daemon.json.orig
  cat > /etc/docker/daemon.json <<'JSON'
{
  "no-new-privileges": true,
  "userland-proxy": false,
  "live-restore": true,
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "2" },
  "default-ulimits": { "nofile": { "Name": "nofile", "Hard": 65536, "Soft": 65536 } }
}
JSON
  systemctl enable --now docker
  systemctl restart docker

  info "Installing daemon"
  local D=/opt/vorlane/daemon
  install -d -m 755 /opt/vorlane
  rsync -a --delete --exclude config.php "$SRC/daemon/" "$D/"
  local ALLOWED="[]"; [ -n "$PANEL_IP" ] && ALLOWED="['$PANEL_IP']"
  cat > "$D/config.php" <<PHP
<?php
return [
    'secret' => '$SECRET',
    'allowed_ips' => $ALLOWED,
    'data_dir' => '/var/lib/gamepanel/servers',
    'state_dir' => '/var/lib/gamepanel/state',
    'docker_socket' => '/var/run/docker.sock',
    'container_uid' => 988,
    'container_gid' => 988,
    'network' => 'vorlane0',
    'network_subnet' => '172.30.0.0/16',
    'php_cli' => '$(command -v php)',
];
PHP
  chown -R root:gamepanel "$D"; chmod -R 750 "$D"; chmod 640 "$D/config.php"

  info "Dedicated php-fpm pool for the daemon (runs as the 'gamepanel' user)"
  fpm_pool vorlane-daemon gamepanel /run/php/vl-daemon.sock "env[HOME] = /var/lib/gamepanel
request_terminate_timeout = 3700
php_admin_value[open_basedir] = /opt/vorlane/daemon/:/var/lib/gamepanel/:/tmp/"
  systemctl restart "php${PHPV}-fpm"

  info "TLS certificate"
  local CRT KEY
  if [ "$LOCAL" = "1" ]; then
    echo "local node: the daemon only listens on 127.0.0.1, no certificate needed"
  elif [ -n "$EMAIL" ]; then
    systemctl stop nginx
    certbot certonly --standalone -d "$FQDN" -m "$EMAIL" --agree-tos --no-eff-email --non-interactive
    systemctl start nginx
    CRT="/etc/letsencrypt/live/$FQDN/fullchain.pem"; KEY="/etc/letsencrypt/live/$FQDN/privkey.pem"
    echo "deploy hook: reload nginx on renewal"
    printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/vorlane-nginx.sh
    chmod +x /etc/letsencrypt/renewal-hooks/deploy/vorlane-nginx.sh
  else
    install -d -m 750 /etc/vorlane
    CRT=/etc/vorlane/daemon.crt; KEY=/etc/vorlane/daemon.key
    if [ ! -f "$CRT" ]; then
      local SAN="DNS:$FQDN"; [[ "$FQDN" =~ ^[0-9.]+$ ]] && SAN="IP:$FQDN"
      openssl req -x509 -newkey rsa:4096 -sha256 -days 3650 -nodes -keyout "$KEY" -out "$CRT" -subj "/CN=$FQDN" -addext "subjectAltName=$SAN"
      chmod 600 "$KEY"
    fi
    SELF_SIGNED=1
  fi

  local LISTEN="listen 8443 ssl;"
  local SSL_CONF="ssl_certificate $CRT;
    ssl_certificate_key $KEY;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;"
  if [ "$LOCAL" = "1" ]; then LISTEN="listen 127.0.0.1:8443;"; SSL_CONF=""; fi
  cat > /etc/nginx/sites-available/vorlane-daemon <<NGX
server {
    $LISTEN
    server_name $FQDN;
    server_tokens off;
    $SSL_CONF
    client_max_body_size 8m;
    ${PANEL_IP:+allow $PANEL_IP;}
    ${PANEL_IP:+deny all;}
    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $D/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_pass unix:/run/php/vl-daemon.sock;
        fastcgi_read_timeout 3700s;
    }
}
NGX
  ln -sf /etc/nginx/sites-available/vorlane-daemon /etc/nginx/sites-enabled/vorlane-daemon
  rm -f /etc/nginx/sites-enabled/default
  nginx -t && systemctl reload nginx

  install_container_firewall

  if [ "$LOCAL" = "1" ]; then
    echo "local node: port 8443 stays closed to the network"
  elif [ -n "$PANEL_IP" ]; then
    ufw allow OpenSSH >/dev/null
    ufw allow from "$PANEL_IP" to any port 8443 proto tcp >/dev/null
    ufw --force enable >/dev/null
  else
    echo "WARNING: --panel-ip not given: restrict port 8443 to your panel's IP yourself (ufw allow from <ip> to any port 8443)."
    base_firewall 8443/tcp
  fi

  echo
  echo "================ Game Panel node installed ================"
  if [ "$LOCAL" = "1" ]; then
    echo " Daemon     : http://127.0.0.1:8443 (this machine, already registered in the panel as node 'local')"
  else
    echo " Daemon URL : https://$FQDN:8443   (add it as a node in the panel, scheme https, port 8443)"
  fi
  if [ "${SELF_SIGNED:-0}" = "1" ]; then
    echo " Self-signed certificate: copy /etc/vorlane/daemon.crt to the panel server and add"
    echo "   'daemon_ca' => '/path/to/daemon.crt',   to the panel's config/config.php so the panel can verify this node."
  fi
  echo " Game ports : add them as Allocations in the panel. Docker publishes them directly; they bypass ufw by design."
  echo "============================================================"
}

# Fence containers off from the host and from every private network (see install/vorlane-firewall).
install_container_firewall() {
  info "Installing container isolation firewall"
  install -m 755 "$SRC/install/vorlane-firewall" /usr/local/sbin/vorlane-firewall
  cat > /etc/systemd/system/vorlane-firewall.service <<'UNIT'
[Unit]
Description=Game Panel container isolation rules
After=docker.service network-online.target
Requires=docker.service
PartOf=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/local/sbin/vorlane-firewall
ExecReload=/usr/local/sbin/vorlane-firewall

[Install]
WantedBy=multi-user.target docker.service
UNIT
  systemctl daemon-reload
  systemctl enable --now vorlane-firewall.service
}

# Registers this machine as a node in the freshly installed panel and installs the daemon for it.
install_local_node() {
  info "Installing the daemon on this machine and registering it as node 'local'"
  local MEM DISK OUT
  MEM=$(awk '/MemTotal/ {m=int($2/1024)-1024; if (m<1024) m=1024; print m}' /proc/meminfo)
  DISK=$(df -m --output=avail /var/lib 2>/dev/null | tail -1 | tr -d ' ')
  DISK=$(( ${DISK:-20000} * 8 / 10 ))
  OUT=$(cd "$APP/panel" && sudo -u www-data php bin/add-node.php --name=local --fqdn=127.0.0.1 --scheme=http --memory="$MEM" --disk="$DISK" --pool-ip=0.0.0.0 --pool=25565-25575) || die "could not register the local node"
  SECRET=$(echo "$OUT" | sed -n 's/^SECRET=//p' | tail -1)
  [ ${#SECRET} -ge 32 ] || die "local node registration did not return a secret"
  FQDN=127.0.0.1; PANEL_IP=127.0.0.1; LOCAL=1
  install_daemon
}

# Installs MariaDB on this machine and registers it in the panel as a database host (for per-server databases).
install_local_db() {
  info "Installing MariaDB for server databases"
  apt-get install -y mariadb-server
  systemctl enable --now mariadb
  local PASS
  PASS=$(openssl rand -hex 16)
  mysql -e "CREATE USER IF NOT EXISTS 'gp_dbadmin'@'127.0.0.1' IDENTIFIED BY '$PASS'; ALTER USER 'gp_dbadmin'@'127.0.0.1' IDENTIFIED BY '$PASS'; GRANT ALL PRIVILEGES ON *.* TO 'gp_dbadmin'@'127.0.0.1' WITH GRANT OPTION; FLUSH PRIVILEGES;" || die "could not create the database admin user"
  (cd "$APP/panel" && sudo -u www-data php bin/add-dbhost.php --name=Local --host=127.0.0.1 --user=gp_dbadmin --pass="$PASS") || die "could not register the database host"
  echo "MariaDB listens on 127.0.0.1 only (default). Registered as database host 'Local'."
}

case "$ROLE" in
  panel) install_panel; [ "$NO_DB" = "1" ] || install_local_db; [ "$NO_DAEMON" = "1" ] || install_local_node;;
  daemon) install_daemon;;
esac
