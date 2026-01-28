<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\DataStore\DataHandlers\Interfaces;

use Cicnavi\Oidc\Exceptions\OidcClientException;
use SimpleSAML\OpenID\Codebooks\PkceCodeChallengeMethodEnum;

interface PkceDataHandlerInterface
{
    /**
     * Get existing code verifier from store or generate new one and store it.
     *
     * @throws OidcClientException If code verifier could not be generated.
     */
    public function getCodeVerifier(): string;

    /**
     * Generate a code challenge for a provided code verifier using the desired
     * method.
     *
     * @return string Code challenge.
     * @throws OidcClientException If a code verifier is not valid.
     */
    public function generateCodeChallengeFromCodeVerifier(
        string $codeVerifier,
        PkceCodeChallengeMethodEnum $pkceCodeChallengeMethodEnum = PkceCodeChallengeMethodEnum::S256
    ): string;

    /**
     * Remove current code verifier from store.
     */
    public function removeCodeVerifier(): void;
}
