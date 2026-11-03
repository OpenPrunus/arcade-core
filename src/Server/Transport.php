<?php

declare(strict_types=1);

namespace Arcade\Server;

/** Canal d'envoi vers un client : le jeu ignore tout des sockets. */
interface Transport
{
    public function send(string $json): void;
}
