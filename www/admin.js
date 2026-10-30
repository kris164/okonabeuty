const login = document.querySelector('#admin-login');
const panel = document.querySelector('#admin-panel');
const date = document.querySelector('#admin-date');
const status = document.querySelector('#admin-status');
const list = document.querySelector('#admin-bookings');
const specialistFilter = document.querySelector('#admin-specialist');
const view = document.querySelector('#admin-view');
let token = '';
let sequence = 0;
let catalog;
const labels = {
  not_requested: 'Nie zamówiono wiadomości', unconfigured: 'API WhatsApp nie skonfigurowane',
  pending: 'Oczekuje na wysłanie', sending: 'Trwa wysyłka', accepted: 'Przyjęte przez WhatsApp',
  failed: 'Błąd wysyłki', unknown: 'Wynik nieznany — sprawdź w Meta',
};
const blockLabels = { break: 'Przerwa', leave: 'Urlop / nieobecność', other: 'Niedostępność' };

async function request(path, method = 'GET', payload) {
  const response = await fetch(path, {
    method,
    headers: {
      Authorization: `Bearer ${token}`,
      ...(payload ? { 'Content-Type': 'application/json' } : {}),
    },
    ...(payload ? { body: JSON.stringify(payload) } : {}),
    signal: AbortSignal.timeout(20_000),
  });
  const body = await response.json();
  if (!response.ok) {
    const error = new Error(body.error || 'Błąd operacji.');
    error.status = response.status;
    throw error;
  }
  return body;
}

function dayOffset(value, days) {
  const day = new Date(`${value}T12:00:00Z`);
  day.setUTCDate(day.getUTCDate() + days);
  return day.toISOString().slice(0, 10);
}

