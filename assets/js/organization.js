(() => {
  'use strict';
  const level = document.getElementById('unit-level');
  const parent = document.getElementById('unit-parent');
  if (level && parent) {
    const label = document.getElementById('unit-parent-label');
    const refresh = () => {
      const isMain = level.value === 'main';
      const expected = level.value === 'sub' ? 'main' : 'sub';
      if (label) {
        label.textContent = level.value === 'sub' ? 'หน่วยหลักที่สังกัด *' : 'หน่วยย่อยที่สังกัด *';
        label.hidden = isMain;
      }
      parent.hidden = isMain;
      parent.disabled = isMain;
      parent.options[0].textContent = isMain ? 'ไม่ต้องเลือกสังกัด' : (level.value === 'sub' ? 'เลือกหน่วยหลัก' : 'เลือกหน่วยย่อย');
      Array.from(parent.options).forEach(option => {
        if (!option.value) return;
        option.disabled = level.value === 'main' || option.dataset.level !== expected;
        option.hidden = option.disabled;
      });
      if (level.value === 'main' || parent.selectedOptions[0]?.disabled) parent.value = '';
      parent.required = !isMain;
    };
    level.addEventListener('change', refresh); refresh();
  }
  const programUnits = document.getElementById('program-units-data');
  if (programUnits) {
    const units = JSON.parse(programUnits.textContent);
    const subUnit = document.getElementById('program-sub');
    const responsible = document.getElementById('program-secondary');
    const template = document.getElementById('program-template');
    const templates = JSON.parse(document.getElementById('program-templates-data').textContent);
    const commonFields = ['strategy_no', 'strategy_name', 'plan_name', 'activity_name'];
    const useTemplate = () => {
      const source = templates.find(row => String(row.id) === template.value && String(row.responsible_unit_id) === responsible.value);
      commonFields.forEach(key => { document.querySelector(`[name="${key}"]`).value = source?.[key] || ''; });
    };
    const refreshTemplates = () => {
      if (!template) return;
      const selected = template.value;
      template.replaceChildren(new Option('สร้างชุดแผนงานใหม่', ''));
      templates.filter(row => Number(row.active) === 1 && String(row.responsible_unit_id) === responsible.value)
        .forEach(row => template.add(new Option(`${row.plan_name} · ${row.activity_name} (จาก ${row.work_group})`, row.id)));
      template.value = Array.from(template.options).some(option => option.value === selected) ? selected : '';
    };
    const refresh = () => {
      const selected = responsible.value;
      responsible.replaceChildren(new Option('เลือกหน่วยรองในหน่วยย่อยนี้', ''));
      units.filter(unit => Number(unit.active) === 1 && unit.unit_level === 'secondary' && String(unit.parent_unit_id) === subUnit.value)
        .forEach(unit => responsible.add(new Option(`${unit.unit_name} (${unit.unit_code})`, unit.id)));
      responsible.value = Array.from(responsible.options).some(option => option.value === selected) ? selected : '';
    };
    const changeScope = () => {
      refreshTemplates();
      if (template) { template.value = ''; useTemplate(); }
    };
    subUnit.addEventListener('change', () => { responsible.value = ''; refresh(); changeScope(); });
    responsible.addEventListener('change', changeScope);
    if (template) template.addEventListener('change', useTemplate);
    refresh(); refreshTemplates();
  }
  const node = document.getElementById('classification-data');
  if (!node) return;
  const data = JSON.parse(node.textContent);
  const main = document.getElementById('classification-main');
  const sub = document.getElementById('classification-sub');
  const secondary = document.getElementById('classification-secondary');
  const program = document.getElementById('classification-program');
  const programLabel = document.getElementById('classification-program-label');
  const status = document.getElementById('classification-choice-status');
  const fill = (select, rows, label) => {
    select.replaceChildren(new Option(label, ''));
    rows.forEach(row => select.add(new Option(row.unit_name ? `${row.unit_name} (${row.unit_code})` : `${row.work_group}${row.project_name ? ' / ' + row.project_name : ''} · ${row.plan_name} (${row.program_code})`, row.id)));
  };
  const activeUnits = data.units.filter(row => Number(row.active) === 1);
  fill(main, activeUnits.filter(row => row.unit_level === 'main'), 'เลือกหน่วยหลัก');
  const details = () => {
    const rows = data.programs.filter(row => Number(row.active) === 1 && String(row.responsible_unit_id) === secondary.value);
    const p = rows.find(row => String(row.id) === program.value);
    // Show shared planning details before the user chooses among several works.
    const value = getter => {
      if (p) return getter(p) || 'ไม่ระบุ';
      if (!rows.length) return '—';
      const values = rows.map(getter);
      return values.every(v => v === values[0]) ? (values[0] || 'ไม่ระบุ') : 'แสดงตามงบงานที่เลือก';
    };
    document.getElementById('classification-strategy').textContent = value(row => {
      const no = row.strategy_no || '';
      const name = row.strategy_name || '';
      return (!no || no === '-') && (!name || name === '-') ? '-' : `${no} ${name}`.trim();
    });
    document.getElementById('classification-plan').textContent = value(row => row.plan_name || '');
    document.getElementById('classification-activity').textContent = value(row => row.activity_name || '');
    document.getElementById('classification-project').textContent = value(row => row.work_group || '');
    const work = document.getElementById('classification-work');
    if (work) work.textContent = p ? (p.work_name || p.project_name || 'ไม่ระบุ') : (rows.length ? 'เลือกงบงานที่ต้องการรับงบ' : '—');
  };
  const programs = () => {
    const rows = data.programs.filter(row => Number(row.active) === 1 && String(row.responsible_unit_id) === secondary.value);
    fill(program, rows, secondary.value && !rows.length ? 'หน่วยนี้ยังไม่มีงบงานที่เปิดใช้งาน กรุณาให้ผู้ดูแลเชื่อมงบงาน' : 'เลือกงบงานย่อย / โครงการที่หน่วยรับผิดชอบ');
    const multiple = rows.length > 1;
    program.hidden = !multiple;
    if (programLabel) programLabel.hidden = !multiple;
    program.required = multiple;
    program.disabled = !rows.length;
    if (rows.length === 1) program.value = String(rows[0].id);
    if (status) status.textContent = !secondary.value ? 'เลือกหน่วยหลัก หน่วยย่อย และหน่วยรอง ระบบจะแสดงข้อมูลงบงานให้อัตโนมัติ' :
      (!rows.length ? 'หน่วยรองนี้ยังไม่ได้เชื่อมงบงานที่เปิดใช้งาน กรุณาให้ผู้ดูแลกำหนดก่อนสร้างบัญชีคุมงบประมาณ' :
        (multiple ? `หน่วยรองนี้รับผิดชอบ ${rows.length} งบงาน เลือกเฉพาะงบงานที่จะรับงบ ไม่ต้องกรอกยุทธศาสตร์ แผนงาน หรือกิจกรรม` : 'ระบบเลือกงบงานของหน่วยรองและเติมรายละเอียดให้อัตโนมัติแล้ว'));
    details();
  };
  const secondaries = () => { fill(secondary, activeUnits.filter(row => row.unit_level === 'secondary' && String(row.parent_unit_id) === sub.value), 'เลือกหน่วยรอง'); programs(); };
  main.addEventListener('change', () => {
    fill(sub, activeUnits.filter(row => row.unit_level === 'sub' && String(row.parent_unit_id) === main.value && (!data.owner || Number(row.id) === Number(data.owner))), 'เลือกหน่วยย่อย'); secondaries();
  });
  sub.addEventListener('change', secondaries); secondary.addEventListener('change', programs); program.addEventListener('change', details);
  programs();
  if (data.owner) {
    const owner = activeUnits.find(row => Number(row.id) === Number(data.owner));
    if (owner) { main.value = String(owner.parent_unit_id || ''); main.dispatchEvent(new Event('change')); sub.value = String(owner.id); secondaries(); }
  }
})();
