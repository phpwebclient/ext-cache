# Changelog

## Version 2.0.0

- Changed [psr/simple-cache](https://packagist.org/packages/psr/simple-cache) to [webclient/cache-contract](https://packagist.org/packages/webclient/cache-contract). You can choose [adapter](https://packagist.org/providers/webclient/cache-contract-implementation) or implements `Webclient\Cache\Contract\CacheInterface` interface.
- Renamed class `Webclient\Extension\Cache\Client` to `Webclient\Extension\Cache\CacheClientDecorator`
- Added tests
- Fixed caching