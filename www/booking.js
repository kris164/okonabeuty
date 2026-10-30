const form = document.querySelector('#booking-form');
const fields = document.querySelector('#booking-fields');
const service = document.querySelector('#booking-service');
const date = document.querySelector('#booking-date');
const slots = document.querySelector('#booking-slots');
const status = document.querySelector('#booking-status');
const submit = document.querySelector('#booking-submit');
const result = document.querySelector('#booking-result');
let selectedTime = '';
let loadSequence = 0;
let loading = false;
let submitting = false;
let frozenRequest = null;
let config;
let activeBookingId = '';
let requestedService = '';
let findingNearest = false;
const daysStrip = document.querySelector('#booking-days');
const emailConsent = document.querySelector('#booking-email-consent');
const email = document.querySelector('#booking-email');

function syncEmailField() {
  const enabled = Boolean(config?.emailReminders && emailConsent.checked);
  email.disabled = !enabled;
  email.required = enabled;
  document.querySelector('#booking-email-field').hidden = !enabled;
  if (!enabled) email.value = '';
}
emailConsent.addEventListener('change', syncEmailField);

function showOffer() {
  const container = document.querySelector('#offer-catalog');
  container.replaceChildren();
  for (const person of config.specialists) {
    const section = document.createElement('section');
    const title = document.createElement('h3');
    title.textContent = person.brand;
    section.append(title);
    const list = document.createElement('dl');
    list.className = 'offer-list';
    for (const item of config.services.filter(item => item.specialistId === person.id)) {
      const row = document.createElement('div');
      const name = document.createElement('dt');
      name.textContent = item.name;
      const duration = document.createElement('small');
      duration.textContent = `${item.minutes} min`;
      name.append(duration);
      const price = document.createElement('dd');
      const amount = document.createElement('strong');
      amount.textContent = item.firstConsultationFree ? 'Pierwsza GRATIS' : item.priceLabel;
      price.append(amount);
      if (item.firstConsultationFree) {
        row.className = 'offer-consultation';
        const next = document.createElement('small');
        next.textContent = `Kolejne: ${item.priceLabel}`;
        price.append(next);
      }
      row.append(name, price);
      list.append(row);
    }
    section.append(list);
    container.append(section);
  }
  document.querySelector('#offer-status').hidden = true;
  const week = document.querySelector('#offer-week');
  week.replaceChildren();
  const labels = { manager: 'miejsce ustala manager', wrzesnia: 'Września', poznan: 'Poznań', closed: 'brak przyjęć' };
  config.offer.week.forEach((location, index) => {
    const item = document.createElement('li');
    const address = config.offer.addresses[location];
    const day = ['Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota', 'Niedziela'][index];
    const hours = index === 5 ? '10:00–20:00' : '8:00–20:00';
    const details = location === 'closed' ? labels[location]
      : `${hours} · ${labels[location]}${address ? ` — ${address}` : ''}`;
    item.textContent = index === 6 ? `${day}: brak zapisów online`
      : `${day}: ${details}`;
    week.append(item);
  });
}

function consultationPrice(details, label) {
  return details.firstConsultationFree
    ? `Pierwsza konsultacja GRATIS (uprawnienie potwierdza manager). Kolejne: ${label}`
    : label;
}

function offsetDate(value, days) {
  const day = new Date(`${value}T12:00:00Z`);
  day.setUTCDate(day.getUTCDate() + days);
  return day.toISOString().slice(0, 10);
}

function message(text, error = false) {
  status.textContent = text;
  status.dataset.error = String(error);
}

async function api(path, options = {}) {
  let response;
  try {
    response = await fetch(path, { ...options, signal: AbortSignal.timeout(20_000) });
  } catch {
    throw new Error('Nie można połączyć się z serwerem.');
  }
  let body;
  try { body = await response.json(); }
  catch { throw new Error('Nieprawidłowa odpowiedź serwera.'); }
  if (!response.ok) {
    const error = new Error(body.error || 'Nie udało się wykonać operacji.');
    error.status = response.status;
    throw error;
  }
  return body;
}

function updateSubmit() {
  submit.disabled = submitting || loading || !selectedTime || !config;
}

