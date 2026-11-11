# laravel-idempotency — design v0.1

> Исходная спецификация, по которой написан v0.1, с решениями по развилкам. Актуальное поведение и конфигурация — в [README](../README.md).

`composer require azhimov/laravel-idempotency`

## Проблема
Клиент отправил `POST /payments`, соединение оборвалось, клиент повторил запрос — платёж создан дважды.
Стандартное решение (Stripe, Adyen и др., IETF draft *The Idempotency-Key HTTP Header Field*): клиент шлёт уникальный
`Idempotency-Key`, сервер выполняет операцию один раз и на повторы отдаёт сохранённый ответ.

## Аналоги и честные отличия
Проверено на Packagist 11.11.2026:

| Пакет | Что уже умеет |
|---|---|
| square1/laravel-idempotency (~308k загрузок) | lock, 409, fingerprint, 422, scope user/ip/global, выбор статусов; только cache, без событий; Laravel 12–13 |
| wendelladriel/laravel-idempotency (~41k) | только cache, lock; по умолчанию сохраняет и 5xx; только Laravel 13 |
| infinitypaul/idempotency-laravel (~40k) | lock, телеметрия, алерты |
| grazulex/laravel-api-idempotency (~1k) | cache/redis/database/DynamoDB, fingerprint, события, scope по тенанту |

Fingerprint, lock, DB-хранилище и события — **не** отличия. Наши отличия:
1. **Надёжное DB-хранилище для денежных операций.** Запись — конечный автомат `processing → completed`, lock = уникальная вставка строки. Не зависит от Redis, ключ не пропадёт при eviction.
2. **Защита при истечении lock.** У записи есть owner-token; ответ сохраняется только если lock всё ещё наш (compare-and-set). Дубль, забравший истёкший lock, не перезапишет результат.
3. **Безопасные умолчания:** 5xx не сохраняются, гости не делят одно пространство ключей.
4. **Laravel 12–13**, связка с laravel-outbox и payments-outbox.

## Решения v0.1
- **Lock держит хранилище.** `begin(scope, key, fingerprint): Started | InProgress | Completed | Mismatch`; `complete(token, response)` и `release(token)` принимают owner-token. Отдельного `Cache::lock` в middleware нет.
- **Гости:** scope `guest:{ip}` (IP через TrustProxies). В README предупредить про NAT/общий IP; рекомендовать `required` только для аутентифицированных маршрутов.
- **`store_status_codes`** — список диапазонов: `[[200, 499]]` (3xx сохраняются: повтор POST с redirect не должен выполняться снова; изменено на ревью v0.1.0).
- **Маршрут в scope** — метод + шаблон URI (`payments/{id}`); конкретный путь входит в fingerprint.
- **Другое тело, пока первый запрос выполняется → 422** (fingerprint сохраняется в `begin()`).
- **Stream- и file-ответы** не сохраняются, lock снимается.
- **CI:** PHP 8.2–8.5 × Laravel 12–13 + prefer-lowest; PHP 8.2 × Laravel 13 исключён.

## Решения на ревью v0.1.0
- `store_status_codes` по умолчанию `[[200, 499]]`: 3xx сохраняются, повтор POST → 303 отдаёт тот же 303.
- Env: `IDEMPOTENCY_STORE`, `IDEMPOTENCY_TTL`, `IDEMPOTENCY_REQUIRED` (строгий парсинг, мусор → ошибка конфига).
- Ограничение v0.1 зафиксировано в README: окно «бизнес-изменение выполнено, `complete()` не успел». Для `DatabaseStore` рекомендуется отдельное подключение.
- v0.2: opt-in `DatabaseStore::completeInTransaction()` — ответ коммитится в той же транзакции, что и бизнес-изменение.
- Laravel 11 убран из поддержки: после окончания security-поддержки у всех релизов 11.x есть открытые advisories, Composer их не ставит. Поддерживаются Laravel 12–13.

## Поведение
1. Middleware `idempotent` (алиас) / `Azhimov\Idempotency\Http\Middleware\EnsureIdempotency`.
   Параметры: `idempotent:required`, `idempotent:ttl=3600`.
2. Работает только для методов из конфига (по умолчанию `POST`, `PATCH`). Остальные — пропускает.
3. Ключ — из заголовка `Idempotency-Key` (имя настраивается). Валидация: непустой, ≤ 255 символов, допустимые символы.
   - нет ключа и режим `required` → **400** (`idempotency_key_missing`)
   - нет ключа и режим optional → обычное выполнение без идемпотентности
