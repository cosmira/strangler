# Независимое ревью в оптике DHH: простота и стоимость сопровождения

Проверено текущее дерево commit `2d026f9`. Старые ревью не использовались. Это самостоятельная оценка через принципы
простоты, соглашений и цельной модели; она не представляет мнение самого DHH. Изменения не вносились. Источники —
текущие README, исходники, руководства и тесты. P1 — препятствует безопасному запуску в указанном сценарии; P2 —
исправить до стабильного API или конкретного rollout; P3 — улучшение удобства. «Условие интеграции» не означает дефект
пакета.

## Карта: 32 самостоятельных параметра

| № | Параметр | Оценка и тип | Наблюдение | Простое решение | Доказательство |
| --- | --- | --- | --- | --- | --- |
| 1 | Смысл продукта | Плюс | Один понятный переход: старый контроллер сохраняет проверки, исполнение переключается на backend. | Сохранить этот узкий смысл; не добавлять универсальный gateway. | README.md:7, src/StranglerFilter.php:31 |
| 2 | Граница применимости | Плюс | JSON API и ограничения файлов/стриминга названы до установки. | Сохранить ограничения на первой странице. | README.md:12 |
| 3 | Composer и название репозитория | P2, предложение до стабильного API | `cosmira/strangler` не говорит о Yii и не совпадает с точным README-заголовком. | Использовать `cosmira/yii-strangler-proxy` и репозиторий `yii-strangler-proxy`, уточнять Yii 1.1 в описании. | composer.json:2, README.md:1 |
| 4 | Версия фреймворка в позиционировании | P3, предложение | В названии Yii можно ожидать Yii2, хотя зависимость только Yii1. | В заголовке «Yii 1.1 Strangler Proxy»; если поддержки Yii2 никогда не планируется, рассмотреть ещё точнее `yii1-strangler-proxy`. | README.md:1, composer.json:30 |
| 5 | Термин API contract adapter | P2, предложение до стабильного API | Пользователь видит «adapter», но пишет `usingModifier()`/`CatalogApiModifier`. Это два имени одной вещи. | Выбрать одну терминологию; до первого стабильного выпуска назвать интерфейс/базовый класс Adapter и метод `usingAdapter()`, либо везде последовательно Modifier. Не держать оба API бесконечно. | README.md:120, docs/api-adaptation.md:52, src/Strangler.php:60 |
| 6 | Первый запуск | P2, выпуск/документация | Для первого запроса требуется clone рядом с приложением, ручное слияние Composer JSON и фиксация commit. Это оправдано pre-release, но тяжело для публичного пакета. | Выпустить проверенный тег и сделать один `composer require` главным путём; pre-release инструкцию оставить дополнительной. | README.md:28 |
| 7 | Совместимость с существующим runtime | Плюс | PHP 8.2 и Yii1.1.31 заявлены сразу, без обещания работать на любом legacy. | Сохранить честный нижний порог. | README.md:12, composer.json:28 |
| 8 | Необходимость fluent builder | Плюс | Цепочка очень короткая, возвращает обычную конфигурацию Yii CFilter; отдельный DI-контейнер не нужен. | Не добавлять новый DSL и контейнер. | src/Strangler.php:52, src/Strangler.php:168 |
| 9 | Дублирование исходящих маршрутов | Плюс | Повтор одной action явно запрещён вместо тихого переопределения. | Сохранить fail-fast. | src/Strangler.php:181 |
| 10 | Семантика входящего HTTP метода | P2, условие интеграции | `put()` задаёт исходящий метод, но не ограничивает входящий. Пример объясняет это честно. | Для каждой write-action показать реальный verb-filter в интеграционном примере; не вводить второй роутер внутрь пакета. | README.md:98, docs/api-adaptation.md:203 |
| 11 | Порядок доступа и lifecycle Yii | P2, условие интеграции | `beforeAction`, `afterAction` и postFilter пропущены намеренно; это существенная смена жизненного цикла. | Перед rollout обязательна проверка этих hooks; README уже правильно предупреждает. Для критического cleanup использовать протестированный onEndRequest. | README.md:102, docs/integration.md:13 |
| 12 | JSON object/array fidelity | P2, дефект контракта | `json_decode(..., true)` + Guzzle `json` превращает `{}` в `[]`, в том числе вложенные пустые объекты; объект с цифровыми ключами тоже может стать списком. Совпадающий API ещё не означает идентичную структуру. | Сохранить форму JSON при отсутствии adapter; при adapter явно определить array-представление/кодирование. Добавить тесты именно на JSON-форму, не только декодированное значение. | src/StranglerProxy.php:351, src/StranglerProxy.php:319, tests/StranglerProxyTest.php:513 |
| 13 | Числовая точность идентификаторов | P2, дефект для большого integer-контракта | Большие JSON integers декодируются без JSON_BIGINT_AS_STRING и могут стать float до отправки. | Для идентификаторов контрактом использовать строки; если обещано прозрачное проксирование, сохранять raw JSON без adapter и тестировать значения вне PHP_INT_MAX. | src/StranglerProxy.php:351, docs/api-adaptation.md:46 |
| 14 | Политика malformed JSON | Плюс | Неподходящий body возвращает 400 до отправки backend, а не маскируется пустым payload. | Сохранить явную ошибку. | src/StranglerProxy.php:145, docs/integration.md:103 |
| 15 | Кого касается JSON reader | P3, уточнение документации/решения | Body читается и проверяется для GET/HEAD, хотя отправляется только для write-методов. Поэтому незначащий body GET может неожиданно вернуть400. | Либо документировать правило для всех mapped actions, либо читать body только там, где нужны payload/route/bypass. Выбрать один простой контракт. | src/StranglerProxy.php:145, src/StranglerProxy.php:318 |
| 16 | Единственность snapshot payload | Плюс | Bypass и отправка используют один snapshot, stateful callback не вызывается повторно. | Сохранить инвариант. | src/StranglerProxy.php:145, src/StranglerProxy.php:152, tests/StranglerProxyTest.php:989 |
| 17 | Приоритет ID | P2, условие интеграции | ID из request побеждает body ID. При конфликте frontend/проверка разрешений могут считать другим ID. | В preceding validation отклонять конфликтующий id/ID; добавить такой пример для update. Не менять приоритет скрыто. | src/StranglerProxy.php:624, docs/api-adaptation.md:207 |
| 18 | Простота placeholder-модели | P3, предложение | `{id}` имеет особый original-body fallback; остальные ищутся в transformed body/query/original query и case aliases. Модель работает, но правил много. | Оставить одно короткое правило в документации с примером конфликтов; не расширять до nested-expression DSL. | src/StranglerProxy.php:507, docs/api-adaptation.md:207 |
| 19 | Работа с prefix base_uri | P2, уточнение контракта | `base_uri` теряет trailing slash, а README пути начинаются `/`: backend URL с `/v1/` не означает автоматического сохранения `/v1`. Относительные пути зависят от URI resolution. | Назвать настройку backend origin и требовать пути от `/`, либо документировать и тестировать base-path composition. | src/StranglerProxy.php:405, README.md:62, README.md:89 |
| 20 | Разрешение сторонних адресов | P2, защитное упрощение | Builder принимает любой path, включая абсолютный URL/`//host`, и отправляет server token и X-User-Id. Это доверенная ошибка конфигурации, не клиентский SSRF. | Для одно-backend модели разрешать только пути от одного `/`; иначе явно документировать absolute URL как отдельное изменение trusted boundary. | src/Strangler.php:179, src/StranglerProxy.php:225, src/StranglerProxy.php:300 |
| 21 | Redirect policy | P2, условие/опция | Redirects выключены по умолчанию — хорошо. Но `allow_redirects` можно включить boolean-cast без описания; новый hop способен изменить trusted boundary и write semantics. | Удалить ненужную опцию либо принимать строго boolean и ограничивать проверенным same-origin; объяснить opt-in. | src/StranglerProxy.php:312, docs/integration.md:23 |
| 22 | TLS и доверенные headers | Плюс | TLS default true, allowlist, server-owned token и удаление backend Cookie образуют понятный безопасный default. | Не размывать default ради удобства локальной среды. | src/StranglerProxy.php:607, src/HttpHeaders.php:130, docs/integration.md:84 |
| 23 | Yii identity и бизнес-доступ | P2, условие интеграции | X-User-Id не содержит роли/tenant и не заменяет доступ к конкретному ресурсу. Руководство уже явно это говорит. | Для одной migrating feature зафиксировать ownership каждого access-check; не изобретать общий permission protocol. | README.md:127, docs/integration.md:19 |
| 24 | Ранее установленные response headers | P2, дефект в окружении с middleware headers | Каждый upstream header записывается с replace=false. Если Yii уже поставил Content-Type/Cache-Control/ETag, могут остаться противоречивые значения даже после adapter header replacement. | Первое значение каждого upstream header заменяет старое, следующие сохраняются повторными; проверять реальную HTTP header queue. Существующие security headers сохранять, если backend их не заменяет. | src/ResponseEmitter.php:43, tests/ResponseEmitterTest.php:59 |
| 25 | Переписывание response и caching | P2, условие интеграции | Adapter ответственен за снятие cache/compression метаданных; пример делает правильную замену всего headers-array, но может потерять нужные request-id/rate-limit headers. | Дать короткий allowlist пример обязательных бизнес headers после mapping; не делать автоматическую магию всех headers. | docs/api-adaptation.md:121, docs/integration.md:94 |
| 26 | Ошибки adapter | P2, условие интеграции | HTML/неожиданный schema backend уходит через Yii error-handler, который может нарушить старый JSON контракт. Этот случай назван, но качественного результата нужно добиваться в host application. | Проверить клиентскую форму errors при malformed upstream JSON и thrown adapter; не маскировать programming errors blanket catch. | docs/api-adaptation.md:178, src/StranglerProxy.php:252 |
| 27 | После запроса callback | P3, предложение | Имя `afterRequest` можно понимать как обработку готового клиентского результата; фактически это только method/path после attempt и до response translation. | Если use-case не нужен — не рекламировать hook. Если нужен — назвать `afterForwarding`/`afterAttempt` до стабильного API, сохранив текущую узкую сигнатуру. | src/Strangler.php:90, docs/integration.md:158 |
| 28 | No retries/no fallback | Плюс | Один executor, никаких повторных write попыток в Yii после backend; текст объясняет потерянный ответ. | Сохранить даже при pressure «сделать отказоустойчивее». | README.md:144, src/StranglerProxy.php:226 |
| 29 | Rollback и ownership данных | Плюс | Feature flag не выдаётся за восстановление данных; in-flight запросы и reload объяснены. | Не добавлять синхронизатор/двойную запись в proxy. | docs/integration.md:110 |
| 30 | Наблюдаемость | P3, предложение | Корреляционный ID forwarded, но proxy log не включает его, не пишет feature в failure path. Для ручной диагностики нужно сопоставлять сторонние logs. | Добавить существующий валидированный request-id и feature в текущий log, без новой tracing подсистемы и без payload/token. | docs/integration.md:154, src/StranglerProxy.php:231, src/StranglerProxy.php:266 |
| 31 | Доказательность тестов | Плюс с P2 пробелом | Guide adapter исполняется из Markdown и проверяет реальный Guzzle request — сильнее теста текста. Но mocks и injected header writer не доказывают native HTTP header behavior. | Сохранить runnable guide; добавить несколько boundary smoke-tests на built-in PHP server для headers/body/HEAD и trust denial. | tests/ApiAdaptationGuideTest.php:106, tests/ApiAdaptationGuideTest.php:64, tests/ResponseEmitterTest.php:107 |
| 32 | Цена расширения и документация | Плюс | Три метода adapter и pass-through base дают достаточную гибкость; README короткий, детали вынесены по задачам. | Не добавлять отдельные DTO, middleware registry, mapping YAML и общий migration framework без конкретного use-case. | src/AbstractStranglerModifier.php:17, README.md:118, README.md:159 |

