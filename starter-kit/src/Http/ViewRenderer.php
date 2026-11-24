<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class ViewRenderer
{
    /** @var array<string, callable(): mixed> */
    private array $shared = [];

    public function __construct(private string $basePath)
    {
    }

    /**
     * Variable disponible dans toutes les vues (ex. l'utilisateur connecté pour l'en-tête).
     * La valeur est calculée au moment du rendu, donc après le démarrage de la session.
     *
     * @param callable(): mixed $provider
     */
    public function share(string $name, callable $provider): void
    {
        $this->shared[$name] = $provider;
    }

    public function render(
        ResponseInterface $response,
        string $template,
        array $data = [],
        int $status = 200
    ): ResponseInterface {
        $file = $this->basePath . '/' . $template . '.php';

        if (!is_file($file)) {
            throw new RuntimeException('Vue introuvable : ' . $template);
        }

        foreach ($this->shared as $name => $provider) {
            $data[$name] ??= $provider();
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $file;
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withStatus($status);
    }
}
