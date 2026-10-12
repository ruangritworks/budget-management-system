(() => {
  'use strict';
  const sub = document.getElementById('assignment-sub');
  const secondary = document.getElementById('assignment-secondary');
  if (!sub || !secondary) return;
  const units = JSON.parse(document.getElementById('assignment-units').textContent);
  sub.addEventListener('change', () => {
    secondary.replaceChildren(new Option('เลือกหน่วยรองในหน่วยย่อยนี้', ''));
    units.filter(u => Number(u.active) === 1 && u.unit_level === 'secondary' && String(u.parent_unit_id) === sub.value)
      .forEach(u => secondary.add(new Option(`${u.unit_name} (${u.unit_code})`, u.id)));
    document.querySelectorAll('[name="work_ids[]"]').forEach(box => { box.checked = false; });
  });
  secondary.addEventListener('change', () => {
    document.querySelectorAll('[name="work_ids[]"]').forEach(box => { box.checked = false; });
  });
  document.getElementById('assignment-search').addEventListener('input', event => {
    const text = event.target.value.trim().toLocaleLowerCase();
    document.querySelectorAll('[data-work-row]').forEach(row => { row.hidden = !row.textContent.toLocaleLowerCase().includes(text); });
  });
})();
