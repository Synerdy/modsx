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

| | |
|---|---|
| PHP | 8.3+ |
| Laravel | 12.x, 13.x |

---

## Instalacja

```bash
composer require synerdy/modsx
```

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
| Katalogi przestrzeni nazw w `app/`, `database/` | `Modsx` + StudlyCase | `ModsxBlog` | `ModsxUserProfile` |
| Pojedyncze pliki — `routes/`, `config/`, `lang/` | `modsx-` + kebab-case | `modsx-blog.php` | `modsx-user-profile.php` |
| Nazwy migracji, po timestampie | `modsx_` + snake_case | `modsx_blog_…` | `modsx_user_profile_…` |

Dwie pierwsze to konwencja samego Laravela, a nie wymysł tego pakietu — framework mapuje `App\View\Components\UserProfile` na `<x-user-profile>` dokładnie tą samą konwersją StudlyCase ↔ kebab-case. Jeśli Twoje katalogi już trzymają się nazewnictwa Laravela, to tym samym trzymają się i tego.

> **Każda forma musi pochodzić od tej samej nazwy.**
>
> `modsx-userprofile` i `ModsxUserProfile` to **dwa różne moduły**: pierwszy to `Userprofile`, drugi `UserProfile`. Zrobisz backup `UserProfile` i widoki z `modsx-userprofile` zostaną po cichu pominięte.
>
> Najpierw zapisz nazwę w StudlyCase, potem konwertuj: `UserProfile` → `user-profile`, nigdy `userprofile`. Albo pozwól, żeby `php artisan modsx:scaffold UserProfile` i `php artisan modsx:make` zapisały je za Ciebie — to jedyny sposób, żeby mieć pewność, że się zgadzają. Jeśli podejrzewasz, że gdzieś już Ci się to przydarzyło, `php artisan modsx:doctor` to znajdzie.

**Prefiks należy do jednego modułu, w całości.** Jeśli istnieje `Blog`, to `modsx-blog-admin.php` i `modsx_blog_posts_table` są jego — ale drugi moduł nazwany `BlogPost` rościłby sobie wtedy prawo do nazw, które już czytają się jako należące do `Blog`. To konflikt nazewniczy, który zgłasza `modsx:doctor`. Jeden moduł, jeden prefiks.

Sam prefiks `modsx` jest konfigurowalny, więc jeśli wolisz `mod-` albo inicjały firmy, zmieniasz go raz i obowiązują te same reguły.

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
database/migrations/2026_01_01_000000_modsx_blog_posts_table.php
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
| `modsx:scaffold {name}` | Tworzy szkielet katalogów nowego modułu |
| `modsx:list` | Moduły obecne w aplikacji |
| `modsx:path {name?}` | Wszystko, co należy do modułu |
| `modsx:backup {name?}` | Kopiuje moduł do nowej numerowanej wersji |
| `modsx:backuplist {name?}` | Dostępne wersje w backupie |
| `modsx:export {name?} {version?}` | Pakuje wersję backupu do przenośnego .zip |
| `modsx:import {path}` | Rozpakowuje .zip stworzony przez `modsx:export` |
| `modsx:delete {name?}` | Robi backup, po czym usuwa moduł |
| `modsx:restore {name?} {version?}` | Backupuje stan bieżący, po czym przywraca wersję |
| `modsx:diff {name?} {version?}` | Porównanie stanu bieżącego z wersją w backupie |
| `modsx:info {name?}` | Rozmiar, liczba plików i historia backupów |
| `modsx:prune {name?}` | Usuwa stare wersje, zostawiając najnowsze |
| `modsx:doctor` | Szuka problemów z nazwami i osieroconych backupów |

### `modsx:make`

Uruchamia jeden z generatorów Laravela, wpisując moduł za Ciebie.

```bash
php artisan modsx:make controller Blog/PostController
php artisan modsx:make view Blog/index
php artisan modsx:make migration Blog/create_posts_table
```

To jest `php artisan make:*` z wyciętym prefiksem. Generator jest Laravela, opcje są Laravela, wyjście jest Laravela — Modsx ustala wyłącznie nazwę:

