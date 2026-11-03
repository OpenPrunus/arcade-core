<?php

declare(strict_types=1);

namespace Arcade\Tests\Fair;

use Arcade\Fair\RankingGuard;
use PHPUnit\Framework\TestCase;

final class RankingGuardTest extends TestCase
{
    private const int NOW = 1_000_000;

    public function testTwoPlayersOnTheSameAddressNeverRankAgainstEachOther(): void
    {
        $g = new RankingGuard();
        $this->assertSame(RankingGuard::SAME_IP, $g->check('203.0.113.5', 'a', '203.0.113.5', 'b', self::NOW));
        $this->assertNull((new RankingGuard(allowSameIp: true))->check('203.0.113.5', 'a', '203.0.113.5', 'b', self::NOW));
        $this->assertSame(RankingGuard::SAME_IP, $g->check('2a01:e0a:1:2::1', 'a', '2a01:e0a:1:2::99', 'b', self::NOW), 'même réseau IPv6');
    }

    public function testDifferentAddressesCanRankAndUnknownAddressesAreNeverRefused(): void
    {
        $g = new RankingGuard();
        $this->assertNull($g->check('203.0.113.5', 'a', '198.51.100.7', 'b', self::NOW));
        $this->assertNull($g->check('', 'a', '', 'a', self::NOW), 'sans adresse connue : aucun refus');
        $this->assertNull($g->check('', 'x', '198.51.100.7', 'y', self::NOW));
    }

    public function testOneAddressCannotRankMoreThanNDifferentAccountsPerWindow(): void
    {
        $g = new RankingGuard(maxAccountsPerIp: 2, maxPairMatches: 0);
        $this->assertNull($g->check('203.0.113.5', 'a1', '198.51.100.1', 'p1', self::NOW));
        $this->assertNull($g->check('203.0.113.5', 'a2', '198.51.100.2', 'p2', self::NOW));
        $this->assertSame(RankingGuard::TOO_MANY_ACCOUNTS, $g->check('203.0.113.5', 'a3', '198.51.100.3', 'p3', self::NOW), 'troisième jeton depuis la même adresse');
        $this->assertNull($g->check('203.0.113.5', 'a1', '198.51.100.4', 'p4', self::NOW), 'un compte déjà connu reste autorisé');
        $this->assertNull($g->check('203.0.113.5', 'a3', '198.51.100.3', 'p3', self::NOW + 86401), 'la fenêtre est écoulée : on repart de zéro');
    }

    public function testTheSamePairOfAddressesCannotMeetMoreThanNTimesEvenWithNewAccounts(): void
    {
        $g = new RankingGuard(maxAccountsPerIp: 0, maxPairMatches: 3);
        for ($i = 0; $i < 3; $i++) {
            $this->assertNull($g->check('203.0.113.5', "a$i", '198.51.100.7', "b$i", self::NOW + $i), "rencontre $i");
        }
        $this->assertSame(RankingGuard::PAIR_LIMIT, $g->check('203.0.113.5', 'nouveau', '198.51.100.7', 'autre', self::NOW + 10));
        $this->assertSame(RankingGuard::PAIR_LIMIT, $g->check('198.51.100.7', 'x', '203.0.113.5', 'y', self::NOW + 11), 'dans l\'autre sens aussi');
        $this->assertNull($g->check('203.0.113.5', 'a', '192.0.2.50', 'c', self::NOW + 12), 'une autre paire n\'est pas concernée');
        $this->assertNull($g->check('203.0.113.5', 'z', '198.51.100.7', 'w', self::NOW + 86400 + 5), 'après la fenêtre');
    }

    public function testARefusedMatchIsNotCountedAndZeroDisablesALimit(): void
    {
        $g = new RankingGuard(maxAccountsPerIp: 1, maxPairMatches: 0);
        $this->assertNull($g->check('203.0.113.5', 'a', '198.51.100.1', 'p', self::NOW));
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(RankingGuard::TOO_MANY_ACCOUNTS, $g->check('203.0.113.5', "n$i", '198.51.100.9', "q$i", self::NOW));
        }
        $this->assertNull($g->check('203.0.113.5', 'a', '198.51.100.9', 'q', self::NOW), 'les refus n\'ont enregistré aucun compte');
    }
}
