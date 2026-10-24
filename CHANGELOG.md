# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [2.1.0] - 2026-10-24

### Added

- `verifyCallback()` checks a callback by fetching the payment's real status from ZAMM PAY, so a forged callback cannot mark an order as paid.
- New response helpers: `isCompleted()`, `isPending()`, `isCanceled()`, `getTransactionId()`, `getPaymentUrl()`, `getStatus()`, `getAmount()`, `get()`, `getErrors()` and `getStatusCode()`.
- `Zammpay\PaymentStatus` constants.
- `Zammpay\ZammpayException`, with the HTTP status when ZAMM PAY answered.
- A pluggable `Zammpay\Http\Transport`. The default `CurlTransport` has configurable timeouts.
- Tests, PHPStan at max level, and CI on PHP 7.2 to 8.4.

### Fixed

- An answer that was not JSON, such as a 502 page or an empty body, caused a fatal `TypeError`. It now throws `ZammpayException`.
- Requests had no timeout, so a slow gateway could hang the merchant's page. They now time out after 30 seconds, or 10 seconds when connecting.
- `createPayment()` now returns the checkout URL that ZAMM PAY sends instead of building its own.
- Validation errors from ZAMM PAY are now kept, through `getErrors()`.
- PHP 8.4 deprecation notices about implicitly nullable parameters are gone.
- `composer.json` had a duplicate `require` key that dropped the PHP version constraint.
- The README described a different product. It has been rewritten.

### Changed

- Connection failures now throw `ZammpayException` instead of `Exception`. `ZammpayException` extends `RuntimeException`, so an existing `catch (Exception $e)` still catches it.
- The constructor refuses an empty API key or a base URL that is not http(s).

## [2.0] - 2026-04-18

- Create and verify payments.
