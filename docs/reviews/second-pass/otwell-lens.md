# Независимое ревью в оптике выразительного API и удобства разработчика

Проверен текущий `2d026f926180b929ca0df9254f5c3ba639467b2e`: README, оба руководства, исходники, тесты и Composer.
Предыдущие ревью и карта исправлений не использованы. Это самостоятельная интерпретация запрошенной оптики Taylor
Otwell, а не его мнение или авторство. Проверки CI заново не запускались; выводы о тестах относятся к их содержанию.

Пакет стал понятным специализированным инструментом: один фильтр, один флаг, явно заданные маршруты, адаптер контрактов.
Главный недостаток DX сейчас — неудобная установка до релиза; главный остаточный технический риск — разрешение URL,
особенно сегментов `.` и `..`. Не стоит компенсировать это новым маршрутизатором, контейнером или системой флагов.

Приоритеты: **P1** — адрес или доверие может измениться опасным образом; **P2** — нужно устранить до удобного публичного
использования; **P3** — улучшение ясности. «Условие приложения» не означает дефект библиотеки. Положительные оценки не
требуют изменений.

## Карта: 33 самостоятельных параметра

| № | Параметр | Наблюдение | Оценка и тип | Минимальная рекомендация | Доказательство |
| --- | --- | --- | --- | --- | --- |
| 1 | Назначение | Перенос по одной фиче с сохранением старого клиентского контракта объяснён сразу. | Плюс | Сохранить этот первый абзац. | `README.md:7`, `README.md:10` |
| 2 | Целевая платформа | Ограничение Yii 1.1 и PHP 8.2+ явно есть; общий Composer name этого не сообщает. | P2, позиционирование | Указать Yii1 в имени пакета, репозитория и заголовке. | `README.md:12`, `composer.json:2`, `composer.json:30` |
| 3 | Область применения | JSON API, формы и исключения для файлов/стримов названы до установки. | Плюс | Не расширять пакет в универсальный reverse proxy. | `README.md:13` |
| 4 | Установка | Клонирование соседнего checkout, path repository, ручной SHA и lock дают слишком много шагов до первого результата. | P2, DX | После выбора имени выпустить версию и дать одну команду `composer require`; текущую процедуру оставить для разработки. | `README.md:28`, `README.md:34`, `README.md:52` |
| 5 | Первый успешный запрос | Есть полный контроллер, curl, ожидаемый ответ и проверка выключенного флага. | Плюс | Сохранить read-only начало и проверку обоих путей. | `README.md:74`, `README.md:107`, `README.md:114` |
| 6 | Безопасность копирования quickstart | Пример сразу включает флаг, хотя доверенный канал описан ссылкой. Читатель может включить интеграцию раньше проверки backend. | P3, документация | В конфигурации начать с `false`, а включение показать непосредственно перед curl после проверки токена. | `README.md:65`, `README.md:70`, `docs/integration.md:77` |
| 7 | Понятность fluent API | `proxy('catalog')->get('view', path)->build()` легко читается и возвращает обычный Yii filter config. | Плюс | Не вводить дополнительный registrar или DSL. | `src/Strangler.php:52`, `src/Strangler.php:120`, `src/Strangler.php:168` |
| 8 | Семантика методов маршрута | `get/post/put` означают исходящий метод, а не ограничение входящего. README это объясняет. | Плюс с условием приложения | Оставить пояснение рядом с примером; проверять входящий verb до фильтра. | `README.md:98`, `docs/api-adaptation.md:203` |
| 9 | Ошибки объявления маршрутов | Пустые и дублирующиеся маршруты отклоняются сразу. | Плюс | Сохранить fail-fast вместо молчаливой замены. | `src/Strangler.php:181` |
| 10 | Согласованность имён адаптера | Документация говорит «API contract adapter», код — `Modifier`; в публичных именах интерфейса много повторяющегося `Strangler`. | P3, эргономика | До релиза рассмотреть `usingAdapter()` и `ApiAdapter`; не делать одновременно несколько равнозначных API. Возможность оставить `Modifier` тоже допустима, если термин объяснён один раз. | `README.md:120`, `src/StranglerModifierInterface.php:7`, `docs/api-adaptation.md:52` |
| 11 | Маленький адаптер | Базовый класс даёт passthrough; можно переопределить только один метод. | Плюс | Сохранить три простых метода, без абстрактной mapping framework. | `src/AbstractStranglerModifier.php:17`, `src/AbstractStranglerModifier.php:37`, `docs/api-adaptation.md:142` |
| 12 | Варианты создания адаптера | Instance, string и Yii config поддержаны, но документация показывает только instance. | P3, discoverability | Коротко показать `usingModifier(CatalogApiModifier::class)` и сказать, когда нужна Yii config. | `src/Strangler.php:60`, `src/StranglerProxy.php:425`, `src/StranglerProxy.php:429`, `docs/api-adaptation.md:189` |
| 13 | Различные формы API | Руководство показывает различие URL, verb, вложенности, типов, списков и ошибок, а не обещает автоматическую совместимость. | Плюс | Сохранить сценарий как основной пример. | `docs/api-adaptation.md:16`, `docs/api-adaptation.md:76`, `docs/api-adaptation.md:168` |
| 14 | Точность пагинации | Неполные страницы уходят в Yii до отправки запроса; нет скрытого округления offsets. | Плюс с условием приложения | Прямо сохранить тесты этой границы при изменении примера. | `docs/api-adaptation.md:159`, `docs/api-adaptation.md:191` |
| 15 | Идентификатор в URL | Порядок request id и original body ID описан; отсутствующие placeholders дают 400. | Плюс | Сохранить явную ошибку вместо вызова коллекции. | `docs/api-adaptation.md:207`, `src/StranglerProxy.php:624`, `src/StranglerProxy.php:514` |
| 16 | Специальные URL-сегменты | `rawurlencode('..')` оставляет `..`; стандартное разрешение URI превращает `/api/items/..` в `/api/`. Это может отправить PUT/DELETE на другой ресурс. | **P1, подтверждённый дефект границы URL** | Отвергать `.` и `..` для значений path placeholders до отправки с 400; добавить тест реального Guzzle URI resolution. | `src/StranglerProxy.php:507`, `src/StranglerProxy.php:518`, `tests/StranglerProxyTest.php:496` |
| 17 | Base URI с префиксом | `base_uri=https://backend/v2` теряет последний path segment для относительного `items/42`; `/items/42` всегда заменяет весь path. `rtrim` дополнительно не позволяет сохранить directory slash. | P2, конфигурационный контракт | Самое простое: объявить и валидировать `base_uri` как origin без path/query/fragment, а весь префикс держать в route. Альтернатива — явно описать URI resolution и сохранить trailing slash. | `src/StranglerProxy.php:401`, `src/StranglerProxy.php:405`, `src/StranglerProxy.php:310` |
| 18 | Автоматические redirects | Opt-in `allow_redirects=true` запускает дополнительные запросы; cross-origin redirect сохраняет пользовательские `X-Strangler-Token` и `X-User-Id`. 302 также превращает POST в GET. Default false безопасен. | **P1, подтверждённый риск opt-in настройки** | Убрать эту настройку и всегда возвращать backend redirect клиенту. Это проще отдельной безопасной redirect policy и соответствует одному отправлению. | `src/StranglerProxy.php:312`, `tests/StranglerProxyTest.php:567`, `README.md:144` |
| 19 | Флаги | Только boolean true, отсутствующее значение выключено; нет скрытого запуска из строки `'false'`. | Плюс | Не встраивать SDK feature flags, оставить Yii params и явный парсинг env. | `src/StranglerProxy.php:385`, `docs/integration.md:38`, `README.md:139` |
| 20 | Bypass | Правила применяются до отправки, payload snapshot один; legacy-only варианты остаются в старом action. | Плюс | Сохранить и не добавлять fallback после отправки. | `src/StranglerProxy.php:145`, `src/StranglerProxy.php:152`, `tests/StranglerProxyTest.php:989` |
| 21 | Порядок security filters | Документация явно требует завершить проверки до Strangler; тест подтверждает, что отказ предыдущего фильтра не вызывает backend. | Плюс с условием приложения | Проверять реальные verb/CSRF/resource filters конкретного приложения. | `README.md:102`, `docs/integration.md:8`, `tests/StranglerProxyTest.php:1006` |
| 22 | Жизненный цикл Yii | `beforeAction`, `afterAction`, `postFilter` не выполняются; это честно и явно раскрыто. | P2, условие интеграции | Для первой миграции выписать используемые hook обязанности и проверить cleanup через request-end. Не менять interception layer ради общего lifecycle framework. | `docs/integration.md:13`, `README.md:103`, `src/ResponseEmitter.php:56` |
| 23 | Доверие identity headers | Client identity/token блокируются; server-owned token, HTTPS и проверка backend показаны. | Плюс с условием приложения | Перед включением обязательно выполнить direct-call negative test; пакет не является системой авторизации. | `src/HttpHeaders.php:130`, `docs/integration.md:60`, `docs/integration.md:77` |
| 24 | Пользователь против tenant/permissions | ID сам по себе не даёт роли или tenant; ответственность явно распределена. | Плюс | Сохранять конкретную фразу вместо обещания «передали права». | `README.md:127`, `docs/integration.md:19` |
| 25 | Политика сессий и заголовков | Allowlist клиента, server override, исключение Set-Cookie и hop-by-hop headers согласованы с Yii-owned sessions. | Плюс | Явное forwarding Authorization/Cookie оставить редким контрактным opt-in. | `src/HttpHeaders.php:35`, `src/HttpHeaders.php:59`, `docs/integration.md:90` |
| 26 | TLS и bounded timeout | Verification включена по умолчанию, CA bundle поддержан, невалидные/нулевые timeouts не становятся бесконечными. | Плюс | Не добавлять network policy abstraction. | `src/StranglerProxy.php:597`, `src/StranglerProxy.php:607`, `docs/integration.md:98` |
| 27 | Идемпотентность и отказ | Нет legacy replay после попытки; прокидывание Idempotency-Key не обещает deduplication. | Плюс | Сохранить ясную границу ответственности; устранить redirect opt-in из строки 18. | `README.md:144`, `docs/integration.md:126`, `src/StranglerProxy.php:240` |
| 28 | Адаптация ошибок | Package 400/503 тоже проходят через response adapter; client format не ломается только потому, что ошибка транспортная. | Плюс | Сохранить единый путь трансформации и error status. | `src/StranglerProxy.php:537`, `docs/api-adaptation.md:170`, `tests/StranglerProxyTest.php:976` |
| 29 | Диагностика вызова | Лог содержит path/status/time, но ошибка backend configuration и missing route parameter выдаёт короткий код без feature/path в логе. | P3, эксплуатационный DX | Добавить маленькие warning records для configuration guards; не логировать body/secret. | `src/StranglerProxy.php:175`, `src/StranglerProxy.php:206`, `src/StranglerProxy.php:266` |
| 30 | Понятность callback | `afterRequest(method,path)` выполняется до response adaptation и даже при transport failure; имя может восприниматься как завершение всего запроса. | P3, эргономика | Либо переименовать до релиза в `afterAttempt`, либо добавить этот смысл в docblock метода и один простой пример. Не расширять hook до event bus. | `src/Strangler.php:90`, `src/StranglerProxy.php:227`, `docs/integration.md:158` |
| 31 | Доказательства примеров | Тест извлекает исполняемый adapter из документации и проверяет настоящий прокси с mock client. Это помогает README не расходиться с API. | Плюс | Расширять только значимые контрактные сценарии. | `tests/ApiAdaptationGuideTest.php:64`, `tests/ApiAdaptationGuideTest.php:109` |
| 32 | Честность quality badges | 100% описано как gate, не как гарантия клиентской совместимости; platform/dependency matrix есть в CI config. | Плюс | Сохранить оговорку; реальные runtime smoke tests нужны до rollout конкретной фичи. | `README.md:172`, `.github/workflows/phpunit.yml:25`, `docs/api-adaptation.md:236` |
| 33 | Карта конфигурации | Настройки раскиданы между quickstart, TLS/header guide, logging section и исходником; отсутствует компактный полный reference. | P3, discoverability | В integration guide добавить одну короткую таблицу supported params + default; скрытые настройки вроде redirect не сохранять только ради полноты. | `README.md:61`, `docs/integration.md:84`, `docs/integration.md:98`, `docs/integration.md:137` |

