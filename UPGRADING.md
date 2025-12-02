# Upgrading

## [3.0.0] - 2025-12-02

Major release with breaking changes to the client instantiation API.

### Changed 
- **Breaking**: Class `Cicnavi\Oidc\Client` (`src/Client.php`) now accepts configuration options as direct constructor parameters using PHP 8.2's property promotion, instead of accepting a `Cicnavi\Oidc\Config` instance.
- **Breaking**: Class `Cicnavi\Oidc\Metadata` (`src/Metadata.php`) now accepts configuration parameters directly instead of a `ConfigInterface` instance.

### Removed 
- **Breaking**: Class `Cicnavi\Oidc\Config` (`src/Config.php`) has been removed.
- **Breaking**: Interface `Cicnavi\Oidc\Interfaces\ConfigInterface` (`src/Interfaces/ConfigInterface.php`) has been removed.
- All `Config::OPTION_*` constants are no longer available.

### Migration Guide

#### Before (v2.x):
```php
use Cicnavi\Oidc\Config;
use Cicnavi\Oidc\Client;

$config = new Config([
    Config::OPTION_OP_CONFIGURATION_URL => 'https://example.org/.well-known/openid-configuration',
    Config::OPTION_CLIENT_ID => 'client-id',
    Config::OPTION_CLIENT_SECRET => 'client-secret',
    Config::OPTION_REDIRECT_URI => 'https://your-app.org/callback',
    Config::OPTION_SCOPE => 'openid profile',
    Config::OPTION_IS_CONFIDENTIAL_CLIENT => true,
    Config::OPTION_DEFAULT_CACHE_TTL => 3600,
]);

$client = new Client($config);
```

#### After (v3.0):
```php
use Cicnavi\Oidc\Client;

$client = new Client(
    opConfigurationUrl: 'https://example.org/.well-known/openid-configuration',
    clientId: 'client-id',
    clientSecret: 'client-secret',
    redirectUri: 'https://your-app.org/callback',
    scope: 'openid profile',
    isConfidentialClient: true,
    defaultCacheTtl: 3600
);
```

#### With Custom Cache (Before):
```php
$config = new Config([...]);
$cache = new FileCache('custom-cache-path');
$client = new Client($config, $cache);
```

#### With Custom Cache (After):
```php
$cache = new FileCache('custom-cache-path');
$client = new Client(
    opConfigurationUrl: '...',
    clientId: '...',
    clientSecret: '...',
    redirectUri: '...',
    scope: '...',
    cache: $cache
);
```

### Benefits
- **Type Safety**: All configuration options are now strongly typed
- **Better IDE Support**: Named parameters provide autocomplete and inline documentation
- **Simplified API**: No need to create a separate Config object
- **Modern PHP**: Leverages PHP 8.2's property promotion feature

Check the [README](README.md) for complete usage examples.

### Added
### Fixed
