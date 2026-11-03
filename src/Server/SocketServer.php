<?php

declare(strict_types=1);

namespace Arcade\Server;

use Arcade\Network\ClientAddress;
use Arcade\Network\TrustedProxies;
use Arcade\WebSocket\FrameCodec;
use Arcade\WebSocket\Handshake;
use Arcade\WebSocket\Opcode;
use Arcade\WebSocket\ProtocolException;
use RuntimeException;

/**
 * Serveur WebSocket (RFC 6455) en PHP pur : une boucle stream_select, des sockets non bloquantes. Il ne connaît du jeu
 * que le Hub : connexions entrantes, messages texte complets, déconnexions, battement (tick).
 */
final class SocketServer
{
    final public const int DEFAULT_MAX_CLIENTS = 300;
    final public const int DEFAULT_MAX_PER_IP = 10;                // connexions simultanées par adresse IP réelle
    final public const int IDLE_TIMEOUT = 90;                  // s sans aucun message : déconnexion
    final public const int READ_SIZE = 8192;
    final public const int MAX_BUFFER = 20000;

    final public const float DEFAULT_TICK = 1 / 60;                // pas du jeu par défaut (s)
    final public const int MAX_CATCH_UP = 5;                       // pas rattrapés au plus par tour de boucle

    /** @var array<int, Connection> indexées par (int) socket */
    private array $connections = [];
    private float $nextTick = 0.0;
    private int $lastReap = 0;

    /** @param resource $listener */
    private function __construct(
        private readonly mixed $listener,
        private readonly Hub $hub,
        private readonly Handshake $handshake,
        private readonly float $interval,
        private readonly int $maxClients,
        private readonly TrustedProxies $trusted,
        private readonly int $maxPerIp,
        private readonly FrameCodec $codec = new FrameCodec(),
    ) {
    }

    /**
     * Ouvre le port d'écoute.
     *
     * @throws RuntimeException si le port est indisponible
     */
    public static function listen(
        int $port,
        Hub $hub,
        Handshake $handshake,
        float $interval = self::DEFAULT_TICK,
        int $maxClients = self::DEFAULT_MAX_CLIENTS,
        ?TrustedProxies $trusted = null,
        int $maxPerIp = self::DEFAULT_MAX_PER_IP,
    ): self {
        $socket = @stream_socket_server("tcp://0.0.0.0:$port", $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("Cannot listen on port $port: $errstr");
        }
        stream_set_blocking($socket, false);

        return new self($socket, $hub, $handshake, $interval, $maxClients, $trusted ?? TrustedProxies::fromString(''), $maxPerIp);
    }

    /** Boucle principale : ne rend jamais la main. */
    public function run(): never
    {
        while (true) {
            $this->cycle();
        }
    }

    /** Un tour de boucle : attend l'activité des sockets jusqu'au prochain battement, la traite, puis fait battre le jeu. */
    public function cycle(): void
    {
        if ($this->nextTick === 0.0) {
            $this->nextTick = microtime(true) + $this->interval;
        }
        $wait = max(0.0, $this->nextTick - microtime(true));
        $read = [$this->listener];
        $write = [];
        $except = null;
        foreach ($this->connections as $connection) {
            $read[] = $connection->socket;
            if ($connection->wantsWrite) {
                $write[] = $connection->socket;
            }
        }
        if (@stream_select($read, $write, $except, 0, (int) min(200000, $wait * 1_000_000)) === false) {
            return;
        }
        foreach ($read as $socket) {
            $socket === $this->listener ? $this->acceptConnection() : $this->readFrom($socket);
        }
        foreach ($write as $socket) {
            ($this->connections[(int) $socket] ?? null)?->flush();
        }
        $this->tick();
    }

    private function acceptConnection(): void
    {
        $socket = @stream_socket_accept($this->listener, 0);
        if ($socket === false) {
            return;
        }
        if ($this->hub->population() >= $this->maxClients) {
            fclose($socket);

            return;
        }
        stream_set_blocking($socket, false);
        $connection = new Connection($socket);
        $connection->peer = ClientAddress::fromSocketName((string) @stream_socket_get_name($socket, true));
        $connection->ip = $connection->peer;
        $connection->session = $this->hub->accept($connection);
        $this->connections[(int) $socket] = $connection;
    }

    /** @param resource $socket */
    private function readFrom(mixed $socket): void
    {
        $connection = $this->connections[(int) $socket] ?? null;
        if ($connection === null) {
            return;
        }
        $data = @fread($socket, self::READ_SIZE);
        if ($data === false || ($data === '' && feof($socket))) {
            $this->drop($connection);

            return;
        }
        $connection->inbound .= $data;
        if (strlen($connection->inbound) > self::MAX_BUFFER) {
            $this->drop($connection);

            return;
        }
        if (!$connection->upgraded && !$this->upgrade($connection)) {
            return;
        }
        $this->readFrames($connection);
    }

