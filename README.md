# MISSEYE — PHP + JSON dla hostingu WWW OVH

System działa na **PHP 8.2+ (64-bit)**. Nie wymaga Node.js, MySQL,
SQLite, Composera ani zewnętrznych bibliotek do działania na hostingu.
Opcjonalna automatyczna wysyłka WhatsApp wymaga rozszerzenia PHP cURL
oraz skonfigurowanego konta Meta Cloud API.

Rezerwowane są **30-minutowe konsultacje**, nie pełne zabiegi:
pn.–pt. 08:00–20:00, sob. 10:00–20:00. Niedziele są do uzgodnienia
przez WhatsApp. Czas Europe/Warsaw, horyzont 90 dni kalendarzowych.

## Struktura

```text
app/                    prywatny kod PHP
config.example.php      wzór konfiguracji, bez sekretów
config.php              prywatna konfiguracja; ignorowana przez Git
data/bookings.json      prywatne rezerwacje; ignorowane przez Git
data/bookings.json.lock stała blokada zapisu
data/rate-limits.json   krótkotrwałe liczniki żądań
www/                    JEDYNY katalog publiczny
scripts/notify.php      opcjonalne zadanie CLI/cron WhatsApp
templates/              pusty wzór JSON do pierwszej instalacji
```

Wszystkie katalogi poza `www` muszą pozostać **poza document root**.
Nie publikuj całego repozytorium jako katalogu WWW.

## Uruchomienie lokalne w Windows

Przy zainstalowanym PHP lub lokalnym przenośnym PHP w `.runtime\php`:

```powershell
.\start.cmd
```

Skrypt uruchamia konfigurację CLI, a następnie serwer testowy:

- strona: http://127.0.0.1:3000/#rezerwacja
- panel: http://127.0.0.1:3000/admin

Klucz panelu to `admin_token` w prywatnym `config.php`. Skrypt generuje
go losowo przy pierwszej instalacji. Jeśli istnieje konfiguracja `.env`
starszej wersji, przenosi z niej klucz panelu, adres strony oraz ustawienia
WhatsApp, nie ujawniając ich w konsoli. Istniejącego `config.php` nie nadpisuje.
Aktualne ustawienia edytuj w `config.php`, nie w dawnym `.env`.
Po zmianie konfiguracji odśwież formularz; PHP odczytuje ją przy każdym żądaniu.

Serwer wbudowany PHP służy wyłącznie do lokalnych testów.

### Migracja wcześniejszych rezerwacji SQLite

Jeśli istnieje stara baza, konfigurator odmówi utworzenia pustego JSON,
aby nie zgubić zapisanych terminów. Zatrzymaj poprzedni serwer. Jednorazowo:

```powershell
.\.runtime\node.exe scripts\migrate-sqlite.mjs
.\start.cmd
```

Do samej migracji potrzebny jest Node 24 z `node:sqlite`; na hostingu
PHP nie jest on potrzebny. Można wskazać dwa argumenty: źródło SQLite
i docelowy JSON. Migrator zachowuje identyfikatory, dane, zgody,
anulowania oraz wyniki powiadomień. Nie nadpisuje istniejącego JSON
ani nie zmienia oryginalnej bazy. Przerwane wysyłki oznacza jako nieznane.
Nie uruchamiaj go podczas przyjmowania rezerwacji na starym serwerze.

## Instalacja na hostingu OVH przez FTPS

Najpierw ustaw w panelu OVH PHP 8.2 lub nowsze (zalecane 8.4) oraz
katalog multisite na `www`. Włącz poprawny certyfikat SSL dla docelowej
domeny, np. `okonabeauty.pl`, przed zbieraniem danych klientów.
Adres techniczny `okonabx.cluster129.hosting.ovh.net` nie zastępuje
certyfikatu dla Twojej domeny.

1. Rozpakuj przygotowaną paczkę. Przez **FTPS** wgraj `app`, `scripts`,
   `templates` oraz `config.example.php` do katalogu domowego konta,
   **obok** `www`. Zawartość katalogu `www` z paczki wgraj do hostowego
   `www`. Musi zostać przesłany także ukryty plik `www/.htaccess`.
2. Skopiuj `config.example.php` do `config.php` obok `www`.
   Ustaw `origin` na dokładny adres HTTPS bez ścieżki, np.
   `https://okonabeauty.pl`, i `admin_token` na losowy klucz o długości
   co najmniej 32 znaków. Możesz użyć klucza wygenerowanego lokalnie
   w `config.php`. Nie przesyłaj klucza w czacie ani w URL.