4. **Scope ключа:** ключ + пользователь (auth id или `guest:{ip}`) + метод + шаблон маршрута. Один и тот же ключ у разных пользователей не конфликтует. Резолвер scope настраивается (например, по API-клиенту/тенанту).
5. **Fingerprint запроса:** hash(method + path + нормализованное тело). Тот же ключ с другим телом → **422** (`idempotency_key_reused`).
6. **Конкурентность:** хранилище атомарно захватывает ключ в `begin()` (cache: все переходы под коротким per-key mutex на `Cache::lock`; database: `insertOrIgnore` по первичному ключу + условные `UPDATE`/`DELETE`).
   Второй запрос с тем же ключом, пока первый выполняется → **409** (`idempotency_request_in_progress`) + заголовок `Retry-After`.
7. **Сохранение ответа:** статус, тело, заголовки из белого списка (`Content-Type`, `Location`), TTL (по умолчанию 24 ч).
   - 2xx, 3xx и 4xx — сохраняются (настраивается)
   - 5xx — НЕ сохраняются, lock снимается, клиент может повторить
8. **Повтор:** отдаётся сохранённый ответ + заголовок `Idempotent-Replayed: true`. Контроллер не вызывается.
9. Исключение в контроллере → lock снимается, ничего не сохраняется.

## Хранилища
- `cache` (по умолчанию) — любой Laravel cache store с поддержкой locks (Redis рекомендуется)
- `database` — таблица `idempotency_keys` (миграция публикуется), artisan `idempotency:purge` для истёкших записей
- Контракт `IdempotencyStore` для своих реализаций

## Конфиг `config/idempotency.php`
```php
return [
    'header' => 'Idempotency-Key',
    'methods' => ['POST', 'PATCH'],
    'required' => false,
    'ttl' => 86400,
    'lock_timeout' => 30,
    'store' => 'cache',            // cache | database
    'cache_store' => null,         // null = default
    'replay_header' => 'Idempotent-Replayed',
    'store_status_codes' => [[200, 499]],
    'replay_headers' => ['Content-Type', 'Location'],
    'scope_resolver' => \Azhimov\Idempotency\Scope\UserScopeResolver::class,
    'max_key_length' => 255,
];
```

## События
`IdempotentRequestStarted`, `IdempotentResponseStored`, `IdempotentResponseReplayed`, `IdempotencyKeyMismatch`, `IdempotencyConflict` — для метрик и логов.

## Ошибки (единый JSON)
```json
{ "error": { "code": "idempotency_key_reused", "message": "..." } }
```
Формат ответа об ошибке настраивается через свой `ErrorResponder`.

## Структура пакета
```
src/
  IdempotencyServiceProvider.php
  Http/Middleware/EnsureIdempotency.php
  Contracts/IdempotencyStore.php, ScopeResolver.php, ErrorResponder.php
  Stores/CacheStore.php, DatabaseStore.php
  Scope/UserScopeResolver.php
  Support/Fingerprint.php, StoredResponse.php (readonly DTO), IdempotencyKey.php (value object)
  Events/…
  Console/PurgeCommand.php
config/idempotency.php
database/migrations/create_idempotency_keys_table.php.stub
tests/ (Pest + Orchestra Testbench)
```

## Тесты (минимум)
- первый запрос выполняется, ответ сохраняется
- повтор отдаёт тот же ответ, контроллер вызван 1 раз, есть `Idempotent-Replayed`
- тот же ключ + другое тело → 422
- параллельный запрос во время выполнения → 409
- 5xx не сохраняется, повтор выполняет заново
- исключение снимает lock
- разные пользователи с одним ключом не конфликтуют
- GET игнорируется; required без ключа → 400
- оба хранилища (cache, database) проходят общий набор тестов
- истечение TTL, команда purge

## CI
GitHub Actions: матрица PHP 8.2–8.5 × Laravel 12–13 (+ prefer-lowest, без PHP 8.2 × Laravel 13), Pest, Larastan, Pint.

## Definition of Done v0.1.0
Чеклист готовности репозитория + пример в README: обычный `POST /payments` и повтор с `curl`, диаграмма последовательности (Mermaid) для первого запроса, повтора и конфликта.
