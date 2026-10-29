const login = document.querySelector('#admin-login');
const panel = document.querySelector('#admin-panel');
const date = document.querySelector('#admin-date');
const status = document.querySelector('#admin-status');
const list = document.querySelector('#admin-bookings');
const specialistFilter = document.querySelector('#admin-specialist');
let token = '';
let sequence = 0;
const notificationLabels = {
  not_requested: 'Nie zamówiono wiadomości',
  unconfigured: 'API WhatsApp nie skonfigurowane',
  pending: 'Oczekuje na wysłanie',
  sending: 'Trwa wysyłka',
  accepted: 'Przyjęte przez WhatsApp',
  failed: 'Błąd wysyłki',
  unknown: 'Wynik wysyłki nieznany — sprawdź w Meta',
};

async function request(path, method = 'GET') {
  const response = await fetch(path, {
    method, headers: { Authorization: `Bearer ${token}` },
    signal: AbortSignal.timeout(15_000),
  });
  const body = await response.json();
  if (!response.ok) throw new Error(body.error || 'Błąd operacji.');
  return body;
}

function paragraph(parent, text) {
  const element = document.createElement('p');
  element.textContent = text;
  parent.append(element);
}

async function load() {
  const current = ++sequence;
  list.replaceChildren();
  status.textContent = 'Wczytuję wizyty…';
  try {
    const { bookings, services, specialists } = await request(`/api/admin/bookings?date=${encodeURIComponent(date.value)}`);
    if (current !== sequence) return;
    const selection = specialistFilter.value;
    specialistFilter.replaceChildren(new Option('Obaj specjaliści', ''));
    for (const person of specialists) {
      specialistFilter.append(new Option(person.name, person.id));
    }
    specialistFilter.value = selection;
    const serviceCatalog = new Map(services.map(item => [item.id, item]));
    const visible = bookings.filter(booking => {
      const details = serviceCatalog.get(booking.service);
      if (!details) throw new Error('Nieznana usługa w rezerwacji. Skontaktuj się z administratorem.');
      return !selection || details.specialistId === selection;
    });
    panel.hidden = false;
    login.hidden = true;
    status.textContent = visible.length ? `Liczba wizyt w wybranym widoku: ${visible.length}` : 'Brak wizyt w tym widoku.';
    for (const booking of visible) {
      const details = serviceCatalog.get(booking.service);
      const card = document.createElement('article');
      card.className = `appointment ${booking.status} ${details.specialistId}`;
      const heading = document.createElement('h2');
      heading.textContent = `${booking.time} — ${booking.name}`;
      card.append(heading);
      paragraph(card, `${details.brand} · ${details.name} · ${details.minutes} min`);
      paragraph(card, details.specialist);
      paragraph(card, `Miejsce: ${details.location}`);
      paragraph(card, `Telefon: ${booking.phone}`);
      paragraph(card, `Status: ${booking.status === 'confirmed' ? 'Zarezerwowana' : 'Anulowana'}`);
      paragraph(card, `WhatsApp: ${notificationLabels[booking.notification]}`);
      paragraph(card, `Nr rezerwacji: ${booking.id}`);
      const actions = document.createElement('div');
      actions.className = 'actions';
      const link = document.createElement('a');
      link.href = `https://wa.me/${booking.phone.slice(1)}`;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.textContent = 'Napisz do klienta na WhatsApp';
      actions.append(link);
      if (booking.status === 'confirmed') {
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = 'Anuluj wizytę';
        cancel.addEventListener('click', async () => {
          if (!confirm('Anulować wizytę i zwolnić termin? Powiadom klienta osobno przez WhatsApp.')) return;
          cancel.disabled = true;
          try {
            await request(`/api/admin/bookings/${booking.id}/cancel`, 'POST');
            await load();
          } catch (error) {
            status.textContent = `${error.message} Odśwież listę, aby sprawdzić wynik anulowania.`;
            cancel.disabled = false;
          }
        });
        actions.append(cancel);
      }
      card.append(actions);
      list.append(card);
    }
  } catch (error) {
    if (current === sequence) status.textContent = `Nie udało się wczytać wizyt: ${error.message}`;
  }
}

login.addEventListener('submit', async event => {
  event.preventDefault();
  token = document.querySelector('#admin-key').value;
  document.querySelector('#admin-key').value = '';
  if (!date.value) {
    try {
      const response = await fetch('/api/config');
      if (!response.ok) throw new Error('Błąd pobierania daty.');
      date.value = (await response.json()).today;
    } catch (error) {
      status.textContent = `Nie udało się otworzyć panelu: ${error.message}`;
      return;
    }
  }
  await load();
});
date.addEventListener('change', load);
specialistFilter.addEventListener('change', load);
document.querySelector('#admin-refresh').addEventListener('click', load);
document.querySelector('#admin-logout').addEventListener('click', () => {
  ++sequence;
  token = '';
  specialistFilter.replaceChildren(new Option('Obaj specjaliści', ''));
  list.replaceChildren();
  status.textContent = 'Wylogowano.';
  panel.hidden = true;
  login.hidden = false;
});