async function loadSlots() {
  const details = config.services.find(item => item.id === service.value);
  if (!details) {
    message('Nieprawidłowa usługa. Odśwież stronę lub skontaktuj się z managerem.', true);
    selectedTime = '';
    slots.replaceChildren();
    updateSubmit();
    return;
  }
  document.querySelector('#booking-provider').textContent =
    `${details.specialist} · usługa ${details.minutes} min, rezerwacja ${details.reservedMinutes} min. ${details.location}. Termin blokuje tylko kalendarz tej osoby.`;
  const sequence = ++loadSequence;
  document.querySelector('#booking-price').textContent = 'Sprawdzam cenę…';
  selectedTime = '';
  slots.replaceChildren();
  loading = true;
  updateSubmit();
  message('Sprawdzam dostępne godziny…');
  try {
    const query = `date=${encodeURIComponent(date.value)}&service=${encodeURIComponent(service.value)}`;
    const [availability, { days }] = await Promise.all([
      api(`/api/availability?${query}`), api(`/api/availability-days?${query}`),
    ]);
    if (sequence !== loadSequence) return;
    const times = availability.slots;
    config.offer.revision = availability.offerRevision;
    document.querySelector('#booking-price').textContent = consultationPrice(details, availability.priceLabel);
    document.querySelector('#booking-provider').textContent =
      `${details.specialist} · usługa ${details.minutes} min, rezerwacja ${details.reservedMinutes} min. Miejsce w wybranym dniu: ${availability.location}.`;
    daysStrip.replaceChildren();
    for (const day of days) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'date-choice';
      button.setAttribute('aria-pressed', String(day.date === date.value));
      const label = new Intl.DateTimeFormat('pl-PL', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' })
        .format(new Date(`${day.date}T12:00:00Z`));
      button.textContent = `${label} · ${day.count ? `${day.count} terminów` : 'brak'}`;
      button.disabled = day.count === 0;
      button.addEventListener('click', () => { date.value = day.date; loadSlots(); });
      daysStrip.append(button);
    }
    document.querySelector('#booking-prev-days').disabled = date.value <= config.today;
    document.querySelector('#booking-next-days').disabled = offsetDate(date.value, 7) > config.maxDate;
    document.querySelector('#booking-duration').textContent = `Czas usługi: ${details.minutes} min. W kalendarzu: ${details.reservedMinutes} min (zaokrąglenie czasu w górę do pełnej godziny). Starty co 30 minut.`;
    for (const time of times) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'slot';
      button.textContent = time;
      button.setAttribute('aria-pressed', 'false');
      button.addEventListener('click', () => {
        selectedTime = time;
        slots.querySelectorAll('button').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
        message(`Wybrano ${date.value}, godz. ${time}. ${details.name} · usługa ${details.minutes} min, rezerwacja ${details.reservedMinutes} min.`);
        updateSubmit();
      });
      slots.append(button);
    }
    const sunday = new Date(`${date.value}T12:00:00Z`).getUTCDay() === 0;
    message(times.length ? 'Wybierz godzinę (czas polski).' : sunday
      ? 'Niedziela: brak zapisów online. Wybierz dzień od poniedziałku do soboty.'
      : 'Brak wolnych godzin. Wybierz inny dzień lub skontaktuj się przez WhatsApp.');
  } catch (error) {
    if (sequence === loadSequence) {
      document.querySelector('#booking-price').textContent = 'Nie można potwierdzić ceny — skontaktuj się z managerem.';
      message(`${error.message} Zmień datę, aby spróbować ponownie.`, true);
    }
  } finally {
    if (sequence === loadSequence) {
      loading = false;
      updateSubmit();
    }
  }
}

const notificationMessages = {
  pending: 'Automatyczne potwierdzenie WhatsApp oczekuje na wysłanie.',
  sending: 'Trwa przekazywanie potwierdzenia do WhatsAppa.',
  accepted: 'WhatsApp przyjął potwierdzenie do wysyłki. Nie jest to jeszcze potwierdzenie doręczenia.',
  unconfigured: 'Automatyczne wiadomości WhatsApp nie są jeszcze uruchomione. Możesz napisać do salonu przyciskiem poniżej.',
  not_requested: 'Nie wybrano automatycznego potwierdzenia WhatsApp.',
  failed: 'Nie udało się wysłać potwierdzenia WhatsApp. Rezerwacja jest zapisana; skontaktuj się z salonem.',
  unknown: 'Nie można potwierdzić wysyłki wiadomości WhatsApp. Rezerwacja jest zapisana; skontaktuj się z salonem.',
};

