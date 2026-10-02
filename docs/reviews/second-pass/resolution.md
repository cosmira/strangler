# Исправления повторного ревью

Эта карта отвечает на все 65 строк исторического ревью версии `2d026f9`:
[33 оценки API/DX](otwell-lens.md) и [32 оценки простоты](dhh-lens.md).
Положительные оценки тоже рассмотрены: «сохранено» означает осознанно оставить проверенное поведение.
«Интеграционное условие» означает обязательство приложения, с конкретным руководством,
а не добавление в библиотеку ещё одной системы авторизации или error-handling framework.

Ссылки ниже относятся к текущей реализации и тестам. Наличие теста не заменяет результата его запуска:
финальные quality gates и удалённые CI должны быть подтверждены отдельно после сборки всех изменений.

## Выразительность API и удобство: O1–O33

| Строка | Параметр | Решение | Реализация | Доказательство |
| --- | --- | --- | --- | --- |
| O1 | Назначение | Сохранено | Одна фича, Yii сохраняет проверки, явное переключение executor. | [README][readme], [filter]; `testConfiguredFilterForwardsInsteadOfRunningTheLegacyAction` [proxy-tests]. |
| O2 | Целевая платформа | Исправлено | Имя Yii 1 Strangler Proxy и Composer yii1-strangler-proxy явно обозначают поколение. Внешний rename отдельно ниже. | [README][readme], [Composer][composer]. |
| O3 | Область применения | Сохранено | JSON API, формы как JSON; файлы, binary и streaming исключены. | [README][readme], [payload contract][integration]. |
| O4 | Установка | Исправлено | Основной путь Composer VCS + ^0.1 без клонирования. Path checkout только для разработки. Packagist не объявлен опубликованным. | [Getting started][readme], [Local development][integration]. |
| O5 | Первый запрос | Сохранено | Read-only action, curl, ожидаемый body/marker, проверка выключенного флага. | [README][readme]; `testConfiguredFilterForwardsInsteadOfRunningTheLegacyAction`, `testDisabledUnmappedAndUnsupportedRequestsRunInYii` [proxy-tests]. |
| O6 | Безопасность quickstart | Исправлено | Начальный флаг false; включение после проверки trusted backend и предыдущих filters; опубликованный backend проверен реальными HTTP-запросами. | [README][readme], [Trusted channel][integration]. |
| O7 | Fluent API | Сохранено | Короткая цепочка возвращает native Yii filter config, без registrar/DSL. | [builder]; `testBuilderExportsAllHttpMethodsAndIndependentActions` [proxy-tests]. |
| O8 | Исходящий метод | Интеграционное условие | Builder задаёт outgoing verb; конкретный preceding verb-filter показывает GET/POST для старого API. | [Write-action validation][integration], [guide]; `testMethodsQueryAndPayloadPolicy` [proxy-tests]. |
| O9 | Маршрутные ошибки | Сохранено | Пустые/повторные actions и некорректные пути отклоняются немедленно. | [builder]; `testEmptyBuilderRoutesAreRejected`, `testDuplicateActionsFailInsteadOfReplacingARoute` [proxy-tests]. |
| O10 | Adapter terminology | Исправлено | Единственный usingAdapter, StranglerAdapterInterface, AbstractStranglerAdapter, CatalogApiAdapter; прежние aliases удалены. | [builder], [interface], [guide]; [guide-tests], [adapter-tests]. |
| O11 | Маленький адаптер | Сохранено | Три transform methods, базовая реализация без DTO/config framework. | [adapter-base], [interface]; `testUnmodifiedContractsKeepEveryValue` [adapter-tests]. |
| O12 | Создание адаптера | Исправлено | Описаны instance, class name и Yii property config; никаких параллельных API. | [guide]; `testYiiCreatesAConfiguredAdapter`, `testClassNameAndEmptyAdapterConfigurations` [proxy-tests]. |
| O13 | Различные API | Сохранено | Полный каталог: URL, POST→PUT, nested body, flags, list response, errors. | [guide]; `testPublishedExamplePreservesTheLegacyCatalogContract`, `testGuideUpdateActuallyUsesTheNewMethodPathAndLegacyResponse` [guide-tests]. |
| O14 | Пагинация | Сохранено; проверка расширена | Неполные offsets остаются в Yii до backend; опубликованный builder bypass исполняется тестом. | [guide]; `testPublishedPaginationBypassNeverRoundsLegacyOffsets` [guide-tests]. |
| O15 | Route ID | Сохранено | Original bodyIdentifier, request-id priority, понятные 400 при отсутствии параметра. | [guide], [proxy]; `testUrlIdentifierTakesPrecedenceOverPayload`, `testInvalidRouteIdentifiersCannotTargetACollection` [proxy-tests]. |
| O16 | Dot segments | Исправлено | Значения . и .. отклоняются до Guzzle; иной resource не вызывается. | [proxy]; `testPayloadPlaceholdersCannotNavigateToAnotherResource` [proxy-tests]. |
| O17 | Backend prefix | Исправлено | base_uri только HTTP(S) origin; весь prefix расположен в /route. | [proxy], [integration]; `testBackendMustBeAnHttpOrigin`, `testBackendOriginKeepsItsSchemeHostAndPort` [proxy-tests]. |
| O18 | Redirects | Исправлено | allow_redirects всегда false; ответ 3xx возвращается, token не следует на второй origin. | [proxy]; `testRedirectResponseNeverReplaysTrustedHeadersToAnotherOrigin` [proxy-tests]. |
| O19 | Feature flags | Сохранено | Только boolean true, missing off; явный environment parsing в Yii bootstrap. | [proxy], [integration]; `testOnlyBooleanTrueEnablesAFeature`, `testArrayLikeFeatureConfigurationCannotEnableAFeature` [proxy-tests]. |
| O20 | Bypass | Сохранено | Preflight и один snapshot; никаких retries после отправки. | [proxy]; `testPayloadReaderRunsOnceAcrossBypassAndForwarding`, `testPayloadBypassKeepsTheOriginalActionAndNeverCallsTheBackend` [proxy-tests]. |
| O21 | Security filter order | Интеграционное условие | Фильтр последний; verb/CSRF/resource checks завершаются раньше. Отказ предыдущего фильтра проверен. | [README][readme], [integration]; `testDeniedPrecedingFilterNeverCallsTheBackend` [proxy-tests]. |
| O22 | Yii lifecycle | Интеграционное условие | Пропущенные hooks перечислены; onEndRequest cleanup пример и native HTTP проверка однократного вызова для proxy/legacy/denied. | [Access checks and Yii hooks][integration], [native-tests], [Write-action validation][integration]. |
| O23 | Identity trust | Интеграционное условие | Server token, HTTPS, direct-call denial и reserved client-header blocking. | [Trusted channel][integration]; `testExplicitAllowlistCannotTrustClientIdentityOrToken` [header-tests]. |
| O24 | Tenant/permissions | Сохранено; интеграционное условие | User ID не передаёт роль/tenant/access автоматически; ownership checks принадлежат приложению. | [README][readme], [Access checks][integration]. |
| O25 | Session/header policy | Сохранено | Client allowlist, server override, Set-Cookie/transport filtering; credentials opt-in явно ограничен контрактом. | [headers], [integration]; `testDefaultPolicyPassesRequestContextWithoutClientCredentials`, `testResponsePolicyPreservesRepeatedHeadersAndDropsCookiesAndTransportMetadata` [header-tests]. |
| O26 | TLS/timeouts | Сохранено | Verify true, CA path; invalid/nonpositive/nonfinite timeout bounded defaults. | [proxy], [integration]; `testTlsVerificationAcceptsExplicitBooleanAndCaBundle`, `testUnboundedTimeoutSettingsFallBackToFiniteDefaults` [proxy-tests]. |
| O27 | Idempotency/failure | Сохранено | Нет retry/legacy replay, Idempotency-Key требует backend contract; redirects follow removed. | [README][readme], [integration]; `testUpstreamErrorsAndRedirectsAreReturnedWithoutRetry`, `testFailedForwardingNeverExecutesTheLegacyActionEvenIfYiiEndReturns` [proxy-tests]. |
| O28 | Package error adaptation | Сохранено | 400/503 используют response adapter и сохраняют ошибочный status. | [proxy], [guide]; `testTransportErrorsUseTheSameResponseAdapter` [proxy-tests], `testPublishedExampleMapsValidationAndTransportErrors` [guide-tests]. |
| O29 | Guard diagnostics | Исправлено | Warning содержит feature/action/path/error/request_id, без body/token. | [proxy], [integration]; `testRejectedRequestsLogFeaturePathErrorAndForwardedCorrelationId` [mutation-tests]. |
| O30 | Callback semantics | Исправлено | afterAttempt вместо afterRequest; явно после transport attempt, до response mapping, включая failure. | [builder], [integration]; `testTransportFailureCallsTheHookOnceAndReturns503`, `testHookFailureIsVisible` [proxy-tests]. |
| O31 | Executable guide | Сохранено; расширено | Markdown adapter и builder исполняются; добавлены shape rejection, metadata, bigint и pagination границы. | [guide-tests]. |
| O32 | Quality badges | Сохранено | Badges описывают gates, не host compatibility; runtime/dependency matrix и обязательный real rollout smoke. | [README][readme], [workflow], [guide]. |
| O33 | Configuration reference | Исправлено | Полная таблица supported params/defaults и builder methods; ненужный redirect setting удалён. | [Configuration reference][integration], [builder], [proxy]. |

