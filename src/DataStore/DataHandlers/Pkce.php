<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\DataStore\DataHandlers;

use Cicnavi\Oidc\DataStore\DataHandlers\Interfaces\PkceDataHandlerInterface;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Helpers\StringHelper;

use function preg_match;

class Pkce extends AbstractDataHandler implements PkceDataHandlerInterface
{
    /**
     * @var string Key used for session storage.
     */
    public const CODE_VERIFIER_SESSION_KEY = 'OIDC_CODE_VERIFIER_PARAMETER';

    /**
     * @var string[]
     */
    public const VALID_PKCE_CODE_CHALLENGE_METHODS = ['plain', 'S256'];

    /**
     * @inheritDoc
     */
    public function getCodeVerifier(): string
    {
        if (is_string($codeVerifier = $this->sessionStore->get(self::CODE_VERIFIER_SESSION_KEY))) {
            return $codeVerifier;
        }

        $codeVerifier = StringHelper::random(128);

        $this->sessionStore->put(self::CODE_VERIFIER_SESSION_KEY, $codeVerifier);

        return $codeVerifier;
    }

    /**
     * @inheritDoc
     */
    public function generateCodeChallengeFromCodeVerifier(string $codeVerifier, string $method = 'S256'): string
    {
        // Validate code_verifier according to RFC-7636
        // @see: https://tools.ietf.org/html/rfc7636#section-4.1
        if (preg_match('/^[A-Za-z0-9-._~]{43,128}$/', $codeVerifier) !== 1) {
            throw new OidcClientException('Code verifier not valid.');
        }

        $this->validatePkceCodeChallengeMethod($method);

        $codeChallenge = $codeVerifier;
        switch ($method) {
            case 'plain':
                break;
            case 'S256':
                $codeChallenge = strtr(rtrim(base64_encode(hash('sha256', $codeVerifier, true)), '='), '+/', '-_');
                break;
        }

        return $codeChallenge;
    }

    /**
     * @inheritDoc
     */
    public function removeCodeVerifier(): void
    {
        $this->sessionStore->delete(self::CODE_VERIFIER_SESSION_KEY);
    }

    /**
     * @inheritDoc
     */
    public function validatePkceCodeChallengeMethod(string $pkceCodeChallengeMethod): void
    {
        if (! in_array($pkceCodeChallengeMethod, self::VALID_PKCE_CODE_CHALLENGE_METHODS)) {
            throw new OidcClientException('PKCE Code challenge method not valid.');
        }
    }
}