function dayLabel(value) {
  return new Intl.DateTimeFormat('pl-PL', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' })
    .format(new Date(`${value}T12:00:00Z`));
}

function paragraph(parent, text) {
  const element = document.createElement('p');
  element.textContent = text;
  parent.append(element);
}

function button(parent, text, action) {
  const item = document.createElement('button');
  item.type = 'button';
  item.textContent = text;
  item.addEventListener('click', action);
  parent.append(item);
  return item;
}

function fillSelect(select, items, all = false) {
  const previous = select.value;
  select.replaceChildren();
  if (all) select.append(new Option('Obie specjalistki', ''));
  for (const item of items) select.append(new Option(item.name, item.id));
  if ([...select.options].some(option => option.value === previous)) select.value = previous;
}

function prepareForms(data) {
  fillSelect(specialistFilter, data.specialists, true);
  const orderedServices = data.specialists.flatMap(person => data.services.filter(item => item.specialistId === person.id));
  fillSelect(document.querySelector('#create-service'), orderedServices.map(item => ({
    id: item.id, name: `${item.brand} · ${item.name} (${item.minutes} min; kalendarz ${item.reservedMinutes} min) · ${item.priceLabel}`,
  })));
  if (!catalog) {
    fillSelect(document.querySelector('#block-specialist'), data.specialists);
    for (const id of ['create-date', 'block-date', 'block-end-date']) {
      const input = document.getElementById(id);
      input.min = data.today;
      input.max = data.maxDate;
      input.value = date.value < data.today ? data.today : date.value;
    }
  }
  prepareOffer(data);
  document.querySelector('#create-email-consent').disabled = !data.emailReminders;
  document.querySelector('#admin-email-note').textContent = data.emailReminders
    ? 'E-mail: integracja poczty włączona. Przypomnienia wymagają uruchomienia zadania cron. Przyjęcie przez serwer poczty nie potwierdza doręczenia; failed/unknown sprawdź ręcznie.'
    : 'E-mail: wysyłka wyłączona do konfiguracji poczty na hostingu i zadania cron. Adresów do przypomnień nie zbieramy.';
  catalog = data;
}

function prepareOffer(data) {
  const form = document.querySelector('#admin-offer');
  if (form.dataset.loaded) return;
  form.dataset.loaded = 'true';
  form.dataset.revision = String(data.offer.revision);
  const fields = document.querySelector('#offer-fields');
  fields.replaceChildren();
  for (const service of data.services) {
    const row = document.createElement('div');
    row.className = 'offer-price-row';
    const title = document.createElement('span');
    title.textContent = `${service.brand} · ${service.name}${service.firstConsultationFree ? ' — kolejne konsultacje' : ''}`;
    const amountLabel = document.createElement('label');
    amountLabel.textContent = 'Kwota PLN (puste = do ustalenia)';
    const amount = document.createElement('input');
    amount.type = 'text';
    amount.inputMode = 'decimal';
    amount.pattern = '[0-9]{1,6}([.,][0-9]{1,2})?';
    amount.maxLength = 9;
    amount.dataset.price = service.id;
    amount.value = service.price.amount === null ? '' : (service.price.amount / 100).toFixed(2);
    amountLabel.append(amount);
    const modeLabel = document.createElement('label');
    modeLabel.textContent = 'Rodzaj ceny';
    const mode = document.createElement('select');
    mode.dataset.mode = service.id;
    mode.append(new Option('Cena stała', 'fixed'), new Option('Cena od', 'from'));
    mode.value = service.price.mode;
    modeLabel.append(mode);
    row.append(title, amountLabel, modeLabel);
    fields.append(row);
  }
  const week = document.createElement('div');
  week.className = 'offer-week-grid';
  ['Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota', 'Niedziela (bez zapisów online)'].forEach((day, index) => {
    const label = document.createElement('label');
    label.textContent = day;
    const select = document.createElement('select');
    select.dataset.weekday = String(index);
    for (const [value, text] of Object.entries({ manager: 'Ustala manager', wrzesnia: 'Września', poznan: 'Poznań', closed: 'Brak przyjęć' })) {
      select.append(new Option(text, value));
    }
    select.value = data.offer.week[index];
    label.append(select);
    week.append(label);
  });
  for (const [city, name] of [['wrzesnia', 'Września'], ['poznan', 'Poznań']]) {
    const label = document.createElement('label');
    label.textContent = `Adres PitSTOP — ${name} (opcjonalny, publiczny)`;
    const input = document.createElement('input');
    input.type = 'text';
    input.maxLength = 200;
    input.dataset.city = city;
    input.value = data.offer.addresses[city];
    label.append(input);
    week.append(label);
  }
  fields.append(week);
}

// Keep the same payload after an uncertain network outcome; never create a second appointment.
function wireMutation(form, path, makePayload, onSaved = () => {}) {
  let pending = null;
  let busy = false;
  const submit = form.querySelector('button[type=submit]');
  const initialText = submit.textContent;
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || !form.reportValidity()) return;
    busy = true;
    const operationToken = token;
    const message = form.querySelector('.mutation-status');
    try {
      pending ??= makePayload();
      form.querySelector('fieldset').disabled = true;
      submit.disabled = true;
      message.textContent = 'Zapisuję…';
      await request(path, 'POST', pending);
      if (token !== operationToken) return;
      onSaved();
      pending = null;
      message.textContent = path === '/api/admin/offer'
        ? 'Zapisano cennik i dni przyjęć.' : 'Zapisano. Zmianę terminu lub odwołanie przekaż klientowi osobno.';
      form.querySelector('fieldset').disabled = false;
      submit.textContent = initialText;
      await load();
    } catch (error) {
      if (token !== operationToken) return;
      const uncertain = !error.status || error.status >= 500 || error.status === 429;
      if (uncertain) {
        message.textContent = `${error.message} Wynik nieznany. Ponów ten sam zapis bez zmiany danych lub sprawdź kalendarz.`;
        submit.textContent = 'Sprawdź / ponów ten sam zapis';
      } else {
        pending = null;
        form.querySelector('fieldset').disabled = false;
        submit.textContent = initialText;
        message.textContent = error.message;
      }
    } finally {
      busy = false;
      submit.disabled = false;
    }
  });
}

