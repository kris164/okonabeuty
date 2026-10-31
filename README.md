# MissEye × PitSTOP — PHP + MySQL 8.4 dla hostingu OVH

System działa na **PHP 8.4+ (64-bit), PDO MySQL i MySQL 8.4+ / InnoDB**.
Nie wymaga Node.js, SQLite ani Composera do działania na hostingu.
MySQL jest domyślnym magazynem rezerwacji, blokad, oferty i limitów żądań.
Plik JSON służy wyłącznie jako źródło migracji, prywatny eksport lub
jawnie wybrany tryb zgodności `storage=json`; awaria MySQL nigdy nie
przełącza aplikacji na JSON ani na pusty kalendarz.
Opcjonalna automatyczna wysyłka WhatsApp wymaga rozszerzenia PHP cURL
oraz skonfigurowanego konta Meta Cloud API.

Rezerwowane są **30-minutowe konsultacje oraz pełne zabiegi**:
u obu specjalistek: pn.–pt. 08:00–20:00, sob. 10:00–20:00.
W niedziele brak zapisów online. Wspólny obszar to Września i Poznań;
miejsce wizyty potwierdza manager. Czas Europe/Warsaw, horyzont 90 dni.

## Dwie marki, dwa kalendarze

- **MissEye**: SMP i PM (makijaż permanentny); specjalistka `misseye-1`.
- **PitSTOP / do_nails**: Nails art (dłonie) i podologia; specjalistka `pitstop-1`,
  profil Instagram [@do_nails.beauty](https://instagram.com/do_nails.beauty).
- Obie specjalistki mają ten sam obszar obsługi: **Września i Poznań**,
  ale różne usługi i niezależne kalendarze. Domyślna informacja dla obu:
  „Września lub Poznań — lokalizacja ustalana z managerem”.
  Manager może przypisać dni tygodnia PitSTOP
  do miasta, podać publiczny adres lub wyłączyć przyjęcia PitSTOP w danym dniu.
  Nieuzupełnione miejsca trzeba potwierdzić z managerem przed przyjazdem.
- Oba miasta PitSTOP korzystają z jednego kalendarza tej samej osoby.
  Informacja o lokalizacji jest widoczna przy wyborze usługi, w potwierdzeniu,
  panelu managera i wiadomościach WhatsApp. Nie ma oddzielnych slotów miast.
- Cztery dotychczasowe konsultacje nadal trwają po 30 minut. Dwanaście
  wariantów zabiegów ma własne czasy (tabela poniżej).
- **Pierwsza konsultacja GRATIS** dotyczy obu specjalistek i czterech
  rodzajów konsultacji, nie zabiegów. Uprawnienie potwierdza manager:
  system nie identyfikuje automatycznie pierwszej wizyty i nie zeruje cen.
  Edytowalne ceny konsultacji dotyczą kolejnych wizyt. Zapisane ceny
  i historyczne rezerwacje pozostają bez zmian.
  API katalogu oznacza konsultacje flagą `firstConsultationFree`.
  Publiczny cennik jest zawsze rozwinięty: nazwa usługi, czas i cena
  w osobnych polach; zaokrąglony czas zajęcia kalendarza pokazuje rezerwacja.
- Usługi tej samej osoby blokują jej wspólny kalendarz. Różne osoby mogą
  przyjmować o tej samej godzinie. Sprawdzenie i zapis nadal są jedną
  transakcją pod blokadą pliku.
- Manager korzysta ze wspólnego panelu z filtrem specjalisty oraz jednego
  numeru telefonu i WhatsApp: **+48 665 638 033**, widocznego także na obu
  kartach specjalistek. Wspólny obszar nie oznacza wspólnego grafiku:
  ustawienia dni PitSTOP nie zamykają ani nie przenoszą wizyt MissEye.
  Istniejące wizyty zachowują wcześniej zapisane miejsce; rozszerzenie
  opisu obszaru nie zmienia automatycznie umówionego adresu.
  Starsze rezerwacje MissEye bez pola miejsca nadal wskazują Wrześnię
  w panelu, potwierdzeniu i powiadomieniach, bez zmieniania historii.
- Panel pokazuje obie marki; nie jest to system osobnych kont partnerów.
  Automatyczne wiadomości nadal wymagają konfiguracji Meta.

Katalog usług i przypisania osób znajdują się w `app/booking.php`.
API `/api/config` zwraca `services` i `specialists`; specjalistę rezerwacji
wyznacza serwer, nigdy pole przesłane przez klienta.

Istniejący identyfikator `brwi` pozostaje technicznym identyfikatorem PM,
żeby zachować stare rezerwacje i ich ponowienia. Wersja logicznego dokumentu
pozostaje 1; schemat MySQL ma osobną wersję. Rekordy bez `specialist_id` należą do osoby przypisanej do
ich usługi; nowe rekordy zapisują również `specialist_id`. Dotychczasowe
rezerwacje SMP i brwi nadal blokują kalendarz MissEye, nie PitSTOP.
Nie zmieniaj przypisania istniejącego identyfikatora usługi do innej osoby
bez jawnej migracji. Stary kod z jednym kalendarzem nie obsłuży poprawnie
równoległych wizyt — przed aktualizacją wykonaj kopię konfiguracji i danych.

Strona przedstawia obie marki równolegle. Dotychczasowe filmy są oznaczone
jako portfolio MissEye, nie PitSTOP. Portfolio PitSTOP / Nails art zawiera
siedem dostarczonych zdjęć. Kolejność: róż/czerń, brąz z panterką
(pełne dłonie, potem detal), wcześniejszy brąz (pełne dłonie, potem detal),
nude (pełne dłonie, potem detal). Kopie WebP
w `www/media/pitstop-nails-*.webp` mają szerokość 768 px i razem około
302 KiB (budżet 384 KiB). Oryginały nie zostały zmienione. Zdjęcia ładują się leniwie,
mają opisy alternatywne i otwierają pełne ujęcie w nowej karcie.
Wgraj je na hosting razem z pozostałymi mediami. Nie przedstawiają podologii.
Profil MissEye zachowuje dostarczony portret SMP/PM
(`www/media/misseye-specialist.webp`, 150 × 150 px, około 18 KiB,
bezstratny WebP). W profilu PitSTOP ostatni załącznik zastępuje wcześniejsze
zdjęcie: `www/media/do-nails-pro-ai.webp` (640 × 1120 px, około 33 KiB).
Za zgodą właściciela ma jawny podpis **„Materiał wizerunkowy AI”**;
zachowuje pełny kadr wraz z oznaczeniem na dole oryginału. Nie jest
dokumentacją rzeczywistego gabinetu ani realizacji zabiegu, dlatego nie
trafia do galerii prac. Oba obrazy mają opisy alternatywne i ładują się
leniwie; żaden nie jest wyświetlany powyżej natywnej szerokości.
Profile pokazują identyczne godziny i obszar. Wskazane godziny to ramy
przyjęć; istniejące wizyty, przerwy i ustawienia dostępności nadal blokują
terminy. Nie przypisujemy miast do konkretnych dni bez decyzji managera.
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
wykonania kontrolowanej migracji poniżej. Blokady i wizyty są w osobnych
tabelach InnoDB, ale ich zmiany odbywają się **w jednej transakcji**
pod wspólną blokadą kalendarza. Nie kasuj ani nie inicjalizuj ponownie
istniejących rezerwacji.
Po zapisaniu nowych zabiegów lub blokad nie wracaj do starego kodu,
który nie rozumie tych wpisów. Przed aktualizacją zrób kopię danych.

### Aktualizacja wcześniejszych przyszłych wizyt

Po wgraniu nowego kodu na serwer, przed udostępnieniem zapisów uruchom:

```sh
php scripts/round-calendar.php --dry-run
php scripts/round-calendar.php --apply
```

W Windows można użyć lokalnego `.runtime\php\php.exe` zamiast `php`.
Skrypt korzysta z tej samej konfiguracji MySQL i transakcji co API. Sprawdza
przyszłe, potwierdzone wizyty względem innych wizyt, ręcznych blokad
i zamknięcia o 20:00. Raport nie zawiera nazwisk ani telefonów.
Przy konflikcie zwraca kod 2 i **nie zmienia żadnej wizyty**; najpierw
rozwiąż kolizje z klientami. Przy bezpiecznym zastosowaniu zapisuje
prywatny eksport poprzedniego stanu w katalogu `data` (`before-hour-rounding-*.json`),
a dopiero potem atomowo aktualizuje całość. Zrób również niezależny backup.
Przed `--apply` utwórz prywatny katalog `data` obok `www`, jeśli nie istnieje,
i zapewnij procesowi PHP prawo zapisu (zalecane uprawnienia `700`).
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

Ustawienia `offer` znajdują się w prywatnej tabeli `misseye_state`;
aktualizacja używa tej samej transakcji i blokady co rezerwacje.
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
data/                  prywatne źródła migracji i eksporty; ignorowane przez Git
www/                    JEDYNY katalog publiczny
scripts/mysql-schema.sql schemat InnoDB, bez danych klientów i sekretów
scripts/migrate-mysql.php kontrolowana migracja JSON -> MySQL
scripts/notify.php      opcjonalne zadanie CLI/cron WhatsApp
scripts/send-reminders.php prywatne zadanie CLI/cron przypomnień e-mail
templates/              historyczny wzór JSON (nie inicjalizuje MySQL)
```

Wszystkie katalogi poza `www` muszą pozostać **poza document root**.
Nie publikuj całego repozytorium jako katalogu WWW.

## Uruchomienie lokalne w Windows

Potrzebne są PHP z `pdo_mysql` i osobna lokalna baza MySQL 8.4.
Nie używaj produkcyjnej bazy OVH do lokalnych testów.
Uruchom `php setup.php --origin=http://127.0.0.1:3000`, uzupełnij prywatnie
lokalne dane bazy w utworzonej konfiguracji, następnie wykonaj migrację
opisaną poniżej. Dopiero po jej zakończeniu:

```powershell
.\start.cmd
```

Skrypt uruchamia konfigurację CLI, a następnie serwer testowy:

- strona: http://127.0.0.1:3000/#rezerwacja
- panel: http://127.0.0.1:3000/admin

Klucz panelu to `admin_token` w prywatnym `config.php`. Skrypt generuje
go losowo przy pierwszym utworzeniu konfiguracji. Istniejącego `config.php`
nie nadpisuje i nie importuje automatycznie dawnego `.env`.
Setup nie tworzy pustej bazy ani nie migruje wizyt; sprawdza połączenie
i gotowość schematu. Przy nowej konfiguracji zakończy się kodem 1, dopóki
nie uzupełnisz hasła i nie wykonasz migracji.
Aktualne ustawienia edytuj w `config.php`, nie w dawnym `.env`.
Po zmianie konfiguracji odśwież formularz; PHP odczytuje ją przy każdym żądaniu.

Serwer wbudowany PHP służy wyłącznie do lokalnych testów.

### Migracja wcześniejszych rezerwacji SQLite

Jeśli istnieje stara baza SQLite, nie twórz pustego MySQL. Zatrzymaj
poprzedni serwer i wyeksportuj SQLite do prywatnego JSON:

```powershell
.\.runtime\node.exe scripts\migrate-sqlite.mjs
```

Do samej migracji potrzebny jest Node 24 z `node:sqlite`; na hostingu
PHP nie jest on potrzebny. Można wskazać dwa argumenty: źródło SQLite
i docelowy JSON. Migrator zachowuje identyfikatory, dane, zgody,
anulowania oraz wyniki powiadomień. Nie nadpisuje istniejącego JSON
ani nie zmienia oryginalnej bazy. Przerwane wysyłki oznacza jako nieznane.
Nie uruchamiaj go podczas przyjmowania rezerwacji na starym serwerze.
Następnie użyj tego JSON jako `--source` w migracji do MySQL.

## Instalacja na hostingu OVH przez FTPS

Najpierw ustaw w panelu OVH PHP 8.4 lub nowsze, włącz
rozszerzenie `pdo_mysql`, przygotuj bazę **MySQL 8.4** oraz
katalog multisite na `www`. Włącz poprawny certyfikat SSL dla docelowej
domeny, np. `okonabeauty.pl`, przed zbieraniem danych klientów.
Adres techniczny `okonabx.cluster129.hosting.ovh.net` nie zastępuje
certyfikatu dla Twojej domeny.

1. Rozpakuj przygotowaną paczkę. Przez **FTPS** wgraj `app`, `scripts`,
   `templates` oraz `config.example.php` do katalogu domowego konta,
   **obok** `www`. Zawartość katalogu `www` z paczki wgraj do hostowego
   `www`. Musi zostać przesłany także ukryty plik `www/.htaccess`.
2. Przy pierwszej instalacji skopiuj `config.example.php` do `config.php`
   obok `www`, albo wygeneruj go poleceniem `php setup.php`.
   Przy aktualizacji zachowaj istniejący klucz i konfigurację integracji.
   Ustaw `origin` na dokładny adres HTTPS bez ścieżki, np.
   `https://okonabeauty.pl`, i `admin_token` na losowy klucz o długości
   co najmniej 32 znaków. Możesz użyć klucza wygenerowanego lokalnie
   w `config.php`. Nie przesyłaj klucza w czacie ani w URL.
3. Ustaw `storage` na `mysql` i dodaj sekcję `database` według wzoru:
   host `vmqwmdgkris.mysql.db`, port `3306`, nazwa bazy `vmqwmdgkris`,
   użytkownik `vmqwmdgkris`. **Hasło wpisz tylko w prywatnym config.php**.
   `vmqwmdgkris.mysql.db` jest hostem, nie nazwą bazy.
   Wymagany jest dostęp sieciowy do bazy z hostingu; nie zakładaj,
   że OVH dopuszcza połączenia z domowego komputera.
   Jeśli serwer wymaga TLS z certyfikatem CA, ustaw `database.ssl_ca`
   na prywatną ścieżkę do zaufanego CA. Weryfikacja certyfikatu pozostaje
   włączona; nie ustawiaj niebezpiecznego obejścia. Puste `ssl_ca`
   nie wymusza TLS — sprawdź wymagania połączenia wewnętrznego OVH.
4. Wstrzymaj stary backend i cron, wykonaj backup, a następnie migrację
   opisaną poniżej. Nie nadpisuj konfiguracji ani plików `data`.
   Zalecane prawa prywatnych katalogów to `700`, plików `600` przy zgodnym
   właścicielu procesu PHP. Nie używaj `777`. Bieżące rezerwacje i limity
   HTTP nie wymagają zapisu JSON, tylko transakcji w bazie.
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
do katalogu publicznego. Nie potrzeba procesu Node.js.
Przy aktualizacjach **nie nadpisuj `config.php` i żadnych plików `data`**.
Paczka nie zawiera prywatnej konfiguracji ani rzeczywistych rezerwacji.

## Migracja JSON do MySQL — bez utraty historii

Nie znamy stanu danych na hostingu. Najpierw znajdź istniejący magazyn,
zatrzymaj przyjmowanie zapisów i stare zadania cron, wykonaj prywatny backup.
Nie usuwaj źródła. Wszystkie procesy WWW i cron po przełączeniu muszą
korzystać z tej samej bazy i konfiguracji.

Z katalogu aplikacji, przez SSH lub prywatne zadanie CLI na hostingu:

```sh
php setup.php
php scripts/migrate-mysql.php --dry-run
php scripts/migrate-mysql.php --apply
```

Domyślne źródło to prywatny `data/bookings.json`. Inne położenie wskaż
argumentem `--source=/pelna/prywatna/sciezka/bookings.json` w obu wywołaniach.
`--dry-run` waliduje cały dokument i stan docelowy, pokazuje tylko liczby
wizyt/blokad i informację o ofercie; nie tworzy tabel ani nie zapisuje SQL.
`--apply` zakłada schemat z `scripts/mysql-schema.sql`, importuje cały stan
w jednej transakcji, następnie porównuje wynik. Źródło jest odczytywane
pod wspólną blokadą i pozostaje bez zmian. Trzeba jednak wyłączyć stary
backend również po migracji, aby nie przyjmował kolejnych zapisów do JSON.

Brakujący lub uszkodzony JSON powoduje błąd, **nie pustą inicjalizację**.
Wyłącznie po potwierdzeniu, że nie istnieją żadne wizyty ani wcześniejszy
magazyn, można użyć:

```sh
php scripts/migrate-mysql.php --dry-run --empty
php scripts/migrate-mysql.php --apply --empty
```

`--empty` odmawia działania, jeśli wykryje domyślne stare dane JSON/SQLite
lub plik wskazany dawnym `data_file`. Nie potrafi odnaleźć plików o dowolnych
nazwach na całym koncie — tę kontrolę wykonuje operator.
Każdy import odmawia nadpisania już zainicjalizowanego MySQL, nawet pustego.
Ponowne wykonanie po przerwanym połączeniu wymaga najpierw sprawdzenia
stanu bazy, nie resetowania tabel. Schemat DDL nie jest transakcyjny;
przerwane tworzenie tabel można dokończyć ze zweryfikowanego pliku SQL,
natomiast import danych jest zawsze atomowy.

Schemat można wgrać przez phpMyAdmin, ale nie uruchamia to migracji danych.
Nie wystawiaj konfiguratora ani migratora przez publiczny URL.
Przy braku SSH użyj prywatnego CLI/harmonogramu OVH lub pomocy operatora.
Do instalacji potrzebne są prawa CREATE/ALTER/INDEX oraz SELECT/INSERT/
UPDATE/DELETE; użytkownik runtime po instalacji potrzebuje tylko DML.

## Jak działa zapis MySQL

- `misseye_bookings`: osobny wiersz dla każdej wizyty, unikalny `request_id`,
  indeks specjalistki/statusu/przedziału czasu.
- `misseye_blocks`: osobny wiersz dla każdej przerwy lub nieobecności.
- `misseye_state`: wersja schematu, oferta i metadane oraz wiersze blokad.
- `misseye_rates`: liczniki żądań z HMAC adresu IP i terminem wygaśnięcia.

Nie ma jednego pliku ani jednego pola zawierającego całą listę rezerwacji.
Wiersze wizyt/blokad zachowują dodatkowo pełny snapshot w polu `payload`
(`LONGTEXT` z ograniczeniem `JSON_VALID`), a oferta jest serializowana
w metadanych. Pozwala to bezstratnie zachować opcjonalne pola starszych
wizyt i kolejność kluczy wykorzystywaną przez dotychczasową idempotencję.
To magazyn SQL z indeksowanymi kolumnami i snapshotami, nie pełna
normalizacja wszystkich pól do odrębnych tabel.

PDO używa parametrów, `utf8mb4`, wyjątków i natywnych prepared statements.
Wszystkie tabele wymagają InnoDB. Odczyty mają spójny snapshot transakcji;
mutacje najpierw blokują wspólny wiersz kalendarza `SELECT ... FOR UPDATE`,
potem sprawdzają kolizje i zapisują zmiany. Chroni to także przed wyścigiem
wizyty z blokadą, ceną, przełożeniem lub zadaniem przypomnień.
Zmieniane są tylko zmodyfikowane wiersze. Błąd powoduje rollback,
timeout blokady jawny błąd 503, a ponowienie używa tego samego requestId.
Unikalny indeks dodatkowo chroni przed duplikatem zgłoszenia.

Adapter zachowuje dotychczasową logikę, która odczytuje stan kalendarza
do pamięci. Globalna blokada jest świadomym wyborem dla obecnego salonu,
nie deklaracją gotowości na ogólnopolski ruch. Kolejny etap skalowania
to zapytania zakresowe i blokady per specjalistka, po testach obciążenia.

Po migracji testuj backup MySQL i jego odtwarzanie. Nie cofaj do starego
JSON, jeżeli MySQL przyjął już nowe wizyty — stary plik będzie nieaktualny.
Nie edytuj SQL ręcznie podczas zapisów. Anulowanie nie usuwa danych;
ustal retencję i ogranicz dostęp do kopii. Limity HTTP są wspólne dla
procesów używających bazy; aplikacja nie ufa `X-Forwarded-For`.

Tryb przejściowy `storage=json` wymaga jawnego `data_file` i zachowuje
starą implementację wyłącznie do kontrolowanego przejścia i testów.
Usunięcie/awaria sekcji `database` nie aktywuje go automatycznie.

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

Testy MySQL wymagają **rzeczywistego lokalnego MySQL 8.4** oraz prywatnej
konfiguracji jak `config.example.php`, ale z hostem `127.0.0.1`, osobną
bazą o nazwie `misseye_test_*` i użytkownikiem mającym prawa do tabel
oraz testowego triggera. **Testy kasują cztery tabele aplikacji w tej
bazie**; nie uruchamiaj ich na danych salonu. Test odmawia połączenia
z hostem innym niż loopback lub bazą bez prefiksu testowego.

```powershell
$env:MISSEYE_MYSQL_TEST_CONFIG = 'C:\prywatne\mysql-test.php'
.\.runtime\php\php.exe -d extension_dir="C:\projekt\.runtime\php\ext" -d extension=pdo_mysql tests\mysql.php
```

Zastąp `C:\projekt` własnym katalogiem. W zwykłej instalacji włącz
`pdo_mysql` w `php.ini` i użyj `php tests/mysql.php`.
Pakiet sprawdza realne transakcje, konkurencyjne procesy PHP, identyczne
zgłoszenia, blokady, przełożenia, wersjonowanie ofert, pojedyncze zajęcie
powiadomień, migrację z zachowaniem źródła, indeks unikalny, awarie SQL
i rollback po częściowym wykonaniu, timeout oraz limit 120 żądań/minutę.
Brak MySQL/PDO jest błędem testu, nie pomijanym sprawdzeniem.

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
