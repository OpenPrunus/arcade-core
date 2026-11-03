<?php

declare(strict_types=1);

namespace Arcade\Network;

/** Les proxys dont on croit l'en-tête X-Real-IP / X-Forwarded-For (liste de réseaux CIDR, IPv4 et IPv6). */
final readonly class TrustedProxies
{
    /** Par défaut : les réseaux privés et le bouclage (le reverse proxy est sur le réseau local). */
    final public const string DEFAULT = '127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1/128,fc00::/7';

    /** @var list<string> */
    public array $cidrs;

    /** @param list<string> $cidrs */
    public function __construct(array $cidrs)
    {
        $this->cidrs = $cidrs;
    }

    /** Liste séparée par des virgules (ex. variable d'environnement) ; vide = valeur par défaut, « none » = aucun proxy de confiance. */
    public static function fromString(string $value): self
    {
        $value = trim($value) === '' ? self::DEFAULT : $value;
        if (strtolower(trim($value)) === 'none') {
            return new self([]);
        }

        return new self(array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $c): bool => $c !== '')));
    }

    public function isTrusted(string $ip): bool
    {
        foreach ($this->cidrs as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $a = @inet_pton($ip);
        $b = @inet_pton((string) $net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : (int) $bits;
        $full = intdiv($bits, 8);
        if (substr($a, 0, $full) !== substr($b, 0, $full)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$full]) & $mask) === (ord($b[$full]) & $mask);
    }
}
