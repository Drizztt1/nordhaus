# DECISIONS — reguły produktów Nordhaus Living (Pimcore 11.5)

Kod: `src/Product/`, jeden subscriber (`EventSubscriber/ProductSubscriber`) na pre/post add/update/delete, więc
admin, import z ERP (PHP API) i Data Hub przechodzą przez te same reguły. Klasa: `var/classes/definition_Product.php`.
*Alternatywa:* własna klasa nadrzędna z nadpisanym `save()` — trudniej wstrzykiwać zależności i testować; zdarzenia
to udokumentowany punkt rozszerzeń.

## R1 — SKU (`Sku/SkuGuard`)
- **Podejście:** w pre-save: trim świadomy Unicode (także NBSP) + `mb_strtoupper`, pusty → `NULL`; sprawdzenie w
  `object_store_product` (tylko własne wartości — tabela query zawiera SKU *dziedziczone* → fałszywe duplikaty).
  Duplikat rzuca `ValidationException` ze wskazaniem drugiego produktu; admin pokazuje go edytorowi (HTTP 422).
  Opublikowany wariant musi mieć *własne* SKU („mandatory” zaakceptowałoby odziedziczone). Zabezpieczenie: opcja
  `unique` → indeks `UNIQUE` (równoczesne zapisy; kopiuj/wklej czyści pole).
- **Alternatywa:** sama flaga `unique`. Za mało: brak normalizacji i tylko ogólny komunikat
  „unique constraint violation”.
- **Ograniczenia:** dwa równoczesne zapisy tego samego SKU przejdą oba sprawdzenie (race condition typu TOCTOU) —
  drugi zatrzyma indeks `UNIQUE` z ogólnym komunikatem Pimcore; dane pozostają poprawne. Autosave normalizuje, ale
  pomija sprawdzenie.

## R2 — Dostępność (`Availability/AvailabilityUpdater`, enum `Availability`)
- **Podejście:** nieedytowalny, indeksowany select zapisany na **każdym** obiekcie. Frontend:
  `setObjectTypes([OBJECT_TYPE_OBJECT])` + `setCondition('availability = ?', ['in_stock'])` (listingi w Pimcore 11
  domyślnie zawierają warianty). Wariant: stock > 0, ustawiane w pre-save. Rodzic: jeden indeksowany `EXISTS` po
  opublikowanych wariantach ze stanem, przeliczany w jego pre-save. Po zapisie/usunięciu/przeniesieniu/przywróceniu
  wariantu jego „opublikowany ORAZ stock > 0” i `parentId` są porównywane z **zapisanym** wierszem (jedno zapytanie
  po PK; flagi dirty są zawodne) i dopiero wtedy odświeżany jest stary/nowy rodzic — godzinny import zwykle nie
  dotyka żadnego rodzica.
- **Zapis rodzica = SQL, nie `$parent->save()`:** obie tabele + usunięcie z cache, tylko gdy wartość się zmienia.
  Pełny zapis tworzyłby wersje, walidował rodzica (zepsuty rodzic blokowałby import) i uruchamiał inheritance helper
  dla wszystkich wariantów. *Koszt:* rodzic nie dostaje zdarzenia `postUpdate`, więc synchronizacja z wyszukiwarką
  / Shopify potrzebuje jawnego hooka.
- **Naprawa/wdrożenie:** `bin/console app:product:recalculate-availability` używa tej samej reguły SQL i zapisuje
  tylko zmienione wiersze (tabela store *i* query), w paczkach — backfill 350k obiektów i nocna siatka bezpieczeństwa.
- **Alternatywy:** liczenie przy odczycie to join po 300k wariantach na każdy listing. Pole Calculated Value
  odświeża się tylko przy zapisie samego rodzica, więc i tak potrzebuje tego samego wyzwalacza.
- **Ograniczenia:** przy równoczesnych zapisach wariantów tego samego rodzica (race condition) wartość rodzica może
  być chwilowo błędna (naprawia to następny zapis lub komenda). Zagnieżdżone warianty nie są agregowane. Rodzic bez
  wariantów jest `out_of_stock`. Reguła wariantu istnieje w PHP i w SQL.
- **Produkcja:** zbierać id rodziców i przeliczać raz na paczkę importu (Messenger, z deduplikacją). Emitować
  zdarzenie domenowe przy zmianie dostępności rodzica, dla synchronizacji z Shopify. Po stronie importu: pomijać
  `save()`, gdy stock się nie zmienił (mniej zapisów, wersji i reindeksacji przy 300k wariantów co godzinę).

