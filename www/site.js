const siteHeader = document.querySelector('.site-header');
const menuToggle = document.querySelector('#menu-toggle');
const navigation = document.querySelector('#site-navigation');
const compactNavigation = matchMedia('(max-width: 980px)');

function setMenu(open) {
  siteHeader.dataset.menuOpen = String(open);
  menuToggle.setAttribute('aria-expanded', String(open));
  menuToggle.querySelector('span').textContent = open ? 'Zamknij' : 'Menu';
}

menuToggle.hidden = false;
siteHeader.classList.add('menu-ready');
setMenu(false);
menuToggle.addEventListener('click', () => setMenu(menuToggle.getAttribute('aria-expanded') !== 'true'));
navigation.addEventListener('click', event => {
  if (event.target.closest('a')) setMenu(false);
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && menuToggle.getAttribute('aria-expanded') === 'true') {
    setMenu(false);
    menuToggle.focus();
  }
});
document.addEventListener('click', event => {
  if (!siteHeader.contains(event.target)) setMenu(false);
});
compactNavigation.addEventListener('change', () => setMenu(false));
