// FILE: modules/js/progress.js
// Implement: #1 Overdue highlight, #2 D+X badge, #4 auto-scroll+highlight after save, #5 Overdue card
// Fix: openInputModal() + modal can be used for NEW and EDIT
(function () {
  'use strict';

  const resolveApiPath = (filename) => {
    if (typeof getApiPath === 'function') return getApiPath(filename);
    const pathname = window.location.pathname || '';
    if (pathname.includes('/update/')) return '/update/' + filename;
    if (pathname.includes('/dashboard/')) return '/dashboard/' + filename;
    return '../' + filename;
  };
  const API = resolveApiPath('api_installations.php');
  const PER_PAGE = 10;

  let currentPage = 1;
  let totalPages = 1;
  let totalData = 0;

  // card mode flags
  let priorityOnly = false;
  let overdueOnly = false;

  // after-save focus
  let lastSavedId = null;

  let filters = { search: '', date: '', status: '', pop: '', tech: '' };

  let currentDetailId = null;
  let isNewMode = false; // ✅ NEW: input baru mode

  const SMART_INPUT_DEBOUNCE_MS = 500;
  let smartInputTimer = null;

  const $ = (id) => document.getElementById(id);

  function toastInfo(msg) {
    if (typeof Swal !== 'undefined' && Swal.mixin) {
      const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 1800,
        timerProgressBar: true,
        didOpen: () => {
          const swalContainer = document.querySelector('.swal2-container');
          if (swalContainer) swalContainer.style.zIndex = '10000';
        }
      });
      Toast.fire({ icon: 'info', title: msg });
    } else console.log('[INFO]', msg);
  }

  function showError(msg) {
    if (typeof Swal !== 'undefined') {
      fireSwal({
        title: 'Error',
        text: msg,
        icon: 'error'
      });
    } else {
      alert(msg);
    }
  }

  function showSuccess(msg) {
    if (typeof Swal !== 'undefined') {
      fireSwal({
        title: 'Sukses',
        text: msg,
        icon: 'success'
      });
    } else {
      alert(msg);
    }
  }

  function showLoading(msg) {
    if (typeof Swal !== 'undefined') {
      fireSwal({
        title: msg || 'Memproses...',
        didOpen: () => Swal.showLoading(),
        allowOutsideClick: false,
        allowEscapeKey: false
      });
    }
  }
  function hideLoading() {
    if (typeof Swal !== 'undefined') Swal.close();
  }

  // Helper untuk Swal.fire dengan z-index otomatis
  function fireSwal(config) {
    if (typeof Swal === 'undefined') return null;

    const originalDidOpen = config.didOpen;
    config.didOpen = (modal) => {
      const swalContainer = document.querySelector('.swal2-container');
      if (swalContainer) swalContainer.style.zIndex = '10000';
      if (typeof originalDidOpen === 'function') originalDidOpen(modal);
    };

    return Swal.fire(config);
  }

  function buildQuery(params) {
    const usp = new URLSearchParams();
    Object.keys(params || {}).forEach((k) => {
      const v = params[k];
      if (v === undefined || v === null || v === '') return;
      usp.set(k, String(v));
    });
    return usp.toString();
  }

  function escapeHtml(str) {
    return String(str ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  const changeFieldLabels = {
    customer_name: 'Nama',
    customer_phone: 'WA',
    address: 'Alamat'
  };

  function formatChangeRow(item) {
    const fieldLabel = changeFieldLabels[item.field_name] || item.field_name || '-';
    const oldValRaw = String(item.old_value ?? '').trim();
    const newValRaw = String(item.new_value ?? '').trim();
    const oldVal = oldValRaw ? escapeHtml(oldValRaw) : '-';
    const newVal = newValRaw ? escapeHtml(newValRaw) : '-';
    const when = item.changed_at || '-';
    const actor = item.changed_by || '-';
    const role = item.changed_by_role || '-';
    const source = item.source || '-';

    return `
      <div class="border-b border-slate-100 pb-2">
        <div class="text-[10px] text-slate-400 mb-1">${escapeHtml(when)} - ${escapeHtml(actor)} (${escapeHtml(role)}) via ${escapeHtml(source)}</div>
        <div class="text-xs text-slate-700">
          <span class="font-bold">${escapeHtml(fieldLabel)}</span>:
          <span class="text-slate-500">${oldVal}</span>
          <span class="text-slate-400 px-1">-></span>
          <span class="text-slate-800 font-bold">${newVal}</span>
        </div>
      </div>
    `;
  }

  async function loadChangeHistory(id) {
    const box = $('change-history-container');
    if (!box) return;
    box.innerHTML = '<span class="italic text-slate-300">Memuat...</span>';
    try {
      const result = await apiGet(buildQuery({ action: 'get_changes', id: id }));
      if (result.status !== 'success') throw new Error(result.msg || 'Gagal ambil data');
      const list = Array.isArray(result.data) ? result.data : [];
      if (list.length === 0) {
        box.innerHTML = '<span class="italic text-slate-300">Belum ada perubahan.</span>';
        return;
      }
      box.innerHTML = list.map(formatChangeRow).join('');
    } catch (e) {
      box.innerHTML = '<span class="italic text-red-400">Gagal memuat riwayat perubahan.</span>';
    }
  }

  function formatDateShort(dateStr) {
    if (!dateStr) return '-';
    const m = String(dateStr).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return String(dateStr);
    const yyyy = Number(m[1]);
    const mm = Number(m[2]);
    const dd = Number(m[3]);
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    return `${dd} ${months[mm - 1] || ''} ${yyyy}`.trim();
  }

  function toDatetimeLocal(dbDatetime) {
    if (!dbDatetime) return '';
    const s = String(dbDatetime).trim();
    const m = s.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})/);
    if (!m) return '';
    return `${m[1]}T${m[2]}`;
  }

  function normalizeCoords(raw) {
    if (!raw) return null;
    const s = String(raw).trim().replace(/\s+/g, ',');
    const parts = s.split(',').map(x => x.trim()).filter(Boolean);
    if (parts.length < 2) return null;
    const lat = parseFloat(parts[0]);
    const lng = parseFloat(parts[1]);
    if (Number.isNaN(lat) || Number.isNaN(lng)) return null;
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;
    return { lat, lng };
  }

  function setSmartInputStatus(message, tone) {
    const el = $('progress-smart-status');
    if (!el) return;
    if (!message) {
      el.textContent = '';
      el.className = 'text-[10px] text-slate-400 dark:text-slate-500 min-h-[12px]';
      return;
    }
    const color = tone === 'error'
      ? 'text-red-500 dark:text-red-400'
      : tone === 'success'
        ? 'text-green-600 dark:text-green-400'
        : 'text-slate-400 dark:text-slate-500';
    el.className = `text-[10px] ${color} min-h-[12px]`;
    el.textContent = message;
  }

  function formatRupiahValue(raw) {
    const num = parseInt(raw || 0, 10);
    if (Number.isNaN(num) || num <= 0) return '';
    return 'Rp. ' + num.toLocaleString('id-ID');
  }

  function setSmartInputSummary(labels, warnings = []) {
    const el = $('progress-smart-summary');
    if (!el) return;
    if ((!labels || labels.length === 0) && (!warnings || warnings.length === 0)) {
      el.textContent = '';
      return;
    }
    const parts = [];
    if (labels && labels.length > 0) parts.push(`Terbaca: ${labels.join(', ')}`);
    if (warnings && warnings.length > 0) parts.push(warnings.join(' | '));
    el.textContent = parts.join(' | ');
  }

  function setSmartInputReady(message, tone = 'idle') {
    const el = $('progress-smart-ready');
    if (!el) return;
    if (!message) {
      el.textContent = '';
      el.className = 'text-[10px] font-bold text-slate-400 dark:text-slate-500';
      return;
    }
    const color = tone === 'ready'
      ? 'text-emerald-600 dark:text-emerald-400'
      : tone === 'warn'
        ? 'text-amber-600 dark:text-amber-400'
        : tone === 'error'
          ? 'text-red-500 dark:text-red-400'
          : 'text-slate-400 dark:text-slate-500';
    el.className = `text-[10px] font-bold ${color}`;
    el.textContent = message;
  }

  function renderSmartInputReview(parsed, options = {}) {
    const wrap = $('progress-smart-review');
    if (!wrap) return;
    if (!parsed) {
      wrap.innerHTML = '<div class="col-span-2 text-slate-400 dark:text-slate-500 italic">Tempel data untuk melihat review.</div>';
      return;
    }
    const phoneDigits = String(options.phoneDigits || '').trim();
    const priceValue = options.priceValue || normalizePriceValue(parsed.price);
    const salesList = Array.isArray(parsed.sales) ? parsed.sales.filter(v => String(v || '').trim() !== '') : [];
    const techListLocal = Array.isArray(parsed.techs) ? parsed.techs.filter(v => String(v || '').trim() !== '') : [];
    const fields = [
      { label: 'Nama', value: String(parsed.name || '').trim(), required: true },
      { label: 'WA', value: phoneDigits, required: true },
      { label: 'Alamat', value: String(parsed.address || '').trim(), required: true },
      { label: 'POP', value: String(parsed.pop || '').trim(), required: true },
      { label: 'Paket', value: String(parsed.plan || '').trim(), required: false },
      { label: 'Harga', value: priceValue ? formatRupiahValue(priceValue) : '', required: false },
      { label: 'Sales', value: salesList.join(', '), required: false },
      { label: 'Teknisi', value: techListLocal.join(', '), required: false },
      { label: 'Koordinat', value: String(options.coordsValue || '').trim(), required: false },
      { label: 'Tanggal', value: String(options.dateValue || '').trim(), required: false }
    ];
    const rows = fields.filter(item => item.required || item.value);
    if (rows.length === 0) {
      wrap.innerHTML = '<div class="col-span-2 text-slate-400 dark:text-slate-500 italic">Belum ada data.</div>';
      return;
    }
    wrap.innerHTML = rows.map(item => `
      <div class="text-[10px] text-slate-500 dark:text-slate-400 font-bold uppercase">${escapeHtml(item.label)}</div>
      <div class="font-medium">${escapeHtml(item.value || '-')}</div>
    `).join('');
  }

  function resetSmartInputUI() {
    const input = $('progress-smart-input');
    if (input) input.value = '';
    setSmartInputStatus('');
    setSmartInputSummary([]);
    setSmartInputReady('');
    renderSmartInputReview(null);
  }

  function toggleSmartInputPanel(show) {
    const panel = $('progress-smart-panel');
    if (!panel) return;
    if (show) panel.classList.remove('hidden');
    else panel.classList.add('hidden');
  }

  function setupSmartInputAuto() {
    const input = $('progress-smart-input');
    if (!input || input.dataset.bound === '1') return;
    input.dataset.bound = '1';
    input.addEventListener('input', () => {
      if (smartInputTimer) clearTimeout(smartInputTimer);
      smartInputTimer = setTimeout(() => {
        applyProgressSmartInput({ silent: true });
      }, SMART_INPUT_DEBOUNCE_MS);
    });
    input.addEventListener('change', () => {
      applyProgressSmartInput({ silent: true });
    });
  }

  function normalizeName(value) {
    return String(value || '').trim().toLowerCase();
  }

  function splitNames(raw) {
    return String(raw || '')
      .split(',')
      .map(s => s.replace(/\(.*?\)/g, '').trim())
      .filter(Boolean);
  }

  function normalizePriceValue(raw) {
    const digits = String(raw || '').replace(/\D/g, '');
    if (!digits) return '';
    let num = parseInt(digits, 10);
    if (digits.length <= 3) num = num * 1000;
    return String(num);
  }

  function normalizeDateInput(raw) {
    const s = String(raw || '').trim();
    if (!s) return '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
    const m = s.match(/^(\d{2})[\/.\-](\d{2})[\/.\-](\d{4})$/);
    if (m) return `${m[3]}-${m[2]}-${m[1]}`;
    return '';
  }

  function findOptionMatch(selectEl, value) {
    const needle = normalizeName(value);
    if (!selectEl || !needle) return '';
    const options = Array.from(selectEl.options || []);
    const exact = options.find(o => normalizeName(o.value) === needle || normalizeName(o.textContent) === needle);
    if (exact) return exact.value;
    const starts = options.filter(o => {
      const v = normalizeName(o.value);
      const t = normalizeName(o.textContent);
      return v.startsWith(needle) || t.startsWith(needle);
    });
    if (starts.length === 1) return starts[0].value;
    const includes = options.filter(o => {
      const v = normalizeName(o.value);
      const t = normalizeName(o.textContent);
      return v.includes(needle) || t.includes(needle);
    });
    if (includes.length === 1) return includes[0].value;
    return '';
  }

  function parseSmartInput(raw) {
    const result = {
      name: '',
      phone: '',
      address: '',
      pop: '',
      plan: '',
      price: '',
      sales: [],
      techs: [],
      coords: '',
      date: ''
    };
    const freeLines = [];
    const keySet = new Set([
      'sales', 'sales1', 'sales2', 'sales3',
      'pop', 'paket', 'plan', 'planname',
      'harga', 'biaya', 'price',
      'nama', 'name',
      'wa', 'whatsapp', 'hp', 'telp', 'phone',
      'nomorwa', 'nomorwhatsapp', 'nomorhp', 'nomortelp', 'nomorphone',
      'nowa', 'nohp',
      'alamat', 'address',
      'maps', 'lokasi',
      'tanggal', 'jadwal', 'install', 'installationdate'
    ]);

    const isLabelKey = (keyClean) => {
      if (!keyClean) return false;
      if (keyClean.startsWith('teknisi') || keyClean.startsWith('psb')) return true;
      if (keyClean.startsWith('koordinat') || keyClean.startsWith('coord')) return true;
      return keySet.has(keyClean);
    };

    const parseLabel = (text) => {
      let m = text.match(/^([^:=]{1,30})\s*[:=]\s*(.+)$/);
      if (!m) m = text.match(/^([a-zA-Z0-9.\s]{2,30})\s+(.+)$/);
      if (!m) return null;
      const keyRaw = m[1].trim().toLowerCase();
      const key = keyRaw.replace(/\s+/g, '');
      const keyClean = key.replace(/[^a-z0-9]/g, '');
      if (!isLabelKey(keyClean)) return null;
      const val = m[2].trim();
      if (!val) return null;
      return { keyClean, val };
    };

    const lines = [];
    String(raw || '').split(/\r?\n/).forEach(line => {
      const l = String(line || '').trim();
      if (!l) return;
      if (l.includes(',')) {
        const parts = l.split(',').map(p => p.trim()).filter(Boolean);
        const hasLabel = parts.some(p => parseLabel(p));
        if (hasLabel) {
          parts.forEach(p => { if (p) lines.push(p); });
          return;
        }
      }
      lines.push(l);
    });

    lines.forEach(l => {
      const parsed = parseLabel(l);
      if (parsed) {
        const keyClean = parsed.keyClean;
        const val = parsed.val;
        if (keyClean.startsWith('teknisi') || keyClean.startsWith('psb')) {
          result.techs = result.techs.concat(splitNames(val));
        } else if (keyClean === 'sales' || keyClean === 'sales1') {
          const sales = splitNames(val);
          sales.forEach((name, idx) => { if (idx < 3) result.sales[idx] = name; });
        } else if (keyClean === 'sales2') result.sales[1] = val;
        else if (keyClean === 'sales3') result.sales[2] = val;
        else if (keyClean === 'pop') result.pop = val;
        else if (keyClean === 'paket' || keyClean === 'plan' || keyClean === 'planname') result.plan = val;
        else if (keyClean === 'harga' || keyClean === 'biaya' || keyClean === 'price') result.price = val;
        else if (keyClean === 'nama' || keyClean === 'name') result.name = val;
        else if (keyClean === 'wa' || keyClean === 'whatsapp' || keyClean === 'hp' || keyClean === 'telp' || keyClean === 'phone' || keyClean === 'nomorwa' || keyClean === 'nomorwhatsapp' || keyClean === 'nomorhp' || keyClean === 'nomortelp' || keyClean === 'nomorphone' || keyClean === 'nowa' || keyClean === 'nohp') result.phone = val;
        else if (keyClean === 'alamat' || keyClean === 'address') result.address = val;
        else if (keyClean.startsWith('koordinat') || keyClean.startsWith('coord') || keyClean === 'maps' || keyClean === 'lokasi') result.coords = val;
        else if (keyClean === 'tanggal' || keyClean === 'jadwal' || keyClean === 'install' || keyClean === 'installationdate') result.date = val;
        else freeLines.push(l);
        return;
      }
      freeLines.push(l);
    });

    const leftovers = freeLines.slice();
    for (let i = 0; i < leftovers.length;) {
      const line = leftovers[i];
      const coord = normalizeCoords(line);
      if (!result.coords && coord) {
        result.coords = `${coord.lat},${coord.lng}`;
        leftovers.splice(i, 1);
        continue;
      }
      const digits = line.replace(/\D/g, '');
      const hasLetters = /[a-zA-Z]/.test(line);
      if (!result.phone && digits.length >= 8) {
        result.phone = line;
        leftovers.splice(i, 1);
        continue;
      }
      if (!result.price && !hasLetters && digits.length > 0) {
        result.price = line;
        leftovers.splice(i, 1);
        continue;
      }
      i += 1;
    }

    if (!result.name) {
      const idx = leftovers.findIndex(l => /[a-zA-Z]/.test(l));
      if (idx >= 0) result.name = leftovers.splice(idx, 1)[0];
    }
    if (!result.address && leftovers.length) result.address = leftovers.shift();
    if (!result.sales[0] && leftovers.length) result.sales[0] = leftovers.shift();
    if (!result.pop && leftovers.length) result.pop = leftovers.shift();

    return result;
  }

  function applyProgressSmartInput(options = {}) {
    const input = $('progress-smart-input');
    if (!input) return;
    const raw = input.value || '';
    if (!raw.trim()) {
      setSmartInputSummary([]);
      setSmartInputReady('');
      renderSmartInputReview(null);
      if (!options.silent) setSmartInputStatus('Smart input kosong.', 'error');
      else setSmartInputStatus('');
      return;
    }

    const parsed = parseSmartInput(raw);
    const filled = [];
    const applied = [];
    const warnings = [];
    const phoneDigits = String(parsed.phone || '').replace(/\D/g, '');
    const coordParsed = parsed.coords ? normalizeCoords(parsed.coords) : null;
    const coordsValue = coordParsed ? `${coordParsed.lat},${coordParsed.lng}` : '';
    const dateValue = parsed.date ? normalizeDateInput(parsed.date) : '';
    const priceValue = normalizePriceValue(parsed.price);

    const formatApplied = (label, value) => {
      const clean = String(value ?? '').trim();
      if (!clean) return;
      const trimmed = clean.length > 40 ? clean.slice(0, 37) + '...' : clean;
      applied.push(`${label}=${trimmed}`);
    };

    const setInputValue = (id, val) => {
      const el = $(id);
      const clean = String(val ?? '').trim();
      if (!el || !clean) return '';
      el.value = clean;
      return clean;
    };
    const setSelectMatch = (id, val, label) => {
      const el = $(id);
      if (!el || !val) return '';
      const match = findOptionMatch(el, val);
      if (match) {
        el.value = match;
        const selected = el.options[el.selectedIndex]?.textContent || match;
        return selected;
      }

      // PERBAIKAN: Jika tidak ketemu, tambahkan opsi manual
      const opt = document.createElement('option');
      opt.value = val;
      opt.innerText = val + ' (Manual)';
      opt.selected = true;
      el.appendChild(opt);

      // Tetap return val agar dianggap "Terisi"
      return val;
    };

    if (parsed.name) {
      const v = setInputValue('edit_name', parsed.name);
      if (v) { filled.push('Nama'); formatApplied('Nama', v); }
    }
    if (parsed.phone) {
      const v = setInputValue('edit_phone', parsed.phone);
      if (v) { filled.push('WA'); formatApplied('WA', v); }
    }
    if (parsed.address) {
      const v = setInputValue('edit_address', parsed.address);
      if (v) { filled.push('Alamat'); formatApplied('Alamat', v); }
    }

    if (parsed.coords) {
      if (coordsValue) {
        const v = setInputValue('edit_coordinates', coordsValue);
        if (v) { filled.push('Koordinat'); formatApplied('Koordinat', v); }
      } else {
        warnings.push(`Koordinat tidak valid: ${parsed.coords}`);
      }
    }

    if (parsed.plan) {
      const v = setInputValue('edit_plan', parsed.plan);
      if (v) { filled.push('Paket'); formatApplied('Paket', v); }
    }
    if (parsed.price) {
      if (priceValue) {
        const v = setInputValue('edit_price', priceValue);
        if (v) { filled.push('Harga'); formatApplied('Harga', v); }
      }
    }
    if (parsed.date) {
      if (dateValue) {
        const v = setInputValue('edit_date', dateValue);
        if (v) { filled.push('Tanggal'); formatApplied('Tanggal', v); }
      } else {
        warnings.push(`Tanggal tidak valid: ${parsed.date}`);
      }
    }

    if (parsed.pop) {
      const v = setSelectMatch('edit_pop', parsed.pop, 'POP');
      if (v) { filled.push('POP'); formatApplied('POP', v); }
    }

    if (parsed.sales[0]) {
      const v = setInputValue('edit_sales_1', parsed.sales[0]);
      if (v) { filled.push('Sales 1'); formatApplied('Sales 1', v); }
    }
    if (parsed.sales[1]) {
      const v = setInputValue('edit_sales_2', parsed.sales[1]);
      if (v) { filled.push('Sales 2'); formatApplied('Sales 2', v); }
    }
    if (parsed.sales[2]) {
      const v = setInputValue('edit_sales_3', parsed.sales[2]);
      if (v) { filled.push('Sales 3'); formatApplied('Sales 3', v); }
    }

    const techIds = ['edit_technician', 'edit_tech_2', 'edit_tech_3', 'edit_tech_4'];
    parsed.techs.forEach((name, idx) => {
      if (idx >= techIds.length) return;
      const v = setSelectMatch(techIds[idx], name, 'Teknisi');
      if (v) {
        filled.push(`Teknisi ${idx + 1}`);
        formatApplied(`Teknisi ${idx + 1}`, v);
      }
    });

    const summaryLabels = [];
    if (parsed.name) summaryLabels.push('Nama');
    if (phoneDigits) summaryLabels.push('WA');
    if (parsed.address) summaryLabels.push('Alamat');
    if (parsed.pop) summaryLabels.push('POP');
    if (parsed.plan) summaryLabels.push('Paket');
    if (priceValue) summaryLabels.push('Harga');
    if (Array.isArray(parsed.sales) && parsed.sales.some(v => String(v || '').trim() !== '')) summaryLabels.push('Sales');
    if (Array.isArray(parsed.techs) && parsed.techs.some(v => String(v || '').trim() !== '')) summaryLabels.push('Teknisi');
    if (coordsValue) summaryLabels.push('Koordinat');
    if (dateValue) summaryLabels.push('Tanggal');

    setSmartInputSummary(summaryLabels, warnings);
    renderSmartInputReview(parsed, { phoneDigits, coordsValue, dateValue, priceValue });

    const hasRequired = !!(parsed.name && phoneDigits.length >= 8 && parsed.address && parsed.pop);
    if (!hasRequired) setSmartInputReady('Belum lengkap', 'error');
    else if (warnings.length) setSmartInputReady('Periksa data', 'warn');
    else setSmartInputReady('Siap disimpan', 'ready');

    if (warnings.length) {
      setSmartInputStatus(warnings.join(' | '), 'error');
      return;
    }
    if (!filled.length) {
      if (!options.silent) setSmartInputStatus('Tidak ada data yang cocok.', 'error');
      return;
    }
    const detailText = applied.length ? `Terisi: ${applied.join(' | ')}` : `Terisi: ${filled.join(', ')}`;
    setSmartInputStatus(detailText, 'success');
  }

  window.applyProgressSmartInput = function () {
    applyProgressSmartInput({ silent: false });
  };

  function safeSetText(id, text) {
    const el = $(id);
    if (el) el.innerText = String(text ?? '');
  }

  function ymdToday() {
    const d = new Date();
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
  }

  // days from installation_date to today
  function daysSinceInstall(installDateYmd) {
    if (!installDateYmd) return null;
    const m = String(installDateYmd).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return null;
    const d0 = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    const now = new Date();
    const d1 = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const diffMs = d1.getTime() - d0.getTime();
    return Math.floor(diffMs / (1000 * 60 * 60 * 24));
  }

  function isStatusClosed(status) {
    return status === 'Selesai' || status === 'Batal';
  }

  // =========================
  // DATE HELPER FUNCTIONS
  // =========================
  function getDateRangeFromPreset(preset) {
    const today = new Date();
    const startDate = new Date(today);
    const endDate = new Date(today);

    switch (preset) {
      case 'today':
        break; // startDate = endDate = today
      case 'yesterday':
        startDate.setDate(today.getDate() - 1);
        endDate.setDate(today.getDate() - 1);
        break;
      case 'this_week':
        startDate.setDate(today.getDate() - today.getDay());
        break;
      case 'this_month':
        startDate.setDate(1);
        break;
      case 'this_year':
        startDate.setMonth(0);
        startDate.setDate(1);
        break;
      case 'last_year':
        startDate.setFullYear(today.getFullYear() - 1);
        startDate.setMonth(0);
        startDate.setDate(1);
        endDate.setFullYear(today.getFullYear() - 1);
        endDate.setMonth(11);
        endDate.setDate(31);
        break;
      case 'last_7':
        startDate.setDate(today.getDate() - 7);
        break;
      case 'last_30':
        startDate.setDate(today.getDate() - 30);
        break;
      default:
        return null;
    }

    return {
      from: formatYMD(startDate),
      to: formatYMD(endDate)
    };
  }

  function formatYMD(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  function applyDatePreset(preset) {
    const presetEl = $('filter-date-preset');
    const customEl = $('filter-date-custom');
    const fromEl = $('filter-date-from');
    const toEl = $('filter-date-to');

    if (preset === 'custom') {
      // Show custom range inputs
      if (customEl) customEl.classList.remove('hidden');
    } else {
      // Hide custom range inputs
      if (customEl) customEl.classList.add('hidden');

      // Apply preset date range
      if (preset && preset !== '') {
        const range = getDateRangeFromPreset(preset);
        if (range) {
          if (fromEl) fromEl.value = range.from;
          if (toEl) toEl.value = range.to;
        }
      } else {
        // No filter
        if (fromEl) fromEl.value = '';
        if (toEl) toEl.value = '';
      }
    }
  }

  // =========================
  // FILTER BY CARD
  // =========================
  window.filterByCard = function (type, value) {
    const statusSelect = $('filter-status-opt');
    const presetEl = $('filter-date-preset');

    // reset all card flags
    priorityOnly = false;
    overdueOnly = false;

    if (type === 'status') {
      if (statusSelect) statusSelect.value = value || '';
    } else if (type === 'today_done') {
      if (statusSelect) statusSelect.value = 'Selesai';
      if (presetEl) {
        presetEl.value = 'today';
        applyDatePreset('today');
      }
    } else if (type === 'priority') {
      priorityOnly = true;
      if (statusSelect) statusSelect.value = '';
      toastInfo('Menampilkan data Prioritas');
    } else if (type === 'overdue') {
      overdueOnly = true;
      if (statusSelect) statusSelect.value = '';
      toastInfo('Menampilkan data Overdue');
    }

    loadProgressData(1);
  };

  function updateStats(summary) {
    safeSetText('stat-prio', summary.priority ?? 0);
    safeSetText('stat-overdue', summary.overdue ?? 0);
    safeSetText('stat-new', summary.Baru ?? 0);
    safeSetText('stat-survey', summary.Survey ?? 0);
    safeSetText('stat-process', summary.Proses ?? 0);
    safeSetText('stat-pending', summary.Pending ?? 0);
    safeSetText('stat-req-cancel', summary.Req_Batal ?? 0);
    safeSetText('stat-cancel', summary.Batal ?? 0);
    safeSetText('stat-today-done', summary.today_done ?? 0);
  }

  // =========================
  // FILTER UI ACTIONS
  // =========================
  window.applyDatePresetChange = function () {
    const presetEl = $('filter-date-preset');
    const preset = presetEl?.value || '';
    applyDatePreset(preset);
    window.applyFilters(); // Trigger load after preset change
  };

  window.applyFilters = function () {
    priorityOnly = false;
    overdueOnly = false;
    loadProgressData(1);
  };

  window.resetFilters = function () {
    ['search-progress', 'filter-date-preset', 'filter-status-opt', 'filter-pop-opt', 'filter-tech-opt']
      .forEach(id => { const el = $(id); if (el) el.value = ''; });

    // Reset custom date range
    const customEl = $('filter-date-custom');
    const fromEl = $('filter-date-from');
    const toEl = $('filter-date-to');
    if (customEl) customEl.classList.add('hidden');
    if (fromEl) fromEl.value = '';
    if (toEl) toEl.value = '';

    priorityOnly = false;
    overdueOnly = false;

    // Note: filtersLoaded stays true to keep dropdown values cached
    loadProgressData(1);
  };

  window.nextPage = function () {
    if (currentPage < totalPages) loadProgressData(currentPage + 1);
  };
  window.prevPage = function () {
    if (currentPage > 1) loadProgressData(currentPage - 1);
  };

  // =========================
  // API CALLS
  // =========================
  async function apiGet(qs) {
    const res = await fetch(API + '?' + qs, { credentials: 'same-origin' });
    const json = await res.json().catch(() => null);
    if (!json) throw new Error('Response JSON tidak valid');
    return json;
  }

  async function apiPostJSON(payload) {
    const res = await fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload || {})
    });
    const json = await res.json().catch(() => null);
    if (!json) throw new Error('Response JSON tidak valid');
    return json;
  }

  // =========================
  // LOAD LIST
  // =========================
  window.loadProgressData = async function (page = 1) {
    currentPage = page;

    filters.search = ($('search-progress')?.value || '').trim();
    filters.status = ($('filter-status-opt')?.value || '').trim();
    filters.pop = ($('filter-pop-opt')?.value || '').trim();
    filters.tech = ($('filter-tech-opt')?.value || '').trim();

    // Handle date filtering with new date preset logic
    const presetEl = $('filter-date-preset');
    const preset = presetEl?.value || '';

    let dateFrom = '';
    let dateTo = '';

    if (preset === 'custom') {
      // Custom date range
      dateFrom = ($('filter-date-from')?.value || '').trim();
      dateTo = ($('filter-date-to')?.value || '').trim();
      filters.date = dateFrom && dateTo ? `${dateFrom},${dateTo}` : '';
    } else if (preset && preset !== '') {
      // Preset date range
      const range = getDateRangeFromPreset(preset);
      if (range) {
        dateFrom = range.from;
        dateTo = range.to;
        filters.date = `${dateFrom},${dateTo}`;
      }
    } else {
      // No date filter
      filters.date = '';
    }

    const tbody = $('progress-table-body');
    if (tbody) {
      tbody.innerHTML = `
        <tr>
          <td colspan="7" class="px-6 py-10 text-center">
            <div class="flex justify-center">
              <div class="w-8 h-8 border-4 border-slate-200 border-t-blue-600 rounded-full animate-spin"></div>
            </div>
            <p class="mt-2 text-xs text-slate-400">Sedang memuat data...</p>
          </td>
        </tr>
      `;
    }

    try {
      const queryParams = {
        action: 'get_list',
        page: currentPage,
        per_page: PER_PAGE,
        search: filters.search,
        status: filters.status,
        pop: filters.pop,
        tech: filters.tech,
        priority_only: priorityOnly ? 1 : 0,
        overdue_only: overdueOnly ? 1 : 0
      };

      // Handle date filter - API expects date_from and date_to, not date
      if (dateFrom || dateTo) {
        if (dateFrom) queryParams.date_from = dateFrom;
        if (dateTo) queryParams.date_to = dateTo;
      }

      const qs = buildQuery(queryParams);

      const result = await apiGet(qs);
      if (result.status !== 'success') throw new Error(result.msg || 'Gagal memuat data');

      const data = Array.isArray(result.data) ? result.data : [];
      totalData = Number(result.total || 0);
      totalPages = Number(result.total_pages || 1);

      if (currentPage > totalPages) currentPage = totalPages;

      if (result.summary) updateStats(result.summary);

      renderTable(data);

      const start = totalData === 0 ? 0 : (currentPage - 1) * PER_PAGE + 1;
      const end = Math.min(totalData, (currentPage - 1) * PER_PAGE + data.length);
      renderPagination(start, end, totalData);

      await populateFiltersOnce();

      if (lastSavedId) {
        focusRowById(lastSavedId);
        lastSavedId = null;
      }

    } catch (err) {
      console.error(err);
      if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="px-6 py-4 text-center text-red-500 text-xs">${err.message}</td></tr>`;
    }
  };

  function renderPagination(start, end, total) {
    safeSetText('page-start', start);
    safeSetText('page-end', end);
    safeSetText('total-data', total);
    safeSetText('current-page', currentPage);
    safeSetText('total-pages', totalPages);

    const btnPrev = $('btn-prev');
    const btnNext = $('btn-next');
    if (btnPrev) btnPrev.disabled = (currentPage <= 1);
    if (btnNext) btnNext.disabled = (currentPage >= totalPages);
  }

  function focusRowById(id) {
    const tbody = $('progress-table-body');
    if (!tbody) return;

    const row = tbody.querySelector(`tr[data-id="${CSS.escape(String(id))}"]`);
    if (!row) return;

    row.scrollIntoView({ behavior: 'smooth', block: 'center' });

    row.classList.add('ring-2', 'ring-blue-400');
    row.style.transition = 'background-color 300ms ease';
    row.style.backgroundColor = 'rgba(59,130,246,0.10)';

    setTimeout(() => {
      row.classList.remove('ring-2', 'ring-blue-400');
      row.style.backgroundColor = '';
    }, 1800);
  }

  function renderTable(data) {
    const tbody = $('progress-table-body');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!data.length) {
      tbody.innerHTML = `<tr><td colspan="7" class="px-6 py-10 text-center text-slate-400 italic text-xs">Tidak ada data ditemukan.</td></tr>`;
      return;
    }

    data.forEach(row => {
      const status = row.status || '-';

      const days = daysSinceInstall(row.installation_date);
      const showDayBadge = typeof days === 'number';
      const badgeDX = showDayBadge
        ? `<span class="ml-2 px-2 py-0.5 rounded-full text-[10px] font-bold ${days > 0 ? 'bg-slate-200 text-slate-700' : 'bg-blue-100 text-blue-700'}">D+${Math.max(0, days)}</span>`
        : '';

      const overdue = (typeof days === 'number' && days > 0 && !isStatusClosed(status));
      const overdueTag = overdue
        ? `<span class="ml-2 px-2 py-0.5 rounded-full text-[10px] font-black bg-red-100 text-red-700">⏰ OVERDUE +${days}d</span>`
        : '';

      let badgeColor = 'bg-slate-100 text-slate-600';
      if (status === 'Baru') badgeColor = 'bg-blue-50 text-blue-600 border border-blue-100';
      if (status === 'Survey') badgeColor = 'bg-purple-50 text-purple-600 border border-purple-100';
      if (status === 'Proses') badgeColor = 'bg-indigo-50 text-indigo-600 border border-indigo-100';
      if (status === 'Selesai') badgeColor = 'bg-green-50 text-green-600 border border-green-100';
      if (status === 'Pending') badgeColor = 'bg-orange-50 text-orange-600 border border-orange-100';
      if (status === 'Batal') badgeColor = 'bg-red-50 text-red-600 border border-red-100';
      if (status === 'Req_Batal') badgeColor = 'bg-red-100 text-red-700 border border-red-200 animate-pulse';

      const starClass = (String(row.is_priority) === '1') ? 'text-yellow-400 hover:scale-110' : 'text-slate-200 hover:text-yellow-400';

      const tr = document.createElement('tr');
      tr.dataset.id = String(row.id);
      tr.className =
        'transition border-b border-slate-50 dark:border-white/5 cursor-pointer group ' +
        (overdue ? 'bg-red-50/30 dark:bg-red-900/10 border-l-4 border-l-red-500' : 'hover:bg-blue-50/50 dark:hover:bg-slate-800/50');

      tr.onclick = function () { openDetailModal(row.id); };

      tr.innerHTML = `
        <td class="px-4 py-3 text-center">
          <button onclick="event.stopPropagation(); window.togglePriority(${row.id}, ${row.is_priority})" class="transition-transform ${starClass} p-2">★</button>
        </td>
        <td class="px-6 py-3">
          <div class="flex items-center flex-wrap gap-1">
            <div class="font-mono text-[10px] text-blue-500 font-bold group-hover:text-blue-700 transition">#${escapeHtml(row.ticket_id || '-')}</div>
            ${badgeDX}
            ${overdueTag}
          </div>
          <div class="text-[10px] text-slate-400">${formatDateShort(row.installation_date)}</div>
        </td>
        <td class="px-6 py-3">
          <div class="font-bold text-slate-700 dark:text-slate-200 text-xs">${escapeHtml(row.customer_name || '-')}</div>
          <div class="text-[10px] text-slate-400 truncate max-w-[150px]">${escapeHtml(row.address || '-')}</div>
        </td>
        <td class="px-6 py-3 text-xs text-slate-600 dark:text-slate-300">
          <span class="px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-700 font-bold text-[10px]">${escapeHtml(row.pop || 'NON-AREA')}</span>
        </td>
        <td class="px-6 py-3 text-xs">
          ${row.technician ? `<div class="flex items-center gap-1"><div class="w-2 h-2 rounded-full bg-green-500"></div><span>${escapeHtml(row.technician)}</span></div>` : '<span class="text-slate-300 italic">Belum assign</span>'}
        </td>
        <td class="px-6 py-3">
          <span class="px-2.5 py-1 rounded-full text-[10px] font-bold ${badgeColor}">${escapeHtml(status)}</span>
        </td>
      `;
      tbody.appendChild(tr);
    });
  }

  // =========================
  // Populate filters once
  // =========================
  let filtersLoaded = false;
  async function populateFiltersOnce() {
    if (filtersLoaded) return;
    const popSelect = $('filter-pop-opt');
    const techSelect = $('filter-tech-opt');

    try {
      if (popSelect && popSelect.options.length <= 1) {
        const pops = await apiGet(buildQuery({ action: 'get_pops' }));
        if (Array.isArray(pops)) {
          pops.forEach(p => {
            if (!p) return;
            const opt = document.createElement('option');
            opt.value = p; opt.innerText = p;
            popSelect.appendChild(opt);
          });
        }
      }

      if (techSelect && techSelect.options.length <= 1) {
        const techs = await apiGet(buildQuery({ action: 'get_technicians' }));
        if (Array.isArray(techs)) {
          techs.forEach(t => {
            if (!t) return;
            const opt = document.createElement('option');
            opt.value = t; opt.innerText = t;
            techSelect.appendChild(opt);
          });
        }
      }

      filtersLoaded = true;
    } catch (e) {
      console.warn('populateFiltersOnce failed', e);
    }
  }

  // =========================
  // MODAL OPEN/CLOSE
  // =========================
  window.openDetailModal = openDetailModal;

  async function openDetailModal(id) {
    isNewMode = false;
    currentDetailId = id;

    $('detail-modal')?.classList.remove('hidden');
    if ($('modal-title-text')) $('modal-title-text').innerText = 'Detail Data';

    toggleSmartInputPanel(false);
    resetSmartInputUI();

    // show delete in edit mode
    const delBtn = document.querySelector('[onclick="window.deleteData()"]');
    if (delBtn) delBtn.classList.remove('hidden');

    try {
      const result = await apiGet(buildQuery({ action: 'get_one', id: id }));
      if (result.status !== 'success' || !result.data) throw new Error(result.msg || 'Data tidak ditemukan');
      await fillForm(result.data);
    } catch (e) {
      console.warn(e);
      showError(e.message || 'Gagal ambil detail data');
    }
  }

  // ✅ NEW: openInputModal (input baru)
  window.openInputModal = async function () {
    isNewMode = true;
    currentDetailId = null;

    $('detail-modal')?.classList.remove('hidden');
    if ($('modal-title-text')) $('modal-title-text').innerText = 'Input Baru';
    safeSetText('modal-ticket-id', '#NEW');

    toggleSmartInputPanel(true);
    resetSmartInputUI();
    setupSmartInputAuto();

    // hide delete in new mode
    const delBtn = document.querySelector('[onclick="window.deleteData()"]');
    if (delBtn) delBtn.classList.add('hidden');

    // reset form + set defaults
    const form = $('form-edit-install');
    if (form) form.reset();

    // clear hidden id
    const setVal = (id, val) => { const el = $(id); if (el) el.value = (val ?? ''); };
    setVal('edit_id', '');
    setVal('edit_ticket_id', '');
    setVal('edit_created_at', '');
    setVal('edit_finished_at', '');
    setVal('edit_notes_old', '');
    if ($('edit_notes')) $('edit_notes').value = '';
    if ($('edit_priority')) $('edit_priority').checked = false;

    // defaults
    if ($('edit_date')) $('edit_date').value = ymdToday();
    if ($('edit_status')) $('edit_status').value = 'Baru';

    // clear history
    const historyBox = $('history-container');
    if (historyBox) historyBox.innerHTML = '<span class="italic text-slate-300">Belum ada riwayat.</span>';
    const changeBox = $('change-history-container');
    if (changeBox) changeBox.innerHTML = '<span class="italic text-slate-300">Belum ada perubahan.</span>';

    // approval panel hidden
    const panel = $('panel-approval-batal');
    if (panel) panel.classList.add('hidden');

    // load dropdowns for pops & techs
    try {
      const pops = await apiGet(buildQuery({ action: 'get_pops' }));
      const sel = $('edit_pop');
      if (sel) {
        sel.innerHTML = '<option value="">-- Pilih --</option>';
        if (Array.isArray(pops)) {
          pops.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p; opt.innerText = p;
            sel.appendChild(opt);
          });
        }
      }
    } catch { }

    try {
      const techs = await apiGet(buildQuery({ action: 'get_technicians' }));
      const list = Array.isArray(techs) ? techs : [];

      const fillSel = (id) => {
        const el = $(id);
        if (!el) return;
        el.innerHTML = '<option value="">-- Pilih --</option>';
        list.forEach(t => {
          const opt = document.createElement('option');
          opt.value = t; opt.innerText = t;
          el.appendChild(opt);
        });
      };

      fillSel('edit_technician');
      fillSel('edit_tech_2');
      fillSel('edit_tech_3');
      fillSel('edit_tech_4');
    } catch { }
  };

  window.closeDetailModal = function () {
    $('detail-modal')?.classList.add('hidden');
    currentDetailId = null;
    isNewMode = false;
    toggleSmartInputPanel(false);
    resetSmartInputUI();
  };

  async function fillForm(item) {
    const setVal = (id, val) => { const el = $(id); if (el) el.value = (val ?? ''); };

    setVal('edit_id', item.id || '');
    safeSetText('modal-ticket-id', '#' + (item.ticket_id || 'UNKNOWN'));

    setVal('edit_ticket_id', item.ticket_id || '');
    setVal('edit_created_at', item.created_at || '');
    setVal('edit_finished_at', toDatetimeLocal(item.finished_at));

    if ($('edit_priority')) $('edit_priority').checked = (String(item.is_priority) === '1');

    setVal('edit_name', item.customer_name || '');
    setVal('edit_phone', item.customer_phone || '');
    setVal('edit_address', item.address || '');

    setVal('edit_coordinates', item.coordinates || '');
    setVal('edit_plan', item.plan_name || '');
    setVal('edit_price', item.price || '');
    setVal('edit_date', item.installation_date || '');

    setVal('edit_sales_1', item.sales_name || '');
    setVal('edit_sales_2', item.sales_name_2 || '');
    setVal('edit_sales_3', item.sales_name_3 || '');

    const statusSelect = $('edit_status');
    if (statusSelect) {
      statusSelect.innerHTML = '';
      ['Baru', 'Survey', 'Proses', 'Pending', 'Selesai', 'Req_Batal', 'Batal'].forEach(s => {
        const opt = document.createElement('option');
        opt.value = s; opt.innerText = s;
        if (item.status === s) opt.selected = true;
        statusSelect.appendChild(opt);
      });
    }

    const panel = $('panel-approval-batal');
    if (panel) {
      if (item.status === 'Req_Batal') panel.classList.remove('hidden');
      else panel.classList.add('hidden');
    }

    setVal('edit_notes_old', item.notes || '');
    const historyBox = $('history-container');
    if (historyBox) {
      const notes = String(item.notes || '').trim();
      if (!notes) historyBox.innerHTML = '<span class="italic text-slate-300">Belum ada riwayat.</span>';
      else {
        historyBox.innerHTML = '';
        notes.split('\n').filter(x => x.trim() !== '').forEach(l => {
          historyBox.innerHTML += `<div class="border-b border-slate-100 pb-1">${escapeHtml(l)}</div>`;
        });
      }
    }
    if ($('edit_notes')) $('edit_notes').value = '';

    await loadChangeHistory(item.id);

    try {
      const pops = await apiGet(buildQuery({ action: 'get_pops' }));
      const sel = $('edit_pop');
      if (sel) {
        sel.innerHTML = '<option value="">-- Pilih --</option>';
        if (Array.isArray(pops)) {
          pops.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p; opt.innerText = p;
            if (String(item.pop) === String(p)) opt.selected = true;
            sel.appendChild(opt);
          });
        }
      }
    } catch { }

    try {
      const techs = await apiGet(buildQuery({ action: 'get_technicians' }));
      const list = Array.isArray(techs) ? techs : [];

      const fillSel = (id, chosen) => {
        const el = $(id);
        if (!el) return;
        el.innerHTML = '<option value="">-- Pilih --</option>';
        list.forEach(t => {
          const opt = document.createElement('option');
          opt.value = t; opt.innerText = t;
          if (String(chosen) === String(t)) opt.selected = true;
          el.appendChild(opt);
        });
      };

      fillSel('edit_technician', item.technician || '');
      fillSel('edit_tech_2', item.technician_2 || '');
      fillSel('edit_tech_3', item.technician_3 || '');
      fillSel('edit_tech_4', item.technician_4 || '');
    } catch { }
  }

  // =========================
  // MAPS
  // =========================
  window.openMaps = function () {
    const val = $('edit_coordinates')?.value || '';
    const c = normalizeCoords(val);
    if (!c) {
      if (typeof Swal !== 'undefined') {
        fireSwal({
          title: 'Info',
          text: 'Koordinat kosong / tidak valid. Contoh: -6.200000,106.816666',
          icon: 'info'
        });
      } else {
        alert('Koordinat kosong / tidak valid. Contoh: -6.200000,106.816666');
      }
      return;
    }
    const url = 'https://www.google.com/maps?q=' + encodeURIComponent(c.lat + ',' + c.lng);
    window.open(url, '_blank', 'noopener');
  };

  // =========================
  // SAVE / DELETE / PRIORITY / DECIDE CANCEL
  // =========================
  window.saveChanges = async function () {
    const form = $('form-edit-install');
    if (!form) return;

    const fd = new FormData(form);
    const data = Object.fromEntries(fd.entries());

    // id untuk edit. untuk new mode, pastikan kosong
    if (isNewMode) data.id = '';

    data.is_priority = $('edit_priority')?.checked ? 1 : 0;
    data.note_append = ($('edit_notes')?.value || '').trim();
    data.finished_at = $('edit_finished_at')?.value || '';
    data.action = 'save';

    // mapping: field name di modal -> name attribute
    // pastikan modal memakai name yang benar (customer_name, customer_phone, etc)
    if (!data.customer_name || String(data.customer_name).trim() === '') {
      showError('Nama pelanggan wajib diisi.');
      return;
    }

    showLoading('Menyimpan...');
    try {
      const result = await apiPostJSON(data);
      hideLoading();

      if (result.status !== 'success') throw new Error(result.msg || 'Gagal menyimpan');

      lastSavedId = result.id || data.id || null;

      showSuccess('Data berhasil disimpan.');
      window.closeDetailModal();
      window.loadProgressData(currentPage);

    } catch (e) {
      hideLoading();
      console.error(e);
      showError(e.message || 'Terjadi kesalahan sistem');
    }
  };

  window.deleteData = async function () {
    if (!currentDetailId) { showError('Tidak ada data yang dipilih.'); return; }

    let ok = false;
    if (typeof Swal !== 'undefined') {
      const conf = await fireSwal({
        title: 'Hapus data ini?',
        text: 'Tindakan ini tidak bisa dibatalkan.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Ya, hapus',
        cancelButtonText: 'Batal'
      });
      ok = conf.isConfirmed;
    } else ok = confirm('Hapus data ini?');

    if (!ok) return;

    showLoading('Menghapus...');
    try {
      const result = await apiPostJSON({ action: 'delete', id: currentDetailId });
      hideLoading();
      if (result.status !== 'success') throw new Error(result.msg || 'Gagal menghapus');

      showSuccess('Data berhasil dihapus.');
      window.closeDetailModal();
      window.loadProgressData(currentPage);
    } catch (e) {
      hideLoading();
      console.error(e);
      showError(e.message || 'Gagal menghapus');
    }
  };

  window.togglePriority = async function (id, currentVal) {
    try {
      await apiPostJSON({ action: 'toggle_priority', id: id, val: (String(currentVal) === '1' ? 0 : 1) });
      window.loadProgressData(currentPage);
    } catch (e) {
      console.error(e);
      showError(e.message || 'Gagal toggle prioritas');
    }
  };

  window.decideCancel = async function (decision) {
    if (!currentDetailId) return;

    let note = '';
    if (typeof Swal !== 'undefined') {
      const res = await fireSwal({
        title: 'Catatan (opsional)',
        input: 'text',
        inputPlaceholder: 'Contoh: alasan approve / reject',
        showCancelButton: true,
        confirmButtonText: 'Kirim',
        cancelButtonText: 'Batal'
      });
      if (!res.isConfirmed) return;
      note = res.value || '';
    } else note = prompt('Catatan (opsional):') || '';

    showLoading('Memproses...');
    try {
      const result = await apiPostJSON({
        action: 'decide_cancel',
        id: currentDetailId,
        decision: decision,
        reason: note
      });
      hideLoading();

      if (result.status !== 'success') throw new Error(result.msg || 'Gagal memproses');

      showSuccess('Berhasil.');
      window.closeDetailModal();
      window.loadProgressData(currentPage);
    } catch (e) {
      hideLoading();
      console.error(e);
      showError(e.message || 'Gagal memproses');
    }
  };

  // =========================
  // Rekap modal (optional)
  // =========================
  function getPopOptionsFromFilter() {
    const sel = $('filter-pop-opt');
    if (!sel) return [];
    const opts = [];
    for (const o of Array.from(sel.options || [])) {
      const val = (o.value || '').trim();
      const label = (o.textContent || '').trim();
      if (!val && !label) continue;
      // skip placeholder "Semua"
      if (!val) continue;
      opts.push({ value: val, label: label || val });
    }
    return opts;
  }

  function buildSwalSelectOptions(items) {
    const map = {};
    for (const it of items) map[it.value] = it.label;
    return map;
  }

  window.openRecapModal = window.openRecapModal || async function () {
    // Ambil daftar POP dari dropdown filter yang sudah di-populate
    const pops = getPopOptionsFromFilter();
    if (!pops.length) {
      showError('Daftar POP belum termuat. Coba Refresh dulu.');
      return;
    }

    // Default pilihan: POP yang sedang difilter (kalau ada)
    const currentPop = ($('filter-pop-opt')?.value || '').trim();

    let chosenPop = '';
    if (typeof Swal !== 'undefined') {
      const res = await fireSwal({
        title: 'Kirim Rekap WA',
        html: '<div style="font-size:12px">Pilih POP yang mau dikirim rekap (status Baru/Survey/Proses)</div>',
        input: 'select',
        inputOptions: buildSwalSelectOptions(pops),
        inputValue: currentPop && pops.some(p => p.value === currentPop) ? currentPop : pops[0].value,
        showCancelButton: true,
        confirmButtonText: 'Kirim',
        cancelButtonText: 'Batal',
        inputValidator: (v) => (!v ? 'POP wajib dipilih' : undefined)
      });
      if (!res.isConfirmed) return;
      chosenPop = (res.value || '').trim();
    } else {
      // Fallback sederhana
      const list = pops.map((p, i) => `${i + 1}. ${p.label}`).join('\n');
      const pick = prompt(`Pilih POP (ketik nomor):\n${list}`);
      const idx = parseInt(pick || '', 10);
      if (!idx || idx < 1 || idx > pops.length) return;
      chosenPop = pops[idx - 1].value;
    }

    if (!chosenPop) return;

    // Konfirmasi akhir
    if (typeof Swal !== 'undefined') {
      const conf = await fireSwal({
        title: 'Konfirmasi',
        text: `Kirim rekap WA untuk POP: ${chosenPop}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Ya, Kirim',
        cancelButtonText: 'Batal'
      });
      if (!conf.isConfirmed) return;
    } else {
      if (!confirm(`Kirim rekap WA untuk POP: ${chosenPop}?`)) return;
    }

    showLoading('Mengirim rekap WA...');
    try {
      const result = await apiPostJSON({
        action: 'send_pop_recap',
        pop_name: chosenPop
      });
      hideLoading();

      if (result.status !== 'success') throw new Error(result.msg || 'Gagal mengirim rekap');

      const count = result.count != null ? ` (${result.count} data)` : '';
      const sent = result.sent != null ? `, terkirim ${result.sent}` : '';
      const failed = result.failed ? `, gagal ${result.failed}` : '';
      const note = result.msg ? ` - ${result.msg}` : '';
      showSuccess('Rekap terkirim' + count + sent + failed + note);
    } catch (e) {
      hideLoading();
      console.error(e);
      showError(e.message || 'Gagal mengirim rekap');
    }
  };

  // =========================
  // INIT
  // =========================
  // PAGE INITIALIZATION
  // =========================

  // Cleanup state ketika page di-swap away
  window.cleanupProgressPage = function () {
    // Clear timeouts & intervals jika ada
    if (window.progressPageCleanupFns) {
      window.progressPageCleanupFns.forEach(fn => {
        try { fn(); } catch (e) { console.warn('Cleanup error:', e); }
      });
      window.progressPageCleanupFns = [];
    }
    // Reset filtersLoaded flag untuk memastikan dropdown di-reload saat kembali
    filtersLoaded = false;
  };

  // Initialize when page is loaded
  function init() {
    // Reset cleanup functions
    window.progressPageCleanupFns = window.progressPageCleanupFns || [];

    // Reset filtersLoaded untuk reload dropdown filters
    filtersLoaded = false;

    // Initialize date preset UI
    const presetEl = $('filter-date-preset');
    if (presetEl && presetEl.value === '') {
      applyDatePreset('');
    }

    // Load initial data
    window.loadProgressData(1);

    // Setup keyboard shortcuts (only once per page load)
    const keydownHandler = (e) => {
      if (e.key === 'Escape') {
        $('detail-modal')?.classList.add('hidden');
        currentDetailId = null;
        isNewMode = false;
      }
    };

    // Remove old listener if exists
    document.removeEventListener('keydown', window.progressKeydownHandler);

    // Add new listener and save reference
    document.addEventListener('keydown', keydownHandler);
    window.progressKeydownHandler = keydownHandler;

    // Register cleanup
    window.progressPageCleanupFns.push(() => {
      document.removeEventListener('keydown', keydownHandler);
    });
  }

  // Public init function for SPA
  window.initProgressPage = function () {
    init();
  };

  // Auto-init on direct load (not SPA)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    // Check if progress elements exist before init
    if (document.getElementById('progress-table-body')) {
      init();
    }
  }

})();
