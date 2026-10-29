# MISSEYE — rezerwacje i WhatsApp

System rezerwuje **30-minutowe konsultacje** SMP i makijażu brwi,
nie pełne zabiegi. Godziny: pn.–pt. 08:00–20:00, sob. 10:00–20:00,
niedziele do uzgodnienia przez WhatsApp. Czas: Europe/Warsaw, również
po zmianie czasu. Rezerwacje do 90 dni naprzód.

## Uruchomienie lokalne

Wymagany Node.js LTS 24.11 lub nowszy. Brak dodatkowych zależności.
W PowerShell, w folderze projektu:

```powershell
.\start.cmd
```

Skrypt tworzy `.env` z losowym kluczem panelu, jeżeli plik jeszcze nie
istnieje, i uruchamia serwer. Może użyć lokalnego, przenośnego Node
w `.runtime`, jeżeli nie ma go w PATH. Folder `.runtime` jest ignorowany
przez Git i nie musi być wysyłany na hosting z zainstalowanym Node.
Nie udostępniaj `.env`, klucza ani tokenów Meta.
Po przygotowaniu `.env` można również uruchamiać:

```powershell
npm start
```

Strona: http://127.0.0.1:3000/#rezerwacja.
Panel właściciela: http://127.0.0.1:3000/admin — użyj `ADMIN_TOKEN`.
Klucz w panelu jest trzymany tylko w pamięci karty; wylogowanie go usuwa.
Nie otwieraj strony przez `file:`, bo rezerwacje wymagają serwera.

```powershell
npm test
```

Przy lokalnym przenośnym Node bez npm użyj `.\.runtime\node.exe --test`.
Testy używają fikcyjnych danych i atrap API; nie wysyłają wiadomości.

## Kalendarz i dane

Rezerwacje trafiają do SQLite (`data/bookings.sqlite`), a nie do pamięci
przeglądarki. Transakcja blokuje kolizje między obiema usługami.
Ponowienie tego samego zgłoszenia nie tworzy drugiej rezerwacji.
Panel pozwala wybrać dzień, zobaczyć wizyty, napisać do klienta i anulować
wizytę. Anulowanie odblokowuje termin, ale **nie powiadamia klienta**.
Powiadom go osobno. Po błędzie sieci odśwież panel, zanim ponowisz działania.

Nie ma synchronizacji z Booksy ani Google Calendar. Terminy zajęte poza
tym systemem nie są automatycznie blokowane. Przed użyciem równolegle
z innym kalendarzem trzeba dodać synchronizację albo wybrać jedno źródło
rezerwacji. System nie uwzględnia świąt, urlopów ani przerw właściciela.

Pliki bazy i jej kopie zawierają dane klientów. Ogranicz do nich dostęp,
szyfruj backupy i ustal okres retencji. Anulowanie nie usuwa danych.
Przed publikacją uzupełnij pełną informację RODO w sekcji danych
rezerwacji: rzeczywisty administrator, adres kontaktowy, okres retencji,
odbiorcy, transfery i prawa. Nie zbieraj w formularzu danych zdrowotnych.
Oświadczenie w formularzu to potwierdzenie zapoznania się z informacją,
nie zgoda na marketing.

## WhatsApp

Numer kontaktowy salonu: **+48 696 293 848**.
Przycisk `wa.me` otwiera wiadomość do salonu, ale klient sam ją wysyła.
Rezerwacja istnieje niezależnie od wysłania tej wiadomości.

Automatyczne potwierdzenia są domyślnie **wyłączone**. Aplikacja WhatsApp
Business sama nie wystarcza: potrzebny jest WhatsApp Business Platform
(Meta Cloud API), numer nadawczy, token oraz zatwierdzony szablon.
Integracja przygotowana w kodzie wysyła potwierdzenie **do klienta**,
nie automatyczne powiadomienie do właściciela. Właściciel widzi wizyty
w panelu i może sam rozpocząć rozmowę z klientem.

1. Skonfiguruj konto Meta Business i numer Cloud API. Numer kontaktowy
   na stronie i numer nadawczy API nie muszą być tym samym numerem.