function rescheduleForm(card, booking) {
  const details = document.createElement('details');
  const summary = document.createElement('summary');
  summary.textContent = 'Przełóż wizytę';
  details.append(summary);
  const form = document.createElement('form');
  form.className = 'manager-form';
  const fields = document.createElement('fieldset');
  const inputs = {};
  for (const [key, title, type, value] of [
    ['date', 'Nowa data', 'date', booking.date], ['time', 'Nowa godzina', 'time', booking.time],
  ]) {
    const label = document.createElement('label');
    label.textContent = title;
    const input = document.createElement('input');
    input.type = type;
    input.required = true;
    input.value = value;
    if (type === 'date') { input.min = catalog.today; input.max = catalog.maxDate; }
    else input.step = '1800';
    label.append(input);
    fields.append(label);
    inputs[key] = input;
  }
  form.append(fields);
  paragraph(form, `Czas usługi pozostaje bez zmian: ${booking.minutes} min; w kalendarzu ${booking.reserved_minutes} min. System sprawdzi cały przedział i blokady. Klienta powiadom osobno.`);
  const submit = document.createElement('button');
  submit.type = 'submit';
  submit.textContent = 'Zapisz nowy termin';
  const message = document.createElement('p');
  message.className = 'mutation-status';
  message.setAttribute('role', 'status');
  form.append(submit, message);
  wireMutation(form, `/api/admin/bookings/${booking.id}/reschedule`, () => ({
    date: inputs.date.value, time: inputs.time.value, revision: booking.revision,
  }));
  details.append(form);
  card.append(details);
}

async function cancelEntry(kind, entry, control) {
  if (!confirm(kind === 'blocks' ? 'Zwolnić tę blokadę czasu?' : 'Anulować wizytę? Poinformuj klienta osobno.')) return;
  control.disabled = true;
  try {
    await request(`/api/admin/${kind}/${entry.id}/cancel`, 'POST');
    await load();
  } catch (error) {
    status.textContent = `${error.message} Odśwież kalendarz i sprawdź wynik przed kolejną operacją.`;
    control.disabled = false;
  }
}

function entryCard(entry, services) {
  const isBlock = !entry.service;
  const details = isBlock ? null : services.get(entry.service);
  if (!isBlock && !details) throw new Error('Nieznana usługa w rezerwacji.');
  const person = isBlock ? entry.specialist_id : details.specialistId;
  const card = document.createElement('details');
  card.className = `appointment ${person} ${entry.status}`;
  card.id = `entry-${entry.id}`;
  const heading = document.createElement('summary');
  heading.textContent = `${entry.date} · ${entry.time}–${entry.end_time} · ${isBlock ? blockLabels[entry.reason] : entry.name}`;
  card.append(heading);
  if (isBlock) {
    paragraph(card, `${catalog.specialists.find(item => item.id === person).name} · do ${entry.end_date}, ${entry.end_time}`);
  } else {
    paragraph(card, `${details.brand} · ${details.name} · usługa ${entry.minutes} min, kalendarz ${entry.reserved_minutes} min`);
    paragraph(card, `${details.specialist}. Miejsce: ${entry.location ?? details.location}`);
    paragraph(card, entry.price_label ?? 'Cena do ustalenia');
    paragraph(card, `Telefon: ${entry.phone}`);
    paragraph(card, `Status: ${entry.status === 'confirmed' ? 'Zarezerwowana' : 'Anulowana'}`);
    paragraph(card, `WhatsApp: ${labels[entry.notification]}${entry.updated_at ? ' (pierwotny termin; zmianę przekaż osobno)' : ''}`);
    const mailLabels = {
      not_requested: 'Nie zamówiono', pending: 'Zaplanowane w ciągu 24 h przed wizytą',
      sending: 'Trwa wysyłka', accepted: 'Przyjęte przez serwer poczty — nie jest to dowód doręczenia',
      failed: 'Odrzucone — skontaktuj się ręcznie', unknown: 'Wynik nieznany — sprawdź ręcznie, bez automatycznego ponowienia',
    };
    paragraph(card, `Przypomnienie e-mail: ${mailLabels[entry.email_notification ?? 'not_requested']}`);
    if (entry.email_consent) {
      paragraph(card, `E-mail: ${entry.email}`);
      button(card, 'Wycofaj zgodę i usuń e-mail', async () => {
        if (!confirm('Wycofać zgodę i usunąć adres e-mail z tej rezerwacji? Wysłanej wiadomości nie można cofnąć.')) return;
        try {
          await request(`/api/admin/bookings/${entry.id}/email-stop`, 'POST');
          await load();
        } catch (error) { status.textContent = `${error.message} Odśwież kalendarz i sprawdź wynik.`; }
      });
    }
    paragraph(card, `Nr rezerwacji: ${entry.id}`);
    const link = document.createElement('a');
    link.href = `https://wa.me/${entry.phone.slice(1)}`;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.textContent = 'Napisz do klienta na WhatsApp';
    card.append(link);
    if (entry.status === 'confirmed') rescheduleForm(card, entry);
  }
  if (isBlock || entry.status === 'confirmed') {
    const control = button(card, isBlock ? 'Usuń blokadę' : 'Anuluj wizytę', () =>
      cancelEntry(isBlock ? 'blocks' : 'bookings', entry, control));
  }
  return card;
}

