# Modsx — moduły dla Laravela oparte na konwencji

[![Latest Version](https://img.shields.io/packagist/v/synerdy/modsx.svg)](https://packagist.org/packages/synerdy/modsx)
[![Tests](https://github.com/Synerdy/modsx/actions/workflows/tests.yml/badge.svg)](https://github.com/Synerdy/modsx/actions)
[![License](https://img.shields.io/packagist/l/synerdy/modsx.svg)](LICENSE)

Podziel aplikację Laravela na moduły przy pomocy samej konwencji nazewnictwa katalogów — a potem rób ich backupy, wersjonuj je i przywracaj z linii poleceń.

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

Z tej nazwy wyprowadzane są dwie formy katalogów — Modsx dopasowuje **obie**:

| Gdzie | Forma | `Blog` | `UserProfile` |
|---|---|---|---|
| Katalogi w `resources/`, `public/`, `lang/` | `modsx-` + kebab-case | `modsx-blog` | `modsx-user-profile` |
| Katalogi przestrzeni nazw w `app/`, `database/` | `Modsx` + StudlyCase | `ModsxBlog` | `ModsxUserProfile` |

To konwencja samego Laravela, a nie wymysł tego pakietu — framework mapuje `App\View\Components\UserProfile` na `<x-user-profile>` dokładnie tą samą konwersją StudlyCase ↔ kebab-case. Jeśli Twoje katalogi już trzymają się nazewnictwa Laravela, to tym samym trzymają się i tego.

> **Obie formy muszą pochodzić od tej samej nazwy.**
>
> `modsx-userprofile` i `ModsxUserProfile` to **dwa różne moduły**: pierwszy to `Userprofile`, drugi `UserProfile`. Zrobisz backup `UserProfile` i widoki z `modsx-userprofile` zostaną po cichu pominięte.
>
> Najpierw zapisz nazwę w StudlyCase, potem konwertuj: `UserProfile` → `user-profile`, nigdy `userprofile`. Jeśli podejrzewasz, że gdzieś już Ci się to przydarzyło, `php artisan modsx:doctor` to znajdzie.

Sam prefiks `modsx` jest konfigurowalny, więc jeśli wolisz `mod-` albo inicjały firmy, zmieniasz go raz i obowiązują te same reguły.

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
```

Żaden z tych katalogów nie jest obowiązkowy. Modułem może być pojedynczy katalog widoków.

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
| `modsx:list` | Moduły obecne w aplikacji |
| `modsx:path {name?}` | Katalogi należące do modułu |
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

### `modsx:list`

```bash
php artisan modsx:list
php artisan modsx:list --json
```

```
 Module        Directories   Backups   Latest
 Blog          4             3         0003
 UserProfile   2             -         -
```

Moduł pojawia się na liście, jeśli istnieje **którykolwiek** z jego katalogów.

### `modsx:path`

Pokazuje, które dokładnie katalogi Modsx uznaje za część modułu — czyli dokładnie to, co skopiuje backup. Warto uruchomić przed pierwszym `modsx:delete`.

```bash
php artisan modsx:path Blog
php artisan modsx:path            # wszystkie moduły
php artisan modsx:path --json
```

### `modsx:backup`

Kopiuje każdy katalog należący do modułu do nowej, kolejnej wersji.

```bash
php artisan modsx:backup Blog
php artisan modsx:backup Blog -m "przed przejściem na repository pattern"
```

```
modsx-backups/
└── Blog/
    ├── 0001/
    │   ├── modsx.json
    │   ├── app/Http/Controllers/ModsxBlog/
    │   └── resources/views/modsx-blog/
    └── 0002/
        └── ...
```

Numer wersji bierze się z najwyższego istniejącego numeru, a nie z tego, co system plików wypisze jako ostatnie, a komenda odmawia zapisu do ścieżki, która już istnieje. Wersje nigdy nie są nadpisywane ani używane ponownie.

`-m`/`--comment` dopina do wersji opcjonalną, dowolną notatkę tekstową — całkowicie opt-in, nie ma o to promptu. Widać ją w `modsx:backuplist` i `modsx:info`.

Każda wersja ma manifest `modsx.json` z nazwą modułu, czasem utworzenia, dokładną listą ścieżek źródłowych, opcjonalnym komentarzem oraz wersjami PHP, Laravela i pakietu. Przywracanie go czyta, dzięki czemu odkłada katalogi tam, skąd zostały wzięte, zamiast zgadywać ich położenie.

Całość kopiowana jest najpierw do katalogu tymczasowego i dopiero na końcu przenoszona na miejsce, więc przerwany backup nie zostawia po sobie wersji zapisanej w połowie.

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
```

Katalogi do usunięcia są wypisywane przed pytaniem o potwierdzenie, a numer utworzonej wersji jest drukowany — więc zawsze wiesz, co podać do `modsx:restore`.

### `modsx:restore`

```bash
php artisan modsx:restore Blog          # najnowsza wersja
php artisan modsx:restore Blog 0003     # konkretna wersja
php artisan modsx:restore               # interaktywnie
```

Kolejność działań:

1. Backup bieżącego stanu modułu, żeby samo przywracanie też dało się cofnąć.
2. Usunięcie bieżących katalogów.
3. Skopiowanie wybranej wersji z powrotem do aplikacji.

Wszystko jest wyciągane z backupu **zanim** cokolwiek zostanie ruszone w aplikacji, więc uszkodzony lub niekompletny backup ujawnia się, gdy bieżący stan jest jeszcze nienaruszony.

Jeśli modułu nie ma aktualnie w aplikacji, kroki 1 i 2 są pomijane i staje się to **instalacją z backupu** — i tak właśnie przenosi się moduł między projektami: kopiujesz `modsx-backups/Blog/` i przywracasz.

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

- **Stan bieżący**: czy moduł istnieje w aplikacji, ile ma katalogów i plików, jaki zajmuje rozmiar na dysku
- **Historia backupów**: liczbę wersji, łączny rozmiar backupów, tabelę każdej wersji z datą utworzenia, rozmiarem i komentarzem (jeśli został podany przy backupie)

Przydatne do zrozumienia zużycia miejsca na dysku i podjęcia decyzji, czy czyścić stare wersje.

### `modsx:doctor`

```bash
php artisan modsx:doctor
php artisan modsx:doctor --json    # kod wyjścia 1, gdy znaleziono problemy — do CI
```

Zgłasza:

- **Moduły, których nazwy różnią się wyłącznie granicami słów**, np. `Userprofile` obok `UserProfile`. Obie nazwy są poprawne, więc nic innego tego nie wyłapie — a to prawie zawsze jeden moduł, który miał być jednym modułem, i zostanie zbackupowany jako dwa.
- Moduły istniejące tylko w jednej z dwóch form katalogów (informacyjnie).
- Backupy bez odpowiadającego im modułu w aplikacji (informacyjnie).

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

- **Tylko katalogi.** Pojedyncze pliki należące do modułu — `routes/modsx-blog.php`, `config/modsx-blog.php`, pliki migracji, `lang/pl/modsx-blog.php` — **nie** są backupowane, usuwane ani przywracane. Trzymaj kod modułu w katalogach albo zajmij się tymi plikami sam.
- **Bez bazy danych.** Przywrócenie starszej wersji nie cofa migracji ani nie rusza danych. Jeśli zmiana wersji oznacza zmianę schematu, to już Twoja część roboty.
- **Bez rozwiązywania zależności.** Modsx nie wie, że `Blog` potrzebuje `Users`. Przywrócenie jednego nie przywróci drugiego.
- **Bez integracji z Composerem.** Zewnętrzne pakiety, od których zależy moduł, pozostają problemem Twojego `composer.json`.
- **Backupy to zwykłe kopie katalogów.** Bez kompresji, bez deduplikacji. Duży moduł zbackupowany pięćdziesiąt razy zajmuje pięćdziesiąt kopii — stąd `modsx:prune`.
- **Przywracanie nie jest w pełni atomowe.** Każdy katalog przenoszony jest osobno. Okno jest małe, a backup sprzed przywracania jest drogą ratunkową, ale maszyna, która padnie w połowie, zostawi część katalogów zaktualizowanych, a część nie.

---

## FAQ

**Czy pakiet jest potrzebny, żeby używać konwencji?**
Nie. O to właśnie chodzi. Prefiksujesz katalogi i wszystko działa. Pakiet instalujesz wtedy, gdy chcesz backupy.

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

- [ ] Opcjonalny backup pojedynczych plików modułu

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
