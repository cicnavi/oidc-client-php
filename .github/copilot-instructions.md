# AI Coding Agent Instructions - OIDC Client PHP

## Project Overview
**cicnavi/oidc-client-php** is a production-grade PHP library implementing OpenID Connect (OIDC) authorization code flow. It handles the complete auth flow including JWT token verification, JWKS key rotation, and userinfo claims fetching. The library targets PHP 8.2+ and uses PSR standards (PSR-7, PSR-18, PSR-16 caching).

## Core Architecture

### Main Components
- **`Client.php`**: Orchestrates the entire OIDC flow (`authorize()` → `getUserData()`). Manages state, nonce, PKCE, token exchange, and userinfo fetching. Dependency-injected design allows PSR-18 HTTP client and PSR-16 cache swapping.
- **`Config.php`**: Immutable configuration holder with mandatory fields (OP URL, client ID/secret, redirect URI, scope) and optional leeway/algorithm settings. Validates config in constructor; throws `OidcClientException` on invalid input.
- **`Metadata.php`**: Fetches and caches OIDC Discovery metadata from the provider's `.well-known/openid-configuration`. Validates required params exist (`issuer`, `token_endpoint`, `jwks_uri`, etc.).
- **`DataStore/PhpSessionDataStore.php`**: Default session storage for state, nonce, and PKCE parameters. Automatically starts PHP session; bypasses for CLI testing.
- **`Cache/FileCache.php`**: PSR-16 file-based cache (wraps cicnavi/simple-file-cache-php). Caches OIDC metadata and JWKS to avoid redundant HTTP requests.

### Data Flow
```
authorize()
  └─ Generate state/nonce (StateNonce handler)
  └─ Generate PKCE if public client (Pkce handler)
  └─ Redirect to provider's authorization_endpoint

getUserData()
  └─ Validate state/nonce from session
  └─ Exchange code for tokens (token_endpoint)
  └─ Verify & parse JWT ID token (Jose library for cryptography)
  └─ Fetch JWKS keys (cached)
  └─ Optionally fetch userinfo claims
  └─ Return merged claims array
```

## Key Patterns & Conventions

### Dependency Injection
All major classes accept dependencies via constructor (Config, Cache, DataStore, HTTP client). Provides optional parameters with sensible defaults (GuzzleHttp client, FileCache). **Always inject test doubles in unit tests**, never mock internals.

### Exception Handling
- **`OidcClientException`**: Base exception for all OIDC errors (invalid config, token validation failure, HTTP errors).
- Thrown during: config validation, metadata fetch failures, JWT verification, state/nonce mismatch.
- **Always propagate as `OidcClientException`** for consistency; wrap PSR/external exceptions.

### PSR Compliance
- **PSR-18** (`ClientInterface`) for HTTP calls via GuzzleHttp by default.
- **PSR-16** (`CacheInterface`) for caching; FileCache implements this.
- **PSR-7** for HTTP messages (Request/Response).
- Allows swapping implementations at instantiation.

### Configuration Constants
Config keys are class constants prefixed `OPTION_` (e.g., `Config::OPTION_CLIENT_ID`). Default values use `getDefaultConfig()` method. Validates mandatory fields; optional fields have sensible defaults (leeway=0, state check enabled, confidential client=true).

### Session & CLI Handling
`PhpSessionDataStore` detects CLI context (`PHP_SAPI === 'cli'`) and uses array instead of `session_start()`. Essential for PHPUnit tests. Always test both web and CLI contexts if modifying session logic.

## Development Workflow

### Running Tests
```bash
composer run-script test  # Runs full test suite via phpunit
```
Tests located in `tests/Oidc/` with config fixtures in `tests/data/oidc-config.json`. Mock HTTP responses using GuzzleHttp `MockHandler`. Tests bootstrap via `tests/bootstrap.php`.

### Code Standards
```bash
composer run-script pre-commit  # Runs full QA pipeline:
  - phpcbf (auto-fix PSR-12)
  - phpcs (code sniffer check)
  - phpstan (static analysis level max)
  - rector (modernization dry-run)
  - phpunit (full test suite)
```
**Always follow PSR-12** style; use `declare(strict_types=1)` at top of every file.

### Key Files for Reference
- Test patterns: `tests/Oidc/ClientTest.php` (833 lines, comprehensive flows)
- Config validation: `src/Validators/ConfigValidator.php`
- JWT handling: See Config's `getIdTokenValidationSupportedSignatureAlgs()` (supports RS256, ES256, EdDSA, etc.)
- HTTP abstraction: `src/Http/RequestFactory.php`

## Integration Points

### External Dependencies
- **web-token/jwt-easy**: JWT parsing/verification (uses Jose Component).
- **guzzlehttp/guzzle**: Default HTTP client (PSR-18).
- **cicnavi/simple-file-cache-php**: Default cache implementation.
- **simplesamlphp/openid**: OpenID support.

### Customization Examples
- **Custom cache**: Implement `PSR\SimpleCache\CacheInterface`, inject via constructor.
- **Custom HTTP client**: Implement `PSR\Http\Client\ClientInterface`, inject via constructor.
- **PKCE toggle**: Set `Config::OPTION_PKCE_CODE_CHALLENGE_METHOD` or `Config::OPTION_IS_CONFIDENTIAL_CLIENT`.
- **Algorithm whitelist**: Override `Config::OPTION_ID_TOKEN_VALIDATION_ALLOWED_SIGNATURE_ALGS`.

## Common Workflows

### Adding a New Feature
1. Update `Config.php` if new option needed (add constant, update `getDefaultConfig()`, getters).
2. Update `ConfigInterface.php` with getter method signature.
3. Update `ConfigValidator.php` if validation logic required.
4. Implement feature in `Client.php` or relevant class.
5. Write comprehensive tests in `tests/Oidc/`.
6. Run `composer run-script pre-commit` before commit.

### Debugging Token Issues
- Check `Config` leeway settings (`IAT_LEEWAY`, `EXP_LEEWAY`, `NBF_LEEWAY`) if time-sync issues.
- Verify JWKS fetch success via cache inspection or `reinitializeCache()`.
- Use `Config::getIdTokenValidationAllowedSignatureAlgs()` to confirm alg support.
- ID token JWT structure: `[header.payload.signature]` parsed by Jose library.

### Extending for Different Flows
Currently supports **authorization code flow** (confidential & public via PKCE). Client-credentials or implicit flows would require separate `Client` subclass or new workflow method.

## Version Considerations
Current branch: **v3.x**. See `UPGRADING.md` for breaking changes from v2.x (Config now immutable, passed as constructor arg to Client).