function drawCalendar(days, people, entries, services) {
  const calendar = document.querySelector('#admin-calendar');
  calendar.replaceChildren();
  calendar.style.setProperty('--calendar-columns', days.length * people.length);
  const axis = document.createElement('div');
  axis.className = 'calendar-axis';
  for (let hour = 8; hour <= 20; hour++) {
    const tick = document.createElement('span');
    tick.style.top = `${(hour - 8) * 60}px`;
    tick.textContent = `${String(hour).padStart(2, '0')}:00`;
    axis.append(tick);
  }
  calendar.append(axis);
  for (const day of days) {
    for (const person of people) {
      const column = document.createElement('section');
      column.className = `calendar-column ${person.id}`;
      const heading = document.createElement('h3');
      heading.textContent = `${dayLabel(day)} · ${person.brand}`;
      const mark = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      mark.classList.add('brand-mark');
      mark.setAttribute('aria-hidden', 'true');
      const shape = document.createElementNS('http://www.w3.org/2000/svg', 'use');
      shape.setAttribute('href', person.id === 'pitstop-1' ? '#mark-nail' : '#mark-eye');
      mark.append(shape);
      heading.prepend(mark);
      const track = document.createElement('div');
      track.className = 'calendar-track';
      for (const entry of entries) {
        const isBlock = !entry.service;
        const details = isBlock ? null : services.get(entry.service);
        const id = isBlock ? entry.specialist_id : details.specialistId;
        if (id !== person.id || !['active', 'confirmed'].includes(entry.status)
            || entry.date > day || (entry.end_date || entry.date) < day) continue;
        const minute = value => Number(value.slice(0, 2)) * 60 + Number(value.slice(3));
        const start = entry.date < day ? 480 : Math.max(480, minute(entry.time));
        const end = (entry.end_date || entry.date) > day ? 1200 : Math.min(1200, minute(entry.end_time));
        if (end <= start) continue;
        const item = button(track, `${entry.time}–${entry.end_time} · ${isBlock ? blockLabels[entry.reason] : `${entry.name} · ${details.name}`}`, () => {
          const target = document.getElementById(`entry-${entry.id}`);
          target.open = true;
          target.scrollIntoView({ block: 'center', behavior: 'smooth' });
          target.querySelector('summary').focus();
        });
        item.className = `calendar-event ${isBlock ? 'calendar-block' : person.id}`;
        item.style.top = `${start - 480}px`;
        item.style.height = `${end - start}px`;
        item.title = item.textContent;
      }
      column.append(heading, track);
      calendar.append(column);
    }
  }
}

