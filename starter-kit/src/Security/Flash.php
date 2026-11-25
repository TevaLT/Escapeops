<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Message affiché une seule fois sur la page suivante (« Session créée. »),
 * après une redirection qui suit un POST réussi.
 */
final class Flash
{
    private const KEY = 'flash';

    public function __construct(private SessionStorage $session)
    {
    }

    public function success(string $message): void
    {
        $this->add('success', $message);
    }

    /** @return list<array{type: string, message: string}> */
    public function pull(): array
    {
        $messages = $this->session->get(self::KEY, []);
        $this->session->remove(self::KEY);

        return is_array($messages) ? $messages : [];
    }

    private function add(string $type, string $message): void
    {
        $messages = $this->session->get(self::KEY, []);
        $messages = is_array($messages) ? $messages : [];
        $messages[] = ['type' => $type, 'message' => $message];
        $this->session->set(self::KEY, $messages);
    }
}