| Wpisujesz | Uruchamia się |
|---|---|
| `modsx:make controller Blog/PostController` | `make:controller ModsxBlog/PostController` |
| `modsx:make view Blog/index` | `make:view modsx-blog/index` |
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

Wszystko, czego nie ma na liście, dostaje `*` — co jest poprawne dla każdej klasy PHP. Dostępne generatory to te zarejestrowane w Twojej aplikacji, a nie sztywna lista, więc dopisanie wpisu sprawia, że konwencję zaczyna respektować też generator z innego pakietu (`make:livewire`, `make:filament-resource`).

**Opcje dla generatora idą po `--`** i są przekazywane bez zmian:

```bash
php artisan modsx:make controller Blog/PostController -- --resource --model=Post
php artisan modsx:make model Blog/Post -- -mfs
```

**Używaj `/`, nie `\`.** Obie formy są przyjmowane, ale powłoka POSIX usuwa backslash bez cudzysłowu, zanim Modsx go w ogóle zobaczy — `Blog\PostController` dociera jako `BlogPostController`. PowerShell używa jako escape'a backticka, więc tam backslash przeżywa. `/` działa w każdej powłoce.

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

Które katalogi powstaną, zależy od Ciebie — `config/modsx.php`:

```php
'scaffold' => [
    'app/Http/Controllers/{Studly}',
    'app/Models/{Studly}',
    'resources/views/{kebab}',
],
```

`{Studly}` staje się `ModsxBlog`, `{kebab}` staje się `modsx-blog`. Oba z tej jednej nazwy, którą wpisałeś.

Tworzy katalogi i nic poza tym — żadnych stubów kontrolerów, żadnego boilerplate'u. Generowanie kodu uczyniłoby z tego generator, czyli dokładnie to, czym Modsx nie jest. Niczego też nie nadpisuje: istniejące katalogi są raportowane i zostawiane w spokoju, więc komendę można bezpiecznie uruchomić ponownie.

Pamiętaj, że git nie śledzi pustych katalogów, więc szkielet, którego nie wypełnisz, po cichu zniknie przy następnym commicie. Tak ma być: katalogi, których faktycznie używasz, będą miały w sobie pliki.

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

Moduł pojawia się na liście, jeśli istnieje **którykolwiek** z jego katalogów.

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
 Version   Created                     Directories   Comment
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
```

Kolejność działań:

1. Backup bieżącego stanu modułu, żeby samo przywracanie też dało się cofnąć.
2. Skopiowanie wybranej wersji z backupu do katalogu tymczasowego.
3. Odsunięcie całego bieżącego stanu na bok, jednym przebiegiem.
4. Przeniesienie przywracanego stanu na miejsce.

Wszystko jest wyciągane z backupu **zanim** cokolwiek zostanie ruszone w aplikacji, więc uszkodzony lub niekompletny backup ujawnia się, gdy bieżący stan jest jeszcze nienaruszony. A ponieważ krok 3 odsuwa stary stan w całości, zamiast kasować ścieżkę po ścieżce, awaria w kroku 4 jest cofana: dostajesz z powrotem dokładnie to, co miałeś, a nie mieszankę starego z nowym.

Wszystko, czego dana wersja nie zawierała, po przywróceniu znika — zostało odsunięte i nie wróciło. Tak właśnie musi działać „przywróć dokładnie ten stan", a `modsx:diff` powie Ci z wyprzedzeniem, czego to dotyczy.

Zarchiwizowane migracje nigdy nie są przywracane. Na tym etapie w ogóle nie są czytane.

Jeśli modułu nie ma aktualnie w aplikacji, kroki 1 i 3 są pomijane i staje się to **instalacją z backupu** — i tak właśnie przenosi się moduł między projektami: kopiujesz `modsx-backups/Blog/` i przywracasz.

### `modsx:prune`

```bash
php artisan modsx:prune                          # wszystkie moduły, domyślna wartość z configu
php artisan modsx:prune Blog --keep=5
php artisan modsx:prune --keep=3 --dry-run       # pokazuje plan, nic nie zmienia
php artisan modsx:prune --dry-run --json         # plan w formacie JSON, do CI
```