async function load() {
  const current = ++sequence;
  list.replaceChildren();
  document.querySelector('#admin-calendar').replaceChildren();
  status.textContent = 'Wczytuję kalendarz…';
  try {
    let start = date.value;
    if (view.value === 'week') {
      const weekday = new Date(`${start}T12:00:00Z`).getUTCDay();
      start = dayOffset(start, -(weekday === 0 ? 6 : weekday - 1));
    }
    const days = Array.from({ length: view.value === 'week' ? 7 : 1 }, (_, index) => dayOffset(start, index));
    const data = await request(`/api/admin/calendar?date=${start}&end=${days.at(-1)}`);
    if (current !== sequence) return;
    prepareForms(data);
    panel.hidden = false;
    login.hidden = true;
    const people = data.specialists.filter(person => !specialistFilter.value || person.id === specialistFilter.value);
    const services = new Map(data.services.map(item => [item.id, item]));
    const entries = [...data.bookings, ...data.blocks].filter(entry =>
      people.some(person => person.id === (entry.specialist_id || services.get(entry.service)?.specialistId)))
      .sort((a, b) => a.starts - b.starts);
    for (const entry of entries) list.append(entryCard(entry, services));
    drawCalendar(days, people, entries, services);
    document.querySelector('#calendar-heading').textContent = `${dayLabel(start)}${days.length > 1 ? ` — ${dayLabel(days.at(-1))}` : ''}`;
    status.textContent = `Wizyty: ${entries.filter(item => item.service && item.status === 'confirmed').length}. Blokady: ${data.blocks.filter(item => people.some(person => person.id === item.specialist_id)).length}.`;
  } catch (error) {
    if (current === sequence) status.textContent = `Nie udało się wczytać kalendarza: ${error.message}`;
  }
}

wireMutation(document.querySelector('#admin-create'), '/api/admin/bookings', () => ({
  requestId: crypto.randomUUID(), service: document.querySelector('#create-service').value,
  date: document.querySelector('#create-date').value, time: document.querySelector('#create-time').value,
  name: document.querySelector('#create-name').value.trim(), phone: document.querySelector('#create-phone').value,
  consent: document.querySelector('#create-consent').checked, whatsappConsent: document.querySelector('#create-whatsapp').checked,
  emailConsent: document.querySelector('#create-email-consent').checked,
  email: document.querySelector('#create-email-consent').checked ? document.querySelector('#create-email').value.trim() : '',
  offerRevision: catalog.offer.revision,
}));
document.querySelector('#create-email-consent').addEventListener('change', event => {
  const input = document.querySelector('#create-email');
  input.disabled = !event.target.checked;
  input.required = event.target.checked;
  if (!event.target.checked) input.value = '';
});
wireMutation(document.querySelector('#admin-offer'), '/api/admin/offer', () => {
  const form = document.querySelector('#admin-offer');
  const prices = {};
  for (const input of form.querySelectorAll('[data-price]')) {
    const [whole, fraction = ''] = input.value.replace(',', '.').split('.');
    prices[input.dataset.price] = {
      amount: input.value === '' ? null : Number(whole) * 100 + Number(fraction.padEnd(2, '0')),
      mode: form.querySelector(`[data-mode="${input.dataset.price}"]`).value,
    };
  }
  return {
    revision: Number(form.dataset.revision), prices,
    week: [...form.querySelectorAll('[data-weekday]')].map(select => select.value),
    addresses: Object.fromEntries([...form.querySelectorAll('[data-city]')].map(input => [input.dataset.city, input.value.trim()])),
  };
}, () => { delete document.querySelector('#admin-offer').dataset.loaded; });
wireMutation(document.querySelector('#admin-block'), '/api/admin/blocks', () => ({
  id: crypto.randomUUID(), specialistId: document.querySelector('#block-specialist').value,
  date: document.querySelector('#block-date').value, time: document.querySelector('#block-time').value,
  endDate: document.querySelector('#block-end-date').value, endTime: document.querySelector('#block-end-time').value,
  reason: document.querySelector('#block-reason').value,
}));
login.addEventListener('submit', async event => {
  event.preventDefault();
  token = document.querySelector('#admin-key').value;
  document.querySelector('#admin-key').value = '';
  try {
    const response = await fetch('/api/config');
    if (!response.ok) throw new Error('Nie można pobrać daty.');
    date.value ||= (await response.json()).today;
    await load();
  } catch (error) { status.textContent = error.message; }
});
for (const input of [date, specialistFilter, view]) input.addEventListener('change', load);
document.querySelector('#admin-refresh').addEventListener('click', load);
document.querySelector('#admin-today').addEventListener('click', () => { date.value = catalog.today; load(); });
for (const [id, direction] of [['admin-prev', -1], ['admin-next', 1]]) {
  document.getElementById(id).addEventListener('click', () => {
    date.value = dayOffset(date.value, direction * (view.value === 'week' ? 7 : 1));
    load();
  });
}
document.querySelector('#admin-logout').addEventListener('click', () => {
  ++sequence;
  token = '';
  location.reload();
});
