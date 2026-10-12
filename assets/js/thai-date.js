(() => {
  'use strict';

  const months = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
    'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
  let sequence = 0;

  function daysInMonth(year, month) {
    const date = new Date(0);
    date.setUTCFullYear(year, month, 0);
    return date.getUTCDate();
  }

  function parseIso(value) {
    const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    if (!parts) return null;
    const [year, month, day] = parts.slice(1).map(Number);
    return year > 0 && month >= 1 && month <= 12 && day >= 1 && day <= daysInMonth(year, month)
      ? { year, month, day } : null;
  }

  function enhance(source) {
    if (source.dataset.thaiDate) return;
    const initialValue = source.value;
    const required = source.required;
    const originalId = source.id;
    const labels = [...(source.labels || [])];
    if (!labels.length && source.previousElementSibling?.tagName === 'LABEL') {
      labels.push(source.previousElementSibling);
    }
    const id = originalId || `thai-date-${++sequence}`;
    const group = document.createElement('div');
    group.className = 'thai-date-control';
    group.setAttribute('role', 'group');
    group.setAttribute('aria-label', `${labels[0]?.textContent.trim() || 'วันที่'} (พ.ศ.)`);
    const disabled = source.disabled || source.readOnly;

    function makeSelect(part, title) {
      const select = document.createElement('select');
      select.id = part === 'day' ? id : `${id}-${part}`;
      select.dataset.datePart = part;
      select.setAttribute('aria-label', title);
      if (source.getAttribute('aria-describedby')) select.setAttribute('aria-describedby', source.getAttribute('aria-describedby'));
      select.disabled = disabled;
      select.add(new Option(title, ''));
      group.append(select);
      return select;
    }

    const day = makeSelect('day', 'วัน');
    const month = makeSelect('month', 'เดือน');
    const year = makeSelect('year', 'ปี พ.ศ.');
    months.forEach((title, index) => month.add(new Option(title, String(index + 1))));
    const todayYear = new Date().getFullYear();
    const values = [parseIso(initialValue), parseIso(source.min), parseIso(source.max)].filter(Boolean);
    const firstYear = Math.min(todayYear - 100, ...values.map(value => value.year));
    const lastYear = Math.max(todayYear + 20, ...values.map(value => value.year));
    for (let value = lastYear; value >= Math.max(1, firstYear); value--) {
      year.add(new Option(`พ.ศ. ${value + 543}`, String(value)));
    }
    labels.forEach(label => { if (!label.contains(source)) label.htmlFor = day.id; });

    // The original named field still submits ISO Gregorian dates; only the controls display BE.
    source.dataset.thaiDate = 'true';
    source.id = `${id}-iso`;
    source.type = 'hidden';
    source.hidden = true;
    source.before(group);
    group.append(source);

    function refreshDays() {
      const old = day.value;
      const count = month.value ? daysInMonth(Number(year.value) || 2000, Number(month.value)) : 31;
      day.length = 1;
      for (let value = 1; value <= count; value++) day.add(new Option(String(value).padStart(2, '0'), String(value)));
      day.value = Number(old) <= count ? old : '';
    }

    function sync(notify = false) {
      const selected = [day.value, month.value, year.value];
      const complete = selected.every(Boolean);
      const anySelected = selected.some(Boolean);
      [day, month, year].forEach(select => {
        select.required = required || anySelected;
        select.setCustomValidity('');
      });
      source.value = complete
        ? `${year.value.padStart(4, '0')}-${month.value.padStart(2, '0')}-${day.value.padStart(2, '0')}` : '';
      if (complete) {
        const minimum = source.getAttribute('min');
        const maximum = source.getAttribute('max');
        if ((minimum && source.value < minimum) || (maximum && source.value > maximum)) {
          day.setCustomValidity('วันที่อยู่นอกช่วงที่กำหนด กรุณาเลือกวันที่ใหม่');
        }
        const step = Number(source.getAttribute('step'));
        if (step > 0 && step !== 1) {
          const base = parseIso(minimum || initialValue) ? (minimum || initialValue) : '1970-01-01';
          const interval = (Date.parse(`${source.value}T00:00:00Z`) - Date.parse(`${base}T00:00:00Z`)) / 86400000;
          if (interval % step !== 0) day.setCustomValidity('วันที่ไม่ตรงกับช่วงวันที่ที่กำหนด');
        }
      }
      if (notify) {
        source.dispatchEvent(new Event('input', { bubbles: true }));
        source.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }

    function setDate(value) {
      const date = parseIso(value);
      year.value = date ? String(date.year) : '';
      month.value = date ? String(date.month) : '';
      refreshDays();
      day.value = date ? String(date.day) : '';
      sync();
    }

    [day, month, year].forEach(select => select.addEventListener('change', () => {
      if (select !== day) refreshDays();
      sync(true);
    }));
    if (!required && !disabled) {
      const clear = document.createElement('button');
      clear.type = 'button';
      clear.className = 'thai-date-clear';
      clear.textContent = 'ล้างวันที่';
      clear.addEventListener('click', () => { setDate(''); sync(true); day.focus(); });
      group.append(clear);
    }
    source.form?.addEventListener('reset', event => {
      setTimeout(() => { if (!event.defaultPrevented) setDate(initialValue); }, 0);
    });
    setDate(initialValue);
  }

  function init() {
    document.querySelectorAll('input[type="date"]').forEach(enhance);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
