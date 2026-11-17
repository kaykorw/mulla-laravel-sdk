# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [0.0.1] - Unreleased

### Added

- Supports Laravel 12 and 13 on PHP 8.2+ (Laravel 10 and 11 no longer receive security fixes).
- Auto-discovered `MullaServiceProvider` and publishable `config/mulla.php` driven by `MULLA_*` variables.
- `Mulla\MullaClient` container binding (alias `mulla`) and the `Mulla` facade (`Mulla::charges()`, …).
- Webhook route (`POST /mulla/webhook`) or `Route::mullaWebhooks()` macro, with `VerifyMullaSignature` middleware.
- Laravel events: `WebhookReceived`, `ChargeSucceeded`, `ChargeFailed`, `ChargeExpired`, `PayoutSucceeded`,
  `PayoutFailed`, `InvoicePaid`, `WebhookTested`.
- Event de-duplication by event id through the cache; failed listeners return 500 so Mulla redelivers.
- Artisan commands: `mulla:install`, `mulla:webhook:test`, `mulla:webhook:simulate`.
- Testing helpers: `Mulla::fake()`, `InteractsWithMulla` (`fakeMulla()`, `postMullaWebhook()`), `WebhookPayloads`.