## Проверенные репродукции двух технических рисков

В текущем установленном Guzzle/Psr7 локально без сети:

```text
rawurlencode('..')                         => '..'
resolve(https://backend.test/v2, /api/items/..) => https://backend.test/api/
resolve(https://backend.test/v2, items/42)     => https://backend.test/items/42
resolve(https://backend.test/v2, /items/42)    => https://backend.test/items/42
```

MockHandler с двумя ответами — 302 Location на другой origin и 200 — показал при `allow_redirects=true`:

```text
POST https://backend.test/write token=demo-secret user=42
GET  https://other.test/receive token=demo-secret user=42
```

Последний риск появляется только после включения настройки; default её выключает. Проверки уровня приложения могут
отвергать `..`, но библиотека сама обещает URL-encoding и должна защищать неизменность адреса для любого разрешённого
scalar placeholder.

## Имя продукта, Composer и репозитория

Имя не должно обещать универсальный reverse proxy. Это **Yii 1.1 filter для поэтапной миграции JSON API с адаптацией
контракта**. `Proxy` описывает отправку HTTP, `Strangler` — цель migration; оба вместе полезнее абстрактного `Bridge`.

| Composer / repository | Ясность Yii1 против Yii2 | Ясность цели | Отличимость | Риск ожидания универсального proxy | Краткость | Discoverability по содержимому имени | Цена переименования до релиза |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `cosmira/strangler` / `strangler` | Нет | Понятно только знающим pattern | Низкая | Низкий, но назначение вообще скрыто | Отличная | Слабая для Yii и HTTP | Нулевая сейчас, неоднозначность остаётся |
| `cosmira/yii-strangler-proxy` / `yii-strangler-proxy` | Yii указан, поколение нет | Отличная | Хорошая | Средний; снижает описание JSON/filter | Средняя | Хорошая | Низкая по размеру diff, нужно обновить ссылки |
| `cosmira/yii-strangler` / `yii-strangler` | Yii указан, поколение нет | Хорошая | Хорошая | Низкий | Хорошая | Хорошая для Yii/pattern, слабее для HTTP | Низкая |
| `cosmira/yii-api-bridge` / `yii-api-bridge` | Yii указан, поколение нет | Migration скрыта; может означать SDK/integration bus | Средняя | Низкий, но широкое bridge ожидание | Хорошая | Хорошая по Yii/API, слабая по migration | Низкая |
| `cosmira/yii-migration-proxy` / `yii-migration-proxy` | Yii указан, поколение нет | Migration понятна; можно спутать с DB migration | Средняя | Средний | Средняя | Хорошая, но migration двусмысленно | Низкая |
| **`cosmira/yii1-strangler-proxy` / `yii1-strangler-proxy`** | **Однозначно** | **Отличная** | **Хорошая** | **Средний; описание JSON/filter снимает** | **Средняя** | **Наиболее конкретная по Yii1/pattern/proxy** | **Низкая до релиза; такой же объём ссылок** |

