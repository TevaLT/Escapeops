<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\HttpInputException;
use App\Http\JsonInput;
use App\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Réponses communes des contrôleurs de l'API (US-54) : ils ne contiennent aucune règle métier,
 * ils traduisent la requête JSON pour le service puis la réponse (ou l'exception) du service en JSON.
 *
 * Toutes les erreurs ont la même forme : {"error": "CODE", "message": "…"} (+ "details" pour un 422).
 */
trait JsonResponses
{
    private function json(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
    {
        return JsonResponder::respond($response, $data, $status);
    }

    /** @param array<string, string> $details erreurs par champ (422) */
    private function error(ResponseInterface $response, int $status, string $code, string $message, array $details = []): ResponseInterface
    {
        $body = ['error' => $code, 'message' => $message];
        if ($details !== []) {
            $body['details'] = $details;
        }

        return JsonResponder::respond($response, $body, $status);
    }

    /**
     * Lit le corps JSON (415 / 400 via JsonInput) et renomme ses champs pour le service :
     * l'API parle camelCase (« nbJoueurs »), les services reçoivent les noms du formulaire HTML (« nb_joueurs »).
     * Les entiers JSON deviennent des chaînes, comme un champ de formulaire : le service les valide de la même façon.
     *
     * @param array<string, string> $mapping champ JSON => champ du service
     * @return array<string, mixed>
     * @throws HttpInputException
     */
    private function readInput(ServerRequestInterface $request, array $mapping): array
    {
        $body = JsonInput::readObject($request);
        $input = [];
        foreach ($mapping as $jsonField => $serviceField) {
            $value = $body[$jsonField] ?? null;
            $input[$serviceField] = is_int($value) ? (string) $value : $value;
        }

        return $input;
    }

    /**
     * Erreurs de validation du service, renvoyées avec les noms de champs de l'API.
     *
     * @param array<string, string> $errors champ du service => message
     * @param array<string, string> $mapping champ JSON => champ du service
     * @return array<string, string>
     */
    private static function apiFieldErrors(array $errors, array $mapping): array
    {
        $names = array_flip($mapping);
        $details = [];
        foreach ($errors as $field => $message) {
            $details[$names[$field] ?? $field] = $message;
        }

        return $details;
    }

    private function inputError(ResponseInterface $response, HttpInputException $exception): ResponseInterface
    {
        return $this->error(
            $response,
            $exception->status,
            $exception->status === 415 ? 'UNSUPPORTED_MEDIA_TYPE' : 'BAD_REQUEST',
            $exception->getMessage()
        );
    }
}
