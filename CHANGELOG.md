# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-11

### Added
- `idempotent` middleware (`EnsureIdempotency`) with route options `required`, `optional`, `ttl=<seconds>`.
- Replay of stored responses (status, body, whitelisted headers) with `Idempotent-Replayed: true`; 2xx, 3xx and 4xx are stored by default, 5xx never.
- Key scoping by owner + HTTP method + route template; `UserScopeResolver` (user model + id, guests by IP) and the `ScopeResolver` contract.
- Request `Fingerprint` (method, path, sorted query, normalized JSON/form body, uploaded file hashes); 422 `idempotency_key_reused` on mismatch.
- 409 `idempotency_request_in_progress` with `Retry-After` for concurrent retries.
- 400 `idempotency_key_missing` / `idempotency_key_invalid`; configurable `ErrorResponder` (default JSON).
- `IdempotencyStore` contract where the store owns the lock, with owner-token fencing on `complete()` / `release()`.
- `CacheStore` (any cache store with atomic locks) and `DatabaseStore` (publishable migration, `idempotency:purge` command).
- Shared store contract test suite for custom stores.
- Events: `IdempotentRequestStarted`, `IdempotentResponseStored`, `IdempotentResponseReplayed`, `IdempotencyKeyMismatch`, `IdempotencyConflict`, `IdempotencyLockLost`.
- `IDEMPOTENCY_STORE`, `IDEMPOTENCY_TTL` and `IDEMPOTENCY_REQUIRED` env variables, parsed strictly.
- Support for PHP 8.2–8.5 and Laravel 12–13.

### Known limitations
- The response is stored after the handler returns, in a separate transaction from the business change: a crash in between lets a retry run the handler again after `lock_timeout`. Planned for 0.2.0: opt-in `DatabaseStore::completeInTransaction()` to commit the response atomically with the operation.

[Unreleased]: https://github.com/azhimov/laravel-idempotency/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/azhimov/laravel-idempotency/releases/tag/v0.1.0
