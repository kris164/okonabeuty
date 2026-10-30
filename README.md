# MissEye × PitSTOP — PHP + JSON dla hostingu WWW OVH

System działa na **PHP 8.2+ (64-bit)**. Nie wymaga Node.js, MySQL,
SQLite, Composera ani zewnętrznych bibliotek do działania na hostingu.
Opcjonalna automatyczna wysyłka WhatsApp wymaga rozszerzenia PHP cURL
oraz skonfigurowanego konta Meta Cloud API.

Rezerwowane są **30-minutowe konsultacje oraz pełne zabiegi**:
pn.–pt. 08:00–20:00, sob. 10:00–20:00. Niedziele są do uzgodnienia
przez WhatsApp. Czas Europe/Warsaw, horyzont 90 dni kalendarzowych.

## Dwie marki, dwa kalendarze

- **MissEye**: SMP i PM (makijaż permanentny); specjalista `misseye-1`.
- **PitSTOP**: Nails art (dłonie) i podologia; specjalista `pitstop-1`,
  profil Instagram [@do_nails.beauty](https://instagram.com/do_nails.beauty).
- MissEye przyjmuje we Wrześni. PitSTOP domyślnie: **Września lub Poznań —
  lokalizacja ustalana z managerem**. Manager może przypisać dni tygodnia
  do miasta, podać publiczny adres lub wyłączyć przyjęcia w danym dniu.
  Nieuzupełnione miejsca trzeba potwierdzić z managerem przed przyjazdem.
- Oba miasta PitSTOP korzystają z jednego kalendarza tej samej osoby.
  Informacja o lokalizacji jest widoczna przy wyborze usługi, w potwierdzeniu,
  panelu managera i wiadomościach WhatsApp. Nie ma oddzielnych slotów miast.
- Cztery dotychczasowe konsultacje nadal trwają po 30 minut. Dwanaście
  wariantów zabiegów ma własne czasy (tabela poniżej).
- Usługi tej samej osoby blokują jej wspólny kalendarz. Różne osoby mogą
  przyjmować o tej samej godzinie. Sprawdzenie i zapis nadal są jedną
  transakcją pod blokadą pliku.
- Manager korzysta ze wspólnego panelu z filtrem specjalisty oraz jednego
  numeru telefonu i WhatsApp: **+48 665 638 033**.
- Panel pokazuje obie marki; nie jest to system osobnych kont partnerów.
  Automatyczne wiadomości nadal wymagają konfiguracji Meta.

Katalog usług i przypisania osób znajdują się w `app/booking.php`.
API `/api/config` zwraca `services` i `specialists`; specjalistę rezerwacji
wyznacza serwer, nigdy pole przesłane przez klienta.

Istniejący identyfikator `brwi` pozostaje technicznym identyfikatorem PM,
żeby zachować stare rezerwacje i ich ponowienia. Schemat JSON pozostaje
w wersji 1. Rekordy bez `specialist_id` należą do osoby przypisanej do
ich usługi; nowe rekordy zapisują również `specialist_id`. Dotychczasowe
rezerwacje SMP i brwi nadal blokują kalendarz MissEye, nie PitSTOP.
Nie zmieniaj przypisania istniejącego identyfikatora usługi do innej osoby
bez jawnej migracji. Stary kod z jednym kalendarzem nie obsłuży poprawnie
równoległych wizyt — przed aktualizacją wykonaj kopię konfiguracji i danych.

Strona przedstawia obie marki równolegle. Dotychczasowe filmy są oznaczone
jako portfolio MissEye, nie PitSTOP. Portfolio PitSTOP / Nails art zawiera
cztery dostarczone zdjęcia (dwie stylizacje w różnych ujęciach). Kopie WebP
w `www/media/pitstop-nails-*.webp` mają szerokość 768 px i razem około
197 KiB. Oryginały nie zostały zmienione. Zdjęcia ładują się leniwie,
mają opisy alternatywne i otwierają pełne ujęcie w nowej karcie.
Wgraj je na hosting razem z pozostałymi mediami. Nie przedstawiają podologii.
Sekcja „Dla specjalistów” opisuje
**planowany** rozwój ogólnopolski: edukację, pozyskiwanie zapytań, marketing,
szkolenia i sprzedaż. Nie udaje działającego katalogu, sklepu ani zapisów
na kursy. Kontakt biznesowy otwiera wiadomość do managera w WhatsApp;
nie zapisuje formularza partnerskiego w systemie.

## Kalendarz wizyt i zabiegów

| Marka | Wariant | Czas usługi (min) | Czas w kalendarzu (min) |
|---|---|---:|---:|
| Obie | Każda z czterech konsultacji | 30 | 60 |
| PitSTOP | Manicure hybrydowy | 90 | 120 |
| PitSTOP | Przedłużanie artystyczne | 240 | 240 |
| PitSTOP | Laminacja i bieżąca pielęgnacja | 60 | 60 |
| PitSTOP | Stopy — podstawa | 90 | 120 |
| PitSTOP | Stopy — 1 etap | 150 | 180 |
| PitSTOP | Stopy — 2 etap | 220 | 240 |
| PitSTOP | Stopy — 3 etap | 280 | 300 |
| MissEye | Mikropigmentacja skóry głowy — 1 etap | 200 | 240 |
| MissEye | Mikropigmentacja skóry głowy — 2 etap | 200 | 240 |
| MissEye | Mikropigmentacja skóry głowy — 3 etap | 220 | 240 |
| MissEye | Brwi — regulacja i henna | 60 | 60 |
| MissEye | Makijaż permanentny | 220 | 240 |

Etapy są osobnymi wizytami, a nie automatycznie rezerwowanym pakietem.
Bez wpisanych kwot system pokazuje „Cena do ustalenia” i nie pobiera opłat.
Starty pozostają co 30 minut. Czas zajęty w kalendarzu to czas usługi
zaokrąglony w górę do wielokrotności 60 minut. Zaokrąglamy **czas trwania**,
nie godzinę zakończenia: manicure 90 min od 10:30 blokuje 10:30–12:30;
zabieg 220 min od 10:00 blokuje 10:00–14:00. Konsultacja 30 min zajmuje
60 min. Cała blokada musi zmieścić się przed 20:00; ostatnia konsultacja
zaczyna się o 19:00. Klient i manager widzą oddzielnie oba czasy.
Nie dodajemy kolejnej automatycznej przerwy; ręczne blokady przerw i urlopów
zachowują dokładne godziny, bez zaokrąglania.

Prezentacje specjalistek i ich portfolio tworzą dwie kolumny: **PitSTOP
po lewej, MissEye po prawej**. Na mniejszych ekranach układają się pionowo,
z PitSTOP jako pierwszym. Zieleń i kreskowy paznokieć oznaczają PitSTOP,
miedź i kreskowe oko — MissEye, również w panelu managera. W kalendarzu
kolejność obowiązuje w każdym dniu, a wysokość wpisu pokazuje zajęty czas.

### Dla klienta

- Wybór konsultacji lub zabiegu z czasem i przypisaną osobą.
- Pasek siedmiu dni z liczbą możliwych startów i nawigacją o tydzień.
- „Najbliższy wolny termin” szuka od wybranej daty do końca horyzontu
  90 dni i zaznacza pierwszą dostępną godzinę. Liczba startów nie oznacza
  liczby klientów, których można przyjąć — długie usługi zajmują kilka slotów.
- Brak publicznego wglądu w imiona, telefony i powody niedostępności.

### Dla managera

Panel `/admin` pokazuje dzień lub tydzień (poniedziałek–niedziela)
z osią czasu 08:00–20:00 i osobną kolumną każdej osoby. Widok tygodnia
na małym ekranie przewija się wewnątrz kalendarza. Kolor oznacza osobę,
szary wpis oznacza blokadę. Kliknięcie wpisu otwiera szczegóły poniżej.

Manager może:

- dodać wizytę ręcznie, na tych samych zasadach godzin i kolizji co klient;
- przełożyć przyszłą wizytę bez zmiany usługi i jej utrwalonego czasu;
- anulować wizytę;
- dodać i usunąć blokadę przerwy, urlopu lub innej niedostępności,
  również obejmującą kilka dni.

Blokada nie anuluje istniejących wizyt. Kolizja zatrzymuje jej zapis.
Przekładanie atomowo zwalnia stary termin i zajmuje nowy; niepowodzenie
pozostawia starą wizytę bez zmian. Numer wersji chroni przed nadpisaniem
zmian z drugiej karty panelu. Ponowienie tego samego zapisu nie tworzy
drugiej wizyty lub blokady. Niepewny wynik sieciowy blokuje edycję formularza
i pozwala powtórzyć identyczne zgłoszenie.

**Zmianę lub odwołanie terminu manager przekazuje klientowi osobno.**
Nie jest wysyłane automatyczne nowe potwierdzenie. Status WhatsApp po
przełożeniu dotyczy pierwotnego terminu. Wizyty z wysyłką `pending` lub
`sending` trzeba odświeżyć po zakończeniu obsługi potwierdzenia, zanim
zostaną przełożone.

Nowe rezerwacje utrwalają `minutes` (rzeczywisty czas usługi),
`reserved_minutes` (zaokrąglona blokada) i `revision`. API katalogu
i potwierdzenia podaje blokadę jako `reservedMinutes`; panel kalendarza
zwraca również `reserved_minutes`. Przekładanie zachowuje oba utrwalone czasy.
Starsze wpisy bez `reserved_minutes` zachowują dotychczasowe końce do
wykonania kontrolowanej migracji poniżej. Opcjonalna lista `blocks` znajduje się **w tym samym prywatnym JSON**
co wizyty, pod tą samą blokadą i atomowym zapisem. Nie jest wymagane
kasowanie, ponowna inicjalizacja ani przepisywanie istniejących rezerwacji.
Po zapisaniu nowych zabiegów lub blokad nie wracaj do starego kodu,
który nie rozumie tych wpisów. Przed aktualizacją zrób kopię danych.

### Aktualizacja wcześniejszych przyszłych wizyt

Po wgraniu nowego kodu na serwer, przed udostępnieniem zapisów uruchom:

```sh
php scripts/round-calendar.php --dry-run
php scripts/round-calendar.php --apply
```

W Windows można użyć lokalnego `.runtime\php\php.exe` zamiast `php`.
Skrypt korzysta z tej samej konfiguracji i blokady JSON co API. Sprawdza
przyszłe, potwierdzone wizyty względem innych wizyt, ręcznych blokad
i zamknięcia o 20:00. Raport nie zawiera nazwisk ani telefonów.
Przy konflikcie zwraca kod 2 i **nie zmienia żadnej wizyty**; najpierw
rozwiąż kolizje z klientami. Przy bezpiecznym zastosowaniu zapisuje prywatną
kopię poprzedniego dokumentu obok pliku danych (`.before-hour-rounding-*.json`),
a dopiero potem atomowo aktualizuje całość. Zrób również niezależny backup.
Nie zmienia startów, identyfikatorów ani rzeczywistego czasu usług.
Przeszłe, rozpoczęte i anulowane wizyty pozostają nietknięte.
Wersja zmienionej wizyty rośnie, aby odrzucić nieaktualne edycje panelu,
ale sama migracja nie oznacza przełożenia terminu. Kolejne uruchomienie
nic nie zmienia. Migracja nie wysyła wiadomości — w razie potrzeby manager
przekazuje klientowi informację osobno.

### Cennik i dni przyjęć — panel managera

Sekcja „Cennik i dni przyjęć PitSTOP — edycja” pozwala podać dla każdej
usługi kwotę brutto PLN i rodzaj „stała” albo „od”. Puste pole nie oznacza
zera: pozostaje „Cena do ustalenia”. Zero oznacza jawnie 0,00 zł.
Kwoty są przechowywane jako całkowite grosze, nie liczby zmiennoprzecinkowe.
Przy cenie „od” końcową wycenę ustala manager; nie ma płatności online.

Dla każdego dnia wybierz Wrześnię, Poznań, ustalenie z managerem albo
brak przyjęć. Jeden dzień oznacza jedno miasto dla całego kalendarza PitSTOP.
Adresy miast są opcjonalne i publiczne. Nie zmienia to dotychczasowych
godzin pracy ani braku niedzielnych zapisów online. Czas na dojazdy
wprowadzaj jako ręczne blokady.

Ustawienia `offer` znajdują się w tym samym prywatnym JSON co rezerwacje.
Domyślna oferta działa także bez tego pola — nie trzeba przepisywać danych.
Wersja oferty chroni przed nadpisaniem zmian z drugiej karty; niepewny zapis
można powtórzyć bez podwójnej zmiany. Przyszłe, potwierdzone wizyty PitSTOP
blokują zmianę miejsca/adresu lub zamknięcie ich dnia. Najpierw uzgodnij
zmianę z klientem i przełóż wizytę; system nie przenosi nikogo automatycznie.
Nowe wizyty utrwalają cenę i miejsce. Późniejsze zmiany cennika nie
przepisują wyceny wcześniejszej wizyty. Klient widzi aktualną cenę
i miejsce wybranej daty przed zapisem oraz w potwierdzeniu.

### Przypomnienia e-mail — przygotowane, domyślnie wyłączone

Nie wymagają Meta. Wysyłka korzysta z funkcji PHP `mail()` i transportu
pocztowego hostingu, nie z dowolnego zewnętrznego SMTP. Lokalny Windows
nie ma automatycznie skonfigurowanej poczty. Nie włączaj tej opcji przed
sprawdzeniem poczty i harmonogramu w swoim pakiecie OVH.

W prywatnym `config.php` dodaj:

```php
'email' => [
    'enabled' => false,
    'from' => 'rezerwacje@twoja-domena.pl',
    'reply_to' => 'kontakt@twoja-domena.pl',
],
```

Wpisz własne zweryfikowane adresy, skonfiguruj SPF/DKIM i sprawdź dostarczenie
wiadomości testowej, również folder spam. Zaplanuj w harmonogramie hostingu
uruchamianie `php /pelna/prywatna/sciezka/scripts/send-reminders.php`
co około 15 minut (lub z częstotliwością dostępną w planie).
Nie wystawiaj zadania jako publicznego URL. Dopiero po sprawdzeniu transportu
i zadania zmień `enabled` na `true`. Dotychczasowy config bez sekcji `email`
nadal działa z wysyłką wyłączoną.

Formularz pokaże osobną, niezaznaczoną zgodę i adres wymagany tylko po jej
wybraniu. Bez włączonej integracji nie zbiera adresów. Manager może zebrać
tę samą zgodę podczas ręcznego zapisu. Stare wizyty nie otrzymują zgody
automatycznie. Przypomnienie jest organizacyjne, bez marketingu i bez
informacji o rodzaju zabiegu w treści.

Zadanie wybiera wyłącznie potwierdzone, przyszłe wizyty z osobną zgodą,
których start jest w ciągu najbliższych 24 godzin. Wizyta zapisana mniej
niż dobę wcześniej kwalifikuje się przy kolejnym uruchomieniu zadania.
Jeżeli zadanie nie działa przed startem, spóźniona wiadomość nie jest wysyłana.
Atomowe zajęcie zgłoszenia zapobiega duplikatom przy równoległych zadaniach.
`accepted` oznacza przyjęcie przez serwer poczty, **nie doręczenie**.
`failed` i `unknown` są jawne w panelu i logach; nie ponawiamy ich
automatycznie. Przerwane `sending` po ponad 5 minutach przechodzi
przy następnym zadaniu lub żądaniu API na `unknown`. Kod wyjścia zadania: 0 — brak błędów,
1 — wyłączona konfiguracja, 2 — odrzucenie lub nieznany wynik.

Przełożenie resetuje przypomnienie dla nowego startu, a anulowana wizyta
nie kwalifikuje się do wysyłki. Podczas `sending` przełożenie, anulowanie
i wycofanie zgody są czasowo blokowane, aby nie wysłać wiadomości ze
zmienionym terminem. W panelu można wycofać zgodę i usunąć adres e-mail
bez anulowania wizyty. Wiadomości już wysłanej nie można cofnąć.
Przed publikacją uzupełnij informacje o administratorze, retencji i dostawcy
poczty. Nie uruchomiono rzeczywistej wysyłki ani harmonogramu na OVH.

### Inspiracje i dalsze etapy

Porównano oficjalne opisy funkcji, nie skopiowano interfejsów ani
nie utworzono integracji z tymi usługami:

- [Booksy](https://biz.booksy.com/pl-pl/funkcje): warianty usług z czasem,
  kalendarz i przerwy techniczne.
- [Fresha](https://www.fresha.com/for-business): kalendarz zespołu,
  zarządzanie lokalizacjami i ochrona przed nieobecnościami przez płatności.
- [SimplyBook.me](https://simplybook.me/en/booking-system-features):
  grafiki usługodawców, wiele lokalizacji i przekładanie rezerwacji.
- [Reservio](https://www.reservio.com/features): rezerwacje, obsługa zespołu,
  przypomnienia i płatności.

Wybrane kolejne etapy, **jeszcze niewdrożone**: bezpieczne samodzielne
odwołanie i przełożenie przez klienta (wymaga odrębnego uwierzytelnienia,
nie samego publicznego UUID), przypomnienia WhatsApp/SMS, zaliczki
i płatności oraz dwukierunkowa synchronizacja kalendarzy. Wymagają
ustalenia zasad odwoływania, dostawców, zgód i obsługi konfliktów.
Obecna wysyłka WhatsApp to wyłącznie opcjonalne pierwsze potwierdzenie,
nie przypomnienia. Rezerwacje nadal nie blokują Booksy ani Google Calendar.

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
scripts/send-reminders.php prywatne zadanie CLI/cron przypomnień e-mail
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
   anulowanie oraz konflikt dwóch usług tej samej osoby o jednej godzinie.
   Sprawdź również, że MissEye i PitSTOP mogą zarezerwować tę godzinę
   niezależnie i że anulowanie wizyty jednej osoby nie zwalnia drugiej.
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

Kontakt managera: **+48 665 638 033**. Numer jest wyróżniony w sekcji kontaktu
i przy rezerwacji; kliknięcie numeru uruchamia połączenie. Link `wa.me` otwiera gotową wiadomość;
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
marka i nazwa usługi z informacją o miejscu, data, godzina, numer rezerwacji. Przykład:
„Potwierdzamy rezerwację {{1}} dnia {{2}} o {{3}} (czas polski).
Numer rezerwacji: {{4}}. Zmiany uzgodnisz z managerem.”
Pierwszy parametr zawiera np. „PitSTOP — Konsultacja podologiczna — stopy
(Września lub Poznań — lokalizacja ustalana z managerem)”.
Przed włączeniem wysyłki upewnij się, że zatwierdzony szablon i profil
nadawcy są odpowiednie dla obu marek; nie używaj dla PitSTOP szablonu,
który na stałe opisuje usługę jako wizytę wyłącznie w MissEye.

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
