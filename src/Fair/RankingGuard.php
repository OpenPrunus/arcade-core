<?php

declare(strict_types=1);

namespace Arcade\Fair;

use Arcade\Network\ClientAddress;
use Arcade\Support\Env;

/**
 * Décide si une partie peut être classée, d'après les adresses IP des deux joueurs (en mémoire, jamais écrites sur disque) :
 * - `same_ip` : deux joueurs de la même adresse ne se classent pas entre eux (un seul humain avec deux navigateurs) ;
 * - `too_many_accounts` : une même adresse ne peut pas faire classer plus de N jetons différents par fenêtre (fermes de faux comptes) ;
 * - `pair_limit` : deux mêmes adresses ne se rencontrent pas plus de N fois par fenêtre (échange de victoires, même avec de nouveaux jetons).
 * Une partie refusée reste jouable, simplement amicale. Sans adresse connue, rien n'est refusé.
 */
final class RankingGuard
{
    final public const string SAME_IP = 'same_ip';
    final public const string TOO_MANY_ACCOUNTS = 'too_many_accounts';
    final public const string PAIR_LIMIT = 'pair_limit';

    /** @var array<string, array<string, int>> adresse → jeton → dernier classement */
    private array $accounts = [];
    /** @var array<string, list<int>> paire d'adresses → horodatages des parties classées */
    private array $pairs = [];

    public function __construct(
        private readonly int $maxAccountsPerIp = 3,
        private readonly int $maxPairMatches = 4,
        private readonly int $window = 86400,
        private readonly bool $allowSameIp = false,
    ) {
    }

    /** Réglages depuis l'environnement : `{PREFIX}_MAX_ACCOUNTS_PER_IP`, `{PREFIX}_MAX_PAIR_MATCHES`, `{PREFIX}_ALLOW_SAME_IP`. */
    public static function fromEnvironment(string $prefix): self
    {
        return new self(
            maxAccountsPerIp: (int) Env::number($prefix . '_MAX_ACCOUNTS_PER_IP', 3, true),
            maxPairMatches: (int) Env::number($prefix . '_MAX_PAIR_MATCHES', 4, true),
            allowSameIp: Env::string($prefix . '_ALLOW_SAME_IP') === '1',
        );
    }

    /**
     * @return ?string null : la partie peut être classée (et elle est comptée) ; sinon la raison du refus
     */
    public function check(string $ipA, string $tokenA, string $ipB, string $tokenB, int $now): ?string
    {
        if ($ipA === '' || $ipB === '') {
            return null;
        }
        $keyA = ClientAddress::key($ipA);
        $keyB = ClientAddress::key($ipB);
        if ($keyA === $keyB && !$this->allowSameIp) {
            return self::SAME_IP;
        }
        $this->purge($now);
        foreach ([[$keyA, $tokenA], [$keyB, $tokenB]] as [$key, $token]) {
            $known = $this->accounts[$key] ?? [];
            if ($this->maxAccountsPerIp > 0 && !isset($known[$token]) && count($known) >= $this->maxAccountsPerIp) {
                return self::TOO_MANY_ACCOUNTS;
            }
        }
        $pair = $keyA <= $keyB ? "$keyA|$keyB" : "$keyB|$keyA";
        if ($this->maxPairMatches > 0 && count($this->pairs[$pair] ?? []) >= $this->maxPairMatches) {
            return self::PAIR_LIMIT;
        }
        $this->accounts[$keyA][$tokenA] = $now;
        $this->accounts[$keyB][$tokenB] = $now;
        $this->pairs[$pair][] = $now;

        return null;
    }

    /** Oublie ce qui est plus vieux que la fenêtre (les parties classées sont rares : un balayage complet suffit). */
    private function purge(int $now): void
    {
        $limit = $now - $this->window;
        foreach ($this->accounts as $key => $tokens) {
            $kept = array_filter($tokens, static fn (int $t): bool => $t > $limit);
            if ($kept === []) {
                unset($this->accounts[$key]);
            } else {
                $this->accounts[$key] = $kept;
            }
        }
        foreach ($this->pairs as $key => $times) {
            $kept = array_values(array_filter($times, static fn (int $t): bool => $t > $limit));
            if ($kept === []) {
                unset($this->pairs[$key]);
            } else {
                $this->pairs[$key] = $kept;
            }
        }
    }
}