## Простота и сопровождение: D1–D32

| Строка | Параметр | Решение | Реализация | Доказательство |
| --- | --- | --- | --- | --- |
| D1 | Смысл | Сохранено | Один фильтр для одной feature, без gateway/framework. | [README][readme], [filter]. |
| D2 | Применимость | Сохранено | JSON API ограничения видны до установки. | [README][readme], [integration]. |
| D3 | Composer/repository name | Исправлено | Выбрано точнее Yii1: cosmira/yii1-strangler-proxy; исходники и ссылки согласованы. | [Composer][composer], [README][readme]. |
| D4 | Framework generation | Исправлено | Yii 1 в product heading и yii1 в package/repository slug. | [README][readme], [Composer][composer]. |
| D5 | Adapter names | Исправлено | Публичный API и docs используют Adapter, aliases старого Modifier не сохранены. | [builder], [interface], [adapter-base], [guide-tests]. |
| D6 | Первый запуск | Исправлено | VCS Composer release ^0.1 без ручного JSON/clone; Packagist registration не обещана. | [Getting started][readme], [Local development][integration]. |
| D7 | Runtime compatibility | Сохранено | PHP 8.2+, Yii 1.1.31+, никаких неподтверждённых обещаний old PHP. | [README][readme], [Composer][composer], [workflow]. |
| D8 | Builder simplicity | Сохранено | Native filter config, без нового container/DSL. | [builder]; `testBuilderExportsAllHttpMethodsAndIndependentActions` [proxy-tests]. |
| D9 | Duplicate action | Сохранено | Повтор отклоняется, включая case aliases. | [builder]; `testDuplicateActionsFailInsteadOfReplacingARoute` [proxy-tests]. |
| D10 | Incoming verb | Интеграционное условие | Реальный preceding validation filter для legacy GET/POST, outbound update PUT отдельно. | [Write-action validation][integration], [validation-tests], [guide]; [guide-tests]. |
| D11 | Security/lifecycle | Интеграционное условие | Hook audit, cleanup через onEndRequest, security до Strangler; native HTTP тест проверяет request-end для proxy/legacy/denied. | [integration]; `testDeniedPrecedingFilterNeverCallsTheBackend` [proxy-tests]. |
| D12 | JSON shape fidelity | Исправлено | Без adapter/reader оригинальные JSON bytes сохранены; adapter array representation явно документирован. | [proxy], [integration]; `testUnmodifiedJsonReachesBackendWithoutChangingItsShape` [proxy-tests]. |
| D13 | Big integers | Исправлено | Raw passthrough без rounding; decoded payload JSON_BIGINT_AS_STRING; guide не приводит category ID к int. | [proxy], [guide]; `testAdaptersReceiveBigIntegerIdentifiersWithoutRounding` [proxy-tests], `testPublishedExamplePreservesUsefulHeadersAndLargeIdentifiers` [guide-tests]. |
| D14 | Malformed JSON | Сохранено | Object/array contract, scalar/null/malformed 400 до upstream. | [proxy], [integration]; `testInvalidJsonIsRejectedWithoutCallingTheBackend` [proxy-tests]. |
| D15 | GET reader scope | Исправлено | Один контракт явно описан: validate all mapped bodies, отправлять body только write; GET/HEAD payload нужен для route/bypass. | [integration]; `testMethodsQueryAndPayloadPolicy` [proxy-tests]. |
| D16 | Snapshot once | Сохранено | Reader не вызывается повторно на bypass/forward. | [proxy]; `testPayloadReaderRunsOnceAcrossBypassAndForwarding` [proxy-tests]. |
| D17 | ID priority conflict | Интеграционное условие | request id priority сохранён; preceding validator отвергает mismatch до resource check. | [Write-action validation][integration], [validation-tests], [guide]; `testUrlIdentifierTakesPrecedenceOverPayload` [proxy-tests]. |
| D18 | Placeholder simplicity | Сохранено; документация уточнена | Одно короткое правило body/query precedence и bodyIdentifier, без nested DSL. | [guide]; `testPlaceholderPrecedenceAndEncoding`, `testOriginalQueryStillSuppliesRoutesAfterAdapterWhitelisting` [proxy-tests]. |
| D19 | Base URI prefix | Исправлено | Origin-only contract, prefix только /path. | [proxy], [integration]; `testBackendMustBeAnHttpOrigin` [proxy-tests]. |
| D20 | External route addresses | Исправлено | Только путь от одного /; absolute/protocol-relative configurations rejected даже при raw config. | [builder], [proxy]; `testBuilderRejectsRoutesOutsideTheBackend`, `testRawConfigurationCannotOverrideTheBackendOrigin` [proxy-tests]. |
| D21 | Redirect policy | Исправлено | Opt-in удалён; alwaysfalse, один backend attempt. | [proxy], [integration]; `testRedirectResponseNeverReplaysTrustedHeadersToAnotherOrigin` [proxy-tests]. |
| D22 | TLS/trusted headers | Сохранено | Verify true, incoming allowlist/reserved headers blocked, server-owned secret, Set-Cookie dropped. | [headers], [proxy], [integration]; [header-tests]. |
| D23 | Identity/access | Интеграционное условие | Распределение auth/tenant/resource responsibilities описано; user ID не permission protocol. | [README][readme], [Access checks][integration]. |
| D24 | Response header replacement | Исправлено | Первое значение каждого backend header заменяет Yii value; следующие повторные сохраняются. Security headers не удаляются без замены. | [emitter]; `testForwardedResponsePreservesRepeatedHeadersAndReplacesItsMarker` [emitter-tests], `testNativeHeadersReplaceLegacyValuesAndHeadHasNoBody` [native-tests]. |
| D25 | Response metadata | Исправлено; интеграционное условие | Guide allowlist сохраняет request-id/retry-after/rate-limit и снимает stale ETag/compression. App выбирает свои обязательные headers. | [guide]; `testPublishedExamplePreservesUsefulHeadersAndLargeIdentifiers` [guide-tests]. |
| D26 | Adapter unexpected schema | Исправлено; интеграционное условие | Guide отвергает malformed/scalar/missing schema; готовый ApiErrorController и native Yii handler проверяют JSON500 без leakage/replay. | [guide]; `testPublishedExampleRejectsUnexpectedBackendResponses` [guide-tests], [native error-handler tests][error-tests]. |
| D27 | Callback naming | Исправлено | Узкий afterAttempt(method,path), до response adaptation; без event bus. | [builder], [integration]; `testTransportFailureCallsTheHookOnceAndReturns503` [proxy-tests]. |
| D28 | No retries/fallback | Сохранено | Один executor и без replay после transport failure. | [proxy], [README][readme]; `testFailedForwardingNeverExecutesTheLegacyActionEvenIfYiiEndReturns` [proxy-tests]. |
| D29 | Data rollback ownership | Сохранено; интеграционное условие | Data plan, in-flight/reload semantics и единственный dataset writer объяснены. | [Rollout and rollback][integration]. |
| D30 | Observability | Исправлено | Текущий log расширен feature и forwarded valid request-id, guard warnings; без tracing subsystem/body/token. | [proxy], [integration]; `testRejectedRequestsLogFeaturePathErrorAndForwardedCorrelationId` [mutation-tests]. |
| D31 | Boundary evidence | Исправлено; host проверки отдельно | Сохранён executable guide; native PHP HTTP test покрывает header queue, body и HEAD. Точный backend snippet исполняется: no/wrong token 403, valid token 200, no server secret 503; cleanup выполняется один раз. | [guide-tests], [native-tests], [integration]. |
| D32 | Expansion cost/docs | Сохранено; удобство улучшено | Три adapter methods; README короткий, детали config/validation/contract в guides, без DTO/YAML/framework. | [interface], [adapter-base], [README][readme], [guide], [integration]. |

