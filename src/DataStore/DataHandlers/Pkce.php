<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\DataStore\DataHandlers;

use Cicnavi\Oidc\DataStore\DataHandlers\Interfaces\PkceDataHandlerInterface;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\Helpers\StringHelper;
use SimpleSAML\OpenID\Codebooks\PkceCodeChallengeMethodEnum;

use function preg_match;

class Pkce extends AbstractDataHandler implements PkceDataHandlerInterface
{
    /**
     * @var string Key used for session storage.
     */
    public const CODE_VERIFIER_SESSION_KEY = 'OIDC_CODE_VERIFIER_PARAMETER';


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
    public function generateCodeChallengeFromCodeVerifier(
        string $codeVerifier,
        PkceCodeChallengeMethodEnum $pkceCodeChallengeMethodEnum = PkceCodeChallengeMethodEnum::S256,
    ): string {
        // Validate code_verifier according to RFC-7636
        // @see: https://tools.ietf.org/html/rfc7636#section-4.1
        if (preg_match('/^[A-Za-z0-9-._~]{43,128}$/', $codeVerifier) !== 1) {
            throw new OidcClientException('Code verifier not valid.');
        }

        return match ($pkceCodeChallengeMethodEnum) {
            PkceCodeChallengeMethodEnum::Plain => $codeVerifier,
            PkceCodeChallengeMethodEnum::S256 => strtr(
                rtrim(base64_encode(hash('sha256', $codeVerifier, true)), '='),
                '+/',
                '-_'
            ),
        };
    }

    /**
     * @inheritDoc
     */
    public function removeCodeVerifier(): void
    {
        $this->sessionStore->delete(self::CODE_VERIFIER_SESSION_KEY);
    }
}