3. Utwórz prywatny katalog `data` obok `www`. **Tylko przy pierwszej,
   pustej instalacji** skopiuj `templates/bookings.empty.json` do niego
   jako `bookings.json`. Jeśli przenosisz istniejące wizyty, użyj ich
   zmigrowanego JSON zamiast pustego wzoru.
4. Nadaj PHP prawo zapisu do katalogu `data` (tworzenie i zmiana nazw
   plików). Zalecane uprawnienia: katalog `700`, pliki danych i konfiguracja
   `600`, przy tym samym właścicielu plików i procesie PHP. Nie używaj `777`.
5. Otwórz `https://okonabeauty.pl/api/config`. Odpowiedź powinna zawierać
   usługi i daty, nigdy klucz panelu. Sprawdź rezerwację, panel `/admin`,
   anulowanie oraz konflikt dwóch rezerwacji tej samej godziny.
6. Zweryfikuj, że `/config.php`, `/data/bookings.json`, `/app/booking.php`
   i `/.env` zwracają **403 lub 404**, a nie zawartość plików.
7. Przekieruj wariant `www` domeny do wybranego adresu. Formularz
   odrzuca żądania z innym `Origin`. Nie wyłączaj sprawdzania certyfikatów.

Nie wgrywaj `.git`, `.env`, `.runtime`, testów ani starej bazy SQLite
do katalogu publicznego. Nie potrzeba instalacji SQL ani procesu Node.js.
Przy aktualizacjach **nie nadpisuj `config.php` i żadnych plików `data`**.
Paczka nie zawiera prywatnej konfiguracji ani rzeczywistych rezerwacji.

## Jak działa zapis JSON

`data/bookings.json` zawiera dokument `version: 1` i tablicę `bookings`.
Rezerwacje, anulowania, zgody i statusy WhatsApp są trwałe między żądaniami.
Serwer nie przechowuje ich w `localStorage` ani w publicznym kalendarzu.

Każdy odczyt i zapis używa `flock()` na oddzielnym, stałym pliku blokady.
Sprawdzenie dostępności i zapis rezerwacji odbywają się pod jedną blokadą
wyłączną, wspólną dla procesów PHP. Ponowienie tego samego zgłoszenia
nie tworzy drugiej rezerwacji. Zapis trafia najpierw do prywatnego pliku
tymczasowego, jest opróżniany na dysk i atomowo zastępuje poprzedni JSON.
Uszkodzony lub brakujący plik powoduje jawny błąd, nie pusty kalendarz.

Wymagany jest **jeden hosting i lokalny system plików wspierający `flock`
oraz atomową zmianę nazwy**. Przed produkcją potwierdź te właściwości
i test równoległych zapisów w swoim planie OVH. Nie używaj kilku
niezależnych kopii pliku, CDN jako bazy, nietrwałego serverless ani
sieciowego dysku bez takich gwarancji. JSON jest rozwiązaniem dla małego
salonu, nie dla dużego systemu wieloserwerowego.

Nie edytuj rezerwacji ręcznie podczas pracy serwera. Rób prywatne backupy
pod tą samą blokadą lub po wstrzymaniu rezerwacji; testuj ich odtwarzanie.
Anulowanie nie usuwa danych. Ustal i stosuj okres retencji oraz ogranicz
dostęp do backupów. Krótkotrwałe liczniki żądań przechowują skróty adresów
IP, ważne minutę; aplikacja nie ufa nagłówkom `X-Forwarded-For`.

## WhatsApp

Kontakt salonu: **+48 696 293 848**. Link `wa.me` otwiera gotową wiadomość;
klient sam ją wysyła. Zapis w kalendarzu nie zależy od tej wiadomości.

Automatyczne potwierdzenia do klienta są domyślnie **wyłączone**.
Aplikacja WhatsApp Business nie wystarczy — potrzebne jest konto
WhatsApp Business Platform, numer Cloud API i zatwierdzony szablon.
W prywatnym `config.php` ustaw wszystkie pola `whatsapp`:

- `access_token` — token Meta, tylko na serwerze;
- `phone_number_id` — identyfikator numeru nadawcy, nie numer telefonu;
- `graph_version` — aktualna obsługiwana wersja z panelu Meta;
- `template_name` i `template_language` (domyślnie `pl`).

