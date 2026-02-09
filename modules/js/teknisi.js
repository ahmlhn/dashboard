        let CURRENT_TECH_NAME = '';
        let CURRENT_TECH_ROLE = '';
        let DEFAULT_POP = '';
        const ALLOWED_ACC_ROLES = ['svp lapangan', 'cs', 'admin'];

        let API = 'api_installations.php';

        let allData = [], techList = [], popList = [], currentTab = 'all', confirmCallback = null;
        let rekapJobsCache = [];
        let rekapHeaderDate = '';
        let rekapDateCache = '';
        let rekapExpenseItems = [];
        let rekapExpenseSaveTimer = null;
        let rekapExpenseLoading = false;
        const REKAP_EXPENSE_DEBOUNCE_MS = 800;

        const normalizeName = (value) => String(value || '').trim().toLowerCase();
        const isAssignedToCurrent = (item) => {
            const current = normalizeName(CURRENT_TECH_NAME);
            if (!current) return false;
            return [
                item.technician,
                item.technician_2,
                item.technician_3,
                item.technician_4
            ].some((t) => normalizeName(t) === current);
        };
        const isManagerRole = () => ALLOWED_ACC_ROLES.includes(CURRENT_TECH_ROLE);
        const isBlankSales = (value) => {
            const v = String(value || '').trim().toLowerCase();
            return v === '' || v === '-' || v === 'null';
        };
        const normalizeEventPart = (value) => {
            return String(value || '')
                .trim()
                .replace(/\s+/g, '_')
                .replace(/[^a-zA-Z0-9._-]/g, '');
        };
        const buildTechEvent = (name, id, extra) => {
            let evt = `teknisi:${name}`;
            if (id !== undefined && id !== null && String(id) !== '') evt += `#${id}`;
            const extraPart = normalizeEventPart(extra);
            if (extraPart) evt += `:${extraPart}`;
            return evt;
        };
        const logTechEvent = (eventName) => {
            const safe = String(eventName || '').trim();
            if (!safe) return;
            if (typeof window.logTechnicianEvent === 'function') {
                window.logTechnicianEvent(safe);
            } else if (typeof window.startTechnicianTracking === 'function') {
                window.startTechnicianTracking(safe);
            }
        };

        function getTeknisiContext() {
            const root = document.getElementById('teknisi-root');
            if (!root) return null;
            return {
                name: root.dataset.techName || '',
                role: root.dataset.techRole || '',
                pop: root.dataset.defaultPop || ''
            };
        }

        async function initTeknisiPage() {
            const ctx = getTeknisiContext();
            if (!ctx) return;
            if (typeof window.startTechnicianTracking === 'function') window.startTechnicianTracking();

            CURRENT_TECH_NAME = ctx.name || 'Teknisi';
            CURRENT_TECH_ROLE = (ctx.role || 'teknisi').toLowerCase();
            DEFAULT_POP = ctx.pop || '';
            API = (typeof getApiPath === 'function') ? getApiPath('api_installations.php') : 'api_installations.php';

            allData = [];
            techList = [];
            currentTab = 'all';
            confirmCallback = null;

            await loadPops();
            await loadTasks();
            await loadTechnicians();
            switchTab('all');
            setupTabSwipe();
            setupBackGuard();

            const u = new URLSearchParams(window.location.search);
            if (u.get('id')) {
                setTimeout(() => {
                    const i = allData.find(t => String(t.id) === String(u.get('id')));
                    if (i) openDetail(u.get('id'));
                }, 400);
            }
        }

        window.initTeknisiPage = initTeknisiPage;

        let allowBackNavigation = false;
        function setupBackGuard() {
            allowBackNavigation = false;

            try {
                history.pushState({ teknisiGuard: true }, '', window.location.href);
            } catch (e) {}

            if (window.__teknisiBackGuard) return;
            window.__teknisiBackGuard = true;

            window.addEventListener('popstate', () => {
                if (allowBackNavigation) return;
                if (document.querySelector('.modal-open')) {
                    document.querySelectorAll('.modal-open').forEach((el) => {
                        el.classList.add('hidden');
                        el.classList.remove('modal-open');
                    });
                    try {
                        history.pushState({ teknisiGuard: true }, '', window.location.href);
                    } catch (e) {}
                    return;
                }

                try {
                    history.pushState({ teknisiGuard: true }, '', window.location.href);
                } catch (e) {}

                showConfirm('Keluar dari halaman teknisi?', () => {
                    allowBackNavigation = true;
                    history.back();
                });
            });
        }

        function setupTabSwipe() {
            const root = document.getElementById('teknisi-root');
            if (!root || root.dataset.swipeBound === '1') return;
            root.dataset.swipeBound = '1';

            let startX = 0;
            let startY = 0;
            let blocked = false;

            const shouldIgnoreTarget = (target) => {
                if (!target) return false;
                return !!target.closest('input, textarea, select, button, a, [role="button"]');
            };

            root.addEventListener('touchstart', (e) => {
                if (e.touches.length !== 1) return;
                if (document.querySelector('.modal-open')) return;
                if (shouldIgnoreTarget(e.target)) return;
                const touch = e.touches[0];
                startX = touch.clientX;
                startY = touch.clientY;
                blocked = false;
            }, { passive: true });

            root.addEventListener('touchmove', (e) => {
                if (!startX) return;
                if (blocked) return;
                const touch = e.touches[0];
                const dx = Math.abs(touch.clientX - startX);
                const dy = Math.abs(touch.clientY - startY);
                if (dy > dx && dy > 12) {
                    blocked = true;
                }
            }, { passive: true });

            root.addEventListener('touchend', (e) => {
                if (!startX || blocked) { startX = 0; startY = 0; return; }
                if (document.querySelector('.modal-open')) { startX = 0; startY = 0; return; }
                const touch = e.changedTouches[0];
                const dx = touch.clientX - startX;
                const dy = touch.clientY - startY;
                startX = 0;
                startY = 0;
                if (Math.abs(dx) < 60) return;
                if (Math.abs(dy) > 80) return;
                if (Math.abs(dx) < Math.abs(dy) * 1.2) return;

                const tabs = ['all', 'mine'];
                const idx = tabs.indexOf(currentTab);
                if (dx < 0 && idx < tabs.length - 1) {
                    switchTab(tabs[idx + 1]);
                } else if (dx > 0 && idx > 0) {
                    switchTab(tabs[idx - 1]);
                }
            }, { passive: true });
        }

        const parseResponse = async (res) => {
            const text = await res.text();
            let json = null;
            try { json = JSON.parse(text); } catch {}
            if (!json) {
                const snippet = text ? text.slice(0, 200) : '';
                throw new Error(`Response JSON tidak valid${snippet ? ': ' + snippet : ''}`);
            }
            if (json && json.status === 'error' && json.error && !String(json.msg || '').includes(json.error)) {
                json.msg = `${json.msg || 'Server error'} (${json.error})`;
            }
            return json;
        };
        const apiGet = async (params) => {
            const usp = new URLSearchParams(params || {});
            const res = await fetch(API + '?' + usp.toString(), { credentials: 'same-origin' });
            return parseResponse(res);
        };
        const apiPost = async (payload) => {
            const res = await fetch(API, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload || {})
            });
            return parseResponse(res);
        };
        const apiPostForm = async (form) => {
            const res = await fetch(API, {
                method: 'POST',
                credentials: 'same-origin',
                body: form
            });
            return parseResponse(res);
        };
        const logClientError = async (context, detail) => {
            const msg = `${context}${detail ? ': ' + detail : ''}`;
            try {
                await apiPost({ action: 'log_custom', id: 0, type: 'ERROR', message: msg });
            } catch (e) {}
        };

        const pad2 = (n) => String(n).padStart(2, '0');
        const nowDatetimeLocal = () => {
            const d = new Date();
            return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) + 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
        };
        const getRecommendedInstallDate = () => {
            const now = new Date();
            const rec = new Date(now);
            if (now.getHours() >= 17) rec.setDate(now.getDate() + 1);
            return rec.getFullYear() + '-' + pad2(rec.getMonth() + 1) + '-' + pad2(rec.getDate());
        };
        const appendNotes = (oldNotes, line) => {
            const base = String(oldNotes || '').trim();
            const add = String(line || '').trim();
            if (!add) return base;
            if (!base) return add;
            return base + "\n\n" + add;
        };

        function escapeHtml(text) {
            return text ? String(text).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#039;'}[m])) : "";
        }

        const changeFieldLabels = {
            customer_name: 'Nama',
            customer_phone: 'WA',
            address: 'Alamat'
        };

        function formatAuditChangeRow(item) {
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
                <div class="border-b border-slate-200 dark:border-white/10 pb-2">
                    <div class="text-[10px] text-slate-400 mb-1">${escapeHtml(when)} - ${escapeHtml(actor)} (${escapeHtml(role)}) via ${escapeHtml(source)}</div>
                    <div class="text-xs text-slate-700 dark:text-slate-200">
                        <span class="font-bold">${escapeHtml(fieldLabel)}</span>:
                        <span class="text-slate-500 dark:text-slate-400">${oldVal}</span>
                        <span class="text-slate-400 px-1">-></span>
                        <span class="text-slate-800 dark:text-slate-100 font-bold">${newVal}</span>
                    </div>
                </div>
            `;
        }

        async function loadAuditChanges(id) {
            const box = document.getElementById('audit-change-list');
            if (!box) return;
            box.innerHTML = '<div class="italic text-slate-400 text-xs">Memuat...</div>';
            try {
                const res = await apiGet({ action: 'get_changes', id: id });
                if (res.status !== 'success') throw new Error(res.msg || 'Gagal ambil data');
                const list = Array.isArray(res.data) ? res.data : [];
                if (list.length === 0) {
                    box.innerHTML = '<div class="italic text-slate-400 text-xs">Belum ada perubahan.</div>';
                    return;
                }
                box.innerHTML = list.map(formatAuditChangeRow).join('');
            } catch (e) {
                box.innerHTML = '<div class="italic text-red-400 text-xs">Gagal memuat riwayat perubahan.</div>';
            }
        }
        const formatDateTeknisi = (dateString) => {
            if (!dateString) return '-';
            try { return new Date(dateString).toLocaleDateString('id-ID', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }); }
            catch(e){ return dateString; }
        };
        const formatShortDateTeknisi = (dateString) => {
            if (!dateString) return '-';
            const d = new Date(dateString);
            if (Number.isNaN(d.getTime())) return '-';
            return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' });
        };
        function formatRupiahTyping(angka, prefix) {
            var number_string = String(angka||'').replace(/[^,\d]/g, '').toString(), split = number_string.split(','), sisa = split[0].length % 3, rupiah = split[0].substr(0, sisa), ribuan = split[0].substr(sisa).match(/\d{3}/gi);
            if(ribuan){ separator = sisa ? '.' : ''; rupiah += separator + ribuan.join('.'); } rupiah = split[1] != undefined ? rupiah + ',' + split[1] : rupiah; return prefix == undefined ? rupiah : (rupiah ? 'Rp. ' + rupiah : '');
        }

        function parseRupiahValue(raw) {
            const digits = String(raw || '').replace(/\D/g, '');
            return digits ? parseInt(digits, 10) : 0;
        }

        function formatRupiahValue(num) {
            const safe = Number.isFinite(num) ? Math.max(0, Math.floor(num)) : 0;
            return formatRupiahTyping(String(safe), 'Rp. ');
        }

        function normalizePriceValue(raw) {
            const digits = String(raw || '').replace(/\D/g, '');
            if (!digits) return '';
            let num = parseInt(digits, 10);
            if (digits.length <= 3) num = num * 1000;
            return num;
        }

        function normalizeDateInput(raw) {
            const s = String(raw || '').trim();
            if (!s) return '';
            if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
            const m = s.match(/^(\d{2})[\/.\-](\d{2})[\/.\-](\d{4})$/);
            if (m) return `${m[3]}-${m[2]}-${m[1]}`;
            return '';
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

        function splitNames(raw) {
            return String(raw || '')
                .split(',')
                .map(s => s.replace(/\(.*?\)/g, '').trim())
                .filter(Boolean);
        }

        function findTechMatchDetailed(name) {
            const needle = normalizeName(name);
            if (!needle || !Array.isArray(techList)) return { match: '', suggestions: [] };
            const exact = techList.find(n => normalizeName(n) === needle);
            if (exact) return { match: exact, suggestions: [] };
            const starts = techList.filter(n => normalizeName(n).startsWith(needle));
            if (starts.length === 1) return { match: starts[0], suggestions: [] };
            const wordMatch = techList.filter(n => normalizeName(n).split(' ').includes(needle));
            if (wordMatch.length === 1) return { match: wordMatch[0], suggestions: [] };

            const includes = techList.filter(n => normalizeName(n).includes(needle));
            const suggestions = Array.from(new Set([
                ...starts,
                ...wordMatch,
                ...includes
            ])).slice(0, 5);
            return { match: '', suggestions: suggestions };
        }

        function findTechMatch(name) {
            const res = findTechMatchDetailed(name);
            return res && res.match ? res.match : '';
        }

        function normalizePersonName(raw) {
            const cleaned = String(raw || '').trim();
            if (!cleaned) return '';
            const match = findTechMatch(cleaned);
            return match || cleaned;
        }

        function setPlanValue(value) {
            const elPlan = document.getElementById('finish-plan');
            if (!elPlan) return;
            const val = String(value || '').trim();
            if (!val) return;
            if (!Array.from(elPlan.options).some(o => o.value === val)) {
                const opt = document.createElement('option');
                opt.value = val; opt.textContent = val; opt.className = 'text-slate-700';
                elPlan.appendChild(opt);
            }
            elPlan.value = val;
            elPlan.classList.remove('text-slate-400');
            elPlan.classList.remove('dark:text-slate-400');
            elPlan.classList.add('text-slate-700');
            elPlan.classList.add('dark:text-slate-100');
        }

        function setTechValue(elId, value) {
            const elTech = document.getElementById(elId);
            if (!elTech) return;
            const val = String(value || '').trim();
            if (!val) return;
            if (!Array.from(elTech.options).some(o => o.value === val)) {
                const opt = document.createElement('option');
                opt.value = val; opt.textContent = val;
                elTech.appendChild(opt);
            }
            elTech.value = val;
        }

        function openNewInstallModal() {
            resetNewInstallForm();
            populateNewInstallTechDropdowns();
            const m = document.getElementById('new-install-modal');
            if (!m) return;
            closeDetail();
            m.classList.remove('hidden');
            m.classList.add('modal-open');
            const nameInput = document.getElementById('new-install-name');
            if (nameInput) nameInput.focus();
        }

        function closeNewInstallModal() {
            const m = document.getElementById('new-install-modal');
            if (!m) return;
            m.classList.add('hidden');
            m.classList.remove('modal-open');
            resetNewInstallForm();
        }

        function resetNewInstallForm() {
            document.getElementById('new-install-name').value = '';
            document.getElementById('new-install-phone').value = '';
            document.getElementById('new-install-address').value = '';
            document.getElementById('new-install-pop').value = '';
            document.getElementById('new-install-plan').value = '';
            document.getElementById('new-install-price').value = '';
            document.getElementById('new-install-sales-1').value = '';
            document.getElementById('new-install-sales-2').value = '';
            document.getElementById('new-install-sales-3').value = '';
            document.getElementById('new-install-tech-1').value = '';
            document.getElementById('new-install-tech-2').value = '';
            document.getElementById('new-install-tech-3').value = '';
            document.getElementById('new-install-tech-4').value = '';
            document.getElementById('new-install-date').value = '';
            document.getElementById('new-install-coords').value = '';
            document.getElementById('new-install-notes').value = '';
        }

        function populateNewInstallTechDropdowns() {
            const techSelects = [
                'new-install-tech-1',
                'new-install-tech-2',
                'new-install-tech-3',
                'new-install-tech-4'
            ];
            techSelects.forEach(id => {
                const select = document.getElementById(id);
                if (!select) return;
                select.innerHTML = '<option value="">-</option>';
                techList.forEach(tech => {
                    select.innerHTML += `<option value="${escapeHtml(tech)}">${escapeHtml(tech)}</option>`;
                });
            });
        }

        async function saveNewInstallManual() {
            const name = document.getElementById('new-install-name').value.trim();
            const phone = document.getElementById('new-install-phone').value.replace(/\D/g, '');
            const address = document.getElementById('new-install-address').value.trim();
            const pop = document.getElementById('new-install-pop').value.trim();
            const plan = document.getElementById('new-install-plan').value;
            const priceRaw = document.getElementById('new-install-price').value;
            const price = parseRupiahValue(priceRaw);
            const sales1 = document.getElementById('new-install-sales-1').value.trim();
            const sales2 = document.getElementById('new-install-sales-2').value.trim();
            const sales3 = document.getElementById('new-install-sales-3').value.trim();
            const tech1 = document.getElementById('new-install-tech-1').value;
            const tech2 = document.getElementById('new-install-tech-2').value;
            const tech3 = document.getElementById('new-install-tech-3').value;
            const tech4 = document.getElementById('new-install-tech-4').value;
            const date = document.getElementById('new-install-date').value || getRecommendedInstallDate();
            const coords = document.getElementById('new-install-coords').value.trim();
            const notes = document.getElementById('new-install-notes').value.trim();

            if (!name) {
                showAlert('Nama pelanggan wajib diisi', 'error');
                return;
            }
            if (!phone || phone.length < 8) {
                showAlert('Nomor WhatsApp tidak valid (minimal 8 digit)', 'error');
                return;
            }
            if (!address) {
                showAlert('Alamat wajib diisi', 'error');
                return;
            }
            if (!pop) {
                showAlert('POP wajib diisi', 'error');
                return;
            }

            // Double input prevention
            const btn = document.getElementById('btn-save-new');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="animate-spin inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full mr-2"></span>Menyimpan...';
                btn.classList.add('opacity-75', 'cursor-not-allowed');
            }

            const payload = {
                action: 'save',
                customer_name: name,
                customer_phone: phone,
                address: address,
                pop: pop,
                plan_name: plan,
                price: price,
                sales_name: sales1,
                sales_name_2: sales2,
                sales_name_3: sales3,
                technician: tech1,
                technician_2: tech2,
                technician_3: tech3,
                technician_4: tech4,
                installation_date: date,
                coordinates: coords,
                status: 'Baru',
                notes: notes
            };

            try {
                const resp = await apiPost(payload);
                if (resp.status !== 'success') throw new Error(resp.msg || 'Gagal menyimpan');
                
                showAlert(`Data ${name} berhasil disimpan`);
                closeNewInstallModal();
                await loadTasks();
                
                // Reset button state (though modal is closed, good practice)
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = 'SIMPAN';
                    btn.classList.remove('opacity-75', 'cursor-not-allowed');
                }
            } catch (e) {
                showAlert(`Gagal menyimpan: ${e.message || 'Error'}`, 'error');
                logClientError('saveNewInstallManual', e.message || 'error');
                
                // Reset button state on error
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = 'SIMPAN';
                    btn.classList.remove('opacity-75', 'cursor-not-allowed');
                }
            }
        }

        function normalizePhoneForWa(raw) {
            let ph = String(raw || '').replace(/[^0-9]/g, '');
            if (!ph) return '';
            if (ph.startsWith('0')) ph = '62' + ph.substring(1);
            else if (ph.startsWith('8')) ph = '62' + ph;
            return ph;
        }

        function buildGreetingMessage(item) {
            const hour = new Date().getHours();
            const sapa = hour>=18?"Malam":(hour>=15?"Sore":(hour>=11?"Siang":"Pagi"));
            return encodeURIComponent(`${sapa} Kak ${item.customer_name}. Saya ${CURRENT_TECH_NAME}, teknisi WiFi. Mohon shareloc lokasi pasang ya. Terima kasih.`);
        }

        function showAlert(m, t='success') {
            const modal = document.getElementById('alert-modal'); if(!modal) return alert(m);
            const icon = t === 'success'
                ? '<svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>'
                : '<svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>';

            const color = t === 'success'
                ? 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-200'
                : 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-200';

            document.getElementById('alert-icon-container').className = `w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-5 ${color}`;
            document.getElementById('alert-icon-container').innerHTML = icon;
            document.getElementById('alert-message').innerText = m;

            modal.classList.remove('hidden');
            modal.classList.add('modal-open');
        }
        function closeAlert() {
            const m = document.getElementById('alert-modal');
            if(m) { m.classList.add('hidden'); m.classList.remove('modal-open'); }
        }

        function showConfirm(m, cb) {
            const modal = document.getElementById('confirm-modal');
            const btnYes = document.getElementById('btn-confirm-yes');
            if (!modal || !btnYes) { if(confirm(m)) cb(); return; }

            document.getElementById('confirm-message').innerText = m;
            confirmCallback = cb;

            btnYes.onclick = function() {
                modal.classList.add('hidden');
                modal.classList.remove('modal-open');
                if(confirmCallback) confirmCallback();
            };

            modal.classList.remove('hidden');
            modal.classList.add('modal-open');
        }
        function closeConfirm() {
            const m = document.getElementById('confirm-modal');
            if(m) { m.classList.add('hidden'); m.classList.remove('modal-open'); }
        }

        function closeDetail() {
            const m = document.getElementById('detail-modal');
            if (!m) return;
            m.classList.add('hidden');
            m.classList.remove('modal-open');
        }

        function clearSearch() {
            const input = document.getElementById('search-input');
            input.value = '';
            input.blur();
            renderTasks();
        }

        function handleSearchInput() {
            renderTasks();
        }

        function handleFilterChange(type) {
            renderTasks();
        }

        function refreshTasks() {
            loadTasks();
        }

        function hasSelectOption(selectEl, value) {
            if (!selectEl) return false;
            const target = value == null ? '' : String(value);
            return Array.from(selectEl.options).some(opt => opt.value === target);
        }

        function renderPopSelect(selectEl, placeholderLabel, options = {}) {
            if (!selectEl) return;
            const previousValue = selectEl.value;
            selectEl.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = options.placeholderValue ?? '';
            placeholder.textContent = placeholderLabel || '';
            if (options.disablePlaceholder) placeholder.disabled = true;
            selectEl.appendChild(placeholder);
            popList.forEach(pop => {
                const label = String(pop ?? '').trim();
                if (!label) return;
                const option = document.createElement('option');
                option.value = label;
                option.textContent = label;
                selectEl.appendChild(option);
            });
            if (previousValue && hasSelectOption(selectEl, previousValue)) {
                selectEl.value = previousValue;
            } else if (options.defaultValue !== undefined) {
                selectEl.value = options.defaultValue;
            } else {
                selectEl.value = placeholder.value;
            }
        }

        function updatePopSelects() {
            renderPopSelect(document.getElementById('filter-pop'), 'Semua Area', { disablePlaceholder: false });
            renderPopSelect(document.getElementById('new-install-pop'), 'POP *', { disablePlaceholder: true });
            renderPopSelect(document.getElementById('finish-pop'), 'POP', { disablePlaceholder: true });
        }

        function setSelectValue(selectEl, value, options = {}) {
            if (!selectEl) return false;
            const target = value == null ? '' : String(value);
            if (target && hasSelectOption(selectEl, target)) {
                selectEl.value = target;
                return true;
            }
            if (target && options.allowCustom) {
                const option = document.createElement('option');
                option.value = target;
                option.textContent = target;
                selectEl.appendChild(option);
                selectEl.value = target;
                return true;
            }
            if (options.defaultValue !== undefined) {
                selectEl.value = options.defaultValue;
            } else {
                selectEl.value = '';
            }
            return false;
        }

        async function loadPops() {
            popList = [];
            updatePopSelects();
            try {
                const data = await apiGet({ action: 'get_pops' });
                popList = (Array.isArray(data) ? data : []).map(item => String(item ?? '').trim()).filter(Boolean);
            } catch (e) {
                popList = [];
            }
            updatePopSelects();
        }

        async function loadTasks() {
            document.getElementById('loading').classList.remove('hidden');
            try {
                const d = await apiGet({ action: 'get_all' });
                allData = Array.isArray(d) ? d : [];
                renderTasks();
            } catch (e) {
                showAlert("Gagal load data", "error");
                logClientError('loadTasks', e.message || 'unknown');
            } finally {
                document.getElementById('loading').classList.add('hidden');
            }
        }

        async function loadTechnicians() {
            try {
                const d = await apiGet({ action: 'get_technicians' });
                techList = Array.isArray(d) ? d : [];
            } catch(e){ techList = []; }
        }

        function switchTab(tabName) {
            currentTab = tabName;
            const tabAll = document.getElementById('tab-all');
            const tabMine = document.getElementById('tab-mine');
            const filterStatusWrapper = document.getElementById('filter-status-wrapper');
            const filterPop = document.getElementById('filter-pop');

            if (tabName === 'all') {
                tabAll.className = "py-3 text-xs font-bold rounded-lg transition-all flex items-center justify-center gap-2 flex-1 tab-active shadow-sm";
                tabMine.className = "py-3 text-xs font-bold rounded-lg transition-all flex items-center justify-center gap-2 flex-1 tab-inactive hover:bg-slate-50";
                filterStatusWrapper.classList.remove('hidden');
            } else {
                tabMine.className = "py-3 text-xs font-bold rounded-lg transition-all flex items-center justify-center gap-2 flex-1 tab-active shadow-sm";
                tabAll.className = "py-3 text-xs font-bold rounded-lg transition-all flex items-center justify-center gap-2 flex-1 tab-inactive hover:bg-slate-50";
                filterStatusWrapper.classList.remove('hidden');
            }
            if (filterPop) {
                const targetPop = tabName === 'all' ? (DEFAULT_POP || '') : '';
                filterPop.value = targetPop;
                if (targetPop && filterPop.value !== targetPop) {
                    filterPop.value = '';
                }
            }
            renderTasks();
        }

        function renderTasks() {
            const container = document.getElementById('task-list');
            const filterPop = document.getElementById('filter-pop').value;
            const filterStatus = document.getElementById('filter-status').value;
            const searchQuery = document.getElementById('search-input').value.toLowerCase();
            const btnClear = document.getElementById('btn-clear-search');
            if(searchQuery.length > 0) btnClear.classList.remove('hidden'); else btnClear.classList.add('hidden');

            const today = new Date(); today.setHours(0,0,0,0);

            allData.sort((a, b) => {
                const sA = (a.status || '').toLowerCase();
                const sB = (b.status || '').toLowerCase();
                const pA = parseInt(a.is_priority || 0);
                const pB = parseInt(b.is_priority || 0);

                if (sA === 'req_batal' && sB !== 'req_batal') return -1;
                if (sA !== 'req_batal' && sB === 'req_batal') return 1;

                if (pA > pB) return -1;
                if (pA < pB) return 1;

                return (parseInt(a.id) || 0) - (parseInt(b.id) || 0);
            });

            const countAll = allData.filter(i => !['Selesai','Batal'].includes(i.status) && (filterPop ? i.pop === filterPop : true)).length;
            const countMine = allData.filter(i => {
                const isMine = (isAssignedToCurrent(i) && !['Selesai','Batal','Baru'].includes(i.status));
                const isReqBatal = (i.status === 'Req_Batal' && isManagerRole());
                return isMine || isReqBatal;
            }).length;

            document.getElementById('badge-all').innerText = countAll; document.getElementById('badge-all').classList.remove('hidden');
            document.getElementById('badge-mine').innerText = countMine; document.getElementById('badge-mine').classList.remove('hidden');

            let filtered = allData.filter(item => {
                if (['Selesai','Batal'].includes(item.status)) return false;
                if (filterPop && item.pop !== filterPop) return false;

                if (searchQuery) {
                    const matchName = (item.customer_name || '').toLowerCase().includes(searchQuery);
                    const matchAddr = (item.address || '').toLowerCase().includes(searchQuery);
                    const matchTicket = (item.ticket_id || '').toLowerCase().includes(searchQuery);
                    if (!matchName && !matchAddr && !matchTicket) return false;
                }

                if (currentTab === 'all') {
                    if (isAssignedToCurrent(item)) return false;
                    if (filterStatus && item.status !== filterStatus) return false;
                    return true;
                }

                // mine
                if (item.status === 'Req_Batal' && isManagerRole()) return true;
                if (!isAssignedToCurrent(item)) return false;
                if (item.status === 'Baru') return false;
                if (filterStatus && item.status !== filterStatus) return false;
                return true;
            });

            container.innerHTML = '';
            if (filtered.length === 0) { document.getElementById('empty-state').classList.remove('hidden'); return; }
            else { document.getElementById('empty-state').classList.add('hidden'); }

            let html = '';
            filtered.forEach(item => {
                let actionArea = '', warningHtml = '', borderClass = 'border-slate-200 dark:border-white/10';
                const isAssigned = isAssignedToCurrent(item);
                const canManage = isAssigned || isManagerRole();

                let priorityHtml = '';
                if (item.is_priority == 1) {
                    borderClass = 'border-l-8 border-l-yellow-400 border-slate-200 dark:border-white/10';
                    priorityHtml = `<div class="flex items-center gap-1 bg-yellow-400 dark:bg-yellow-500/20 text-slate-900 dark:text-yellow-200 px-2 py-1 rounded text-[10px] font-black mb-2 w-max">PRIORITAS TINGGI</div>`;
                }

                if (item.installation_date && !['Selesai', 'Batal', 'Req_Batal'].includes(item.status)) {
                    const installDate = new Date(item.installation_date); installDate.setHours(0,0,0,0);
                    const diff = Math.ceil((today - installDate) / (1000 * 60 * 60 * 24));
                    if (diff > 0) {
                        warningHtml = `<div class="bg-red-100 dark:bg-red-900/20 text-red-600 dark:text-red-200 px-2 py-1 rounded text-[10px] font-bold mb-2 w-max">TELAT ${diff} HARI</div>`;
                        if (diff > 3 && item.is_priority != 1) borderClass = 'border-red-500 ring-1 ring-red-200 bg-red-50/30 dark:border-red-500/40 dark:ring-red-500/30 dark:bg-red-900/20';
                    }
                }

                if (item.status === 'Baru') {
                    actionArea = `<div class="mt-4 pt-4 border-t border-slate-100 dark:border-white/10">
                        <button onclick="openClaimModal(${item.id})" class="btn-thumb w-full bg-blue-600 text-white shadow hover:bg-blue-700 transition">AMBIL TUGAS</button>
                    </div>`;
                } else if (item.status === 'Req_Batal') {
                    if (isManagerRole()) {
                        actionArea = `<div class="mt-4 pt-4 border-t border-slate-100 dark:border-white/10">
                            <button onclick="openReviewModal(${item.id})" class="btn-thumb w-full bg-slate-800 text-white shadow-lg active:scale-95 transition flex items-center justify-center gap-2">
                                <svg class="w-5 h-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                                TINJAU & PUTUSKAN
                            </button>
                        </div>`;
                    } else {
                        actionArea = `<div class="mt-4 pt-4 border-t border-slate-100 dark:border-white/10"><button onclick="openDetail(${item.id})" class="btn-thumb w-full bg-slate-300 dark:bg-slate-800 text-slate-500 dark:text-slate-400 cursor-not-allowed">MENUNGGU ACC</button></div>`;
                    }
                } else {
                    const label = canManage ? 'LIHAT DETAIL & AKSI' : 'LIHAT DETAIL';
                    const btnClass = canManage
                        ? 'btn-thumb w-full bg-blue-600 text-white shadow hover:bg-blue-700 transition'
                        : 'btn-thumb w-full bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-300 dark:hover:bg-slate-700 transition';
                    actionArea = `<div class="mt-4 pt-4 border-t border-slate-100 dark:border-white/10">
                        <button onclick="openDetail(${item.id})" class="${btnClass}">${label}</button>
                    </div>`;
                }

                let techLabel = '';
                if (item.technician && normalizeName(item.technician) !== normalizeName(CURRENT_TECH_NAME)) {
                    techLabel = `<span class="text-[10px] bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-200 border border-amber-100 dark:border-amber-500/30 px-2 py-1 rounded font-bold">Teknisi: ${escapeHtml(item.technician)}</span>`;
                }

                const statusKey = (item.status || '').toLowerCase();
                let statusClass = 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-200';
                if (statusKey === 'proses') statusClass = 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-200';
                else if (statusKey === 'pending') statusClass = 'bg-orange-50 text-orange-700 dark:bg-orange-900/30 dark:text-orange-200';
                else if (statusKey === 'req_batal') statusClass = 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200';
                else if (statusKey === 'baru') statusClass = 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200';

                const scheduleLabel = formatShortDateTeknisi(item.installation_date);

                html += `
                    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-sm border ${borderClass} fade-in relative p-4 space-y-3">
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-xs font-bold text-slate-400 dark:text-slate-500">#${escapeHtml(item.ticket_id || item.id)}</span>
                            <span class="px-2 py-1 rounded text-[10px] font-bold uppercase ${statusClass}">${escapeHtml(item.status || '-')}</span>
                        </div>
                        ${priorityHtml}
                        ${warningHtml}
                        <div>
                            <h3 class="font-bold text-slate-800 dark:text-slate-100 text-base">${escapeHtml(item.customer_name || '-')}</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400 leading-snug line-clamp-2">${escapeHtml(item.address || '-')}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-[10px]">
                            <div class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-white/10 rounded-lg px-2 py-1.5">
                                <div class="text-slate-400 dark:text-slate-500 font-bold uppercase">Jadwal</div>
                                <div class="text-slate-700 dark:text-slate-200 font-bold">${scheduleLabel}</div>
                            </div>
                            <div class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-white/10 rounded-lg px-2 py-1.5">
                                <div class="text-slate-400 dark:text-slate-500 font-bold uppercase">POP</div>
                                <div class="text-slate-700 dark:text-slate-200 font-bold">${escapeHtml(item.pop || '-')}</div>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-[10px] bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-200 border border-blue-100 dark:border-blue-500/30 px-2 py-1 rounded font-bold">${escapeHtml(item.plan_name || '-')}</span>
                            ${techLabel}
                        </div>
                        ${actionArea}
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        function openDetail(id) {
            const item = allData.find(i => String(i.id) === String(id));
            if (!item) return;

            const mapsUrl = item.coordinates ? `https://www.google.com/maps?q=${encodeURIComponent(item.coordinates)}` : '';
            const maps = mapsUrl ? `<a href="${mapsUrl}" target="_blank" rel="noopener" class="text-blue-600 dark:text-blue-400 underline font-bold">Buka Maps</a>` : '-';

            const isAssigned = isAssignedToCurrent(item);
            const isManager = isManagerRole();
            const canManage = isAssigned || isManager;
            const canClaim = item.status === 'Baru';
            const allowActions = canManage || canClaim;

            const ph = normalizePhoneForWa(item.customer_phone || '');
            const msg = buildGreetingMessage(item);
            const showWaBtn = (item.status !== 'Baru' && isAssigned && ph);
            const readOnlyNote = (!canManage && item.status !== 'Baru')
                ? `<div class="bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-200 px-3 py-2 rounded-lg text-xs font-bold border border-amber-100 dark:border-amber-500/30">Hanya lihat detail. Tugas ini sudah diambil teknisi lain.</div>`
                : '';

            const waBtn = showWaBtn
                ? `<a href="https://wa.me/${ph}?text=${msg}" target="_blank" rel="noopener"
                      class="inline-flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-green-600 text-white font-extrabold text-sm shadow active:scale-95">
                      <svg class="w-5 h-5" viewBox="0 0 32 32" fill="currentColor" aria-hidden="true">
                        <path d="M19.11 17.45c-.28-.14-1.63-.8-1.88-.9-.25-.09-.43-.14-.61.14-.18.28-.7.9-.86 1.08-.16.18-.32.21-.6.07-.28-.14-1.16-.43-2.21-1.36-.82-.73-1.38-1.64-1.54-1.92-.16-.28-.02-.43.12-.57.13-.13.28-.32.42-.48.14-.16.18-.28.28-.46.09-.18.05-.35-.02-.49-.07-.14-.61-1.47-.84-2.01-.22-.53-.45-.46-.61-.46-.16 0-.35-.02-.53-.02-.18 0-.49.07-.75.35-.25.28-.98.95-.98 2.32 0 1.37 1 2.69 1.14 2.88.14.18 1.96 2.99 4.74 4.19.66.28 1.18.45 1.58.57.66.21 1.25.18 1.73.11.53-.08 1.63-.66 1.86-1.3.23-.64.23-1.19.16-1.3-.07-.11-.25-.18-.53-.32z"/>
                        <path d="M26.5 5.5A14.5 14.5 0 0 0 3.64 22.64L2 30l7.53-1.58A14.5 14.5 0 0 0 30 16 14.4 14.4 0 0 0 26.5 5.5zM16 28a12 12 0 0 1-6.11-1.68l-.44-.26-4.46.94.95-4.34-.29-.45A12 12 0 1 1 28 16 12 12 0 0 1 16 28z"/>
                      </svg>
                      CHAT WA
                    </a>`
                : '';

            let content = `
                <div class="p-5 space-y-5">
                    <div class="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 shadow-sm text-xs grid grid-cols-2 gap-3">
                        <div class="text-slate-500 dark:text-slate-400 font-bold uppercase">TIKET</div>
                        <div class="text-right font-mono font-bold text-slate-800 dark:text-slate-100">#${escapeHtml(item.ticket_id||item.id)}</div>

                        <div class="text-slate-500 dark:text-slate-400 font-bold uppercase">STATUS</div>
                        <div class="text-right"><span class="bg-slate-100 dark:bg-slate-900 px-2 py-1 rounded font-bold text-slate-700 dark:text-slate-200">${escapeHtml(item.status||'-')}</span></div>

                        <div class="text-slate-500 dark:text-slate-400 font-bold uppercase">JADWAL</div>
                        <div class="text-right text-blue-600 dark:text-blue-300 font-bold">${formatDateTeknisi(item.installation_date)}</div>

                        <div class="text-slate-500 dark:text-slate-400 font-bold uppercase">WHATSAPP</div>
                        <div class="text-right font-extrabold text-slate-800 dark:text-slate-100">${escapeHtml(item.customer_phone || '-')}</div>

                        <div class="text-slate-500 dark:text-slate-400 font-bold uppercase">MAPS</div>
                        <div class="text-right">${maps}</div>

                        <div class="text-slate-500 dark:text-slate-400 font-bold uppercase">POP</div>
                        <div class="text-right font-bold text-slate-800 dark:text-slate-100">${escapeHtml(item.pop || '-')}</div>

                        <div class="text-slate-500 dark:text-slate-400 font-bold uppercase">PAKET</div>
                        <div class="text-right font-bold text-slate-800 dark:text-slate-100">${escapeHtml(item.plan_name || '-')}</div>
                    </div>

                    ${waBtn ? `
                    <div class="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-[10px] text-slate-400 font-bold uppercase">Aksi Cepat</div>
                                <div class="text-sm font-extrabold text-slate-800 dark:text-slate-100">Hubungi Pelanggan</div>
                            </div>
                            ${waBtn}
                        </div>
                        <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-2">*Tombol WA tampil setelah tugas diambil (status Proses).* </div>
                    </div>
                    ` : ''}

                    <div class="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 shadow-sm text-sm space-y-3">
                        <div>
                            <label class="text-[10px] text-slate-400 font-bold uppercase">Nama</label>
                            <div class="font-bold text-lg text-slate-800 dark:text-slate-100">${escapeHtml(item.customer_name || '-')}</div>
                        </div>
                        <div>
                            <label class="text-[10px] text-slate-400 font-bold uppercase">Alamat</label>
                            <div class="leading-relaxed text-slate-700 dark:text-slate-300">${escapeHtml(item.address || '-')}</div>
                        </div>
                    </div>

                    ${readOnlyNote}

                    <div class="space-y-2">
                        <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Audit Perubahan Data</h4>
                        <div id="audit-change-list" class="bg-slate-50 dark:bg-slate-900/60 p-3 rounded-xl text-xs text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-white/10">Memuat...</div>
                    </div>

                    <div class="space-y-2">
                        <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Catatan / Riwayat</h4>
                        <div class="bg-slate-50 dark:bg-slate-900/60 p-3 rounded-xl text-sm text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-white/10 whitespace-pre-line">${escapeHtml(item.notes) || '-'}</div>
                    </div>
                </div>
            `;

            document.getElementById('modal-content').innerHTML = content;
            loadAuditChanges(id);

            const footer = document.getElementById('modal-footer');
            const hdr = document.getElementById('header-actions');

            let btnPrio = '';
            if (isManager) {
                if (item.is_priority == 1) btnPrio = `<button onclick="togglePriority(${item.id}, 0)" class="p-2 bg-yellow-100 dark:bg-yellow-500/20 text-yellow-600 dark:text-yellow-200 rounded-lg text-[10px] font-bold border border-yellow-200 dark:border-yellow-500/30" title="Matikan Prioritas">UN-PRIO</button>`;
                else btnPrio = `<button onclick="togglePriority(${item.id}, 1)" class="p-2 bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-300 rounded-lg text-[10px] font-bold border border-slate-200 dark:border-white/10 hover:bg-yellow-50 dark:hover:bg-yellow-900/30 hover:text-yellow-600 dark:hover:text-yellow-200" title="Jadikan Prioritas">PRIO</button>`;
            }

            let btnTransfer = '';
            if (canManage && ['Proses','Pending','Survey'].includes(item.status)) {
                btnTransfer = `<button onclick="openTransferModal(${item.id})" class="p-2 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-200 rounded-lg text-[10px] font-bold border border-indigo-100 dark:border-indigo-500/30">Transfer</button>`;
            }
            hdr.innerHTML = `<div class="flex gap-2">${btnPrio} ${btnTransfer}</div>`;

            if (!allowActions) {
                footer.innerHTML = `<button onclick="closeDetail()" class="btn-thumb w-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200">Tutup</button>`;
            } else if (item.status === 'Baru') {
                footer.innerHTML = `<button onclick="openClaimModal(${item.id})" class="btn-thumb w-full bg-blue-600 text-white shadow-lg hover:bg-blue-700 transition">AMBIL TUGAS</button>`;
            } else if (item.status === 'Req_Batal') {
                if (isManager) {
                    footer.innerHTML = `<div class="grid grid-cols-2 gap-4">
                        <button onclick="decideCancelTeknisi(${item.id}, 'reject')" class="h-14 bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold rounded-2xl text-base active:scale-95 transition">TOLAK</button>
                        <button onclick="decideCancelTeknisi(${item.id}, 'approve')" class="h-14 bg-red-600 text-white font-bold rounded-2xl text-base shadow-lg active:scale-95 transition">ACC BATAL</button>
                    </div>`;
                } else {
                    footer.innerHTML = `<button class="btn-thumb w-full bg-slate-200 dark:bg-slate-800 text-slate-500 dark:text-slate-400 cursor-not-allowed">Menunggu Admin/SVP/CS</button>`;
                }
            } else if (item.status === 'Pending') {
                footer.innerHTML = `
                    <button onclick="resumeProses(${item.id})" class="btn-thumb w-full bg-blue-600 text-white shadow-lg mb-4">LANJUTKAN PROSES</button>
                    <div class="grid grid-cols-2 gap-4">
                        <button onclick="openPendingModal(${item.id})" class="h-14 border-2 border-orange-200 dark:border-orange-500/30 text-orange-600 dark:text-orange-200 font-bold rounded-2xl">TUNDA</button>
                        <button onclick="openCancelReqModal(${item.id})" class="h-14 border-2 border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-200 font-bold rounded-2xl">BATAL</button>
                    </div>
                `;
            } else if (item.status === 'Survey') {
                footer.innerHTML = `
                    <button onclick="resumeProses(${item.id})" class="btn-thumb w-full bg-blue-600 text-white shadow-lg mb-4">JADIKAN PROSES</button>
                    <div class="grid grid-cols-2 gap-4">
                        <button onclick="openPendingModal(${item.id})" class="h-14 border-2 border-orange-200 dark:border-orange-500/30 text-orange-600 dark:text-orange-200 font-bold rounded-2xl">TUNDA</button>
                        <button onclick="openCancelReqModal(${item.id})" class="h-14 border-2 border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-200 font-bold rounded-2xl">BATAL</button>
                    </div>
                `;
            } else if (item.status === 'Proses') {
                footer.innerHTML = `
                    <button onclick="openFinishModal(${item.id})" class="btn-thumb w-full bg-slate-900 dark:bg-slate-700 text-white shadow-lg mb-4">LAPOR SELESAI</button>
                    <div class="grid grid-cols-2 gap-4">
                        <button onclick="openPendingModal(${item.id})" class="h-14 border-2 border-orange-200 dark:border-orange-500/30 text-orange-600 dark:text-orange-200 font-bold rounded-2xl">TUNDA</button>
                        <button onclick="openCancelReqModal(${item.id})" class="h-14 border-2 border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-200 font-bold rounded-2xl">BATAL</button>
                    </div>
                `;
            } else {
                footer.innerHTML = `<button onclick="closeDetail()" class="btn-thumb w-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200">Tutup</button>`;
            }

            const dm = document.getElementById('detail-modal');
            dm.classList.remove('hidden');
            dm.classList.add('modal-open');

            apiPost({ action: 'log_custom', id: id, type: 'VIEW_DETAIL', message: 'Melihat rincian tugas' }).catch(()=>{});
        }

        async function resumeProses(id) {
            const item = allData.find(i => String(i.id) === String(id));
            if (!item) return;
            try {
                logTechEvent(buildTechEvent('resume', id));
                const r = await apiPost({
                    ...item,
                    action: 'save',
                    id: id,
                    status: 'Proses',
                    notes: item.notes || '',
                    installation_date: item.installation_date || new Date().toISOString().slice(0,10)
                });
                if (r.status === 'success') { closeDetail(); await loadTasks(); setTimeout(()=>openDetail(id), 300); }
                else {
                    showAlert("Gagal: " + (r.msg || 'Error'), "error");
                    logClientError('resumeProses', r.msg || 'error');
                }
            } catch(e){
                showAlert("Gagal update ke Proses", "error");
                logClientError('resumeProses', e.message || 'error');
            }
        }

        async function togglePriority(id, val) {
            const action = val == 1 ? "Jadikan PRIORITAS" : "Hapus Prioritas";
            showConfirm(`Yakin ingin ${action}?`, async () => {
                try {
                    const eventLabel = val == 1 ? 'priority_on' : 'priority_off';
                    logTechEvent(buildTechEvent(eventLabel, id));
                    const r = await apiPost({ action: 'toggle_priority', id: id, val: val });
                    if(r.status === 'success') {
                        const idx = allData.findIndex(i => String(i.id) === String(id));
                        if(idx !== -1) allData[idx].is_priority = val;
                        closeDetail(); renderTasks(); showAlert("Berhasil Update Prioritas!");
                        setTimeout(() => openDetail(id), 350);
                    } else {
                        showAlert("Gagal: " + r.msg, "error");
                        logClientError('togglePriority', r.msg || 'error');
                    }
                } catch(e) {
                    showAlert("Error koneksi", "error");
                    logClientError('togglePriority', e.message || 'error');
                }
            });
        }

        function openClaimModal(id) {
            document.getElementById('claim-id').value = id;
            const now = new Date();
            const isAfterCutoff = now.getHours() >= 17;
            const recDate = new Date(now);
            if (isAfterCutoff) recDate.setDate(now.getDate() + 1);
            const recDateStr = `${recDate.getFullYear()}-${pad2(recDate.getMonth() + 1)}-${pad2(recDate.getDate())}`;
            document.getElementById('claim-date').value = recDateStr;
            const label = document.getElementById('claim-date-label');
            if (label) label.textContent = formatDateTeknisi(recDateStr);
            const note = document.getElementById('claim-recommendation-note');
            if (note) note.textContent = isAfterCutoff
                ? 'Jadwal pasang direkomendasikan besok.'
                : 'Jadwal pasang direkomendasikan hari ini.';
            setupClaimMainOptions();
            setupClaimSupportOptions();
            resetClaimSupportInputs();
            bindClaimSupportInputs();

            closeDetail();
            const m = document.getElementById('claim-modal');
            m.classList.remove('hidden');
            m.classList.add('modal-open');
        }
        function closeClaimModal() {
            const m = document.getElementById('claim-modal');
            m.classList.add('hidden');
            m.classList.remove('modal-open');
        }
        async function submitClaim(btn) {
            const id = document.getElementById('claim-id').value;
            const date = document.getElementById('claim-date')?.value || '';
            const mainTech = document.getElementById('claim-tech-main')?.value || '';
            if (!mainTech) { showAlert("Teknisi utama wajib diisi", "error"); return; }
            if (!validateClaimTeam(true)) return;
            const tech2 = document.getElementById('claim-tech-2')?.value || '';
            const tech3 = document.getElementById('claim-tech-3')?.value || '';
            const tech4 = document.getElementById('claim-tech-4')?.value || '';

            const orig = btn.innerText;
            btn.innerText = "...";
            btn.disabled = true;

            try {
                // CLAIM -> otomatis status PROSES (di API)
                logTechEvent(buildTechEvent('claim', id));
                const r = await apiPost({
                    action:'claim',
                    id:id,
                    technician:mainTech,
                    technician_2: tech2,
                    technician_3: tech3,
                    technician_4: tech4,
                    installation_date:date
                });
                if(r.status === 'success') {
                    closeClaimModal();
                    await loadTasks();
                    switchTab('mine');
                    showAlert("Tugas Diambil (Proses)!");
                    setTimeout(() => { openDetail(id); }, 350);
                } else {
                    showAlert("Gagal: " + (r.msg || 'Error'), "error");
                    logClientError('submitClaim', r.msg || 'error');
                }
            } catch(e) {
                showAlert("Error koneksi", "error");
                logClientError('submitClaim', e.message || 'error');
            } finally {
                btn.innerText = orig;
                btn.disabled = false;
            }
        }

        function setupClaimMainOptions() {
            const main = document.getElementById('claim-tech-main');
            if (!main) return;
            const current = main.value;
            main.innerHTML = '<option value="">-- Pilih --</option>';
            techList.forEach((name) => {
                main.innerHTML += `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`;
            });
            const preferred = CURRENT_TECH_NAME || current;
            if (preferred && Array.from(main.options).some(o => o.value === preferred)) {
                main.value = preferred;
            } else {
                main.value = '';
            }
        }

        function setupClaimSupportOptions() {
            const selects = [
                document.getElementById('claim-tech-2'),
                document.getElementById('claim-tech-3'),
                document.getElementById('claim-tech-4')
            ];
            selects.forEach((sel) => {
                if (!sel) return;
                const current = sel.value;
                sel.innerHTML = '<option value="">-- Pilih --</option>';
                techList.forEach((name) => {
                    sel.innerHTML += `<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`;
                });
                sel.value = current && Array.from(sel.options).some(o => o.value === current) ? current : '';
            });
        }

        function updateClaimSupportVisibility() {
            const wrap3 = document.getElementById('claim-tech-3-wrap');
            const wrap4 = document.getElementById('claim-tech-4-wrap');
            const s2 = document.getElementById('claim-tech-2');
            const s3 = document.getElementById('claim-tech-3');
            const s4 = document.getElementById('claim-tech-4');

            if (s2 && wrap3) {
                if (s2.value) {
                    wrap3.classList.remove('hidden');
                } else {
                    wrap3.classList.add('hidden');
                    if (s3) s3.value = '';
                }
            }

            if (s3 && wrap4) {
                if (s3.value) {
                    wrap4.classList.remove('hidden');
                } else {
                    wrap4.classList.add('hidden');
                    if (s4) s4.value = '';
                }
            }

            validateClaimTeam(false);
        }

        function validateClaimTeam(showMessage) {
            const main = document.getElementById('claim-tech-main')?.value || '';
            const s2 = document.getElementById('claim-tech-2')?.value || '';
            const s3 = document.getElementById('claim-tech-3')?.value || '';
            const s4 = document.getElementById('claim-tech-4')?.value || '';
            const current = normalizeName(CURRENT_TECH_NAME);
            if (!current) return true;
            const isMain = normalizeName(main) === current;
            const isSupport = [s2, s3, s4].some((name) => normalizeName(name) === current);

            if (isSupport && isMain) {
                if (showMessage) showAlert("Jika Anda sebagai tim support, ubah teknisi utama.", "error");
                return false;
            }
            if (!isMain && !isSupport) {
                if (showMessage) showAlert("Anda harus masuk sebagai tim support atau teknisi utama.", "error");
                return false;
            }
            return true;
        }

        function resetClaimSupportInputs() {
            const s2 = document.getElementById('claim-tech-2');
            const s3 = document.getElementById('claim-tech-3');
            const s4 = document.getElementById('claim-tech-4');
            if (s2) s2.value = '';
            if (s3) s3.value = '';
            if (s4) s4.value = '';
            updateClaimSupportVisibility();
        }

        function bindClaimSupportInputs() {
            const main = document.getElementById('claim-tech-main');
            const s2 = document.getElementById('claim-tech-2');
            const s3 = document.getElementById('claim-tech-3');
            const s4 = document.getElementById('claim-tech-4');
            if (main && main.dataset.bound !== '1') {
                main.dataset.bound = '1';
                main.addEventListener('change', () => validateClaimTeam(true));
            }
            if (s2 && s2.dataset.bound !== '1') {
                s2.dataset.bound = '1';
                s2.addEventListener('change', () => {
                    updateClaimSupportVisibility();
                    validateClaimTeam(true);
                });
            }
            if (s3 && s3.dataset.bound !== '1') {
                s3.dataset.bound = '1';
                s3.addEventListener('change', () => {
                    updateClaimSupportVisibility();
                    validateClaimTeam(true);
                });
            }
            if (s4 && s4.dataset.bound !== '1') {
                s4.dataset.bound = '1';
                s4.addEventListener('change', () => validateClaimTeam(true));
            }
        }

        function openPendingModal(id) {
            document.getElementById('pending-id').value = id;
            document.getElementById('pending-reason').value = '';
            document.getElementById('pending-date').value = '';
            closeDetail();
            const m = document.getElementById('pending-modal');
            m.classList.remove('hidden');
            m.classList.add('modal-open');
        }
        function closePendingModal() {
            const m = document.getElementById('pending-modal');
            m.classList.add('hidden');
            m.classList.remove('modal-open');
        }
        async function submitPending(btn) {
            const id=document.getElementById('pending-id').value;
            const r=document.getElementById('pending-reason').value;
            const d=document.getElementById('pending-date').value;

            if(!r) { showAlert("Isi alasan", "error"); return; }

            try {
                const item = allData.find(i => String(i.id) === String(id));
                if (!item) { showAlert("Data tidak ditemukan", "error"); return; }
                logTechEvent(buildTechEvent('pending', id));

                const logLine = `[PENDING] ${r} - ${new Date().toLocaleString('id-ID')}`;
                const newNotes = appendNotes(item.notes, logLine);

                const resp = await apiPost({
                    ...item,
                    action:'save',
                    id:id,
                    status:'Pending',
                    notes: newNotes,
                    installation_date: d || item.installation_date
                });
                if (resp.status !== 'success') {
                    showAlert("Gagal: " + (resp.msg || 'Error'), "error");
                    logClientError('submitPending', resp.msg || 'error');
                    return;
                }
            } catch(e) {
                showAlert("Gagal update pending", "error");
                logClientError('submitPending', e.message || 'error');
                return;
            }

            closePendingModal();
            await loadTasks();
            openDetail(id);
        }

        function openCancelReqModal(id) {
            document.getElementById('cancel-req-id').value=id;
            document.getElementById('cancel-req-reason').value='';
            closeDetail();
            const m=document.getElementById('cancel-req-modal');
            m.classList.remove('hidden');
            m.classList.add('modal-open');
        }
        function closeCancelReqModal() {
            const m=document.getElementById('cancel-req-modal');
            m.classList.add('hidden');
            m.classList.remove('modal-open');
        }
        async function submitCancelReq(btn) {
            const id=document.getElementById('cancel-req-id').value;
            const r=document.getElementById('cancel-req-reason').value;
            if(!r) { showAlert("Isi alasan", "error"); return; }
            try {
                logTechEvent(buildTechEvent('cancel_req', id));
                const resp = await apiPost({ action:'request_cancel', id:id, reason:r });
                if (resp.status !== 'success') {
                    showAlert("Gagal: " + (resp.msg || 'Error'), "error");
                    logClientError('submitCancelReq', resp.msg || 'error');
                    return;
                }
                closeCancelReqModal(); await loadTasks(); showAlert("Diajukan ke Admin");
            } catch(e) {
                showAlert("Gagal koneksi", "error");
                logClientError('submitCancelReq', e.message || 'error');
            }
        }

        function openReviewModal(id) {
            const item = allData.find(i => String(i.id) === String(id)); if (!item) return;
            document.getElementById('review-id').value = id;
            document.getElementById('review-name').innerText = item.customer_name || '-';
            document.getElementById('review-plan').innerText = (item.plan_name || '-') + " (" + (item.pop || '-') + ")";
            document.getElementById('review-address').innerText = item.address || '-';
            document.getElementById('review-history').innerText = item.notes || 'Belum ada catatan.';
            document.getElementById('review-note').value = '';
            const m=document.getElementById('review-modal');
            m.classList.remove('hidden');
            m.classList.add('modal-open');
        }
        function closeReviewModal() {
            const m=document.getElementById('review-modal');
            m.classList.add('hidden');
            m.classList.remove('modal-open');
        }
        async function submitReview(decision) {
            const id = document.getElementById('review-id').value;
            const note = document.getElementById('review-note').value.trim();
            if (decision === 'reject' && !note) { showAlert("Wajib mengisi alasan penolakan!", "error"); return; }
            const txt = decision === 'approve' ? 'ACC BATAL' : 'TOLAK BATAL';
            showConfirm(`Yakin ingin ${txt}?`, async () => {
                try {
                    const eventName = decision === 'approve' ? 'cancel_approve' : 'cancel_reject';
                    logTechEvent(buildTechEvent(eventName, id));
                    const r = await apiPost({ action:'decide_cancel', id:id, decision:decision, reason:note });
                    if(r.status==='success') { closeReviewModal(); closeDetail(); await loadTasks(); showAlert("Berhasil: " + txt); }
                    else {
                        showAlert("Gagal: " + r.msg, "error");
                        logClientError('submitReview', r.msg || 'error');
                    }
                } catch(e) {
                    showAlert("Terjadi kesalahan koneksi.", "error");
                    logClientError('submitReview', e.message || 'error');
                }
            });
        }

        async function decideCancelTeknisi(id, decision) { openReviewModal(id); }

        function openTransferModal(id) {
            document.getElementById('transfer-id').value=id;
            const s=document.getElementById('transfer-target');
            s.innerHTML='<option value="">-- Pilih --</option>';
            techList.forEach(n=>{ if(n!==CURRENT_TECH_NAME) s.innerHTML+=`<option value="${escapeHtml(n)}">${escapeHtml(n)}</option>`; });
            document.getElementById('transfer-reason').value='';
            closeDetail();
            const m=document.getElementById('transfer-modal');
            m.classList.remove('hidden');
            m.classList.add('modal-open');
        }
        function closeTransferModal() {
            const m=document.getElementById('transfer-modal');
            m.classList.add('hidden');
            m.classList.remove('modal-open');
        }
        async function submitTransfer(btn) {
            const id=document.getElementById('transfer-id').value;
            const t=document.getElementById('transfer-target').value;
            const r=document.getElementById('transfer-reason').value;
            if(!t) { showAlert("Pilih teknisi", "error"); return; }
            try {
                logTechEvent(buildTechEvent('transfer', id));
                const resp = await apiPost({ action:'transfer', id:id, to_tech:t, from_tech:CURRENT_TECH_NAME, reason:r });
                if (resp.status !== 'success') {
                    showAlert("Gagal: " + (resp.msg || 'Error'), "error");
                    logClientError('submitTransfer', resp.msg || 'error');
                    return;
                }
                closeTransferModal(); await loadTasks(); showAlert("Berhasil Transfer");
            } catch(e) {
                showAlert("Gagal koneksi", "error");
                logClientError('submitTransfer', e.message || 'error');
            }
        }

        function openFinishModal(id) {
            const i = allData.find(x => String(x.id) === String(id));
            if (!i) return;

            document.getElementById('finish-id').value = id;
            document.getElementById('finish-name').value = i.customer_name || '';
            document.getElementById('finish-phone').value = i.customer_phone || '';
            document.getElementById('finish-address').value = i.address || '';
            const finishPopSelect = document.getElementById('finish-pop');
            if (finishPopSelect) {
                setSelectValue(finishPopSelect, i.pop || '', { allowCustom: true });
            }
            const elPlan = document.getElementById('finish-plan');
            if (elPlan) {
                const wanted = (i.plan_name || '').trim();
                if (wanted && !Array.from(elPlan.options).some(o => o.value === wanted)) {
                    const opt = document.createElement('option');
                    opt.value = wanted; opt.textContent = wanted; opt.className = 'text-slate-700';
                    elPlan.appendChild(opt);
                }
                elPlan.value = wanted;
                if (wanted) { elPlan.classList.remove('text-slate-400'); elPlan.classList.add('text-slate-700'); }
            }

            document.getElementById('finish-price').value = formatRupiahTyping(String(i.price||''), 'Rp. ');
            document.getElementById('finish-note').value = i.notes || '';

            const sales1Input = document.getElementById('finish-sales-1');
            const sales1Raw = i.sales_name || '';
            const sales1Blank = isBlankSales(sales1Raw);
            if (sales1Input) {
                sales1Input.value = sales1Blank ? '' : sales1Raw;
                if (!sales1Blank && !isManagerRole()) {
                    sales1Input.readOnly = true;
                    sales1Input.title = 'Sales 1 sudah terisi';
                    sales1Input.classList.add('cursor-not-allowed', 'opacity-70');
                } else {
                    sales1Input.readOnly = false;
                    sales1Input.removeAttribute('title');
                    sales1Input.classList.remove('cursor-not-allowed', 'opacity-70');
                }
            }
            document.getElementById('finish-sales-2').value = i.sales_name_2 || '';
            document.getElementById('finish-sales-3').value = i.sales_name_3 || '';

            function populate(elId, val) {
                const el = document.getElementById(elId);
                if (!el) return;
                el.innerHTML = '<option value="">-- Pilih --</option>';
                techList.forEach(n => {
                    const isSelected = (String(n) === String(val));
                    el.innerHTML += `<option value="${escapeHtml(n)}" ${isSelected ? 'selected' : ''}>${escapeHtml(n)}</option>`;
                });
            }

            const tech1Default = i.technician && String(i.technician).trim() !== "" ? i.technician : CURRENT_TECH_NAME;
            populate('finish-tech-1', tech1Default);
            populate('finish-tech-2', i.technician_2 || '');
            populate('finish-tech-3', i.technician_3 || '');
            populate('finish-tech-4', i.technician_4 || '');

            closeDetail();
            const m=document.getElementById('finish-modal');
            m.classList.remove('hidden');
            m.classList.add('modal-open');
        }
        function closeFinishModal() {
            const m=document.getElementById('finish-modal');
            m.classList.add('hidden');
            m.classList.remove('modal-open');
        }
        async function submitFinish(btn) {
            showConfirm("Selesaikan tugas?", async()=>{
                const id = document.getElementById('finish-id').value;
                const i = allData.find(x => String(x.id) === String(id));
                if (!i) { showAlert("Data tidak ditemukan", "error"); return; }

                const p = {
                    ...i,
                    action: 'save',
                    id: id,
                    status: 'Selesai',

                    customer_name: document.getElementById('finish-name').value,
                    customer_phone: document.getElementById('finish-phone').value,
                    address: document.getElementById('finish-address').value,
                    pop: document.getElementById('finish-pop').value,
                    plan_name: document.getElementById('finish-plan').value,
                    price: document.getElementById('finish-price').value.replace(/[^0-9]/g,''),

                    coordinates: i.coordinates,

                    notes: document.getElementById('finish-note').value,

                    sales_name: document.getElementById('finish-sales-1').value,
                    sales_name_2: document.getElementById('finish-sales-2').value,
                    sales_name_3: document.getElementById('finish-sales-3').value,

                    technician: document.getElementById('finish-tech-1').value,
                    technician_2: document.getElementById('finish-tech-2').value,
                    technician_3: document.getElementById('finish-tech-3').value,
                    technician_4: document.getElementById('finish-tech-4').value
                };

                p.finished_at = nowDatetimeLocal();

                try {
                    logTechEvent(buildTechEvent('finish', id));
                    const resp = await apiPost(p);
                    if (resp.status !== 'success') {
                        showAlert("Gagal: " + (resp.msg || 'Error'), "error");
                        logClientError('submitFinish', resp.msg || 'error');
                        return;
                    }
                    closeFinishModal();
                    await loadTasks();
                    showAlert("Selesai!");
                } catch(e) {
                    showAlert("Gagal koneksi", "error");
                    logClientError('submitFinish', e.message || 'error');
                }
            });
        }

        function getRekapJobTeams(job) {
            const techTeam = [job.technician, job.technician_2, job.technician_3, job.technician_4]
                .filter(t => t && String(t).trim() !== '')
                .join(', ');
            const salesTeam = [job.sales_name, job.sales_name_2, job.sales_name_3]
                .filter(s => s && String(s).trim() !== '')
                .join(', ');
            return { techTeam, salesTeam };
        }

        function renderRekapJobList(jobs) {
            const emptyEl = document.getElementById('rekap-job-empty');
            const listEl = document.getElementById('rekap-job-list');
            if (emptyEl) {
                if (jobs.length === 0) emptyEl.classList.remove('hidden');
                else emptyEl.classList.add('hidden');
            }
            if (!listEl) return;

            let html = '';
            jobs.forEach(j => {
                const teams = getRekapJobTeams(j);
                const harga = formatRupiahTyping(String(j.price || ''), 'Rp. ');
                html += `<div class="flex justify-between border-b border-slate-100 pb-2 mb-2">
                            <div class="flex flex-col">
                                <span class="font-bold text-slate-700 text-xs">${escapeHtml(j.customer_name || '-')}</span>
                                <span class="text-[10px] text-slate-400">${escapeHtml(teams.techTeam || '-')}</span>
                            </div>
                            <span class="font-bold text-blue-600 text-xs">${escapeHtml(harga)}</span>
                         </div>`;
            });
            listEl.innerHTML = html;
        }

        function getRekapExpenses() {
            return Array.isArray(rekapExpenseItems) ? rekapExpenseItems : [];
        }

        function normalizeRekapExpenses(items) {
            const safeItems = Array.isArray(items) ? items : [];
            const cleaned = [];
            safeItems.forEach(item => {
                if (!item) return;
                const name = String(item.name || '').trim();
                const amount = parseRupiahValue(item.amount || 0);
                if (!name && !amount) return;
                cleaned.push({ name: name || 'Pengeluaran', amount: amount || 0 });
            });
            return cleaned.filter(item => item.amount > 0);
        }

        function parseRekapExpenseSmartInput(raw) {
            const text = String(raw || '').trim();
            if (!text) return [];
            const rows = text.split(/\r?\n/);
            const parts = [];
            rows.forEach(row => {
                row.split(',').forEach(part => {
                    const trimmed = part.trim();
                    if (trimmed) parts.push(trimmed);
                });
            });

            const items = [];
            parts.forEach(line => {
                const digits = line.replace(/\D/g, '');
                if (!digits) return;
                const amount = parseInt(digits, 10);
                if (!amount) return;
                let name = line.replace(/rp\.?/ig, '');
                name = name.replace(/[\d.,]/g, ' ').replace(/[:\-]+/g, ' ');
                name = name.replace(/\s+/g, ' ').trim();
                items.push({ name: name || 'Pengeluaran', amount });
            });
            return normalizeRekapExpenses(items);
        }

        function formatRekapExpenseSmartInput(items) {
            const safeItems = normalizeRekapExpenses(items);
            return safeItems.map(item => `${item.name} ${formatRupiahValue(item.amount)}`).join('\n');
        }

        function setRekapExpenseInputValue(text) {
            const input = document.getElementById('rekap-expense-input');
            if (!input) return;
            input.value = text || '';
        }

        function renderRekapExpenseDisplay(items) {
            const wrap = document.getElementById('rekap-expense-display-wrap');
            const list = document.getElementById('rekap-expense-display');
            if (!wrap || !list) return;
            const safeItems = normalizeRekapExpenses(items);
            if (safeItems.length === 0) {
                wrap.classList.add('hidden');
                list.innerHTML = '';
                return;
            }
            wrap.classList.remove('hidden');
            list.innerHTML = safeItems.map(item => `
                <div class="flex items-center justify-between">
                    <span class="font-medium">${escapeHtml(item.name)}</span>
                    <span class="font-bold">${escapeHtml(formatRupiahValue(item.amount))}</span>
                </div>
            `).join('');
        }

        function setRekapExpenseItems(items, options = {}) {
            rekapExpenseItems = normalizeRekapExpenses(items);
            renderRekapExpenseDisplay(rekapExpenseItems);
            updateRekapPreview();
            if (!options.skipSave) scheduleRekapExpenseSave();
        }

        function bindRekapExpenseInput() {
            const input = document.getElementById('rekap-expense-input');
            if (!input || input.dataset.bound === '1') return;
            input.dataset.bound = '1';
            const handler = () => {
                const items = parseRekapExpenseSmartInput(input.value);
                setRekapExpenseItems(items);
            };
            input.addEventListener('input', handler);
            input.addEventListener('change', handler);
        }

        function toggleRekapExpenseInput() {
            const box = document.getElementById('rekap-expense-smart');
            const toggle = document.getElementById('rekap-expense-toggle');
            if (!box) return;
            box.classList.toggle('hidden');
            if (toggle) {
                if (box.classList.contains('hidden')) toggle.classList.remove('hidden');
                else toggle.classList.add('hidden');
            }
            if (!box.classList.contains('hidden')) {
                const input = document.getElementById('rekap-expense-input');
                if (input) input.focus();
            }
        }

        function scheduleRekapExpenseSave() {
            if (rekapExpenseLoading) return;
            if (!rekapDateCache) return;
            if (rekapExpenseSaveTimer) clearTimeout(rekapExpenseSaveTimer);
            rekapExpenseSaveTimer = setTimeout(() => {
                saveRekapExpenses();
            }, REKAP_EXPENSE_DEBOUNCE_MS);
        }

        async function saveRekapExpenses() {
            if (rekapExpenseLoading) return;
            if (!rekapDateCache) return;
            try {
                const expenses = getRekapExpenses();
                const result = await apiPost({ action: 'save_rekap_expenses', date: rekapDateCache, expenses });
                if (result.status !== 'success') throw new Error(result.msg || 'Gagal simpan');
            } catch (e) {
                logClientError('saveRekapExpenses', e.message || 'error');
            }
        }

        async function loadRekapExpenses(dateStr) {
            if (!dateStr) return;
            rekapExpenseLoading = true;
            try {
                const result = await apiGet({ action: 'get_rekap_expenses', date: dateStr });
                if (result.status !== 'success') throw new Error(result.msg || 'Gagal memuat');
                const expenses = result.data && Array.isArray(result.data.expenses) ? result.data.expenses : [];
                setRekapExpenseItems(expenses, { skipSave: true });
                setRekapExpenseInputValue(formatRekapExpenseSmartInput(expenses));
            } catch (e) {
                setRekapExpenseItems([], { skipSave: true });
                setRekapExpenseInputValue('');
                logClientError('loadRekapExpenses', e.message || 'error');
            } finally {
                rekapExpenseLoading = false;
                updateRekapPreview();
            }
        }

        function updateRekapPreview() {
            const preview = document.getElementById('rekap-preview');
            if (!preview) return;

            const jobs = Array.isArray(rekapJobsCache) ? rekapJobsCache : [];
            const headerDate = rekapHeaderDate || new Date().toLocaleDateString('id-ID', {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            });

            let txt = `${headerDate}.\n\n`;

            jobs.forEach(j => {
                const teams = getRekapJobTeams(j);
                const harga = formatRupiahTyping(String(j.price || ''), 'Rp. ');
                txt += `${(j.customer_name || '').toUpperCase()}\n`;
                txt += `${j.customer_phone || '-'}\n`;
                txt += `${(j.address || '').toUpperCase()}\n`;
                txt += `${(teams.techTeam || '-').toUpperCase()}\n`;
                txt += `SALES : ${teams.salesTeam || '-'}\n`;
                txt += `${harga}\n\n`;
            });

            const expenses = getRekapExpenses();
            if (expenses.length > 0) {
                txt = txt.trimEnd() + '\n\n';
                expenses.forEach(item => {
                    const label = item.name || 'Pengeluaran';
                    txt += `${label} ${formatRupiahValue(item.amount)}\n`;
                });
                txt = txt.trimEnd() + '\n\n';
            }

            const incomeTotal = jobs.reduce((sum, j) => sum + parseRupiahValue(j.price), 0);
            const expenseTotal = expenses.reduce((sum, item) => sum + item.amount, 0);
            if (jobs.length > 0 || expenses.length > 0) {
                txt = txt.trimEnd() + '\n\n';
                txt += `Masuk : ${formatRupiahValue(incomeTotal)}\n`;
                txt += `Keluar : ${formatRupiahValue(expenseTotal)}\n`;
                txt += `Saldo : ${formatRupiahValue(incomeTotal - expenseTotal)}`;
            }

            preview.value = txt;
        }

        function openRekapModal() {
            const now = new Date();
            const todayStr = now.getFullYear() + '-' + pad2(now.getMonth() + 1) + '-' + pad2(now.getDate());

            const jobs = allData.filter(i =>
                i.status === 'Selesai' &&
                (i.finished_at || '').startsWith(todayStr) &&
                isAssignedToCurrent(i)
            );

            const dateOptions = { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' };
            rekapHeaderDate = now.toLocaleDateString('id-ID', dateOptions);
            rekapJobsCache = jobs;
            rekapDateCache = todayStr;

            renderRekapJobList(jobs);
            bindRekapExpenseInput();
            setRekapExpenseItems([], { skipSave: true });
            setRekapExpenseInputValue('');
            updateRekapPreview();
            loadRekapExpenses(todayStr);

            const m=document.getElementById('rekap-modal');
            m.classList.remove('hidden');
            m.classList.add('modal-open');
        }
        function closeRekapModal() {
            const m=document.getElementById('rekap-modal');
            if (!m) return;
            m.classList.add('hidden');
            m.classList.remove('modal-open');
        }
        function copyRekap() {
            const t=document.getElementById('rekap-preview');
            t.select();
            navigator.clipboard.writeText(t.value);
            showAlert("Disalin");
        }
        async function shareRekapWa() {
            const previewEl = document.getElementById('rekap-preview');
            if (!previewEl) return;
            let msg = previewEl.value || '';
            const fileInput = document.getElementById('rekap-proof-file');
            const file = fileInput?.files?.[0];
            if (file) {
                try {
                    const form = new FormData();
                    form.append('action', 'upload_rekap_bukti');
                    form.append('file', file);
                    if (rekapDateCache) form.append('date', rekapDateCache);
                    const res = await apiPostForm(form);
                    if (res.status !== 'success') throw new Error(res.msg || 'Gagal upload bukti');
                    const url = res.data?.file_url || res.data?.file_path || '';
                    if (url) msg += `\n\nBukti Transfer: ${url}`;
                } catch (e) {
                    showAlert(e.message || 'Gagal upload bukti');
                    return;
                }
            }
            window.open(`https://wa.me/?text=${encodeURIComponent(msg)}`, '_blank');
        }
        function confirmLogout() {
            showConfirm("Keluar?", () => {
                logTechEvent(buildTechEvent('logout'));
                window.location.href = 'login.php?action=logout';
            });
        }
