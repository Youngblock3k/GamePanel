<?php
declare(strict_types=1);

namespace Wings;

/** Minimal Docker Engine API client over the unix socket. */
final class Docker
{
    public function __construct(private string $socket)
    {
    }

    /** @return array{0:int,1:string} status, raw body */
    public function raw(string $method, string $path, ?array $body = null, int $timeout = 30): array
    {
        $ch = curl_init('http://localhost/v1.43' . $path);
        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => $this->socket,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
        $out = curl_exec($ch);
        if ($out === false) {
            $e = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Docker unreachable: ' . $e);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, (string)$out];
    }

    public function json(string $method, string $path, ?array $body = null, int $timeout = 30): array
    {
        [$code, $raw] = $this->raw($method, $path, $body, $timeout);
        $data = json_decode($raw, true);
        if ($code >= 400) {
            throw new \RuntimeException('Docker: ' . (is_array($data) ? ($data['message'] ?? $raw) : $raw), $code);
        }
        return is_array($data) ? $data : [];
    }

    public function inspect(string $name): ?array
    {
        [$code, $raw] = $this->raw('GET', '/containers/' . $name . '/json');
        return $code === 200 ? json_decode($raw, true) : null;
    }

    /** Dedicated bridge: containers cannot talk to each other (ICC off) and use a fixed subnet the host firewall can fence off. */
    public function ensureNetwork(string $name, string $subnet = '172.30.0.0/16'): void
    {
        [$code] = $this->raw('GET', '/networks/' . $name);
        if ($code === 200) {
            return;
        }
        $this->json('POST', '/networks/create', [
            'Name' => $name,
            'Driver' => 'bridge',
            'EnableIPv6' => false,
            'IPAM' => ['Config' => [['Subnet' => $subnet]]],
            'Options' => [
                'com.docker.network.bridge.enable_icc' => 'false',
                'com.docker.network.bridge.enable_ip_masquerade' => 'true',
                'com.docker.network.bridge.name' => 'vl0',
            ],
        ]);
    }

    public function pull(string $image): void
    {
        [$img, $tag] = array_pad(explode(':', $image, 2), 2, 'latest');
        // Streams progress; we only care that it finishes without an error line.
        [$code, $raw] = $this->raw('POST', '/images/create?fromImage=' . rawurlencode($img) . '&tag=' . rawurlencode($tag), null, 900);
        if ($code >= 400 || str_contains($raw, '"errorDetail"')) {
            throw new \RuntimeException('Image pull failed for ' . $image);
        }
    }
}