Wypisuje dokładnie, które wersje znikną, i dopiero pyta. Najnowsza wersja nie jest usuwana nigdy, niezależnie od `--keep`.

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
```

Problemy (kod wyjścia 1):

- **Nazwy modułów różniące się wyłącznie granicami słów**, np. `Userprofile` obok `UserProfile`. Obie nazwy są poprawne, więc nic innego tego nie wyłapie — a to prawie zawsze jeden moduł, który miał być jednym.
- **Drzewa backupów różniące się wyłącznie wielkością liter.** Na Windows i macOS to jeden katalog, więc dwa moduły dzielą sekwencję wersji, a przywracanie może zwrócić nie ten.
- **Jeden moduł mieszczący się w prefiksie drugiego**, np. `BlogPost` obok `Blog`. Migracja nazwana od któregokolwiek z nich czyta się wtedy jako należąca do obu, a nic tu nie zgaduje.
- **Wersje backupu bez czytelnego `modsx.json`.**

Informacyjnie (kod wyjścia 0):

- **Migracje, które nazywają moduł, ale nie są z nim archiwizowane** — klasyczne `create_modsx_blog_posts_table` — wraz z nazwą, której potrzebują zamiast tego. Bez tego konwencja po prostu po cichu nic by nie robiła, a Ty nigdy byś się nie dowiedział dlaczego.
- Backupy zrobione, gdy skonfigurowany był inny prefiks.
- Katalogi w drzewie backupów, które nie są wersjami i przez to są pomijane przy listowaniu.
- Moduły istniejące tylko w jednej z dwóch form katalogów.
- Backupy bez odpowiadającego im modułu w aplikacji.

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

    // Co tworzy modsx:scaffold. Oba placeholdery pochodzą z jednej nazwy,
    // którą wpisujesz — i to właśnie zapobiega rozjechaniu się obu form.
    'scaffold' => [
        'app/Http/Controllers/{Studly}',
        'app/Models/{Studly}',
        'resources/views/{kebab}',
    ],

    // Jak modsx:make wpisuje moduł w nazwę przekazywaną generatorowi
    // Laravela. '*' to reguła dla wszystkiego, czego nie ma na liście.
    'generators' => [
        '*' => '{Studly}/',
        'view' => '{kebab}/',
        'config' => '{kebab}-',
        'migration' => '{snake}_',
    ],

    // 4 daje 0001, 0002, ...
    'version_padding' => 4,

    // Domyślna wartość dla modsx:prune.
    'prune' => ['keep' => 5],

];
```

Dwie uwagi:

- Jeśli zmienisz `prefix` po utworzeniu modułów, zmień nazwy istniejących katalogów. Pod starym prefiksem nic nie zostanie znalezione.
- Katalog backupu nigdy nie jest przeszukiwany w poszukiwaniu modułów, gdziekolwiek go ustawisz — także wtedy, gdy leży wewnątrz przeszukiwanej ścieżki.

---

## Ograniczenia

Świadome, ale warto je znać, zanim na tym polegniesz:

- **Bez bazy danych, a więc i bez przywracania migracji.** Przywrócenie starszej wersji nie cofa migracji ani nie rusza danych. *Pliki* migracji są archiwizowane w każdym backupie, żeby dało się odczytać, jak schemat wyglądał wcześniej, ale nigdy nie są przywracane ani usuwane — odłożenie starego pliku przy schemacie, który poszedł do przodu, zostawiłoby repozytorium i bazę w niezgodzie, bez żadnego sygnału. To decyzja, a nie luka do wypełnienia w przyszłości.
- **Jeden moduł ma swój prefiks na wyłączność.** `Blog` i `BlogPost` nie mogą współistnieć, bo `modsx_blog_post_*` czyta się jako należące do obu. `modsx:doctor` zgłasza konflikt, zamiast zgadywać.
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
```

## Licencja

MIT. Patrz [LICENSE](LICENSE).
