<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\DataStore\DataHandlers\Interfaces;

use Cicnavi\Oidc\Exceptions\OidcClientException;

interface PkceDataHandlerInterface
{
    /**
     * Get existing code verifier from store or generate new one and store it.
     *
     * @throws OidcClientException If code verifier could not be generated.
     */
    public function getCodeVerifier(): string;

    /**
     * Generate code challenge for provided code verifier using the desired method.
     *
     * @return string Code challenge.
     * @throws OidcClientException If code verifier is not valid or method is not valid.
     */
    public function generateCodeChallengeFromCodeVerifier(string $codeVerifier, string $method = 'S256'): string;

    /**
     * Remove current code verifier from store.
     */
    public function removeCodeVerifier(): void;

    /**
     * @throws OidcClientException If code challenge method is not valid
     */
    public function validatePkceCodeChallengeMethod(string $pkceCodeChallengeMethod): void;
}