## R3 — Klucz URL (`UrlKey/UrlKeyGenerator`)
- **Podejście:** lokalizowane `urlKey` plus ukryte lokalizowane `urlKeyAuto` (ostatnia wygenerowana wartość).
  Dla każdego języka:
  - Pusty klucz dostaje slug z nazwy (`AsciiSlugger`: `Łóżko` → `lozko`).
  - Przed pierwszą publikacją klucz podąża za nazwą, dopóki równa się `urlKeyAuto`, czyli nie był edytowany ręcznie.
  - Pierwszy zapis opublikowanego produktu czyści `urlKeyAuto`, co zamraża klucz na stałe.
  - Pusty klucz jest uzupełniany także po publikacji, bo nie istnieje żaden działający URL do zepsucia.
  - Dotyczy tylko rodziców; warianty dziedziczą klucz.
- **Alternatywa:** typ `UrlSlug` w Pimcore wiąże klucz z routingiem Pimcore, a tu sklep i Shopify używają go jako
  zwykłej wartości.
- **Ograniczenia:** klucze URL nie są unikalne między produktami. Brak przekierowań przy ręcznej zmianie. Kopia
  opublikowanego produktu zachowuje klucz źródła. Przywrócenie wersji sprzed publikacji przywraca `urlKeyAuto`;
  naprawiłaby to niewersjonowana flaga „pierwsza publikacja”. Bardzo długie nazwy są po cichu ucinane do długości
  kolumny (190), czasem w środku słowa; na produkcji cięcie na granicy `-`.

## Gdzie wolno wypełniać pola (kolumna „Where it is filled”)
- **Podejście:** panel — dwa custom layouty w `config/pimcore/custom-layouts/` (wersjonowane, `config_location` →
  `symfony-config`): rodzic bez `stock`/`fabric`/`leg`, wariant z `name`/`description`/`urlKey` tylko do odczytu
  (widać dziedziczoną wartość). Pimcore przypisuje layouty do ról, nie do typu obiektu, więc wybiera je
  `AdminLayoutSubscriber` (`OBJECT_GET_PRE_SEND_DATA`; ręczny wybór layoutu i uprawnienia są szanowane).
  Backend — `FieldPlacement/FieldPlacementGuard` w pre-save odrzuca zapis z wartością w złym miejscu, bo import
  z ERP i API omijają panel.
- **Alternatywa:** reguły w kodzie nakładane na layout klasy (nowe pole działa od razu) albo generator layoutów
  z plików różnic jak w dużych projektach — przy 2 layoutach i małej klasie to przerost.
- **Ograniczenia:** nowe pole w klasie trzeba dodać też do obu layoutów.

## Konfiguracja i testy
Języki `en, pl` w `config/config.yaml` (obowiązuje, dopóki ustawienia systemowe nie zostaną zapisane w settings
store). `tests/Unit/Product` — czyste reguły; `tests/Functional/Product` — 38 testów integracyjnych na prawdziwym Pimcore
i osobnej bazie (`test-php` + `test-db`, izolowany folder na test; w środowisku `test` kolejki AMQP nie trafiają
do workera dev: `pimcore_core` jest `sync://`, reszta `in-memory`). Uruchomienie (raz `pimcore-install`, potem tylko `codecept`):
`docker compose run --rm test-php vendor/bin/pimcore-install -n --skip-database-config`,
`docker compose run --rm test-php bin/console pimcore:deployment:classes-rebuild -c -n`,
`docker compose run --rm test-php vendor/bin/codecept run`. Analiza statyczna: PHPStan level 8 bez błędów
(`phpstan.neon.dist`, komenda w pliku, bez dodawania zależności).

**Admin:** wartości wyliczane w pre-save (availability, urlKey, znormalizowane SKU) nie wracają do otwartego
formularza, więc `public/static/product/admin/reloadAfterSave.js` przeładowuje produkt po zapisie (poza autosave,
po zakończeniu zapisu). `tests/Browser/run.sh` — test klikany w panelu (Playwright w Dockerze): zmiana stocku i
publikacja, dostępność rodzica, komunikat o duplikacie SKU, urlKey/SKU widoczne od razu po zapisie, layouty rodzica i wariantu, „zapisz i zamknij” bez błędów JS.

## Użycie AI
Używałem Claude Code (tryb agentowy, z dostępem do powłoki mojej lokalnej instancji w Dockerze). Utworzył klasę,
kod, testy i pierwszą wersję tego pliku. Przegląd kodu przez AI znalazł potem prawdziwe błędy, które poprawiono.

Niezależny agent QA, który dostał tylko treść zadania, wykonał ok. 110 sprawdzeń. Jego znaleziska poprawiono.

Każde założenie o kodzie Pimcore sprawdziłem w źródłach 11.5 i uruchomiłem wszystkie scenariusze na instancji.
Zdecydowałem też o kompromisach opisanych wyżej: tabela store vs query, zapis SQL vs `save()` oraz `urlKeyAuto`.
