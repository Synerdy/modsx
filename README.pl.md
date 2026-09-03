# Modsx — moduły dla Laravela oparte na konwencji

[![Latest Version](https://img.shields.io/packagist/v/synerdy/modsx.svg)](https://packagist.org/packages/synerdy/modsx)
[![Tests](https://github.com/Synerdy/modsx/actions/workflows/tests.yml/badge.svg)](https://github.com/Synerdy/modsx/actions)
[![License](https://img.shields.io/packagist/l/synerdy/modsx.svg)](LICENSE)

Podziel aplikację Laravela na moduły przy pomocy samej konwencji nazewnictwa katalogów — a potem rób ich backupy, wersjonuj je i przywracaj z linii poleceń.

📖 **[Pełna dokumentacja](https://synerdy.github.io/modsx/pl/)** — ten sam README z bocznym menu nawigacyjnym.

**Ten dokument w innym języku:** [English](README.md)

---

## Idea

Większość pakietów modułowych do Laravela wymaga przebudowy aplikacji: osobne drzewo źródeł, service provider na moduł, własny autoloading, własne przestrzenie nazw dla widoków i tras. To sporo maszynerii do wdrożenia — i sporo do rozplątania, jeśli zmienisz zdanie.

Modsx robi odwrotnie. **Moduł to po prostu zbiór katalogów o wspólnej nazwie.** Tworzysz je sam, w miejscach, w których Laravel i tak trzyma swoje rzeczy:

```
resources/views/modsx-blog/
app/Http/Controllers/ModsxBlog/
```

To już jest moduł. Działa od razu — Laravel znajduje te widoki i kontrolery dokładnie tak jak zawsze, bo we frameworku nic się nie zmieniło. Bez providera, bez rejestrowania przestrzeni nazw, bez reguł autoloadingu.

Ten pakiet nie tworzy tej struktury i nie bierze udziału w jej działaniu. On ją tylko **znajduje** i nią zarządza: backup, wersjonowanie, przywracanie, usuwanie.

Wynikają z tego trzy rzeczy:

- **Możesz stosować konwencję bez instalowania czegokolwiek.** Zacznij prefiksować katalogi już dziś, a pakiet doinstaluj w dniu, w którym faktycznie będziesz chciał backupy.
- **Możesz go odinstalować i nic nie tracisz.** Usuwasz pakiet, moduły działają dalej — to przecież zwykłe katalogi Laravela i nigdy nie były niczym innym.
- **Nie gryzie się z resztą ekosystemu.** Livewire, Filament, Inertia, Folio — wszystko, co czyta z `app/`, `resources/` czy `routes/`, widzi zwykłe katalogi, bo to właśnie są zwykłe katalogi.

Kompromis jest uczciwy: to nie jest menedżer pakietów. Nie rozwiązuje zależności między modułami, nie zarządza wymaganiami Composera i nie dotyka bazy danych. Patrz [Ograniczenia](#ograniczenia).

---

## Wymagania

| Wymaganie | Wersja |
|---|---|
| PHP | 8.3+ |
| Laravel | 12.x, 13.x |

---

## Instalacja

```bash
composer require --dev synerdy/modsx
```

`--dev`, bo Modsx to narzędzie: poza komendą artisana nie robi zupełnie nic — service provider wychodzi natychmiast, jeśli aplikacja nie działa w konsoli, a żaden fragment Twojej aplikacji nigdy nie sięga do tego pakietu. Konwencja działa również przy odinstalowanym Modsx; o to w niej właśnie chodzi.

Instaluj do `require`, jeśli uruchamiasz `modsx:*` tam, gdzie zależności deweloperskich nie ma — skrypt wdrożeniowy backupujący moduł przed zmianą albo CI odpalające `modsx:doctor` po `composer install --no-dev`.

Service provider jest wykrywany automatycznie. Żeby cokolwiek zmienić, opublikuj konfigurację:

```bash
php artisan vendor:publish --tag=modsx-config
```

Backupy trafiają do `modsx-backups/` w katalogu głównym projektu. Prawie na pewno chcesz je trzymać poza kontrolą wersji:

```gitignore
# .gitignore
/modsx-backups
```

Zostawienie ich w Gicie też jest sensownym wyborem, jeśli chcesz, żeby wersje modułów podróżowały razem z repozytorium — pamiętaj tylko, że backup to pełna kopia katalogów, więc repo urośnie przy każdym.

### Aktualizacja

Dopóki Modsx jest w `0.x`, nowy minor **nie** przyjdzie zwykłym `composer update`. Trzeba go wskazać wprost:

```bash
composer require --dev synerdy/modsx:^0.7
```

Composer traktuje wszystko poniżej `1.0.0` z ostrożnością należną wersjom przedpremierowym: tam `^0.6.1` znaczy `>=0.6.1 <0.7.0`, czyli minor stoi w miejscu, w którym normalnie stoi major. Dlatego `composer update` zostaje przy zainstalowanym minorze — celowo, a nie przez przypadek — a `composer why-not synerdy/modsx 0.7.0` to potwierdzi. Wymuszenie nowego minora przepisuje ograniczenie i aktualizuje za jednym razem.

Warto najpierw zajrzeć do [changelogu](https://github.com/Synerdy/modsx/blob/master/CHANGELOG.md): w `0.x` minor może nieść zmianę łamiącą i wtedy jest to tam wyraźnie zaznaczone.

#### Wypróbowanie bety 1.0

Komend oznaczonych **1.0** w tabeli poniżej nie ma w `0.7.0`. Są w wydaniu przedpremierowym, którego Composer nie zainstaluje, dopóki mu tego nie powiesz:

```bash
composer require --dev synerdy/modsx:1.0.0-beta.1     # dokładnie ta beta
```

Podanie wersji z sufiksem samo zdejmuje filtr stabilności dla tego pakietu — `minimum-stability` nie trzeba ruszać. Żeby zamiast przypinania jednej bety śledzić wszystkie przedpremierowe wydania 1.0, wpisz to do `composer.json` i uruchom `composer update synerdy/modsx`:

```json
"require-dev": {
    "synerdy/modsx": "^1.0@beta"
}
```

Zwykłe `composer require synerdy/modsx` nadal wybierze najnowsze wydanie **stabilne**, więc nikt nie dostanie bety przypadkiem. Powrót to ta sama komenda ze stabilnym ograniczeniem:

```bash
composer require --dev synerdy/modsx:^0.7
```

To beta dlatego, że dwie rzeczy, które ten pakiet zapisuje — `modsx-state.json` i `_snapshots/*.json` — nie były jeszcze używane poza jego własnymi testami. Jeśli któraś okaże się potrzebować innego kształtu, zmiana teraz nic nie kosztuje; po `1.0.0` kosztuje ścieżkę migracji albo wersję major. Traktuj zapisywane drzewo backupów jak coś, o czyje skasowanie i odtworzenie możesz zostać poproszony.

---

## Konwencja nazewnictwa

**To jedyna sekcja, którą naprawdę warto przeczytać uważnie.** Cała reszta z niej wynika.

Moduł ma jedną kanoniczną nazwę zapisaną w **StudlyCase**:

```
Blog        UserProfile        AdminPanel
```

Z tej nazwy wyprowadzane są wszystkie pozostałe formy — Modsx dopasowuje **każdą**:

| Gdzie | Forma | `Blog` | `UserProfile` |
|---|---|---|---|
| Katalogi w `resources/`, `public/`, `lang/` | `modsx-` + kebab-case | `modsx-blog` | `modsx-user-profile` |
| Katalogi przestrzeni nazw w `app/`, `database/`, `tests/` | `Modsx` + StudlyCase | `ModsxBlog` | `ModsxUserProfile` |
| Pojedyncze pliki — `routes/`, `config/`, `lang/` | `modsx-` + kebab-case | `modsx-blog.php` | `modsx-user-profile.php` |
| Nazwy migracji, po timestampie | `modsx_` + snake_case | `modsx_blog_…` | `modsx_user_profile_…` |

Dwie pierwsze to konwencja samego Laravela, a nie wymysł tego pakietu — framework mapuje `App\View\Components\UserProfile` na `<x-user-profile>` dokładnie tą samą konwersją StudlyCase ↔ kebab-case. Z prefiksem jest identycznie: `App\View\Components\ModsxUserProfile\PostCard` to `<x-modsx-user-profile.post-card>`. Jeśli Twoje katalogi już trzymają się nazewnictwa Laravela, to tym samym trzymają się i tego.

Podział na dwie formy nie jest kosmetyczny. Katalogi w `app/`, `database/` i `tests/` to segmenty przestrzeni nazw PSR-4, a identyfikator PHP nie może zawierać myślnika — `App\Support\modsx-blog` to nazwa, której PHP nigdy nie załaduje. Wszędzie indziej nazwa jest po prostu ścieżką, więc obowiązuje kebab-case.

> **Każda forma musi pochodzić od tej samej nazwy.**
>
> `modsx-userprofile` i `ModsxUserProfile` to **dwa różne moduły**: pierwszy to `Userprofile`, drugi `UserProfile`. Zrobisz backup `UserProfile` i widoki z `modsx-userprofile` zostaną po cichu pominięte.
>
> Najpierw zapisz nazwę w StudlyCase, potem konwertuj: `UserProfile` → `user-profile`, nigdy `userprofile`. Albo pozwól, żeby `php artisan modsx:scaffold UserProfile` i `php artisan modsx:make` zapisały je za Ciebie — to jedyny sposób, żeby mieć pewność, że się zgadzają. Jeśli podejrzewasz, że gdzieś już Ci się to przydarzyło, `php artisan modsx:doctor` to znajdzie.

Sam prefiks `modsx` jest konfigurowalny, więc jeśli wolisz `mod-` albo inicjały firmy, zmieniasz go raz i obowiązują te same reguły.

### Które nazwy należą do modułu

**Jedna zasada: nazwa identyfikuje moduł.** Tak samo w katalogu, w pliku i w migracji. `modsx-blog` należy do Bloga; `modsx-blog-post` to inna nazwa, więc inny moduł.

**Katalogi — obie formy nazwy:**

| Ścieżka | Moduł | Dlaczego |
|---|---|---|
| `app/Models/ModsxBlog/` | Blog | forma StudlyCase |
| `resources/views/modsx-blog/` | Blog | forma kebab-case |
| `resources/views/modsx-blog-post/` | **BlogPost** | inna nazwa to inny moduł |
| `resources/views/modsx-blogging/` | Blogging | tak samo |

**Pojedyncze pliki — nazwa do pierwszej kropki, dokładnie:**

| Ścieżka | Moduł | Dlaczego |
|---|---|---|
| `config/modsx-blog.php` | Blog | dokładnie ta nazwa |
| `routes/modsx-blog.php` | Blog | dowolna skanowana ścieżka |
| `lang/en/modsx-blog.php`, `lang/pl/modsx-blog.php` | Blog | każdy język |
| `public/modsx-blog.css`, `public/modsx-blog.min.js` | Blog | dowolne rozszerzenie, cięcie na **pierwszej** kropce |
| `config/modsx-blog-post.php` | **BlogPost** | nie Blog — tak samo jak katalog |
| `config/modsx-blog-admin.php` | **BlogAdmin** | nazywa moduł; niczyj, jeśli takiego nie ma |
| `config/blog-modsx.php` | żaden | prefiks nie z przodu |
| `app/Support/ModsxBlog.php` | żaden | klasy mieszkają w katalogu modułu — `app/Support/ModsxBlog/ChangeFormat.php` |

Nazwy modułów są unikalne, więc do jednego pliku pasuje najwyżej jeden moduł — dwa nie mogą go sobie przypisać naraz.

Forma pojedynczego pliku jest dla miejsc, w których Laravel oczekuje jednego pliku na dany temat — `routes/`, `config/`, `lang/`. Wszędzie indziej moduł jest katalogiem: widoki w `resources/views/modsx-blog/`, źródła assetów w `resources/css/modsx-blog/` i `resources/js/modsx-blog/`, klasy w `app/Support/ModsxBlog/`. Tak właśnie tworzy je `modsx:scaffold` i to jest forma, po którą sięgać w razie wątpliwości — katalog dopasowuje się po dokładnej nazwie i pomieści dowolnie dużo. Zadziała nawet `config/modsx-blog/settings.php`, które Laravel czyta jako `config('modsx-blog.settings')`.

**Migracje — moduł z przodu, potem zwykła nazwa Laravela:**

| Nazwa po timestampie | Moduł | Dlaczego |
|---|---|---|
| `modsx_blog_create_posts_table` | Blog | |
| `modsx_blog_add_slug_to_posts_table` | Blog | |
| `modsx_blog_post_create_comments_table` | **BlogPost**, a gdy go nie ma — Blog | wygrywa dłuższa nazwa |
| `modsx_blogging_create_x_table` | Blogging | |
| `create_modsx_blog_posts_table` | żaden | `modsx:doctor` zgłosi to i poda nazwę do zmiany |

Migracja jest jedyną rzeczą, której nie da się nazwać samą nazwą modułu i niczym więcej — każda potrzebuje własnej nazwy — więc to jedyne miejsce, gdzie dłuższa nazwa modułu ma pierwszeństwo.

**`Blog` obok `BlogPost` to układ wspierany:**

```
app/Models/ModsxBlog/                                    Blog
app/Models/ModsxBlogPost/                                BlogPost
config/modsx-blog.php                                    Blog
config/modsx-blog-post.php                               BlogPost
..._modsx_blog_create_posts_table.php                    Blog
..._modsx_blog_post_create_comments_table.php            BlogPost
```

`modsx:delete Blog` rusza wyłącznie pierwszą kolumnę.

### Co należy do modułu

| | Backupowane | Przywracane | Usuwane przez `modsx:delete` |
|---|---|---|---|
| Katalogi (`ModsxBlog/`, `modsx-blog/`) | tak | tak | tak |
| Pliki (`routes/modsx-blog.php`, …) | tak | tak | tak |
| Migracje | **tylko archiwum** | **nie** | **nie** |

Migracje to celowy wyjątek. Modsx nigdy nie dotyka bazy danych, więc przywrócenie starego pliku migracji przy schemacie, który poszedł do przodu, zostawiłoby repozytorium i bazę w niezgodzie — bez żadnego sygnału. Usunięcie takiego pliku, gdy jego tabele wciąż istnieją, byłoby jeszcze gorsze. Dlatego są kopiowane do każdego backupu dla wglądu — zawsze możesz odczytać, jak schemat wyglądał wcześniej — a poza tym zostawiane dokładnie tam, gdzie są.

### Przykładowy układ

```
app/
├── Http/Controllers/ModsxBlog/
│   ├── PostController.php
│   └── CategoryController.php
├── Livewire/ModsxBlog/
│   └── PostList.php
├── Models/ModsxBlog/
│   └── Post.php
└── Services/ModsxBlog/
    └── PostPublisher.php

resources/
├── views/modsx-blog/
│   ├── index.blade.php
│   └── show.blade.php
├── views/components/modsx-blog/
│   └── post-card.blade.php
├── css/modsx-blog/
│   └── blog.css
└── js/modsx-blog/
    └── editor.js

routes/modsx-blog.php
config/modsx-blog.php
database/migrations/2026_01_01_000000_modsx_blog_create_posts_table.php
```

Nic z tego nie jest obowiązkowe. Modułem może być pojedynczy katalog widoków.

### Livewire

Livewire 3 i 4 działają bez dodatkowej obsługi, bo Livewire wykrywa komponenty po katalogach:

```
app/Livewire/ModsxBlog/PostList.php               → <livewire:modsx-blog.post-list />
resources/views/livewire/modsx-blog/post-list.blade.php
```

Komponenty jednoplikowe z Livewire 4 leżą w `resources/views/components/`, więc obowiązuje ten sam prefiks:

```
resources/views/components/modsx-blog/post-list.blade.php
```

W kodzie pakietu nie ma niczego specyficznego dla Livewire — widzi zwykłe katalogi i właśnie dlatego działa niezależnie od jego wersji.

---

## Komendy

Uruchom dowolną komendę bez argumentów, a zapyta Cię o resztę — z listą do wyboru zamiast przepisywania nazw.

| Komenda | Do czego |
|---|---|
| `modsx:make {generator} {Moduł/Nazwa}` | Uruchamia generator Laravela z wpisanym modułem |
| `modsx:scaffold {name} {path?*}` | Tworzy katalogi modułu — z konfiguracji albo wskazane |
| `modsx:list` | Moduły obecne w aplikacji |
| `modsx:status {name?}` | **1.0** &nbsp; Każdy moduł: co jest, z czego się wzięło, co się ruszyło |
| `modsx:deps {name?}` | **1.0** &nbsp; Których modułów potrzebuje moduł — wyliczone z kodu |
| `modsx:path {name?}` | Wszystko, co należy do modułu |
| `modsx:backup {name?}` | Kopiuje moduł do nowej numerowanej wersji |
| `modsx:backuplist {name?}` | Dostępne wersje w backupie |
| `modsx:export {name?} {version?}` | Pakuje wersję backupu do przenośnego .zip |
| `modsx:import {path}` | Rozpakowuje .zip stworzony przez `modsx:export` |
| `modsx:delete {name?}` | Robi backup, po czym usuwa moduł |
| `modsx:restore {name?} {version?}` | Backupuje stan bieżący, po czym przywraca wersję |
| `modsx:diff {name?} {version?} {against?}` | Porównanie z wersją w backupie albo dwóch wersji ze sobą |
| `modsx:info {name?}` | Rozmiar, liczba plików i historia backupów |
| `modsx:prune {name?}` | Usuwa stare wersje, zostawiając najnowsze |
| `modsx:snapshot {name?}` | **1.0** &nbsp; Zapis wersji każdego modułu jako jeden snapshot |
| `modsx:snapshotlist` | **1.0** &nbsp; Wykonane snapshoty |
| `modsx:rollback {snapshot?}` | **1.0** &nbsp; Cofnięcie całego projektu do snapshotu |
| `modsx:snapshotprune` | **1.0** &nbsp; Usunięcie starych snapshotów, zachowując najnowsze |
| `modsx:doctor` | Szuka problemów z nazwami i osieroconych backupów |

Komendy oznaczone **1.0** są w wydaniu przedpremierowym `1.0.0-beta.1` i nie ma ich w bieżącym wydaniu stabilnym — patrz [Wypróbowanie bety 1.0](#wypróbowanie-bety-10).

### `modsx:make`

Uruchamia jeden z generatorów Laravela, wpisując moduł za Ciebie.

```bash
php artisan modsx:make controller Blog/PostController
php artisan modsx:make view blog.index
php artisan modsx:make migration Blog/create_posts_table
```

To jest `php artisan make:*` z wyciętym prefiksem. Generator jest Laravela, opcje są Laravela, wyjście jest Laravela — Modsx ustala wyłącznie nazwę:

| Wpisujesz | Uruchamia się |
|---|---|
| `modsx:make controller Blog/PostController` | `make:controller ModsxBlog/PostController` |
| `modsx:make view blog.index` | `make:view modsx-blog/index` |
| `modsx:make config Blog/settings` | `make:config modsx-blog-settings` |
| `modsx:make migration Blog/create_posts_table` | `make:migration modsx_blog_create_posts_table --create=posts` |

Trzy formy jednej nazwy, inna przy każdym generatorze, to ta część konwencji, w której najłatwiej o subtelną pomyłkę — a pomyłka daje dwa moduły, które czyta się jak jeden. Która forma gdzie trafia, mówi tabela w `config/modsx.php`:

```php
'generators' => [
    '*'         => '{Studly}/',   // ModsxBlog/PostController
    'view'      => '{kebab}/',    // modsx-blog/index
    'config'    => '{kebab}-',    // modsx-blog-settings
    'migration' => '{snake}_',    // modsx_blog_create_posts_table
],
```

Wszystko, czego nie ma na liście, dostaje `*` — co jest poprawne dla każdej klasy PHP.

Wszystko, czego nie ma na liście, dostaje `*`, a dostępne generatory to te zarejestrowane w Twojej aplikacji, a nie sztywny zbiór — generator z pakietu jest obsłużony tak samo dobrze jak ten od Laravela.

#### Wszystkie generatory Laravela i nazwa, jaką dostają

Laravel używa w swoich generatorach trzech stylów nazewnictwa i powyższa tabela pokrywa wszystkie trzy. Rozpisane w całości, dla Laravela 12/13:

**PascalCase — do katalogu przestrzeni nazw modułu.** Wszystkie podpadają pod `*`:

| Wpisujesz | Uruchamia się |
|---|---|
| `modsx:make cast Blog/MoneyCast` | `make:cast ModsxBlog/MoneyCast` |
| `modsx:make channel Blog/OrderChannel` | `make:channel ModsxBlog/OrderChannel` |
| `modsx:make class Blog/PaymentService` | `make:class ModsxBlog/PaymentService` |
| `modsx:make command Blog/SendEmails` | `make:command ModsxBlog/SendEmails` |
| `modsx:make component Blog/Alert` | `make:component ModsxBlog/Alert` |
| `modsx:make controller Blog/UserController` | `make:controller ModsxBlog/UserController` |
| `modsx:make enum Blog/OrderStatus` | `make:enum ModsxBlog/OrderStatus` |
| `modsx:make event Blog/OrderCreated` | `make:event ModsxBlog/OrderCreated` |
| `modsx:make exception Blog/PaymentException` | `make:exception ModsxBlog/PaymentException` |
| `modsx:make factory Blog/UserFactory` | `make:factory ModsxBlog/UserFactory` |
| `modsx:make interface Blog/PaymentGateway` | `make:interface ModsxBlog/PaymentGateway` |
| `modsx:make job Blog/ProcessOrder` | `make:job ModsxBlog/ProcessOrder` |
| `modsx:make job-middleware Blog/RateLimited` | `make:job-middleware ModsxBlog/RateLimited` |
| `modsx:make listener Blog/SendWelcomeEmail` | `make:listener ModsxBlog/SendWelcomeEmail` |
| `modsx:make mail Blog/OrderShipped` | `make:mail ModsxBlog/OrderShipped` |
| `modsx:make middleware Blog/Authenticate` | `make:middleware ModsxBlog/Authenticate` |
| `modsx:make model Blog/User` | `make:model ModsxBlog/User` |
| `modsx:make notification Blog/InvoicePaid` | `make:notification ModsxBlog/InvoicePaid` |
| `modsx:make observer Blog/UserObserver` | `make:observer ModsxBlog/UserObserver` |
| `modsx:make policy Blog/UserPolicy` | `make:policy ModsxBlog/UserPolicy` |
| `modsx:make provider Blog/AppServiceProvider` | `make:provider ModsxBlog/AppServiceProvider` |
| `modsx:make request Blog/StoreUserRequest` | `make:request ModsxBlog/StoreUserRequest` |
| `modsx:make resource Blog/UserResource` | `make:resource ModsxBlog/UserResource` |
| `modsx:make rule Blog/ValidPhoneNumber` | `make:rule ModsxBlog/ValidPhoneNumber` |
| `modsx:make scope Blog/PopularScope` | `make:scope ModsxBlog/PopularScope` |
| `modsx:make seeder Blog/UserSeeder` | `make:seeder ModsxBlog/UserSeeder` |
| `modsx:make test Blog/UserTest` | `make:test ModsxBlog/UserTest` |
| `modsx:make trait Blog/HasRoles` | `make:trait ModsxBlog/HasRoles` |

**Trzy, które się różnią:**

| Wpisujesz | Uruchamia się | Forma |
|---|---|---|
| `modsx:make view blog.users.index` | `make:view modsx-blog/users.index` | ścieżka widoku |
| `modsx:make config blog.services` | `make:config modsx-blog-services` | kebab-case |
| `modsx:make migration blog.create_users_table` | `make:migration modsx_blog_create_users_table --create=users` | snake_case |

**Dowolny separator, przy każdym generatorze.** Tabele powyżej wybierają ten, który czyta się naturalniej, ale moduł kończy się na pierwszym `/`, `\` albo `.` — niezależnie od tego, co generujesz. To są te same wywołania:

| Z ukośnikiem | Z kropką |
|---|---|
| `modsx:make config Blog/services` | `modsx:make config blog.services` |
| `modsx:make migration Blog/create_users_table` | `modsx:make migration blog.create_users_table` |
| `modsx:make controller Blog/UserController` | `modsx:make controller Blog.UserController` |

`make:config` to jedyne miejsce, w którym Modsx odchodzi od gołego Laravela, gdzie nazwa konfiguracji jest w snake_case. I musi: `config/modsx_blog_services.php` **nie** zostałby rozpoznany jako plik modułu — reguła szuka kebabowego prefiksu `modsx-` — więc konfiguracja byłaby osierocona, backupowana z niczym i usuwana z niczym. Kebab-case jest tu wymuszony przez konwencję, a nie wybrany.

**Zupełnie poza zakresem modułu:** `make:cache-table`, `make:session-table`, `make:notifications-table`, `make:queue-table`, `make:queue-batches-table` i `make:queue-failed-table` nie przyjmują nazwy — generują stałą migrację frameworka. Podanie jej przez `modsx:make` kończy się odpowiedzią Laravela *„No arguments expected"*, i słusznie: te tabele należą do aplikacji, a nie do modułu.

**Generatory z innych pakietów** są traktowane tak samo — lista to te zarejestrowane w Twojej aplikacji, a `*` jest już właściwą formą dla klasy:

| Wpisujesz | Uruchamia się | Co dostajesz |
|---|---|---|
| `modsx:make livewire Blog/Alert` | `make:livewire ModsxBlog/Alert` | `app/Livewire/ModsxBlog/Alert.php` oraz `views/livewire/modsx-blog/alert.blade.php`, czyli `<livewire:modsx-blog.alert />` |
| `modsx:make filament-resource Blog/PostResource` | `make:filament-resource ModsxBlog/PostResource` | `app/Filament/Resources/ModsxBlog/PostResource.php` |

Livewire wyprowadza ścieżkę widoku z tego, gdzie trafiła klasa — dokładnie tak jak komponenty samego Laravela — więc StudlyCase to wszystko, czego od nas potrzebuje. Wpis w `modsx.generators` dopisujesz tylko wtedy, gdy `*` jest dla danego generatora **niewłaściwe** — gdy jego nazwa jest ścieżką albo nazwą pliku, a nie klasy.

#### `layout`, `page`, `partial`

Własne widoki modułu mieszkają w `resources/views/modsx-blog/`, ale jego layout jest wycinkiem wspólnego `layouts/` aplikacji — najpierw katalog frameworka, potem moduł, dokładnie jak w `resources/css/modsx-blog/`. Sięgają tam trzy własne nazwy Modsx:

```bash
php artisan modsx:make layout blog.app     # -> resources/views/layouts/modsx-blog/app.blade.php
php artisan modsx:make page blog.index     # -> resources/views/pages/modsx-blog/index.blade.php
php artisan modsx:make partial blog.head   # -> resources/views/partials/modsx-blog/head.blade.php
```

W Laravelu nie ma `make:layout`. To wpisy w tej samej tabeli konfiguracji, a różni je to, że wpis podaje nie tylko formę, ale i generator do uruchomienia:

```php
'generators' => [
    'view'    => '{kebab}/',                    // uruchamia make:view
    'layout'  => ['view', 'layouts/{kebab}/'],  // też make:view, tylko gdzie indziej
    'page'    => ['view', 'pages/{kebab}/'],
    'partial' => ['view', 'partials/{kebab}/'],
],
```

Nazwy są więc Twoje do wyboru. `'service' => ['class', 'Services/{Studly}/']` daje `modsx:make service Blog/PostPublisher` zapisujące `app/Services/ModsxBlog/PostPublisher.php` — i pojawia się w liście wyboru obok generatorów Laravela.

Świadomie nie ma tu `component`: `make:component` to generator samego Laravela i już teraz ląduje poprawnie — zapisuje klasę, a Laravel wyprowadza `views/components/modsx-blog/` z tego, gdzie ta klasa trafiła.

**Forma dotyczy całej nazwy, nie tylko modułu.** Wpisujesz, jak Ci wygodnie, a generator dostaje nazwę w formie ze swojego wpisu, przerobioną człon po członie:

```bash
php artisan modsx:make view blog.PostList          # -> modsx-blog/post-list
php artisan modsx:make view blog.admin.PostList    # -> modsx-blog/admin.post-list
php artisan modsx:make config Blog/MailSettings    # -> modsx-blog-mail-settings
php artisan modsx:make migration Blog/CreatePostsTable
                                                   # -> modsx_blog_create_posts_table
php artisan modsx:make controller Blog/PostController
                                                   # -> ModsxBlog/PostController, bez zmian
```

Wpis `{Studly}` zostawia resztę nazwy w spokoju, bo nazwa klasy jest już zapisana tak, jak generator jej oczekuje.

**Opcje generatora piszesz tam, gdzie i tak byś je napisał** — są przekazywane bez zmian:

```bash
php artisan modsx:make controller Blog/PostController --resource --model=Post
php artisan modsx:make model Blog/Post -fs
php artisan modsx:make component blog.alert --view
```

`modsx:make` ma dokładnie jedną własną opcję, `--dry-run`. Wszystko, czego nie rozpoznaje, należy do generatora i jest przekazywane dalej — dlatego dla `make:livewire` ani żadnego innego pakietu nie trzeba niczego deklarować.

`--` nadal działa i jest sposobem na opcję generatora, która koliduje z naszą:

```bash
php artisan modsx:make controller Blog/PostController -- --dry-run
```

**Moduł oddzielasz `/` albo `.`** — tym, co lepiej czyta się z danym generatorem. Nazwę widoku w dokumentacji Laravela pisze się kropkami, więc tutaj pisz ją tak samo: małymi literami, dokładnie jak wpisałbyś ją do `make:view`:

```bash
php artisan modsx:make view blog.create        # -> make:view modsx-blog/create
php artisan modsx:make view blog.admin.index   # -> make:view modsx-blog/admin.index
php artisan modsx:make controller Blog/PostController
```

Dzieli wyłącznie **pierwszy** separator — nazwa modułu nie może zawierać żadnego — więc reszta nazwy zachowuje własne kropki. Obie formy są wymienne, podobnie jak wynik: `make:view` sam zamienia kropki na ukośniki, dlatego `modsx-blog/create` to ten sam widok co `modsx-blog.create`.

Backslash też zadziała, ale unikaj go w powłoce POSIX: bez cudzysłowu zostaje usunięty, zanim Modsx go zobaczy, więc `Blog\PostController` dociera jako `BlogPostController`. PowerShell używa jako escape'a backticka, więc tam przeżywa.

`--dry-run` wypisuje komendę, którą by uruchomił, i kończy:

```bash
$ php artisan modsx:make migration Blog/create_posts_table --dry-run
  Would run:
  php artisan make:migration modsx_blog_create_posts_table --create=posts
```

To `--create=posts` nie jest ozdobnikiem. Laravel zgaduje tabelę z nazwy migracji wzorcem `/^create_(\w+)_table$/`, którego `modsx_blog_create_posts_table` nie może spełnić z modułem z przodu — więc bez tego każda migracja tworząca tabelę wychodziłaby jako pusty stub. Modsx uruchamia zgadywanie na tej części, którą faktycznie napisałeś, i przekazuje wynik dalej.

Jeśli moduł jeszcze nie istnieje, dowiesz się o tym i dostaniesz jedno pytanie — domyślnie „tak", z najbliższą istniejącą nazwą na wypadek literówki. Uruchomienie nieinteraktywne ostrzega i leci dalej: utworzenie pliku nie jest destrukcyjne, a to jedyna komenda w pakiecie, która nigdy nie jest.

Jednej rzeczy nie naprawi: przy `make:model -m` migrację nazywa *Laravel*, więc wychodzi jako `create_posts_table`, bez prefiksu modułu, i do modułu nie należy — nie zostanie z nim zbackupowana. Modsx o tym ostrzega. Wygeneruj migrację osobno.

### `modsx:scaffold`

Tworzy katalogi nowego modułu. Konwencja działa świetnie i bez tej komendy — możesz zrobić katalogi ręcznie i niczego nie instalować — ale wpisywanie obu form samodzielnie to jedyny sposób, żeby się pomylić. Tutaj obie pochodzą z jednej nazwy, więc nie mogą się rozjechać.

```bash
php artisan modsx:scaffold Blog
php artisan modsx:scaffold user-profile   # dowolna wielkość liter, jest normalizowana
```

#### Lista z konfiguracji

Które katalogi powstaną, zależy od Ciebie — `config/modsx.php`:

```php
'scaffold' => [
    'app/Http/Controllers/{Studly}',
    'app/Models/{Studly}',
    'resources/views/{kebab}',
],
```

`{Studly}` staje się `ModsxBlog`, `{kebab}` staje się `modsx-blog`. Oba z tej jednej nazwy, którą wpisałeś.

Opublikowany config niesie dłuższą listę, zakomentowaną — Livewire, serwisy, form requesty, fabryki, seedery, testy, `resources/css/`, `resources/js/`, komponenty — więc odkomentowujesz to, co Twoje moduły faktycznie mają. Domyślna lista jest krótka celowo: katalog, którego nikt nie wypełni, jest niewidoczny dla gita i zgłasza go `modsx:doctor`, więc hojna wartość domyślna robiłaby tylko robotę dla `--fix`.

Widoki mają ten sam kształt co wszystko inne — **najpierw katalog frameworka, moduł w środku**, dokładnie jak `resources/css/modsx-blog/`:

```
resources/views/
├── components/
│   ├── layouts/app.blade.php     aplikacji
│   └── modsx-blog/card.blade.php Bloga   -> <x-modsx-blog.card>
├── layouts/
│   ├── app.blade.php             aplikacji
│   └── modsx-blog/               Bloga
├── partials/modsx-blog/          Bloga
└── modsx-blog/                   własne strony Bloga
```

Jedno i drugie żyje obok siebie: `layouts/app.blade.php` ze starter kitu nie nosi prefiksu, więc żaden moduł go nie zagarnie, a `layouts/modsx-blog/` jest Bloga i wędruje razem z nim. Moduły są znajdowane na dowolnej głębokości, więc zagnieżdżenie o poziom nic nie kosztuje.

Zwróć uwagę na kolejność. `layouts/modsx-blog/` — nie `modsx-blog/layouts/`. W całej tej konwencji moduł idzie *do wnętrza* katalogu frameworka i widoki nie są wyjątkiem; odwrócenie tego akurat tutaj sprawiłoby, że `<x-modsx-blog.card>` przestaje się rozwiązywać.

#### Wskazanie katalogów wprost

Podaj katalogi po nazwie modułu, a powstaną one zamiast listy z konfiguracji — ten jeden, którego potrzebujesz teraz, bez zmieniania tego, co dostaje każdy przyszły moduł:

```bash
php artisan modsx:scaffold Blog resources/css
# resources/css/modsx-blog/

php artisan modsx:scaffold Blog resources/css resources/js app/Services
# resources/css/modsx-blog/
# resources/js/modsx-blog/
# app/Services/ModsxBlog/
```

Zwróć uwagę na trzeci: **`ModsxBlog`, nie `modsx-blog`**. Piszesz ścieżkę tak, jak wygląda w projekcie, a forma katalogu modułu jest odczytywana z tego, dokąd ta ścieżka prowadzi:

| Wpisujesz | Powstaje | Dlaczego |
|---|---|---|
| `resources/css` | `resources/css/modsx-blog` | ścieżka, więc kebab-case |
| `resources/js` | `resources/js/modsx-blog` | |
| `resources/views/layouts` | `resources/views/layouts/modsx-blog` | |
| `public/vendor` | `public/vendor/modsx-blog` | |
| `lang/en` | `lang/en/modsx-blog` | |
| `app/Services` | `app/Services/`**`ModsxBlog`** | `app/` to PSR-4 |
| `app/Livewire` | `app/Livewire/`**`ModsxBlog`** | |
| `database/factories` | `database/factories/`**`ModsxBlog`** | `database/` to PSR-4 |
| `tests/Feature` | `tests/Feature/`**`ModsxBlog`** | `tests/` to PSR-4 |

`app/`, `database/` i `tests/` to korzenie PSR-4 standardowej aplikacji Laravela — `App\`, `Database\`, `Tests\` — a myślnik nie jest poprawnym identyfikatorem PHP, więc katalogi pod nimi biorą formę StudlyCase. Wszędzie indziej nazwa jest wyłącznie ścieżką. To ta sama reguła, którą opisuje tabela konwencji, tylko zastosowana za Ciebie.

Gdy to odgadnięcie jest nietrafione — na przykład przy własnym korzeniu PSR-4 — wpisujesz placeholder i to on rozstrzyga:

```bash
php artisan modsx:scaffold Blog "modules/Shared/{Studly}"
# modules/Shared/ModsxBlog/

php artisan modsx:scaffold Blog "storage/exports/{kebab}"
# storage/exports/modsx-blog/
```

Istniejący katalog jest raportowany i zostawiany w spokoju, tak samo jak przy liście z konfiguracji, więc ponowne uruchomienie jest bezpieczne:

```bash
$ php artisan modsx:scaffold Blog resources/css
  resources/css/modsx-blog ...................................... created

$ php artisan modsx:scaffold Blog resources/css
  resources/css/modsx-blog ................................ already existed
```

A ścieżka nie może opuścić projektu:

```bash
$ php artisan modsx:scaffold Blog ../../etc
   ERROR  Invalid path [../../etc]. Give a directory inside the project, such as
          "resources/css" or "app/Services"; it may not contain "..".
```

Tworzy katalogi i nic poza tym — żadnych stubów kontrolerów, żadnego boilerplate'u. Generowanie kodu uczyniłoby z tego generator, czyli dokładnie to, czym Modsx nie jest. Niczego też nie nadpisuje: istniejące katalogi są raportowane i zostawiane w spokoju, więc komendę można bezpiecznie uruchomić ponownie.

Pamiętaj, że git nie śledzi pustych katalogów, więc szkielet, którego nie wypełnisz, po cichu zniknie przy następnym commicie. Tak ma być: katalogi, których faktycznie używasz, będą miały w sobie pliki. Do tego czasu wciąż leży na dysku — `php artisan modsx:doctor --fix` znajduje i usuwa puste katalogi modułu, więc nie trzeba ich szukać ręcznie.

### `modsx:list`

```bash
php artisan modsx:list
php artisan modsx:list --json
```

```
 Module        Directories   Files   Backups   Latest
 Blog          4             2       3         0003
 UserProfile   2             -       -         -
```

Moduł pojawia się na liście, jeśli istnieje **którykolwiek z jego katalogów** — moduł to zbiór katalogów i to one go tworzą. Pliki i migracje nazwane od modułu do niego należą i są liczone w kolumnach powyżej, ale go nie powołują do istnienia: `config/modsx-blog.php` bez żadnego katalogu `modsx-blog` jest zgłaszany przez `modsx:doctor` jako nazywający nieistniejący moduł, a `modsx:backup Blog` powie to samo.

### `modsx:status`

```bash
php artisan modsx:status
php artisan modsx:status Blog
php artisan modsx:status --json
```

```
 Module    State       Current   Latest backup   Changes
 Blog      modified    0001      0002            1
 Shop      clean       0001      0001            0
 Billing   untracked   -         -               -
 Admin     missing     -         0001            -
```

Układ jest ten sam, którego nauczyła Cię kontrola wersji. **Current** to wersja, z której wzięło się drzewo robocze, więc **Changes** liczy to, co się od tamtej pory wydarzyło — a nie odległość od najnowszego backupu, bo to inne pytanie i ma własną kolumnę.

| Stan | Znaczenie |
|---|---|
| `clean` | Jest w aplikacji, zgodny z wersją, z której się wziął |
| `modified` | Jest w aplikacji, różni się od niej — `Changes` mówi o ile plików |
| `untracked` | Jest w aplikacji, nigdy nie backupowany |
| `missing` | Backupy istnieją, ale modułu nie ma w aplikacji |

Moduł może być `clean` i mimo to być w tyle — i właśnie o tym połączeniu warto wiedzieć:

```
 WARN  [Blog] is working from 0001, but 0002 exists. Backing up now would build the next version on the older one.
```

#### Skąd bierze się `Current`

`modsx:backup` i `modsx:restore` zapisują wersję w `modsx-backups/Blog/modsx-state.json`. `modsx:import` celowo nie zapisuje nic: dokłada wersję do drzewa backupów, nie dotykając aplikacji, więc drzewo robocze się z niej nie wzięło.

Ten plik **nigdy nie decyduje o tym, czy moduł istnieje**. Wykrywanie zostaje takie, jakie było zawsze — katalog nazwany zgodnie z konwencją. Moduł zrobiony przez `mkdir` nie ma tu żadnego wpisu i jest pokazany jako `untracked`, co jest prawdą, a nie brakiem. Skasuj wszystkie te pliki, a pakiet zachowa się dokładnie tak jak przed ich powstaniem; pusta zrobi się jedynie kolumna `Current`.

Leży obok wersji, na które wskazuje, bo bez nich nic nie znaczy. Przytnij wskazywaną wersję, a `modsx:doctor` to zgłosi, natomiast `modsx:status` będzie działać dalej, mierząc względem najnowszej.

Jeśli trzymasz drzewo backupów w repozytorium, zostaw ten plik poza nim — to, z której wersji wzięła się *Twoja* kopia robocza, jest faktem lokalnym, a nie wspólnym:

```gitignore
modsx-backups/*/modsx-state.json
```

### `modsx:deps`

```bash
php artisan modsx:deps          # wszystkie moduły
php artisan modsx:deps Blog     # jeden moduł i to, co objąłby jego snapshot
php artisan modsx:deps --json
```

```
 INFO  Blog
  Media ......................... found in the code
  User .......................... found in the code

 INFO  Media
  needs nothing else
```

Graf jest **wyprowadzany, a nie deklarowany.** Moduł o nazwie `Media` występuje w kodzie innych modułów jako `ModsxMedia`, `modsx-media` albo `modsx_media` i w żadnej innej postaci, więc odwołanie do niego jest czymś, co można znaleźć, a nie czymś, o czym trzeba pamiętać.

To cały argument za czytaniem zamiast prowadzenia listy. Ręcznie utrzymywana lista zależności to lista, której nic nie sprawdza: dopiszesz odwołanie do innego modułu, zapomnisz zaktualizować listę i snapshot z niej zbudowany jest po cichu niekompletny — a to gorsze niż brak snapshotu, bo się mu ufa.

| Gdzie szuka | Co liczy się jako odwołanie |
|---|---|
| `use App\Models\ModsxMedia\Asset;` | forma Studly, w dowolnym PHP |
| `@include('modsx-media.player')` | forma kebab, w dowolnym pliku |
| `config('modsx-media.disk')` | ta sama forma, w dowolnym wywołaniu |
| `$table = 'modsx_media_assets';` | forma snake, razem z nazwami tabel |

Wzmianka w komentarzu albo w stringu też się liczy. To celowe: błąd, który stąd wynika, to snapshot obejmujący o jeden moduł za dużo, czyli koszt jednego katalogu — a błąd przeciwny psuje rollback.

Granice nazw są respektowane, więc `ModsxBlogPost` jest odwołaniem do `BlogPost`, a nie przy okazji do `Blog` — ta sama reguła, która rozstrzyga, czyj jest plik. Jedyny wyjątek to forma snake, gdzie sufiks jest czymś zwyczajnym: `modsx_media_assets` to tabela Media, więc podkreślenie może po niej wystąpić.

Przy modułach `Blog`, `BlogPost`, `Media` i `MediaAssets` linia znaleziona w `Blog` daje:

| Linia w module Blog | Krawędź do |
|---|---|
| `use App\Models\ModsxMedia\Asset;` | `Media` |
| `use App\Models\ModsxMediaAssets\Row;` | `MediaAssets` — nie `Media` |
| `@include('modsx-media.player')` | `Media` |
| `@include('modsx-media-assets.row')` | `MediaAssets` — nie `Media` |
| `view('modsx-blog-post.comment')` | `BlogPost` — nie `Blog` |
| `config('modsx-media.disk')` | `Media` |
| `$table = 'modsx_media_assets';` | `Media` **i** `MediaAssets` — forma snake dopuszcza sufiks |
| `// zobacz też ModsxMedia` | `Media` — komentarz też się liczy |
| `use App\Models\ModsxBlog\Post;` w samym Blogu | nic; moduł nigdy nie potrzebuje sam siebie |
| `use App\Models\ModsxGhost\Thing;` bez modułu Ghost | nic; krawędzią może być tylko istniejący moduł |


#### Czego czytanie nie zobaczy

Nazwy klasy sklejanej ze stringa, listenera podpiętego gdzie indziej. To trafia do konfiguracji, która krawędzie **dodaje** i nigdy nie zastępuje tych znalezionych w kodzie:

```php
// config/modsx.php
'dependencies' => [
    'Blog' => ['Search'],
],
```

Krawędź znaleziona w obu miejscach jest raportowana jako *found in the code* — z dwóch twierdzeń to jest to, które da się wskazać palcem.

Moduły zależne od siebie nawzajem są wypisywane, a nie traktowane jak usterka. Pierścień bywa świadomym projektem; jedyną konsekwencją jest tu to, że snapshot któregokolwiek z nich obejmuje je wszystkie.

### `modsx:path`

Pokazuje dokładnie, co Modsx uznaje za część modułu — czyli dokładnie to, co skopiuje backup. Warto uruchomić przed pierwszym `modsx:delete`.

```bash
php artisan modsx:path Blog
php artisan modsx:path            # wszystkie moduły
php artisan modsx:path --json
```

Katalogi, pliki i migracje są wypisywane osobno, przy czym migracje są oznaczone jako archiwalne — żeby było jasne, że przywracanie ich nie odtworzy.

### `modsx:backup`

Kopiuje każdy katalog należący do modułu do nowej, kolejnej wersji.

```bash
php artisan modsx:backup Blog
php artisan modsx:backup Blog -m "przed przejściem na repository pattern"
php artisan modsx:backup --all                  # wszystkie moduły naraz
php artisan modsx:backup Blog --skip-unchanged  # nic nie rób, jeśli nic się nie zmieniło
php artisan modsx:backup Blog --json
```

```
modsx-backups/
└── Blog/
    ├── 0001/
    │   ├── modsx.json
    │   ├── app/Http/Controllers/ModsxBlog/     ← przywracane
    │   ├── routes/modsx-blog.php               ← przywracane
    │   └── _archive/
    │       └── database/migrations/...         ← tylko do wglądu
    └── 0002/
        └── ...
```

Numer wersji bierze się z najwyższego istniejącego numeru, a nie z tego, co system plików wypisze jako ostatnie, a komenda odmawia zapisu do ścieżki, która już istnieje. Wersje nigdy nie są nadpisywane ani używane ponownie.

Zarchiwizowane migracje leżą w `_archive/`, z dala od reszty. To nie jest etykieta — przywracanie czyta listę ścieżek i plików z manifestu i nigdzie indziej nie zagląda, więc nie ma tu flagi, którą dałoby się źle ustawić.

`-m`/`--comment` dopina do wersji opcjonalną, dowolną notatkę tekstową — całkowicie opt-in, nie ma o to promptu. Widać ją w `modsx:backuplist` i `modsx:info`.

`--skip-unchanged` porównuje moduł z jego najnowszą wersją plik po pliku i nie robi nic, jeśli są identyczne — dzięki temu backup przy każdym wdrożeniu nie zapycha dysku identycznymi kopiami. Zmieniona migracja nie liczy się tu jako zmiana, bo nie jest częścią tego, co przywracanie odtwarza.

Każda wersja ma manifest `modsx.json` z nazwą modułu, czasem utworzenia, dokładną listą ścieżek i plików źródłowych, zarchiwizowanymi migracjami, opcjonalnym komentarzem oraz wersjami PHP, Laravela i pakietu. Przywracanie go czyta, dzięki czemu odkłada rzeczy tam, skąd zostały wzięte, zamiast zgadywać ich położenie.

Całość kopiowana jest najpierw do katalogu tymczasowego i dopiero na końcu przenoszona na miejsce, więc przerwany backup nie zostawia po sobie wersji zapisanej w połowie.

Backup dwóch modułów, których nazwy różnią się wyłącznie wielkością liter, jest odrzucany. Na Windows i macOS `UserProfile` i `Userprofile` to ten sam katalog, więc dzieliłyby jedną sekwencję wersji, a przywracanie mogłoby zwrócić nie ten moduł. Odmowa obowiązuje na każdej platformie: zachowanie zależne od systemu plików jest gorsze niż konsekwentne „nie".

### `modsx:backuplist`

```bash
php artisan modsx:backuplist                    # wszystkie moduły
php artisan modsx:backuplist Blog
php artisan modsx:backuplist Blog --limit=5     # 5 najnowszych
php artisan modsx:backuplist --json
```

```
 Blog
 Version   Created                     Directories   Files   Archived   Comment
 0001      2026-08-20T09:14:02+02:00   2             -
 0002      2026-08-21T17:40:55+02:00   2             przed przejściem na repository pattern
```

### `modsx:export`

Pakuje jedną wersję backupu do przenośnego `.zip`, zapisanego obok katalogu wersji, z którego powstał.

```bash
php artisan modsx:export Blog          # najnowsza wersja
php artisan modsx:export Blog 0003     # konkretna wersja
php artisan modsx:export               # interaktywnie
```

```
modsx-backups/
└── Blog/
    ├── 0001/
    ├── 0002/
    └── 0002.zip     ← utworzony przez modsx:export
```

Zip to pochodny, tworzony na żądanie artefakt, a nie nowa wersja. Same wersje pozostają rozpakowanymi katalogami, celowo: otwórz jedną w eksploratorze plików albo wejdź do niej przez `cd`, a zobaczysz dokładnie, co należy do modułu, natychmiast — bez rozpakowywania, bez narzędzi. `modsx:export` tego domyślnego zachowania nie zmienia; dokłada jednoplikową formę do tej jednej rzeczy, w której rozpakowane katalogi wypadają gorzej — przeniesienia wersji gdzie indziej. Ponowne uruchomienie `modsx:export` na tej samej wersji nadpisuje jej zip — nie ma tu zabezpieczenia "już istnieje" takiego jak przy samej wersji. Usunięcie wersji przez `modsx:prune` usuwa też jej zip.

Miejsce, gdzie ląduje zip, nie jest konfigurowalne — przeniesienie go gdziekolwiek indziej to zwykłe `cp`/`mv`, nie coś, co modsx musi wiedzieć.

### `modsx:import`

Rozpakowuje `.zip` stworzony przez `modsx:export` z powrotem do drzewa backupów, pod moduł i wersję, które nazywa jego własny `modsx.json` — tak właśnie moduł podróżuje między projektami jako jeden plik zamiast drzewa katalogów.

```bash
php artisan modsx:import sciezka/do/Blog-0002.zip
```

Odmawia importu na wersję, która już istnieje — z tego samego powodu, dla którego `modsx:backup` odmawia nadpisania istniejącej: raz zapisana wersja nigdy nie jest po cichu zastępowana. Po imporcie przywracasz ją normalnie: `php artisan modsx:restore Blog 0002`.

### `modsx:delete`

**Najpierw robi backup** i nie usuwa niczego, jeśli backup się nie powiódł.

```bash
php artisan modsx:delete Blog
php artisan modsx:delete Blog --force          # bez pytania, do CI
php artisan modsx:delete Blog --skip-backup    # jeśli naprawdę tego chcesz
php artisan modsx:delete Blog --force --json
```

Wszystko, co zostanie usunięte, jest wypisywane przed pytaniem o potwierdzenie, a numer utworzonej wersji jest drukowany — więc zawsze wiesz, co podać do `modsx:restore`.

Migracje też są wypisywane — jako **zostawione**. Zostają w aplikacji, bo ich tabele wciąż są w bazie, a usunięcie pliku, który je dokumentuje, zostawiłoby schemat bez żadnego wyjaśnienia. `modsx:doctor` przypomni Ci później, że należą do modułu, którego już nie ma.

### `modsx:restore`

```bash
php artisan modsx:restore Blog          # najnowsza wersja
php artisan modsx:restore Blog 0003     # konkretna wersja
php artisan modsx:restore               # interaktywnie
php artisan modsx:restore Blog --json
php artisan modsx:restore Blog 0003 --force   # bez pytania, do skryptów
```

Kolejność działań:

1. Backup bieżącego stanu modułu, żeby samo przywracanie też dało się cofnąć.
2. Skopiowanie wybranej wersji z backupu do katalogu tymczasowego.
3. Odsunięcie całego bieżącego stanu na bok, jednym przebiegiem.
4. Przeniesienie przywracanego stanu na miejsce.

Wszystko jest wyciągane z backupu **zanim** cokolwiek zostanie ruszone w aplikacji, więc uszkodzony lub niekompletny backup ujawnia się, gdy bieżący stan jest jeszcze nienaruszony. A ponieważ krok 3 odsuwa stary stan w całości, zamiast kasować ścieżkę po ścieżce, awaria w kroku 4 jest cofana: dostajesz z powrotem dokładnie to, co miałeś, a nie mieszankę starego z nowym.

Wszystko, czego dana wersja nie zawierała, po przywróceniu znika — zostało odsunięte i nie wróciło. Tak właśnie musi działać „przywróć dokładnie ten stan", a `modsx:diff` powie Ci z wyprzedzeniem, czego to dotyczy.

Dotyczy to **wszystkiego, co moduł ma w tej chwili**, a nie tylko tego, co wymienia manifest wersji — inaczej wynik nie odpowiadałby żadnej wersji. Załóżmy, że powstał backup `Blog 0001`, a potem dołożyłeś:

| Dodane po backupie | Po `modsx:restore Blog 0001` |
|---|---|
| `resources/views/modsx-blog/extra.blade.php` | znika — w katalogu należącym do modułu |
| `routes/modsx-blog.php` | znika — osobny plik należący do modułu |
| `app/Models/ModsxBlog/Draft.php` | znika — to samo, w formie Studly |
| `config/unrelated.php` | nietknięty — nie należy do żadnego modułu |
| `config/modsx-shop.php` | nietknięty — należy do `Shop`, nie do `Blog` |

To usuwanie jest bezpieczne wyłącznie dlatego, że nigdy nie dotyczy ostatniej kopii. Krok 1 powyżej najpierw backupuje stan bieżący, więc plik zmieciony przez przywracanie jest o jedną wersję wstecz, a nie stracony:

```bash
php artisan modsx:restore Blog 0001 --force   # extra.blade.php znika...
php artisan modsx:backuplist Blog             # ...do wersji 0002, wykonanej przed chwilą
php artisan modsx:restore Blog 0002           # i oto jest z powrotem
```

Tak samo działa `modsx:rollback`, który przed ruszeniem czegokolwiek wykonuje cały snapshot bezpieczeństwa.

Wyjątkiem, który bywa mylący, jest `modsx:import`: on **nigdy nie dotyka aplikacji.** Rozpakowuje zip wyłącznie do drzewa backupów, więc w Twojej kopii roboczej nic się nie zmienia, dopóki czegoś nie przywrócisz.

Zarchiwizowane migracje nigdy nie są przywracane. Na tym etapie w ogóle nie są czytane.

Jeśli modułu nie ma aktualnie w aplikacji, kroki 1 i 3 są pomijane i staje się to **instalacją z backupu** — i tak właśnie przenosi się moduł między projektami: kopiujesz `modsx-backups/Blog/` i przywracasz.

### `modsx:prune`

```bash
php artisan modsx:prune                          # wszystkie moduły, domyślna wartość z configu
php artisan modsx:prune Blog --keep=5
php artisan modsx:prune --keep=3 --dry-run       # pokazuje plan, nic nie zmienia
php artisan modsx:prune --dry-run --json         # plan w formacie JSON, do CI
php artisan modsx:prune Blog --keep=3 --force    # bez pytania, do skryptów
```

Wypisuje dokładnie, które wersje znikną, i dopiero pyta. Najnowsza wersja nie jest usuwana nigdy, niezależnie od `--keep`.

### `modsx:snapshot`

```bash
php artisan modsx:snapshot                                  # cały projekt
php artisan modsx:snapshot --comment="przed przepisaniem"
php artisan modsx:snapshot Blog                             # Blog i wszystko, czego potrzebuje
php artisan modsx:snapshot --json
```

```
 Module   Version
 Blog     0002      backed up
 Media    0001      unchanged
 User     0001      unchanged

 INFO  Snapshot 0002 taken, holding 3 module(s).
```

Snapshot zapisuje, **która wersja każdego modułu była bieżąca w jednej chwili.** Odpowiada na pytanie, na które backup pojedynczego modułu odpowiedzieć nie potrafi:

> Nie mogę przywrócić Bloga sprzed trzech tygodni, bo zależał wtedy od innej wersji User.

**Nic nie kopiuje.** Wersje, które wymienia, już są w drzewie backupów, więc zapisywane jest kilkaset bajtów numerów w `modsx-backups/_snapshots/0002.json`. Kopiowanie ich podwoiłoby koszt dysku i dałoby jednej wersji dwa miejsca zamieszkania.

Moduł, który nie zmienił się od ostatniego backupu, nie dostaje nowej wersji — tylko kolejne odwołanie do tej, którą już miał. Snapshot nietkniętego projektu nie zapisuje więc nic poza samym snapshotem. I o to chodzi: snapshot, którego nikomu nie żal zrobić, to ten, który będzie na miejscu, gdy okaże się potrzebny.

Podanie nazwy modułu obejmuje ten moduł i domknięcie jego zależności, wyliczone dokładnie tak, jak pokazuje `modsx:deps`.

### `modsx:snapshotlist`

```bash
php artisan modsx:snapshotlist
php artisan modsx:snapshotlist --limit=5
php artisan modsx:snapshotlist --json
```

```
 Snapshot   Created                     Scope           Modules   Comment
 0001       2026-09-03T20:42:51+00:00   whole project   3         przed przepisaniem
 0002       2026-09-03T20:42:52+00:00   whole project   3         -
 0003       2026-09-03T20:43:01+00:00   Blog            2         -
```

Snapshot pokazany na czerwono wskazuje wersję, której nie ma już w drzewie backupów, i nie da się do niego cofnąć. `modsx:prune` do tego nie doprowadzi — wstrzymuje takie wersje — więc oznacza to, że ktoś zmieniał drzewo backupów ręcznie.

### `modsx:rollback`

```bash
php artisan modsx:rollback           # najnowszy snapshot
php artisan modsx:rollback 0001
php artisan modsx:rollback 0001 --force
```

```
 Module   Current   Snapshot
 Blog     0002      0001        will move
 Media    0001      0001        already there
 User     0001      0001        already there
```

Osobny czasownik niż `modsx:restore`, i to celowo: przywracanie dotyczy jednego modułu i jednej wersji, a rollback rusza wszystkim naraz — tych dwóch rzeczy nikt nie powinien móc pomylić o drugiej w nocy. Plan pokazywany jest przed pytaniem, bo liczbą, która ma znaczenie, nie jest to, ile modułów obejmuje snapshot, tylko ile z nich jest teraz gdzie indziej.

Co dokładnie gwarantuje:

| Etap | Co robi |
|---|---|
| **Sprawdzane najpierw** | Istnienie każdej wymienionej wersji jest potwierdzane, **zanim cokolwiek zostanie tknięte**. To jest awaria, która faktycznie się zdarza, i wychodzi na jaw, gdy aplikacja jest jeszcze cała. |
| **Droga powrotna** | Snapshot stanu bieżącego powstaje przed wszystkim innym, a jego numer jest wypisywany na końcu. |
| **W obrębie modułu** | Każdy moduł jest stage'owany i podmieniany osobno, więc awaria wewnątrz jednego zostawia go nietkniętym. |
| **Pomiędzy modułami** | Awaria w połowie cofa moduły już przywrócone do stanu, w jakim zastał je snapshot bezpieczeństwa, i mówi o tym wprost. |

Czym to **nie** jest, to pojedyncza transakcja systemu plików — coś takiego nie istnieje w poprzek kilkunastu drzew katalogów. Ostatni wiersz to kompensacja, nie atomowość, i właśnie dlatego snapshot bezpieczeństwa jest nazwany, a nie pozostawiony Tobie do odszukania.

Rusza **wyłącznie modułami wymienionymi w snapshocie.** Moduł utworzony po jego wykonaniu nigdy nie należał do tamtej chwili i zostaje dokładnie tam, gdzie jest; moduł skasowany od tamtej pory wraca. Ostrzeżenie powyżej dotyczy więc plików wewnątrz przywracanego modułu, a nie projektu jako całości.

`--force` jest wymagany w trybie nieinteraktywnym, również przy `--json`. Format odczytywalny maszynowo to nie jest zgoda.

**Nie dotyka bazy danych.** Cofnięcie kodu nie cofa schematu, a modsx nie uruchamia żadnych migracji, w żadną stronę. Zarchiwizowane migracje podróżują z backupem swojego modułu, więc odzyskujesz pliki; czy schemat nadal do nich pasuje, oceniasz sam.

### `modsx:snapshotprune`

```bash
php artisan modsx:snapshotprune --keep=5
php artisan modsx:snapshotprune --keep=5 --dry-run
php artisan modsx:snapshotprune --keep=5 --force
```

Snapshoty wstrzymują wersje przed `modsx:prune`, więc ta komenda istnieje po to, żeby móc któryś puścić. Usunięcie snapshotu **nie usuwa żadnych wersji** — przestaje je tylko wstrzymywać, więc następny `modsx:prune` znowu weźmie je pod uwagę.

#### Snapshoty a przycinanie

`modsx:prune` nie skasuje wersji wymienionej w snapshocie — tak jak tag nie pozwala zebrać commita. Bez tego rollback dowiadywałby się o stracie dopiero w chwili, w której ta wersja była mu potrzebna, czyli o wiele za późno. Mówi przy tym wprost, co zostawił i dlaczego:

```
 Shop 0001 ....... kept, held by a snapshot
```

Nie ma flagi, która by to obeszła. `--force` w całym pakiecie znaczy *nie pytaj mnie*, a pozwolenie, by znaczyło również *zignoruj zabezpieczenie*, sprawiłoby, że skryptowy prune po cichu unieważniałby każdy snapshot wymieniający te wersje. Żeby je zwolnić, trzeba puścić snapshot przez `modsx:snapshotprune` — wtedy następny prune znowu je uwzględni.

Snapshot nadal może wskazywać wersję, której już nie ma, jeśli ktoś grzebał w drzewie backupów ręcznie. Zgłasza to `modsx:doctor`, a sprząta `modsx:snapshotprune`.

#### Przykład od początku do końca

Trzy moduły, gdzie `Blog` korzysta z obu pozostałych:

```bash
php artisan modsx:deps
```

```
 INFO  Blog
  Media ......................... found in the code
  User .......................... found in the code

 INFO  Media
  needs nothing else

 INFO  User
  needs nothing else
```

Snapshot przed zabraniem się za coś ryzykownego:

```bash
php artisan modsx:snapshot --comment="przed przepisaniem płatności"
```

```
 Module   Version
 Blog     0001      backed up
 Media    0001      backed up
 User     0001      backed up

 INFO  Snapshot 0001 taken, holding 3 module(s).
```

Trzy tygodnie później `Blog` i `User` poszły dalej. Kolejny snapshot:

```bash
php artisan modsx:snapshot
```

```
 Module   Version
 Blog     0004      backed up
 Media    0001      unchanged
 User     0002      backed up

 INFO  Snapshot 0002 taken, holding 3 module(s).
```

`Media` przez te trzy tygodnie nie była ruszana, więc nie powstała dla niej nowa wersja — tylko kolejne odwołanie do `0001`. To właśnie sprawia, że snapshot jest na tyle tani, by robić go przed każdą ryzykowną rzeczą.

Teraz przepisywanie trzeba porzucić. Przywrócenie samego Bloga nie załatwi sprawy:

```bash
php artisan modsx:restore Blog 0001     # Blog 0001 obok User 0002, którego nigdy nie znał
php artisan modsx:rollback 0001         # cała chwila, taka jaka była
```

```
 Module   Current   Snapshot
 Blog     0004      0001        will move
 Media    0001      0001        already there
 User     0002      0001        will move

 WARN  Anything not in the snapshot is replaced by what was. Files added since are moved aside and not put back.

 INFO  Rolled back to snapshot 0001. 3 module(s) restored.
  The state before this is snapshot ......................................... 0003
```

Snapshot `0003` to droga powrotna, wykonana automatycznie, zanim cokolwiek się ruszyło. Zmiana zdania po raz kolejny:

```bash
php artisan modsx:rollback 0003
```

Jak sprawdzić, gdzie się jest w dowolnym momencie:

```bash
php artisan modsx:status
php artisan modsx:snapshotlist
```

```
 Snapshot   Created                     Scope           Modules   Comment
 0001       2026-09-03T09:14:02+00:00   whole project   3         przed przepisaniem płatności
 0002       2026-09-24T16:30:55+00:00   whole project   3         -
 0003       2026-09-24T16:41:18+00:00   whole project   3         before rolling back to snapshot 0001
```

A gdy drzewo backupów urośnie — najpierw puść stare chwile, dopiero potem przycinaj wersje:

```bash
php artisan modsx:snapshotprune --keep=5 --dry-run   # zobacz, które odejdą
php artisan modsx:snapshotprune --keep=5             # puść je
php artisan modsx:prune --keep=3                     # teraz te wersje są wolne
```

| Czego chcesz | Komenda |
|---|---|
| Tylko ten moduł z powrotem | `modsx:restore Blog 0004` |
| Ten moduł i to, czego wtedy potrzebował | najpierw `modsx:snapshot Blog`, potem `modsx:rollback` |
| Cały projekt taki, jaki był | `modsx:rollback 0001` |
| Najpierw zobaczyć, co się ruszy | `modsx:rollback 0001` i przeczytać tabelę przed odpowiedzią |
| Wiedzieć, co obejmie snapshot | `modsx:deps Blog` |


### `modsx:diff`

```bash
php artisan modsx:diff Blog          # względem najnowszej wersji
php artisan modsx:diff Blog 0003     # względem konkretnej wersji
php artisan modsx:diff               # interaktywnie
php artisan modsx:diff Blog --json
```

Porównuje moduł w aplikacji z wersją w backupie **plik po pliku**, po skrócie zawartości:

- **Dodane** — są teraz w aplikacji, nie było ich w tamtej wersji. Przywrócenie je usunie.
- **Zmienione** — są po obu stronach, ale zawartość się różni. Przywrócenie je nadpisze.
- **Usunięte** — były w tamtej wersji, nie ma ich w aplikacji. Przywrócenie je przywróci.
- **Bez zmian** — identyczne po obu stronach.

Porównywana jest zawartość plików, a nie nazwy katalogów, więc moduł, w którym przepisano wszystkie pliki w miejscu, zostanie pokazany jako zmieniony, a nie jako niezmieniony.

```bash
php artisan modsx:diff Blog --summary   # same liczby, bez listy plików
```

#### Dwie wersje względem siebie

Podaj drugą wersję, a aplikacja całkowicie wypada z porównania — porównywane są obie wersje ze sobą:

```bash
php artisan modsx:diff Blog 0002 0004
```

Pierwsza wersja jest punktem odniesienia, druga tym, z czym ją porównujemy — dokładnie tak, jak się to czyta na głos: *co stało się z Blogiem między `0002` a `0004`*. Trzy grupy zachowują nazwy i zmieniają znaczenie:

- **Dodane** — tylko w `0004`; pojawiły się po `0002`.
- **Zmienione** — są w obu wersjach, ale zawartość się różni.
- **Usunięte** — tylko w `0002`; do `0004` już ich nie ma.

Zamiana argumentów miejscami daje to samo porównanie widziane z drugiej strony: co było dodane, staje się tym, czego brakuje. W żadną stronę nie ma tu przywracania, więc nic nie jest opisane w jego kategoriach.

W tym trybie katalog roboczy nie jest w ogóle czytany, więc odpowiedź jest ta sama niezależnie od tego, w jakim stanie jest w tej chwili aplikacja.

`--summary` i `--json` działają również tutaj. W JSON-ie zamiast `version` pojawiają się `from` i `to`, więc skrypt rozpozna tryb po samym kształcie odpowiedzi.

Warto uruchomić przed `modsx:restore` — pokazuje dokładnie, co się za chwilę straci.

### `modsx:info`

```bash
php artisan modsx:info Blog
php artisan modsx:info --json
```

Pokazuje:

- **Stan bieżący**: czy moduł istnieje w aplikacji, jego katalogi i pliki, jaki zajmuje rozmiar na dysku
- **Historia backupów**: liczbę wersji, łączny rozmiar backupów, tabelę każdej wersji z datą utworzenia, rozmiarem, liczbą zarchiwizowanych migracji i komentarzem (jeśli został podany przy backupie)

Przydatne do zrozumienia zużycia miejsca na dysku i podjęcia decyzji, czy czyścić stare wersje.

### `modsx:doctor`

```bash
php artisan modsx:doctor
php artisan modsx:doctor --json    # kod wyjścia 1, gdy znaleziono problemy — do CI
php artisan modsx:doctor --fix     # usuwa puste katalogi modułu
```

Problemy (kod wyjścia 1):

- **Nazwy modułów różniące się wyłącznie granicami słów**, np. `Userprofile` obok `UserProfile`. Obie nazwy są poprawne, więc nic innego tego nie wyłapie — a to prawie zawsze jeden moduł, który miał być jednym.
- **Drzewa backupów różniące się wyłącznie wielkością liter.** Na Windows i macOS to jeden katalog, więc dwa moduły dzielą sekwencję wersji, a przywracanie może zwrócić nie ten.
- **Wersje backupu bez czytelnego `modsx.json`.**

Informacyjnie (kod wyjścia 0):

- **Migracje, które nazywają moduł, ale nie są z nim archiwizowane** — klasyczne `create_modsx_blog_posts_table` — wraz z nazwą, której potrzebują zamiast tego. Bez tego konwencja po prostu po cichu nic by nie robiła, a Ty nigdy byś się nie dowiedział dlaczego.
- Backupy zrobione, gdy skonfigurowany był inny prefiks.
- Katalogi w drzewie backupów, które nie są wersjami i przez to są pomijane przy listowaniu.
- Moduły istniejące tylko w jednej z dwóch form katalogów.
- Backupy bez odpowiadającego im modułu w aplikacji.
- **Puste katalogi modułu** — pozostawione przez `modsx:scaffold` albo przez ręczne usunięcie ostatniego pliku. `--fix` je usuwa. Sprawdzanie uwzględnia też pliki ukryte, więc katalog trzymany celowo dzięki `.gitkeep` nigdy nie jest ruszany — zgłaszany jest tylko katalog, w którym naprawdę nic nie ma, na żadnej głębokości.
- **Pliki nazywające nieistniejący moduł**, np. `config/modsx-blog-admin.php`, gdy nie ma modułu `BlogAdmin`. Plik nadal działa w aplikacji — po prostu do niczego nie należy i z niczym nie jest backupowany, co warto wiedzieć.
- **Nazwa jednego modułu będąca przedłużeniem drugiej**, np. `BlogPost` obok `Blog`. Układ wspierany, wypisywany po to, żeby reguła dla ich migracji była gdzieś powiedziana wprost: wygrywa dłuższa nazwa.
- **Moduł zapisany jako pochodzący z wersji, której już nie ma**, po jej przycięciu. To nie usterka: zapis nadal zgodnie z prawdą mówi, skąd wzięło się drzewo robocze, a `modsx:status` działa dalej, mierząc względem najnowszej wersji. Skasowanie pliku w pełni to naprawia.
- **Snapshot wskazujący wersję, której już nie ma** — do czego doprowadzić może wyłącznie ręczna zmiana w drzewie backupów, bo `modsx:prune` takie wersje wstrzymuje. Snapshot nadal jest na liście i nadal wygląda na sprawny, podczas gdy jedyna rzecz, do której istnieje — cofnięcie się do niego — jest już niemożliwa. Sprząta to `modsx:snapshotprune`.

---

## Konfiguracja

`config/modsx.php`:

```php
return [

    // Prefiks katalogów. 'modsx' dopasowuje modsx-blog i ModsxBlog.
    'prefix' => env('MODSX_PREFIX', 'modsx'),

    // Gdzie zapisywane są wersjonowane backupy.
    'backup_path' => env('MODSX_BACKUP_PATH', base_path('modsx-backups')),

    // Przeszukiwane są tylko te ścieżki. Krótka lista to właśnie to,
    // co utrzymuje szybkość wykrywania: pełny skan katalogu głównego
    // przechodziłby przez storage/, .git/ i public/build/.
    'scan_paths' => [
        'app', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'tests',
    ],

    // Nazwy katalogów, do których nigdy nie wchodzimy.
    'exclude' => [
        'vendor', 'node_modules', 'storage', 'bootstrap/cache', '.git', '.idea', '.vscode',
    ],

    // Co tworzy modsx:scaffold, gdy sam nie wskażesz katalogów. Oba
    // placeholdery pochodzą z jednej nazwy, którą wpisujesz — i to właśnie
    // zapobiega rozjechaniu się obu form. Opublikowany plik niesie pod spodem
    // dłuższą listę, zakomentowaną — Livewire, serwisy, form requesty,
    // fabryki, seedery, testy, resources/css, resources/js, komponenty —
    // więc odkomentowujesz to, co Twoje moduły faktycznie mają.
    'scaffold' => [
        'app/Http/Controllers/{Studly}',
        'app/Models/{Studly}',
        'resources/views/{kebab}',
    ],

    // Co modsx:make przekazuje każdemu generatorowi. Wartość sama w sobie
    // uruchamia generator o tej nazwie; para uruchamia generator, który
    // wskażesz, z modułem umieszczonym w jednym z katalogów aplikacji.
    'generators' => [
        '*' => '{Studly}/',
        'view' => '{kebab}/',
        'config' => '{kebab}-',
        'migration' => '{snake}_',

        'layout' => ['view', 'layouts/{kebab}/'],
        'page' => ['view', 'pages/{kebab}/'],
        'partial' => ['view', 'partials/{kebab}/'],
    ],

    // 4 daje 0001, 0002, ...
    'version_padding' => 4,

    // Domyślna wartość dla modsx:prune.
    'prune' => ['keep' => 5],

    // Dodatkowe zależności, dla tego, czego czytanie plików modułu nie zobaczy.
    // Dokłada krawędzie do grafu wyprowadzanego przez modsx:deps, nigdy ich nie zastępuje.
    'dependencies' => [
        // 'Blog' => ['Search'],
    ],

];
```

Dwie uwagi:

- Jeśli zmienisz `prefix` po utworzeniu modułów, zmień nazwy istniejących katalogów. Pod starym prefiksem nic nie zostanie znalezione.
- Katalog backupu nigdy nie jest przeszukiwany w poszukiwaniu modułów, gdziekolwiek go ustawisz — także wtedy, gdy leży wewnątrz przeszukiwanej ścieżki.

---

## Ograniczenia

Świadome, ale warto je znać, zanim na tym polegniesz:

- **Bez bazy danych, a więc i bez przywracania migracji.** Przywrócenie starszej wersji nie cofa migracji ani nie rusza danych. *Pliki* migracji są archiwizowane w każdym backupie, żeby dało się odczytać, jak schemat wyglądał wcześniej, ale nigdy nie są przywracane ani usuwane — odłożenie starego pliku przy schemacie, który poszedł do przodu, zostawiłoby repozytorium i bazę w niezgodzie, bez żadnego sygnału. To decyzja, a nie luka do wypełnienia w przyszłości.
- **Migracja pasująca do dwóch modułów trafia do dłuższej nazwy.** `Blog` i `BlogPost` współistnieją bez problemu — pliki nazywają po jednym module — ale `modsx_blog_post_create_comments_table` pasuje do obu i wygrywa dłuższa nazwa. Dla migracji BlogPosta to poprawne; jeśli Blog kiedyś potrzebuje migracji, której nazwa zaczyna się jak nazwa BlogPosta, musi ją nazwać inaczej. To jedyna reguła, której nie odczytasz z samej nazwy pliku.
- **Bez rozwiązywania zależności.** Modsx nie wie, że `Blog` potrzebuje `Users`. Przywrócenie jednego nie przywróci drugiego.
- **Bez integracji z Composerem.** Zewnętrzne pakiety, od których zależy moduł, pozostają problemem Twojego `composer.json`.
- **Backupy to zwykłe kopie katalogów.** Bez kompresji, bez deduplikacji. Duży moduł zbackupowany pięćdziesiąt razy zajmuje pięćdziesiąt kopii — stąd `modsx:prune` i `--skip-unchanged`.
- **Przywracanie jest odwracalne, ale nie atomowe.** Bieżący stan jest odsuwany w całości, zanim wejdzie przywracany, więc awaria w połowie jest automatycznie cofana. Maszyna, która padnie dokładnie w złym momencie, wciąż może zostawić moduł w kawałkach — ale wszystko, co miał, leży w jednym miejscu, a backup sprzed przywracania nadal tam jest.

---

## FAQ

**Czy pakiet jest potrzebny, żeby używać konwencji?**
Nie. O to właśnie chodzi. Prefiksujesz katalogi i wszystko działa. Pakiet instalujesz wtedy, gdy chcesz backupy. `modsx:scaffold` i `modsx:make` to udogodnienia dla tych, którzy już go mają, a nie wymóg — tworzą katalogi i nazwy, które równie dobrze możesz wpisać ręcznie.

**Dlaczego moje migracje nie są archiwizowane?**
Prawie na pewno przez nazwę. Konwencja mówi, że nazwa *po timestampie* zaczyna się od prefiksu modułu: `2026_01_01_000000_modsx_blog_create_posts_table.php`, a nie typowe „czasownik z przodu" `..._create_modsx_blog_posts_table.php`. Uruchom `php artisan modsx:doctor` — znajduje migracje, które wspominają moduł, ale nie są od niego nazwane, i podpowiada, na co je przemianować. `php artisan modsx:make migration Blog/create_posts_table` zapisuje nazwę poprawnie od razu.

**Co się stanie z modułami, jeśli go odinstaluję?**
Nic. To zwykłe katalogi Laravela i nigdy nie były niczym innym. Bez opieki zostaje tylko `modsx-backups/` — a to zwykłe pliki, które możesz zachować albo usunąć.

**Czy gryzie się z `nwidart/laravel-modules`?**
Oba rozwiązują ten sam problem w niekompatybilny sposób, więc używanie obu naraz to zły pomysł. Na dysku się jednak nie pobiją: domyślny katalog backupu to `modsx-backups/` właśnie po to, żeby ominąć drzewo źródeł `Modules/` tamtego pakietu.

**Czy mogę przenieść moduł do innego projektu?**
Tak, na dwa sposoby. Skopiuj `modsx-backups/Blog/` do katalogu backupów w projekcie docelowym i uruchom `php artisan modsx:restore Blog`. Albo, żeby przenieść jeden plik zamiast drzewa katalogów: zrób `modsx:export`, skopiuj `.zip`, zrób tam `modsx:import`, potem przywróć. Przestrzenie nazw przeżywają, bo przeżywa układ katalogów.

**Dlaczego numerowane wersje, a nie znaczniki czasu?**
Są krótkie, poprawnie się sortują i łatwo je wybrać w promptcie. Czas utworzenia jest w manifeście.

**Czy dwa moduły mogą dzielić katalog?**
Nie. Katalog należy do dokładnie jednego modułu — tego, którego nazwę koduje.

**Czy można to uruchamiać na produkcji?**
To narzędzia deweloperskie. Pytają przed zniszczeniem czegokolwiek i odmawiają działania nieinteraktywnie bez `--force`, ale pipeline wdrożeniowy to nie jest miejsce, w którym katalogi modułów powinny się przemieszczać.

---

## Plany

Nic w kolejce. Przywracanie migracji jest świadomie nieobecne, a nie
odłożone na później — patrz [Ograniczenia](#ograniczenia).

---

## Współpraca

Zgłoszenia i pull requesty mile widziane — patrz [CONTRIBUTING.md](CONTRIBUTING.md).

```bash
composer install
composer test
composer lint
composer analyse

# Uruchomienie komendy ręcznie w aplikacji Testbench
composer smoke -- modsx:doctor
```

`composer smoke` przed uruchomieniem przebudowuje wykrywanie pakietów. Zestaw testów rejestruje service providera samodzielnie, więc po jego uruchomieniu zostaje manifest pakietów, w którym Modsx nie występuje — a wtedy `vendor/bin/testbench` w ogóle nie widzi komend.

## Licencja

MIT. Patrz [LICENSE](LICENSE).
