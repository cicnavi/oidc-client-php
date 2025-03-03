# Upgrading

## [3.0.0] - 2025-08-24

Major release.

### Changed 
- **Breaking**: Class `Cicnavi\Oidc\Client` (`src/Client.php`) now expects options as arguments,
 instead of `Cicnavi\Oidc\Config` (`src/Config.php`) instance. Check the [README](README.md)
 for more details on how to instantiate client instances. 

### Removed 
- **Breaking**: Class `Cicnavi\Oidc\Config` (`src/Config.php`) has been removed. Configuration
 options for client are now passed as arguments.

### Added
### Fixed 