async function watchNotification(id) {
  for (let attempt = 0; attempt < 10; attempt++) {
    await new Promise(resolve => setTimeout(resolve, 2000));
    if (result.hidden || activeBookingId !== id) return;
    try {
      const { booking } = await api(`/api/bookings/${id}`);
      if (result.hidden || activeBookingId !== id) return;
      document.querySelector('#booking-notification').textContent = booking.status === 'cancelled'
        ? 'Ta rezerwacja została anulowana. Skontaktuj się z salonem.'
        : booking.rescheduled ? 'Manager zmienił termin. Aktualny termin potwierdź z managerem; pierwotna wiadomość WhatsApp może być nieaktualna.'
          : notificationMessages[booking.notification];
      if (!['pending', 'sending'].includes(booking.notification) || booking.status === 'cancelled') return;
    } catch (error) {
      if (activeBookingId !== id) return;
      document.querySelector('#booking-notification').textContent =
        `${error.message} Rezerwacja została wcześniej zapisana, ale nie można sprawdzić stanu wiadomości.`;
      return;
    }
  }
  if (activeBookingId !== id) return;
  document.querySelector('#booking-notification').textContent =
    'Rezerwacja zapisana. Wysyłka WhatsApp nie została jeszcze potwierdzona — w razie potrzeby skontaktuj się z salonem.';
}

form.addEventListener('submit', async event => {
  event.preventDefault();
  if (submitting || !selectedTime || !form.reportValidity()) return;
  if (!frozenRequest) {
    frozenRequest = {
      requestId: crypto.randomUUID(), service: service.value, date: date.value, time: selectedTime,
      name: document.querySelector('#booking-name').value.trim(),
      phone: document.querySelector('#booking-phone').value,
      consent: document.querySelector('#booking-consent').checked,
      whatsappConsent: document.querySelector('#booking-wa-consent').checked,
      emailConsent: emailConsent.checked, email: emailConsent.checked ? email.value.trim() : '',
      offerRevision: config.offer.revision,
    };
  }
  submitting = true;
  fields.disabled = true;
  updateSubmit();
  message('Zapisuję rezerwację…');
  try {
    const { booking } = await api('/api/bookings', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(frozenRequest),
    });
    const details = config.services.find(item => item.id === frozenRequest.service);
    document.querySelector('#booking-summary').textContent =
      `${booking.brand} · ${booking.service} — ${booking.date}, godz. ${booking.time} (czas polski). Usługa: ${booking.minutes} min, czas zajęty w kalendarzu: ${booking.reservedMinutes} min. ${booking.specialist}. Miejsce: ${booking.location}. ${consultationPrice(details, booking.priceLabel)}.`;
    document.querySelector('#booking-reference').textContent = `Numer rezerwacji: ${booking.id}`;
    document.querySelector('#booking-notification').textContent = notificationMessages[booking.notification];
    document.querySelector('#booking-email-notification').textContent = booking.emailReminder === 'pending'
      ? 'Przypomnienie e-mail zaplanowane w ciągu 24 godzin przed wizytą. Sprawdź również folder spam.'
      : 'Nie zamówiono przypomnienia e-mail.';
    document.querySelector('#booking-whatsapp').href = booking.whatsappUrl;
    form.hidden = true;
    result.hidden = false;
    activeBookingId = booking.id;
    result.focus();
    if (['pending', 'sending'].includes(booking.notification)) watchNotification(booking.id);
    form.reset();
    syncEmailField();
    frozenRequest = null;
  } catch (error) {
    if (error.status && error.status < 500 && error.status !== 429) {
      fields.disabled = false;
      frozenRequest = null;
      if (error.status === 409) {
        await loadSlots();
        message(error.message, true);
      } else {
        message(error.message, true);
      }
    } else {
      message(`${error.message} Wynik zapisu jest nieznany. Kliknij „Sprawdź / ponów zapis” bez zmiany danych. Nie twórz drugiej rezerwacji.`, true);
      submit.textContent = 'Sprawdź / ponów zapis';
    }
  } finally {
    submitting = false;
    updateSubmit();
  }
});

