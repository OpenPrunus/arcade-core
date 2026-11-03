<?php

declare(strict_types=1);

namespace Arcade\Server;

/** Ce que le serveur doit savoir d'un client connecté pour le gérer : depuis quand il est muet. */
interface Session
{
    /** Secondes écoulées depuis son dernier message. */
    public function idleFor(int $now): int;
}
