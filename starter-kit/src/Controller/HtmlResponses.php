<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\AccessDeniedException;
use App\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Réponses communes aux contrôleurs HTML. La classe qui l'utilise doit avoir une propriété $renderer.
 *
 * @property ViewRenderer $renderer
 */
trait HtmlResponses
{
    /** 303 : après un POST réussi, le navigateur recharge la page cible en GET (pas de double envoi avec F5). */
    private function redirect(ResponseInterface $response, string $to): ResponseInterface
    {
        return $response->withHeader('Location', $to)->withStatus(303);
    }

    private function notFound(ResponseInterface $response, string $what = 'Page'): ResponseInterface
    {
        return $this->renderer->render($response, 'erreur', [
            'titre' => $what . ' introuvable',
            'message' => 'Cet élément n\'existe pas ou n\'existe plus.',
        ], 404);
    }

    private function denied(ResponseInterface $response, AccessDeniedException $exception): ResponseInterface
    {
        return $this->renderer->render($response, 'erreur', [
            'titre' => 'Accès refusé',
            'message' => $exception->getMessage(),
        ], 403);
    }

    /**
     * Champs du formulaire, toujours sous forme de chaînes (un tableau envoyé devient '').
     *
     * @param list<string> $fields
     * @return array<string, string>
     */
    private function formValues(ServerRequestInterface $request, array $fields): array
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = is_string($body[$field] ?? null) ? $body[$field] : '';
        }

        return $values;
    }
}
