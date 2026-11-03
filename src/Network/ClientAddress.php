<?php

declare(strict_types=1);

namespace Arcade\Network;

/** L'adresse du vrai client derrière un reverse proxy, sans se laisser tromper par un en-tête forgé. */
final class ClientAddress
{
    /**
     * @param string  $peer      adresse du pair TCP (le reverse proxy en production)
     * @param ?string $forwarded valeur de X-Real-IP, sinon de X-Forwarded-For (on garde la première adresse)
     */
    public static function resolve(string $peer, ?string $forwarded, TrustedProxies $trusted): string
    {
        if ($forwarded !== null && $trusted->isTrusted($peer)) {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        return $peer;
    }

    /** « host:port » ou « [ipv6]:port » (stream_socket_get_name) → l'adresse seule. */
    public static function fromSocketName(string $name): string
    {
        if (str_starts_with($name, '[')) {
            return substr($name, 1, (int) strpos($name, ']') - 1);
        }
        $pos = strrpos($name, ':');

        return $pos === false ? $name : substr($name, 0, $pos);
    }

    /** Clé de regroupement : l'adresse IPv4 telle quelle, ou le réseau /64 pour IPv6 (un abonné y dispose de milliards d'adresses). */
    public static function key(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }
        if (strlen($packed) === 4) {
            return $ip;
        }
        if (str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {                // IPv4 mappée en IPv6
            return (string) inet_ntop(substr($packed, 12));
        }

        return bin2hex(substr($packed, 0, 8)) . '::/64';
    }
}
