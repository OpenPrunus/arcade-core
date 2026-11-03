<?php

declare(strict_types=1);

namespace Arcade\Tests\Network;

use Arcade\Network\ClientAddress;
use Arcade\Network\TrustedProxies;
use Arcade\WebSocket\Handshake;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    public function testPrivateNetworksAreTrustedByDefaultAndPublicOnesAreNot(): void
    {
        $t = TrustedProxies::fromString('');
        foreach (['127.0.0.1', '10.1.2.3', '172.20.0.9', '192.168.1.46', '::1', 'fd12:3456::1'] as $ip) {
            $this->assertTrue($t->isTrusted($ip), $ip);
        }
        foreach (['82.67.16.220', '172.32.0.1', '8.8.8.8', '2a01:e0a::1', 'pas une adresse'] as $ip) {
            $this->assertFalse($t->isTrusted($ip), $ip);
        }
        $this->assertFalse(TrustedProxies::fromString('none')->isTrusted('127.0.0.1'));
        $this->assertTrue(TrustedProxies::fromString('203.0.113.0/24, 198.51.100.7')->isTrusted('198.51.100.7'));
        $this->assertTrue(TrustedProxies::fromString('203.0.113.0/24')->isTrusted('203.0.113.200'));
        $this->assertFalse(TrustedProxies::fromString('203.0.113.0/24')->isTrusted('203.0.114.1'));
    }

    public function testTheForwardedAddressIsOnlyBelievedFromATrustedProxy(): void
    {
        $t = TrustedProxies::fromString('');
        $this->assertSame('82.67.16.220', ClientAddress::resolve('192.168.1.46', '82.67.16.220', $t));
        $this->assertSame('82.67.16.220', ClientAddress::resolve('192.168.1.46', '82.67.16.220, 10.0.0.1', $t), 'première adresse de la liste');
        $this->assertSame('203.0.113.9', ClientAddress::resolve('203.0.113.9', '1.2.3.4', $t), 'un client public ne peut pas se faire passer pour un autre');
        $this->assertSame('192.168.1.46', ClientAddress::resolve('192.168.1.46', 'forgé', $t), 'en-tête invalide ignoré');
        $this->assertSame('192.168.1.46', ClientAddress::resolve('192.168.1.46', null, $t));
        $this->assertSame('192.168.1.46', ClientAddress::resolve('192.168.1.46', '1.2.3.4', TrustedProxies::fromString('none')));
    }

    public function testSocketNamesAreParsedAndIpv6IsGroupedByNetwork(): void
    {
        $this->assertSame('192.168.1.5', ClientAddress::fromSocketName('192.168.1.5:51234'));
        $this->assertSame('2a01:e0a::1', ClientAddress::fromSocketName('[2a01:e0a::1]:443'));
        $this->assertSame('82.67.16.220', ClientAddress::key('82.67.16.220'));
        $this->assertSame(ClientAddress::key('2a01:e0a:1049:d780::1'), ClientAddress::key('2a01:e0a:1049:d780:aaaa:bbbb:cccc:dddd'), 'même réseau /64');
        $this->assertNotSame(ClientAddress::key('2a01:e0a:1049:d780::1'), ClientAddress::key('2a01:e0a:1049:d781::1'));
        $this->assertSame('1.2.3.4', ClientAddress::key('::ffff:1.2.3.4'), 'IPv4 mappée en IPv6');
    }

    public function testHandshakeHeadersAreParsedInLowerCase(): void
    {
        $h = Handshake::parseHeaders("GET /ws HTTP/1.1\r\nHost: x\r\nX-Real-IP: 1.2.3.4\r\nUpgrade: websocket");
        $this->assertSame('1.2.3.4', $h['x-real-ip']);
        $this->assertSame('websocket', $h['upgrade']);
        $this->assertStringStartsWith('HTTP/1.1 429', Handshake::tooMany());
    }
}