    /** Poignée de main HTTP → WebSocket. Retourne faux tant qu'elle n'est pas faite (incomplète ou refusée). */
    private function upgrade(Connection $connection): bool
    {
        $end = strpos($connection->inbound, "\r\n\r\n");
        if ($end === false) {
            return false;
        }
        $head = substr($connection->inbound, 0, $end);
        $connection->inbound = (string) substr($connection->inbound, $end + 4);
        $headers = Handshake::parseHeaders($head);
        $connection->ip = ClientAddress::resolve($connection->peer, $headers['x-real-ip'] ?? $headers['x-forwarded-for'] ?? null, $this->trusted);
        if ($this->maxPerIp > 0 && $this->connectionsFrom($connection) >= $this->maxPerIp) {
            $connection->writeRaw(Handshake::tooMany());                      // trop de connexions depuis cette adresse
            $this->drop($connection);

            return false;
        }
        $response = $this->handshake->respond($head);
        if ($response === null) {
            $connection->writeRaw(Handshake::rejection());
            $this->drop($connection);

            return false;
        }
        $connection->writeRaw($response);
        $connection->upgraded = true;
        if ($connection->session instanceof IpAware) {
            $connection->session->setAddress($connection->ip);
        }

        return true;
    }

    private function readFrames(Connection $connection): void
    {
        while (true) {
            try {
                $frame = $this->codec->decode($connection->inbound);
            } catch (ProtocolException) {
                $this->drop($connection, sendClose: true);                           // client non conforme

                return;
            }
            if ($frame === null) {
                return;                                                              // trame incomplète : on attend la suite
            }
            $connection->inbound = (string) substr($connection->inbound, $frame->consumed);

            switch ($frame->type()) {
                case Opcode::Close:
                    $this->drop($connection, sendClose: true);

                    return;
                case Opcode::Ping:
                    $connection->sendControl(Opcode::Pong, $frame->payload);
                    break;
                case Opcode::Text:
                case Opcode::Continuation:
                    $connection->fragment = $frame->opcode === Opcode::Text->value
                        ? $frame->payload
                        : ($connection->fragment ?? '') . $frame->payload;
                    if (strlen($connection->fragment) > FrameCodec::DEFAULT_MAX_PAYLOAD) {
                        $this->drop($connection, sendClose: true);

                        return;
                    }
                    if ($frame->fin) {
                        $message = $connection->fragment;
                        $connection->fragment = null;
                        $this->hub->receive($this->clientOf($connection), $message);
                        if (!isset($this->connections[(int) $connection->socket])) {
                            return;
                        }
                    }
                    break;
                default:
                    break;                                                           // pong, binaire : ignorés
            }
        }
    }

    private function clientOf(Connection $connection): Session
    {
        return $connection->session ?? throw new RuntimeException('connection without session');
    }

    /** Fait battre le jeu à la cadence demandée (en rattrapant un éventuel retard), puis expulse les connexions mortes ou muettes. */
    private function tick(): void
    {
        $now = microtime(true);
        for ($i = 0; $i < self::MAX_CATCH_UP && $now >= $this->nextTick; $i++) {
            $this->hub->tick();
            $this->nextTick += $this->interval;
        }
        if ($now >= $this->nextTick) {
            $this->nextTick = $now + $this->interval;                       // trop de retard : on repart de maintenant
        }
        $second = (int) $now;
        if ($second === $this->lastReap) {
            return;
        }
        $this->lastReap = $second;
        foreach ($this->connections as $connection) {
            if ($connection->dead || $this->clientOf($connection)->idleFor($second) > self::IDLE_TIMEOUT) {
                $this->drop($connection, sendClose: true);
            }
        }
    }

    /** Connexions déjà ouvertes (poignée de main faite) depuis la même adresse que celle-ci. */
    private function connectionsFrom(Connection $connection): int
    {
        $key = ClientAddress::key($connection->ip);
        $n = 0;
        foreach ($this->connections as $other) {
            if ($other !== $connection && $other->upgraded && ClientAddress::key($other->ip) === $key) {
                $n++;
            }
        }

        return $n;
    }

    private function drop(Connection $connection, bool $sendClose = false): void
    {
        if (!isset($this->connections[(int) $connection->socket])) {
            return;
        }
        if ($sendClose && $connection->upgraded) {
            $connection->writeRaw(FrameCodec::encode('', Opcode::Close));
        }
        $this->hub->disconnect($this->clientOf($connection));
        unset($this->connections[(int) $connection->socket]);
        $connection->close();
    }
}