document.querySelector('#booking-new').addEventListener('click', () => {
  activeBookingId = '';
  result.hidden = true;
  form.hidden = false;
  fields.disabled = false;
  submit.textContent = 'Zarezerwuj wizytę';
  date.value = config.today;
  loadSlots();
});
service.addEventListener('change', loadSlots);
date.addEventListener('change', loadSlots);
for (const [id, delta] of [['booking-prev-days', -7], ['booking-next-days', 7]]) {
  document.getElementById(id).addEventListener('click', () => {
    date.value = [config.today, offsetDate(date.value, delta)].sort().at(-1);
    loadSlots();
  });
}
document.querySelector('#booking-nearest').addEventListener('click', async () => {
  findingNearest = true;
  const sequence = ++loadSequence;
  selectedTime = '';
  slots.replaceChildren();
  loading = true;
  fields.disabled = true;
  updateSubmit();
  message('Szukam pierwszego terminu od wybranego dnia, maksymalnie 90 dni naprzód…');
  try {
    const { slot } = await api(`/api/next-availability?date=${encodeURIComponent(date.value)}&service=${encodeURIComponent(service.value)}`);
    if (sequence !== loadSequence) return;
    if (!slot) {
      message('Brak wolnych terminów w dostępnym horyzoncie. Skontaktuj się z managerem.');
    } else {
      date.value = slot.date;
      await loadSlots();
      fields.disabled = false;
      const button = [...slots.querySelectorAll('button')].find(item => item.textContent === slot.time);
      if (button) button.click();
      else message('Termin właśnie się zmienił. Wybierz godzinę z aktualnej listy.');
    }
  } catch (error) {
    message(`${error.message} Spróbuj ponownie lub wybierz datę ręcznie.`, true);
  } finally {
    findingNearest = false;
    loading = false;
    fields.disabled = false;
    updateSubmit();
  }
});
for (const link of document.querySelectorAll('[data-service]')) {
  link.addEventListener('click', () => {
    if (submitting || findingNearest || frozenRequest || !result.hidden) return;
    requestedService = link.dataset.service;
    if (!config) return;
    service.value = requestedService;
    loadSlots();
  });
}

async function init() {
  if (location.protocol === 'file:') {
    document.querySelector('#offer-status').textContent = 'Cennik wymaga uruchomienia strony przez serwer. Ceny potwierdź z managerem.';
    message('Rezerwacje wymagają uruchomienia serwera. Otwórz stronę przez adres http://127.0.0.1:3000, a nie bezpośrednio z pliku.', true);
    return;
  }
  try {
    config = await api('/api/config');
    showOffer();
    for (const person of config.specialists) {
      const group = document.createElement('optgroup');
      group.label = person.brand;
      for (const item of config.services.filter(item => item.specialistId === person.id)) {
        const option = document.createElement('option');
        option.value = item.id;
        option.textContent = `${item.name}${item.firstConsultationFree ? ' — pierwsza GRATIS' : ''} (${item.minutes} min)`;
        group.append(option);
      }
      service.append(group);
    }
    if (config.services.some(item => item.id === requestedService)) service.value = requestedService;
    date.min = config.today;
    date.max = config.maxDate;
    date.value = config.today;
    document.querySelector('#booking-wa-note').textContent = config.automatedWhatsApp
      ? 'Możesz otrzymać automatyczne potwierdzenie rezerwacji przez WhatsApp.'
      : 'Automatyczne potwierdzenia WhatsApp są jeszcze nieaktywne. Po zapisie możesz otworzyć rozmowę z salonem.';
    document.querySelector('#booking-wa-label').hidden = !config.automatedWhatsApp;
    document.querySelector('#booking-email-label').hidden = !config.emailReminders;
    document.querySelector('#booking-email-note').textContent = config.emailReminders
      ? 'Przypomnienie e-mail jest dobrowolne; rezerwacja nie wymaga adresu e-mail.'
      : 'Przypomnienia e-mail oczekują na konfigurację poczty. Nie zbieramy teraz adresów.';
    syncEmailField();
    fields.disabled = false;
    await loadSlots();
  } catch (error) {
    if (!document.querySelector('#offer-status').hidden) {
      document.querySelector('#offer-status').textContent = `${error.message} Cennik jest chwilowo niedostępny — ceny potwierdź z managerem.`;
    }
    message(`${error.message} Rezerwacje są chwilowo niedostępne — skontaktuj się przez WhatsApp.`, true);
  }
}
init();
