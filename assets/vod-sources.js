(() => {
  const list = document.querySelector('#vs-links');
  if (!list) return;
  const mode = document.querySelector('#vs-mode');
  const refresh = () => {
    [...list.children].forEach((row, index) => {
      row.querySelector('.vs-rank').textContent = index === 0 ? 'PRIMARY' : `BACKUP ${index}`;
      row.querySelectorAll('[data-vs-name]').forEach(input => { input.name = `links[${index}][${input.dataset.vsName}]`; });
    });
    document.querySelector('#vs-count').value = list.children.length;
  };
  document.querySelector('#vs-add').addEventListener('click', () => {
    const row = list.firstElementChild?.cloneNode(true);
    if (!row) return;
    row.querySelectorAll('input').forEach(input => {
      if (input.type === 'checkbox') input.checked = input.dataset.vsName === 'enabled';
      else input.value = input.dataset.vsName === 'saved_position' ? '-1' : input.dataset.vsName === 'candidate' ? '0' : '';
      if (input.type === 'url') input.placeholder = 'https://…';
    });
    row.querySelector('p').textContent = 'Nouvelle alternative à confirmer';
    list.append(row); mode.value = 'custom'; refresh();
  });
  list.addEventListener('click', event => {
    const button = event.target.closest('button'); if (!button) return;
    const row = button.closest('.vs-row');
    if (button.hasAttribute('data-vs-up') && row.previousElementSibling) list.insertBefore(row, row.previousElementSibling);
    if (button.hasAttribute('data-vs-down') && row.nextElementSibling) list.insertBefore(row.nextElementSibling, row);
    if (button.hasAttribute('data-vs-remove') && list.children.length > 1) row.remove();
    mode.value = 'custom'; refresh();
  });
  list.addEventListener('change', () => { if (mode.value === 'legacy') mode.value = 'custom'; });
  document.querySelector('#vs-form').addEventListener('submit', refresh);
})();