2. Utwórz i zatwierdź szablon typu utility, z czterema parametrami
   tekstowymi w treści, w podanej kolejności: nazwa konsultacji, data,
   godzina, numer rezerwacji. Przykładowa treść:
   „MISSEYE: potwierdzamy rezerwację {{1}} dnia {{2}} o {{3}} (czas polski).
   Numer rezerwacji: {{4}}. Zmiany uzgodnisz z salonem.”
3. Ustaw w `.env`: `WA_ACCESS_TOKEN`, `WA_PHONE_NUMBER_ID`,
   `WA_GRAPH_VERSION` (aktualna obsługiwana wersja z panelu Meta),
   `WA_TEMPLATE_NAME` i `WA_TEMPLATE_LANGUAGE` (domyślnie `pl`).
4. Uruchom ponownie serwer. Formularz pokaże dobrowolną zgodę na
   jednorazowe potwierdzenie WhatsApp. Bez zgody nie wysyła wiadomości.
5. Przetestuj na uprawnionym numerze testowym Meta i następnie wykonaj
   kontrolowaną rezerwację produkcyjną po zatwierdzeniu konta i szablonu.

Token jest używany wyłącznie na serwerze. Nie wstawiaj go do HTML/JS
przeglądarki. Meta może naliczać opłaty i wymagać dodatkowej weryfikacji.
Nie skonfigurowano prawdziwego konta Meta ani nie zweryfikowano doręczeń.

Status „przyjęte przez WhatsApp” oznacza jedynie akceptację żądania przez
Meta. System nie ma webhooka doręczeń ani obsługi przychodzących rozmów.
Nie zastępuje komunikatora WhatsApp i nie odbiera wiadomości od klientów.
Błąd WhatsApp nie usuwa rezerwacji. Nieznany wynik wysyłki, np. po
zerwaniu połączenia, wymaga ręcznego sprawdzenia w Meta; system nie
ponawia wysyłki automatycznie, żeby nie wysyłać duplikatów.
Wiadomość niewysłana z powodu błędu wymaga kontaktu ręcznego.

## Publikacja pod okonabeauty.pl

Sama domena nie zapewnia hostingu. Potrzebny jest serwer obsługujący
Node.js 24+ i **trwały dysk**, z jednym procesem aplikacji.
Nie wdrażaj SQLite na nietrwałej funkcji serverless.

- Ustal nazwę marki: strona nadal używa MISSEYE; domena jej nie zmienia.
- Skopiuj projekt i skonfiguruj prywatny plik `.env`.
- Ustaw `PUBLIC_ORIGIN=https://okonabeauty.pl` i właściwe `HOST`/`PORT`.
- Skonfiguruj reverse proxy HTTPS z ważnym certyfikatem. Przekieruj
  wariant `www` do wybranej domeny, żeby źródło żądań było jednoznaczne.
- Uruchom aplikację jako usługę; baza nie może być publicznie dostępna.
  Serwer udostępnia tylko jawnie wskazane pliki, nie cały folder.
- Ogranicz dostęp do panelu dodatkowo na proxy (np. VPN lub allowlista).
  Nie przesyłaj klucza w URL. Ogranicz żądania na proxy; wbudowany limit
  widzi IP proxy, nie ufa nagłówkom `X-Forwarded-For`.
- Przed publicznym otwarciem uzupełnij RODO, zasady odwoływania wizyt,
  harmonogram nieobecności oraz zabezpieczenie przed fałszywymi
  rezerwacjami (np. weryfikacja numeru i ochrona antybotowa).
- Zabezpiecz komputer/hosting i bazę, ustaw retencję i backupy SQLite
  spójne z WAL (SQLite backup API lub kopia po zatrzymaniu aplikacji).
- Sprawdź równoczesne rezerwacje, odtworzenie bazy i realne doręczenie
  WhatsApp. Testy automatyczne nie potwierdzają działania konta Meta.

Domena/DNS, hosting, HTTPS i Meta nie są skonfigurowane przez samo
utworzenie tych plików. Wersja lokalna jest przeznaczona do sprawdzenia
przed tymi czynnościami.