## Выпуск и верификация

Имя репозитория и `origin` обновлены на `cosmira/yii1-strangler-proxy`.
Composer name, описание, installation snippets и badges согласованы.
Документированная VCS-установка использует первый тег `v0.1.0` и диапазон `^0.1`.
Регистрация на Packagist не требуется для этого способа и не объявляется выполненной.

Локальные проверки собранных изменений:

| Проверка | Результат |
| --- | --- |
| PHPStan | `max`, без ошибок |
| Soda | 0 замечаний |
| Rector | Config valid, dry run без изменений |
| Pint, Composer strict, Markdownlint, Typos | Без замечаний |
| PHPUnit | 193 теста, 995 assertions |
| Coverage | 100%: 6 классов, 57 методов, 437 строк |
| Infection default profile | 447/447 уничтожены тестами; MSI и mutation coverage 100% |
| Escaped/uncovered/ignored/skipped/errors/syntax errors/timeouts | Все 0 |

Для локального MSI использован последовательный запуск: параллельный на загруженном macOS
превышал лимит для одного мутанта, который отдельно уничтожался тестами.
Лимит процесса — 120 секунд, допустимых timeout — 0; мутации и исключения не отключены.
Остались четыре существующих deprecation из Yii на PHP 8.5; source quality gates проходят.

