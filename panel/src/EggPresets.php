<?php
declare(strict_types=1);

namespace App;

/**
 * Built-in egg templates. Admins can install any of them with one click and then edit or clone them.
 * Image names follow the community "ptero-eggs" registry; confirm tags before production use.
 */
final class EggPresets
{
    private const STEAMCMD = <<<'SH'
set -e
cd /tmp
curl -sSfL -o steamcmd.tar.gz https://steamcdn-a.akamaihd.net/client/installer/steamcmd_linux.tar.gz
mkdir -p /mnt/server/steamcmd /mnt/server/steamapps
tar -xzf steamcmd.tar.gz -C /mnt/server/steamcmd
export HOME=/mnt/server
cd /mnt/server/steamcmd
./steamcmd.sh +quit || true
./steamcmd.sh +force_install_dir /mnt/server +login anonymous +app_update __APPID__ validate +quit
mkdir -p /mnt/server/.steam/sdk32 /mnt/server/.steam/sdk64
cp -f linux32/steamclient.so /mnt/server/.steam/sdk32/steamclient.so
cp -f linux64/steamclient.so /mnt/server/.steam/sdk64/steamclient.so
SH;

    public static function all(): array
    {
        $v = static fn(string $key, string $name, string $default, string $regex, bool $editable = true, array $options = []) =>
            ['key' => $key, 'name' => $name, 'default' => $default, 'editable' => $editable, 'regex' => $regex] + ($options ? ['options' => $options] : []);

        return [
            'nodejs' => [
                'name' => 'Node.js', 'category' => 'runtime',
                'description' => 'Run any Node.js app. Installs package.json dependencies on every start.',
                'docker_image' => 'node:20-alpine', 'stop_command' => '',
                'startup' => 'if [ -f package.json ]; then npm install --omit=dev --no-audit --no-fund; fi; node {{MAIN_FILE}}',
                'variables' => [$v('MAIN_FILE', 'Main file', 'index.js', '^[A-Za-z0-9_.\-]{1,60}$')],
                'install_image' => 'ghcr.io/ptero-eggs/installers:alpine',
                'install_script' => "[ -f index.js ] || echo \"console.log('Hello from Game Panel');setInterval(()=>{},1000)\" > index.js\n",
            ],
            'python' => [
                'name' => 'Python', 'category' => 'runtime',
                'description' => 'Run a Python script. Installs requirements.txt into a local folder on start.',
                'docker_image' => 'python:3.12-slim', 'stop_command' => '',
                'startup' => 'if [ -f requirements.txt ]; then pip install --no-cache-dir --target ./.packages -r requirements.txt; fi; PYTHONPATH=./.packages python -u {{PY_FILE}}',
                'variables' => [$v('PY_FILE', 'Main file', 'main.py', '^[A-Za-z0-9_.\-]{1,60}$')],
                'install_image' => 'ghcr.io/ptero-eggs/installers:alpine',
                'install_script' => "[ -f main.py ] || printf 'import time\\nprint(\"Hello from Game Panel\")\\nwhile True:\\n    time.sleep(1)\\n' > main.py\n",
            ],
            'minecraft_paper' => [
                'name' => 'Minecraft: Java', 'category' => 'game',
                'description' => 'Minecraft Java server: Paper, Purpur, Fabric or Vanilla. Pick the type, version and the usual world rules; server.properties is written for you.',
                'docker_image' => 'ghcr.io/pterodactyl/yolks:java_21', 'stop_command' => 'stop',
                'startup' => 'java -Xms128M -Xmx{{SERVER_MEMORY_JVM}}M -Dterminal.jline=false -Dterminal.ansi=true -jar server.jar --nogui --port {{SERVER_PORT}}',
                'variables' => [
                    $v('SERVER_TYPE', 'Server type', 'paper', '^(paper|purpur|fabric|vanilla)$', true, ['paper' => 'Paper (fast, plugins)', 'purpur' => 'Purpur (Paper + extras)', 'fabric' => 'Fabric (mods)', 'vanilla' => 'Vanilla (official)']),
                    $v('MC_VERSION', 'Minecraft version', '1.21.11', '^(1|2[0-9])\.[0-9]{1,2}(\.[0-9]{1,2})?$'),
                    $v('ACCEPT_EULA', 'Accept the Minecraft EULA', 'false', '^(true|false)$', true, ['false' => 'Not yet', 'true' => 'I accept the EULA']),
                    $v('MOTD', 'Server message (MOTD)', 'A Game Panel server', "^[A-Za-z0-9 .,!'_:+&()-]{0,60}$"),
                    $v('MAX_PLAYERS', 'Max players', '20', '^[0-9]{1,4}$'),
                    $v('GAMEMODE', 'Default game mode', 'survival', '^(survival|creative|adventure|spectator)$', true, ['survival' => 'Survival', 'creative' => 'Creative', 'adventure' => 'Adventure', 'spectator' => 'Spectator']),
                    $v('DIFFICULTY', 'Difficulty', 'normal', '^(peaceful|easy|normal|hard)$', true, ['peaceful' => 'Peaceful', 'easy' => 'Easy', 'normal' => 'Normal', 'hard' => 'Hard']),
                    $v('ONLINE_MODE', 'Only allow paid accounts (online mode)', 'true', '^(true|false)$', true, ['true' => 'Yes (safe)', 'false' => 'No (cracked allowed)']),
                    $v('PVP', 'Players can fight each other (PvP)', 'true', '^(true|false)$', true, ['true' => 'Yes', 'false' => 'No']),
                    $v('WHITELIST', 'Whitelist (invite only)', 'false', '^(true|false)$', true, ['false' => 'Off', 'true' => 'On']),
                    $v('COMMAND_BLOCKS', 'Command blocks', 'false', '^(true|false)$', true, ['false' => 'Off', 'true' => 'On']),
                    $v('VIEW_DISTANCE', 'View distance (chunks)', '10', '^[0-9]{1,2}$'),
                    $v('LEVEL_SEED', 'World seed (empty = random)', '', '^-?[0-9A-Za-z_]{0,32}$'),
                ],
                'install_image' => 'ghcr.io/ptero-eggs/installers:alpine',
                'install_script' => <<<'SH'
set -e
V="$MC_VERSION"
case "${SERVER_TYPE:-paper}" in
  paper)
    U=$(curl -sSf "https://fill.papermc.io/v3/projects/paper/versions/$V/builds/latest" | jq -r '.downloads["server:default"].url')
    [ -n "$U" ] && [ "$U" != "null" ] || { echo "Paper has no build for $V"; exit 1; }
    curl -sSfL -o server.jar "$U";;
  purpur)
    curl -sSfL -o server.jar "https://api.purpurmc.org/v2/purpur/$V/latest/download";;
  fabric)
    L=$(curl -sSf https://meta.fabricmc.net/v2/versions/loader | jq -r '[.[]|select(.stable)][0].version')
    I=$(curl -sSf https://meta.fabricmc.net/v2/versions/installer | jq -r '[.[]|select(.stable)][0].version')
    curl -sSfL -o server.jar "https://meta.fabricmc.net/v2/versions/loader/$V/$L/$I/server/jar";;
  vanilla)
    M=$(curl -sSf https://piston-meta.mojang.com/mc/game/version_manifest_v2.json | jq -r --arg v "$V" '.versions[]|select(.id==$v)|.url')
    [ -n "$M" ] || { echo "Unknown Minecraft version $V"; exit 1; }
    U=$(curl -sSf "$M" | jq -r '.downloads.server.url')
    curl -sSfL -o server.jar "$U";;
esac
if [ "$ACCEPT_EULA" = "true" ]; then echo "eula=true" > eula.txt; fi
if [ ! -f server.properties ]; then
cat > server.properties <<EOF
motd=$MOTD
max-players=$MAX_PLAYERS
gamemode=$GAMEMODE
difficulty=$DIFFICULTY
online-mode=$ONLINE_MODE
pvp=$PVP
white-list=$WHITELIST
enable-command-block=$COMMAND_BLOCKS
view-distance=$VIEW_DISTANCE
level-seed=$LEVEL_SEED
server-port=$SERVER_PORT
EOF
fi
SH,
            ],
            'java' => [
                'name' => 'Java application', 'category' => 'runtime',
                'description' => 'Run any .jar. Use Files → Pull URL to fetch your jar.',
                'docker_image' => 'ghcr.io/pterodactyl/yolks:java_21', 'stop_command' => '',
                'startup' => 'java -Xms128M -Xmx{{SERVER_MEMORY_JVM}}M -jar {{JARFILE}}',
                'variables' => [$v('JARFILE', 'Jar file', 'server.jar', '^[A-Za-z0-9_.\-]{1,60}$')],
                'install_image' => '', 'install_script' => '',
            ],
            'rust' => [
                'name' => 'Rust', 'category' => 'game',
                'description' => 'Rust dedicated server (Steam app 258550). Needs 8 GB+ RAM and ~15 GB disk. UDP game port.',
                'docker_image' => 'ghcr.io/ptero-eggs/games:rust', 'stop_command' => 'quit',
                'startup' => 'export LD_LIBRARY_PATH=$(pwd)/RustDedicated_Data/Plugins/x86_64:$(pwd):$LD_LIBRARY_PATH; ./RustDedicated -batchmode +server.port {{SERVER_PORT}} +server.identity rust +rcon.port {{RCON_PORT}} +rcon.web 1 +rcon.password {{RCON_PASS}} +server.hostname {{SERVER_NAME}} +server.level "Procedural Map"+server.worldsize {{WORLD_SIZE}} +server.seed {{WORLD_SEED}} +server.maxplayers {{MAX_PLAYERS}}',
                'variables' => [
                    $v('SERVER_NAME', 'Server name (no spaces)', 'GamePanel-Rust', '^[A-Za-z0-9_.\-]{1,40}$'),
                    $v('MAX_PLAYERS', 'Max players', '50', '^[0-9]{1,4}$'),
                    $v('WORLD_SIZE', 'World size', '3000', '^[0-9]{3,4}$'),
                    $v('WORLD_SEED', 'World seed', '12345', '^[0-9]{1,9}$'),
                    $v('RCON_PORT', 'RCON port (internal)', '28016', '^[0-9]{4,5}$'),
                    $v('RCON_PASS', 'RCON password', 'ChangeMe-Now-123', '^[A-Za-z0-9_.\-]{8,40}$'),
                ],
                'install_image' => 'ghcr.io/ptero-eggs/installers:debian',
                'install_script' => str_replace('__APPID__', '258550', self::STEAMCMD),
            ],
            'cs2' => [
                'name' => 'Counter-Strike 2', 'category' => 'game',
                'description' => 'CS2 dedicated server (Steam app 730). Needs 4 GB+ RAM and ~60 GB disk. Provide a GSLT token to be listed.',
                'docker_image' => 'ghcr.io/ptero-eggs/steamcmd:sniper', 'stop_command' => 'quit',
                'startup' => './game/bin/linuxsteamrt64/cs2 -dedicated -console -usercon -port {{SERVER_PORT}} +map {{MAP}} -maxplayers {{MAX_PLAYERS}} +hostname {{SERVER_NAME}} +sv_setsteamaccount {{STEAM_ACC}}',
                'variables' => [
                    $v('SERVER_NAME', 'Server name (no spaces)', 'GamePanel-CS2', '^[A-Za-z0-9_.\-]{1,40}$'),
                    $v('MAP', 'Start map', 'de_dust2', '^[A-Za-z0-9_]{1,40}$'),
                    $v('MAX_PLAYERS', 'Max players', '12', '^[0-9]{1,2}$'),
                    $v('STEAM_ACC', 'Game Server Login Token (GSLT)', '', '^[A-Za-z0-9]{0,40}$'),
                ],
                'install_image' => 'ghcr.io/ptero-eggs/installers:debian',
                'install_script' => str_replace('__APPID__', '730', self::STEAMCMD),
            ],
            'hytale' => [
                'name' => 'Hytale', 'category' => 'game',
                'description' => 'Hytale dedicated server (Java 25, UDP/QUIC). You must supply HytaleServer.jar and Assets.zip from Hypixel Studios (Files → Pull URL), then run "/auth login device" in the console. Flags are community-documented; run with --help to confirm.',
                'docker_image' => 'ghcr.io/ptero-eggs/yolks:java_25', 'stop_command' => 'stop',
                'startup' => 'java -Xms256M -Xmx{{SERVER_MEMORY_JVM}}M -jar HytaleServer.jar --assets {{ASSETS_FILE}} --bind 0.0.0.0:{{SERVER_PORT}}',
                'variables' => [$v('ASSETS_FILE', 'Assets archive', 'Assets.zip', '^[A-Za-z0-9_.\-]{1,60}$')],
                'install_image' => 'ghcr.io/ptero-eggs/installers:alpine',
                'install_script' => "printf 'Place HytaleServer.jar and Assets.zip in this folder (Files tab), then start the server.\\nIn the console run: /auth login device\\n' > README_HYTALE.txt\n",
            ],
        ];
    }

    /** Insert a preset as a new egg (idempotent per preset key). Returns the egg id or null if it exists. */
    public static function install(string $key): ?int
    {
        $p = self::all()[$key] ?? null;
        if (!$p || DB::one('SELECT id FROM eggs WHERE preset_key = ?', [$key])) {
            return null;
        }
        return DB::insert('eggs', [
            'name' => $p['name'], 'category' => $p['category'], 'preset_key' => $key, 'description' => $p['description'],
            'docker_image' => $p['docker_image'], 'startup' => $p['startup'], 'stop_command' => $p['stop_command'],
            'variables' => json_encode($p['variables']), 'install_image' => $p['install_image'], 'install_script' => $p['install_script'],
        ]);
    }
}
