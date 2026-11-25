<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Accès « Mes réservations » d'un client, rangé dans la session HTTP sous une clé distincte
 * de celle du personnel (Auth) : être reconnu comme client ne donne aucun droit sur /espace ou /gestion.
 */
final class ClientAccess
{
    private const CONTACT = 'client_contact';
    private const FAILURES = 'client_login_failures';

    public function __construct(private SessionStorage $session)
    {
    }

    /** @param string $contactKey contact normalisé (TrackingCode::normalizeContact) */
    public function login(string $contactKey, string $label): void
    {
        $this->session->regenerate();   // contre la fixation de session, comme pour le personnel
        $this->session->set(self::CONTACT, ['cle' => $contactKey, 'libelle' => $label]);
        $this->session->remove(self::FAILURES);
    }

    /** @return array{cle: string, libelle: string}|null */
    public function contact(): ?array
    {
        $contact = $this->session->get(self::CONTACT);

        return is_array($contact) && is_string($contact['cle'] ?? null) && is_string($contact['libelle'] ?? null)
            ? ['cle' => $contact['cle'], 'libelle' => $contact['libelle']]
            : null;
    }

    public function logout(): void
    {
        $this->session->remove(self::CONTACT);
        $this->session->regenerate();
    }

    /** @return list<int> horodatages (secondes) des échecs récents */
    public function failures(): array
    {
        $failures = $this->session->get(self::FAILURES, []);

        return is_array($failures) ? array_values(array_filter($failures, 'is_int')) : [];
    }

    /** @param list<int> $failures */
    public function setFailures(array $failures): void
    {
        $this->session->set(self::FAILURES, $failures);
    }
}
