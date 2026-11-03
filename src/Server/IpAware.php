<?php

declare(strict_types=1);

namespace Arcade\Server;

/** Une session qui veut connaître l'adresse IP réelle de son client (résolue après la poignée de main WebSocket). */
interface IpAware
{
    public function setAddress(string $ip): void;
}