## Именование: сравнение вариантов

Доступность Composer/repository имён не проверялась; это оценка смысла, а не подтверждение возможности регистрации.

| Вариант Composer / repository | Что понимает новый читатель | Что остаётся неясным | Цена/вердикт |
| --- | --- | --- | --- |
| `cosmira/strangler` / `strangler` | Название архитектурного паттерна | Yii1, HTTP-фильтр, JSON и граница применимости | Коротко, но требует объяснения почти везде; менять до стабильного выпуска дешевле. |
| `cosmira/yii-strangler-proxy` / `yii-strangler-proxy` | Yii + постепенная миграция + пересылка HTTP | Yii1 vs Yii2; слово proxy может вызвать ожидание binary/streaming | Лучший из предложенных баланс: точный subtitle «Yii 1.1, JSON API actions», ограничения уже есть. |
| `cosmira/yii-strangler` / `yii-strangler` | Yii + паттерн миграции | Как реализован переход: router/filter/proxy? Yii1 vs Yii2 | Сдержаннее и короче, но менее конкретно для пользователя. Второй выбор. |
| `cosmira/yii-api-bridge` / `yii-api-bridge` | Связь двух API | Не видны feature flags, strangler migration и временный характер bridge | Слишком широко; создаёт ожидание универсального API adapter. |
| `cosmira/yii-migration-proxy` / `yii-migration-proxy` | Proxy для миграции Yii | «Migration» можно принять за миграции БД; Yii1 vs Yii2 | Понятно после описания, но хуже отделяется от database migrations. |

