'use strict';
const $ = s => document.querySelector(s);
const key = 'hogarporfavor.draft.v1';
let mode = 'renta';
$('#year').textContent = new Date().getFullYear();
document.querySelectorAll('[data-mode]').forEach(button => button.addEventListener('click', () => {
  mode = button.dataset.mode;
  document.querySelectorAll('[data-mode]').forEach(item => { const selected = item === button; item.classList.toggle('selected', selected); item.setAttribute('aria-pressed', String(selected)); });
}));
$('#search').addEventListener('submit', event => {
  event.preventDefault();
  const place = $('#location').value.trim();
  $('#results h3').textContent = `Aún no hay anuncios de ${mode}${place ? ` en ${place}` : ''}.`;
  $('#results p').textContent = 'Tu búsqueda está lista. En esta primera etapa todavía no hay inventario publicado; vuelve pronto para explorar nuevas opciones.';
});
const draft = $('#draft-dialog');
const form = $('#draft-form');
document.querySelectorAll('[data-open]').forEach(button => button.addEventListener('click', () => {
  $('#draft-status').textContent = '';
  try { const saved = JSON.parse(localStorage.getItem(key) || 'null'); if (saved) for (const name of ['title','operation','type','city','price','description']) { if (form.elements[name] && typeof saved[name] === 'string') form.elements[name].value = saved[name]; } } catch { $('#draft-status').textContent = 'El almacenamiento de este navegador no está disponible. Puedes descargar tu borrador.'; }
  draft.showModal();
}));
document.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
$('#privacy-open').addEventListener('click', () => $('#privacy-dialog').showModal());
form.addEventListener('submit', event => {
  event.preventDefault();
  try { localStorage.setItem(key, JSON.stringify(Object.fromEntries(new FormData(form)))); $('#draft-status').textContent = 'Borrador guardado en este navegador. No se ha enviado ni publicado.'; } catch { $('#draft-status').textContent = 'No se pudo guardar en este navegador. Descarga una copia para conservarla.'; }
});
$('#download-draft').addEventListener('click', () => {
  if (!form.reportValidity()) return;
  const values = Object.fromEntries(new FormData(form));
  const content = `HOGAR POR FAVOR — BORRADOR SIN PUBLICAR\n\n${values.title}\n${values.operation} · ${values.type}\nZona: ${values.city}\nPrecio: ${values.price} MXN\n\n${values.description}\n`;
  const url = URL.createObjectURL(new Blob([content], {type:'text/plain;charset=utf-8'}));
  const a = document.createElement('a'); a.href = url; a.download = 'mi-anuncio-hogar-por-favor.txt'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
  $('#draft-status').textContent = 'Se ha preparado la descarga de tu borrador. Todavía no está publicado.';
});
$('#delete-draft').addEventListener('click', () => { try { localStorage.removeItem(key); form.reset(); $('#draft-status').textContent = 'Borrador local borrado.'; } catch { $('#draft-status').textContent = 'No se pudo acceder al almacenamiento local.'; } });
