'use strict';
const feedback = document.querySelector('.feedback');
const preview = document.querySelector('dialog');
document.addEventListener('click', event => {
  const button = event.target.closest('button');
  if (!button) return;
  if (button.matches('.preview')) preview.showModal();
  if (button.matches('.close')) preview.close();
  if (button.matches('.modes button')) {
    button.parentElement.querySelectorAll('button').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
    feedback.textContent = `Mode « ${button.textContent} » sélectionné dans la maquette. Aucune commande envoyée.`;
  }
  if (button.matches('.refresh')) {
    const label = button.querySelector('span');
    button.disabled = true;
    label.textContent = 'Actualisation…';
    setTimeout(() => {
      button.disabled = false;
      label.textContent = 'Actualiser l’état CEC';
      feedback.textContent = 'Simulation terminée : En veille. Aucune interrogation de la box.';
    }, 700);
  }
  if (button.dataset.demo) {
    feedback.textContent = `${button.dataset.demo} — commande conservée dans cette maquette, sans action sur la box.`;
    const menu = button.closest('details');
    if (menu) menu.open = false;
  }
});
preview.addEventListener('click', event => { if (event.target === preview) preview.close(); });
document.addEventListener('keydown', event => {
  if (event.key === 'Escape') document.querySelectorAll('details[open]').forEach(item => item.open = false);
});