Рекомендация: **Yii 1.1 Strangler Proxy**, Composer **`cosmira/yii-strangler-proxy`**, repository
**`yii-strangler-proxy`**. Если продукт навсегда ограничен Yii1, `yii1-strangler-proxy` ещё точнее, хотя выходит за
исходный список. Сохранить namespace `Cosmira\Strangler` допустимо: технический namespace не обязан копировать
repository slug. Не надо переименовывать внутренние классы только ради совпадения slug.

Переименование до стабильного релиза требует согласовать composer.name, repository URL/homepage/support, README install
snippet и все badges/workflow links, composer.lock metadata/root tools при наличии, описание проекта и внешние ссылки.
При уже существующих потребителях дать короткую upgrade-note; не объявлять старый package abandoned без проверки
публикации. Github redirect не заменяет смену Composer dependency name. Тег/выпуск и реальные registry/server действия —
отдельная реализация, здесь не выполнялись.

## Пять первых действий

1. Исправить JSON fidelity: `{}`, вложенные пустые объекты, цифровые keys и большие integers. Подтверждено локально на
   установленном Guzzle MockHandler: декодированный `{}` ушёл как `[]`.
2. Определить header replacement при уже установленных Yii headers и проверить это на настоящем HTTP boundary.
3. Упростить destination contract до единственного доверенного origin; заодно определиться с base URI prefix и redirect
   opt-in.
4. До stable release согласовать product/Composer/repository имя и adapter терминологию, затем дать `composer require`
   как основной путь.
5. Для первой write feature завершить host-contract проверки: incoming verb/CSRF, конфликт id/ID, resource access,
   malformed backend response. Это условия интеграции, не повод расширять proxy до security framework.

## Вердикт

Для ограниченной интеграции с явно согласованным JSON contract пакет уже выглядит небольшим и практичным. Общего
архитектурного переписывания не требуется. Перед стабильной публичной рекомендацией нужно устранить оставшиеся JSON/HTTP
boundary расхождения и завершить именование/установку. Безопасные defaults и отсутствие replay — сильная основа;
сохранять простоту здесь важнее, чем превращать все пожелания ревью в новые настройки.
