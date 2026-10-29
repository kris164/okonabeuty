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
    `${details.specialist} · ${details.minutes} min. ${details.location}. Termin blokuje tylko kalendarz tej osoby.`;
  const sequence = ++loadSequence;
  selectedTime = '';
  slots.replaceChildren();
  loading = true;
  updateSubmit();
  message('Sprawdzam dostępne godziny…');
  try {
    const { slots: times } = await api(`/api/availability?date=${encodeURIComponent(date.value)}&service=${encodeURIComponent(service.value)}`);
    if (sequence !== loadSequence) return;
    for (const time of times) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'slot';
      button.textContent = time;
      button.setAttribute('aria-pressed', 'false');
      button.addEventListener('click', () => {
        selectedTime = time;
        slots.querySelectorAll('button').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
        message(`Wybrano ${date.value}, godz. ${time}.`);
        updateSubmit();
      });
      slots.append(button);
    }
    const sunday = new Date(`${date.value}T12:00:00Z`).getUTCDay() === 0;
    message(times.length ? 'Wybierz godzinę (czas polski).' : sunday
      ? 'Niedziela: termin wyłącznie do uzgodnienia przez WhatsApp.'
      : 'Brak wolnych godzin. Wybierz inny dzień lub skontaktuj się przez WhatsApp.');
  } catch (error) {
    if (sequence === loadSequence) message(`${error.message} Zmień datę, aby spróbować ponownie.`, true);
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
    document.querySelector('#booking-summary').textContent =
      `${booking.brand} · ${booking.service} — ${booking.date}, godz. ${booking.time} (czas polski), ${booking.minutes} minut. ${booking.specialist}. Miejsce: ${booking.location}.`;
    document.querySelector('#booking-reference').textContent = `Numer rezerwacji: ${booking.id}`;
    document.querySelector('#booking-notification').textContent = notificationMessages[booking.notification];
    document.querySelector('#booking-whatsapp').href = booking.whatsappUrl;
    form.hidden = true;
    result.hidden = false;
    activeBookingId = booking.id;
    result.focus();
    if (['pending', 'sending'].includes(booking.notification)) watchNotification(booking.id);
    form.reset();
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
  submit.textContent = 'Zarezerwuj konsultację';
  date.value = config.today;
  loadSlots();
});
service.addEventListener('change', loadSlots);
date.addEventListener('change', loadSlots);
for (const link of document.querySelectorAll('[data-service]')) {
  link.addEventListener('click', () => {
    if (submitting || frozenRequest || !result.hidden) return;
    requestedService = link.dataset.service;
    if (!config) return;
    service.value = requestedService;
    loadSlots();
  });
}

async function init() {
  if (location.protocol === 'file:') {
    message('Rezerwacje wymagają uruchomienia serwera. Otwórz stronę przez adres http://127.0.0.1:3000, a nie bezpośrednio z pliku.', true);
    return;
  }
  try {
    config = await api('/api/config');
    for (const person of config.specialists) {
      const group = document.createElement('optgroup');
      group.label = person.brand;
      for (const item of config.services.filter(item => item.specialistId === person.id)) {
        const option = document.createElement('option');
        option.value = item.id;
        option.textContent = `${item.name} (${item.minutes} min)`;
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
    fields.disabled = false;
    await loadSlots();
  } catch (error) {
    message(`${error.message} Rezerwacje są chwilowo niedostępne — skontaktuj się przez WhatsApp.`, true);
  }
}
init();
