<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Jeton anti-CSRF (US-12) : un secret aléatoire par session, recopié dans
 * chaque formulaire POST. Un site tiers ne peut pas le lire, donc pas le forger.
 */
final class Csrf
{
    public const FIELD = '_csrf';
    public const HEADER = 'X-CSRF-Token';
    private const KEY = 'csrf_token';

    public function __construct(private SessionStorage $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }

        return $token;
    }

    /** Accepte uniquement une chaîne identique au jeton de la session (comparaison à temps constant). */
    public function isValid(mixed $submitted): bool
    {
        $expected = $this->session->get(self::KEY);

        return is_string($submitted)
            && is_string($expected)
            && $expected !== ''
            && hash_equals($expected, $submitted);
    }

    /** Champ caché à placer dans chaque <form method="post">. */
    public function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8')
        );
    }
}