**Рекомендация: продукт `Yii1 Strangler Proxy`, Composer `cosmira/yii1-strangler-proxy`, repository
`yii1-strangler-proxy`.** Если важно именно более привычное `yii-…`, пользовательский вариант
`cosmira/yii-strangler-proxy` — достойный второй выбор, но слово Yii1 в заголовке и описании всё равно обязательно.

Не переименовывать PHP namespace, Yii params key, HTTP headers и флаги лишь из-за нового имени repository: для
интегратора это лишний breaking diff без роста ясности. Публичное имя package и понятная первая строка уже решают
задачу. Keywords добавить `yii1`, `yii-1.1`, `api-migration`. Composer description явно написать Yii1/JSON/filter вместо
общего «legacy Yii applications» (`composer.json:3`).

Доступность имён на GitHub/Packagist не проверялась. «Низкая цена до релиза» основана на описанном README pre-release
состоянии (`README.md:28`), а не на доказанном отсутствии внешних пользователей. Перед реальным переименованием
проверить внешних потребителей, регистрацию имени, Composer references, clone instructions и badges; GitHub rename —
отдельное внешнее действие.

## Пять первых действий

1. Запретить dot-segment значения route placeholders, проверить итоговый URI через Guzzle.
2. Удалить opt-in автоматических redirects; возвращать 3xx клиенту, сохранив один backend attempt и доверенный hop.
3. Определить `base_uri` как origin и отклонять скрыто меняющие адрес компоненты либо документировать точную
   поддержанную семантику.
4. Выбрать `Yii1 Strangler Proxy` и подготовить согласованный rename package/repository/references до первого tagged
   release.
5. Выпустить installable release; свернуть пользовательскую установку до одной Composer-команды, сохранив подробные
   integration/adaptation guides.

## Вердикт

Для управляемого внедрения в конкретное Yii1 приложение пакет уже достаточно выразителен: ответственность понятна,
стартовый сценарий проверяем, API adaptation объяснена предметно. Для публичного предложения «установил и включил»
сначала исправить URL/redirect границы и дать нормальный Composer release. Добавление новых архитектурных слоёв здесь
ухудшит продукт; полезнее убрать одну опасную настройку, установить ясные URL-инварианты и привести публичное имя к
реальному назначению.
