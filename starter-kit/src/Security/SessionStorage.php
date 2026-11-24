<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Accès à la session HTTP. NativeSession utilise $_SESSION ; ArraySession
 * sert aux tests, sans navigateur ni cookie.
 */
interface SessionStorage
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** Change l'identifiant de session (contre la fixation de session). */
    public function regenerate(): void;

    /** Vide la session et la détruit côté serveur. */
    public function destroy(): void;
}
