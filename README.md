# Arcade Core

Shared PHP 8.5 core of the OpenPrunus multiplayer games
([Chicken Challenge](https://github.com/OpenPrunus/chicken-challenge), [Gloop Volley](https://github.com/OpenPrunus/gloop-volley)).

- `Arcade\WebSocket` — RFC 6455 handshake and frame codec in pure PHP.
- `Arcade\Server` — non-blocking `stream_select` WebSocket server. It knows nothing about the game: a game plugs in by
  implementing `Hub` (accept / receive / disconnect / tick / population) and `Session` (who is idle for how long);
  `Transport` is the send channel handed to the game. The tick interval and the client cap are parameters.
- `Arcade\Support` — `Env`, `Nickname`, `AtomicFile`, `SystemClock`, `StdoutLogger` (PSR-3 / PSR-20).

```php
$server = SocketServer::listen(8081, $hub, new Handshake($allowedOrigins), interval: 1 / 60, maxClients: 300);
$server->run();
```

## Development

```
composer install
composer check   # phpcs (PSR-12), phpstan level 8, phpunit
```

MIT licensed.
