<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Session en mémoire pour les tests.
 */
final class ArraySession implements SessionStorage
{
    /** Nombre d'appels à regenerate() : un test vérifie ainsi la régénération après login. */
    public int $regenerations = 0;

    /** @param array<string, mixed> $data */
    public function __construct(private array $data = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
        $this->regenerations++;
    }

    public function destroy(): void
    {
        $this->data = [];
    }
}