Удалённое подтверждение выпуска — тег `v0.1.0`, история `main`,
[workflow runs](https://github.com/cosmira/yii1-strangler-proxy/actions) соответствующего commit
и установка из чистого Composer-проекта по командам README.

Для конкретной migrating feature приложение дополнительно проверяет свой доступ/CSRF/verb,
JSON error handler, direct-call trust denial, cleanup hooks, данные и rollback.
Примеры и список обязательных проверок находятся в [руководстве интеграции][integration].

[readme]: ../../../README.md
[composer]: ../../../composer.json
[filter]: ../../../src/StranglerFilter.php
[builder]: ../../../src/Strangler.php
[proxy]: ../../../src/StranglerProxy.php
[headers]: ../../../src/HttpHeaders.php
[emitter]: ../../../src/ResponseEmitter.php
[interface]: ../../../src/StranglerAdapterInterface.php
[adapter-base]: ../../../src/AbstractStranglerAdapter.php
[integration]: ../../integration.md
[guide]: ../../api-adaptation.md
[proxy-tests]: ../../../tests/StranglerProxyTest.php
[guide-tests]: ../../../tests/ApiAdaptationGuideTest.php
[header-tests]: ../../../tests/HttpHeadersTest.php
[emitter-tests]: ../../../tests/ResponseEmitterTest.php
[native-tests]: ../../../tests/NativeHttpResponseTest.php
[adapter-tests]: ../../../tests/AbstractStranglerAdapterTest.php
[mutation-tests]: ../../../tests/MutationRegressionTest.php
[workflow]: ../../../.github/workflows/phpunit.yml

[validation-tests]: ../../../tests/IntegrationGuideTest.php
[error-tests]: ../../../tests/IntegrationErrorResponseTest.php