Szablon utility musi mieć cztery tekstowe parametry treści w kolejności:
nazwa konsultacji, data, godzina, numer rezerwacji. Przykład:
„MISSEYE: potwierdzamy rezerwację {{1}} dnia {{2}} o {{3}} (czas polski).
Numer rezerwacji: {{4}}. Zmiany uzgodnisz z salonem.”

Po włączeniu API formularz pokaże dobrowolną zgodę. Bez niej nie wysyła
wiadomości. Potwierdzenie jest wysyłane podczas żądania rezerwacji
(do 10 sekund oczekiwania na Meta), ale błąd wysyłki nie usuwa terminu.
„Przyjęte przez WhatsApp” nie jest potwierdzeniem doręczenia.

Opcjonalny cron OVH może uruchamiać prywatny `scripts/notify.php`,
aby obsłużyć zgłoszenia pozostawione jako `pending` przez przerwane
żądanie. Po ponad dwóch minutach status przerwanej wysyłki `sending`
zmienia się na `unknown` przy kolejnym żądaniu API/cron. Takich wiadomości
nie ponawia się automatycznie, żeby uniknąć duplikatów. Sprawdź je w Meta
i skontaktuj się z klientem ręcznie. Błędy są widoczne w panelu i logach PHP.

System nie ma webhooka doręczeń ani skrzynki przychodzących rozmów.
Właściciel widzi wizyty w panelu i pisze do klientów przez link WhatsApp.
Anulowanie wizyty odblokowuje termin, ale **nie powiadamia klienta**.
Powiadom go osobno. Meta może naliczać opłaty. Prawdziwe doręczenia
wymagają kontrolowanego testu na skonfigurowanym koncie.

## Testy

```powershell
.\.runtime\php\php.exe tests\run.php
```

Przy zainstalowanym PHP wystarczy `php tests\run.php`. Testy uruchamiają
kilka niezależnych procesów PHP, sprawdzają kolizje i utratę zapisów,
idempotencję, trwałość, uszkodzony JSON, godziny/DST, API i panelowe
anulowania. Używają fikcyjnych danych i atrap Meta, bez wysyłki wiadomości.

## Galeria filmów

Sekcja „Efekty” zawiera pięć filmów w `www/media`, z miniaturami WebP.
To zoptymalizowane kopie H.264/AAC, 720 × 1280, z metadanymi MP4 na
początku pliku (`faststart`). Oryginały z dysku E: nie zostały zmienione.
Kopie wraz z miniaturami zajmują około 28,5 MiB zamiast 167 MB oryginałów.

Filmy mają własne przyciski odtwarzania, dźwięku i pełnego ekranu.
Nie startują automatycznie, a `preload="none"` ogranicza pobieranie
przed odtworzeniem. Uruchomienie kolejnego filmu zatrzymuje poprzedni.
Błąd odtwarzania jest widoczny przy filmie, z odnośnikiem do pliku MP4.

Na OVH wgraj także cały `www/media` oraz `www/gallery.js`.
Serwer powinien obsługiwać żądania `Range` dla MP4, aby działało przewijanie.
Filmy i miniatury są publiczne, z godziną cache. Przy podmianie materiału
zmień nazwę pliku i odnośniki, żeby uniknąć wyświetlania starej wersji.
Przed publikacją potwierdź prawa do muzyki i zgody osób widocznych
w materiałach. Jeśli filmy zawierają wypowiedzi, dodaj także ich napisy.

## Przed publicznym uruchomieniem

Uzupełnij pełne dane administratora i informacje RODO w sekcji danych
rezerwacji: kontakt, okres retencji, odbiorcy i transfery danych.
Ustal zasady odwoływania wizyt, przerw i urlopów. System nie uwzględnia
świąt ani nieobecności i nie synchronizuje się z Booksy lub Google Calendar.
Dodaj weryfikację numeru/ochronę antybotową przed fałszywymi rezerwacjami
oraz dodatkową ochronę panelu na hostingu. Formularz nie służy do
zbierania danych zdrowotnych. Nie otwieraj go publicznie przed tymi krokami.

Domena, certyfikat, FTPS i konto Meta wymagają konfiguracji u dostawców.
Utworzenie kodu lub paczki nie oznacza publikacji na OVH.
