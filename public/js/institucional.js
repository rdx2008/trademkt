document.querySelectorAll('.nav-toggle').forEach(button => {
 const menu = document.getElementById(button.getAttribute('aria-controls'));
 button.addEventListener('click', () => {
  const expanded = button.getAttribute('aria-expanded') === 'true';
  button.setAttribute('aria-expanded', String(!expanded)); menu.classList.toggle('open', !expanded);
 });
 menu.querySelectorAll('a').forEach(link => link.addEventListener('click', () => { menu.classList.remove('open'); button.setAttribute('aria-expanded','false'); }));
 document.addEventListener('keydown', event => { if(event.key === 'Escape') { menu.classList.remove('open'); button.setAttribute('aria-expanded','false'); button.focus(); } });
});
