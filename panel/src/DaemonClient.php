<?php
declare(strict_types=1);

namespace App;

final class DaemonException extends \RuntimeException
{
}

/**
 * Talks to a node's daemon. Every request is HMAC-signed with the node secret
 * (timestamp + nonce + method + path + body hash) so it cannot be forged or replayed.
 */
final class DaemonClient
{
    /** @return array{status:int,data:mixed} */
    public static function call(array $node, string $method, string $path, ?array $body = null, int $timeout = 15): array
    {
        $secret = Security::decrypt($node['daemon_secret']);
        $json = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR);
        $ts = (string)time();
        $nonce = bin2hex(random_bytes(12));
        $sig = hash_hmac('sha256', implode("\n", [$ts, $nonce, $method, $path, hash('sha256', $json)]), $secret);

        $https = $node['scheme'] === 'https';
        $ch = curl_init($node['scheme'] . '://' . $node['fqdn'] . ':' . (int)$node['daemon_port'] . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => $https,
            CURLOPT_SSL_VERIFYHOST => $https ? 2 : 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-GP-Ts: ' . $ts,
                'X-GP-Nonce: ' . $nonce,
                'X-GP-Sig: ' . $sig,
            ],
        ]);
        // Self-signed node certificates: pin them by listing the PEM file(s) in config 'daemon_ca'.
        if ($https && cfg('daemon_ca')) {
            curl_setopt($ch, CURLOPT_CAINFO, cfg('daemon_ca'));
        }
        if ($json !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new DaemonException('Node unreachable: ' . $err);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'data' => json_decode($raw, true)];
    }

    /** Like call() but throws on non-2xx so controllers can show one error. */
    public static function ok(array $node, string $method, string $path, ?array $body = null, int $timeout = 15): mixed
    {
        $r = self::call($node, $method, $path, $body, $timeout);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            $msg = is_array($r['data']) ? ($r['data']['error'] ?? 'Daemon error') : 'Daemon error';
            throw new DaemonException($msg . ' (HTTP ' . $r['status'] . ')');
        }
        return $r['data'];
    }
}
