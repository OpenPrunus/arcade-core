<?php

declare(strict_types=1);

namespace Arcade\Server;

/** Le jeu vu du serveur : connexions entrantes, messages texte complets, déconnexions et battement (tick). */
interface Hub
{
    /** Nouveau client sur ce canal. */
    public function accept(Transport $transport): Session;

    /** Message texte complet reçu d'un client accepté par ce hub. */
    public function receive(Session $session, string $raw): void;

    public function disconnect(Session $session): void;

    /** Appelé à chaque pas de la boucle (cadence : le paramètre `tick` du serveur). */
    public function tick(): void;

    /** Nombre de clients connectés (sert à refuser les connexions au-delà du plafond). */
    public function population(): int;
}
