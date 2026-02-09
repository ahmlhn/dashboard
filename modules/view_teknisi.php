<?php
$teknisiName = $_SESSION['teknisi_name'] ?? $_SESSION['admin_name'] ?? 'Teknisi';
$teknisiPop = $_SESSION['teknisi_pop'] ?? '';
$teknisiRole = $_SESSION['teknisi_role'] ?? ($_SESSION['level'] ?? 'teknisi');
?>
<style>
    .fade-in { animation: fadeIn 0.25s ease-in-out; }
    @keyframes fadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
    .modal-open { display: flex; animation: fadeIn 0.2s ease-out; }
    textarea::-webkit-scrollbar { width: 5px; }
    textarea::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 5px; }
    .tab-active { color: #2563eb; border-bottom: 2px solid #2563eb; background-color: #eff6ff; }
    .tab-inactive { color: #64748b; border-bottom: 2px solid transparent; }
    .dark .tab-active { color: #93c5fd; border-bottom-color: #3b82f6; background-color: rgba(59, 130, 246, 0.18); }
    .dark .tab-inactive { color: #94a3b8; }
    .btn-thumb { height: 3.5rem; font-size: 1rem; font-weight: 700; border-radius: 1rem; }
    @media (max-width: 640px) { .btn-thumb { height: 3.1rem; font-size: 0.95rem; } }
</style>
<div id="teknisi-root" class="text-slate-800 dark:text-slate-100 pb-24" data-tech-name="<?php echo htmlspecialchars($teknisiName, ENT_QUOTES, 'UTF-8'); ?>" data-tech-role="<?php echo htmlspecialchars(strtolower($teknisiRole), ENT_QUOTES, 'UTF-8'); ?>" data-default-pop="<?php echo htmlspecialchars($teknisiPop, ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        @media (max-width: 768px) {
            body button[title="Ganti Tema"] {
                display: none !important;
            }
        }
    </style>
    <div class="sticky top-0 z-30 w-full">
        <div class="absolute inset-x-0 top-0 bottom-0 bg-slate-50/95 dark:bg-slate-900/90 backdrop-blur border-b border-slate-200 dark:border-white/10 pointer-events-none"></div>
        <div class="relative max-w-3xl mx-auto px-3 sm:px-5 pb-2 sm:pb-3 pt-2">
            <div class="grid grid-cols-2 bg-slate-50 dark:bg-slate-900/60 rounded-xl p-1.5 shadow-sm border border-slate-200 dark:border-white/10 gap-1 flex-1">
                <button id="tab-all" onclick="switchTab('all')" class="py-3 text-xs font-bold rounded-lg transition-all flex items-center justify-center gap-2">SEMUA TUGAS <span id="badge-all" class="bg-red-500 text-white text-[10px] px-2 py-0.5 rounded-full hidden">0</span></button>
                <button id="tab-mine" onclick="switchTab('mine')" class="py-3 text-xs font-bold rounded-lg transition-all flex items-center justify-center gap-2">TUGAS SAYA <span id="badge-mine" class="bg-blue-600 text-white text-[10px] px-2 py-0.5 rounded-full hidden">0</span></button>
            </div>
        </div>
    </div>

    <div class="max-w-3xl mx-auto pt-4">
        <div class="px-3 sm:px-5 space-y-3 sm:space-y-4 mt-1">
            <div class="space-y-2 w-full">
                <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-2">
                    <select id="filter-pop" onchange="handleFilterChange('pop')" class="w-full h-12 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 text-xs font-bold rounded-xl px-2 shadow-sm outline-none text-slate-700 dark:text-slate-200 truncate"><option value="">Semua Area</option></select>
                    <div id="filter-status-wrapper" class="w-28 sm:w-36">
                        <select id="filter-status" onchange="handleFilterChange('status')" class="w-full h-12 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 text-xs font-bold rounded-xl px-2 shadow-sm outline-none text-slate-700 dark:text-slate-200">
                            <option value="">Status</option>
                            <option value="Proses">Proses</option>
                            <option value="Pending">Pending</option>
                            <option value="Req_Batal">Req Batal</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-2 w-full">
                    <div class="relative flex-1">
                        <div class="relative h-12 bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl shadow-sm flex items-center overflow-hidden focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-200 dark:focus-within:ring-blue-500/30">
                            <div class="absolute left-0 top-0 bottom-0 w-12 flex items-center justify-center pointer-events-none text-slate-500 dark:text-slate-400"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg></div>
                            <input type="text" id="search-input" onkeyup="handleSearchInput()" class="w-full h-full pl-12 pr-10 text-sm font-bold text-slate-700 dark:text-slate-100 bg-transparent border-none outline-none placeholder-slate-400 dark:placeholder-slate-500 focus:placeholder-slate-400 focus:cursor-text" placeholder="Cari nama / alamat..." autocomplete="off">
                            <button onclick="clearSearch()" id="btn-clear-search" class="absolute right-0 top-0 bottom-0 w-10 flex items-center justify-center text-slate-400 hover:text-red-500 dark:text-slate-500 dark:hover:text-red-400 hidden"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                        </div>
                    </div>
                    <button onclick="openNewInstallModal()" class="h-12 w-12 flex items-center justify-center bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 rounded-xl text-blue-600 dark:text-blue-300 shadow-sm hover:bg-blue-50 dark:hover:bg-slate-800 transition active:scale-95" title="Input Pasang Baru">
                        <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    </button>
                    <button onclick="refreshTasks()" class="h-12 w-12 flex items-center justify-center bg-white dark:bg-slate-900 border border-slate-200 dark:border-white/10 rounded-xl text-slate-600 dark:text-slate-300 shadow-sm hover:bg-blue-50 dark:hover:bg-slate-800 transition active:scale-95" title="Refresh">
                        <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                </div>
            </div>

            <div id="loading" class="text-center py-10 hidden"><div class="animate-spin rounded-full h-10 w-10 border-b-4 border-blue-600 mx-auto"></div><p class="text-sm text-slate-400 dark:text-slate-500 mt-3 font-bold">Memuat...</p></div>
            <div id="task-list" class="grid grid-cols-1 gap-3 sm:gap-4 pb-10"></div>
            <div id="empty-state" class="hidden text-center py-16 sm:py-20"><div class="w-20 h-20 bg-slate-200 dark:bg-slate-800 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-400 dark:text-slate-500"><svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><h3 class="text-slate-600 dark:text-slate-300 font-bold text-base">Tidak ada tugas</h3></div>
        </div>
    </div>

    <div id="detail-modal" class="fixed inset-0 bg-black/60 z-40 hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh] border border-slate-200 dark:border-white/10">
            <div class="bg-slate-50 dark:bg-slate-900 px-5 py-4 border-b border-slate-200 dark:border-white/10 flex justify-between items-center">
                <h3 class="font-bold text-lg text-slate-800 dark:text-slate-100">Detail & Aksi</h3>
                <div class="flex items-center gap-2">
                    <div id="header-actions"></div>
                    <button onclick="closeDetail()" aria-label="Tutup" class="h-9 w-9 flex items-center justify-center rounded-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-white/10 text-slate-500 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 hover:text-slate-700 dark:hover:text-white transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
            <div id="modal-content" class="p-0 overflow-y-auto text-sm bg-slate-50/50 dark:bg-slate-900/50"></div>
            <div id="modal-footer" class="p-5 border-t border-slate-100 dark:border-white/10 bg-white dark:bg-slate-900 shadow-[0_-5px_10px_rgba(0,0,0,0.02)]"></div>
        </div>
    </div>

    <div id="new-install-modal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[95vh] border border-slate-200 dark:border-white/10">
            <div class="bg-slate-900 dark:bg-slate-800 px-5 py-4 border-b border-slate-800 dark:border-white/10 flex justify-between items-center text-white">
                <h3 class="font-bold text-lg">Input Pasang Baru</h3>
                <button onclick="closeNewInstallModal()" aria-label="Tutup" class="h-9 w-9 flex items-center justify-center rounded-full bg-white/10 hover:bg-white/20 text-white transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-5 overflow-y-auto bg-slate-50 dark:bg-slate-900/60 space-y-4">
                <div class="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 space-y-3">
                    <h4 class="text-xs font-bold text-blue-600 uppercase border-b border-slate-200 dark:border-white/10 pb-2">Data Pelanggan</h4>
                    <input type="text" id="new-install-name" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="Nama Pelanggan *" required>
                    <input type="tel" id="new-install-phone" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="WhatsApp (08xxxxxxxxxx) *" required>
                    <textarea id="new-install-address" rows="2" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="Alamat Lengkap *" required></textarea>
                    <div class="grid grid-cols-2 gap-3">
                        <select id="new-install-pop" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" required>
                            <option value="" disabled selected>POP *</option>
                        </select>
                        <select id="new-install-plan" onchange="this.classList.remove('text-slate-400'); this.classList.remove('dark:text-slate-400'); this.classList.add('text-slate-700'); this.classList.add('dark:text-slate-100');" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 outline-none text-slate-400 dark:text-slate-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 dark:focus:ring-blue-500/30 transition">
                            <option value="" disabled selected>Paket</option>
                            <option value="Standar - 10 Mbps" class="text-slate-700">Standar - 10 Mbps</option>
                            <option value="Premium - 15 Mbps" class="text-slate-700">Premium - 15 Mbps</option>
                            <option value="Wide - 20 Mbps" class="text-slate-700">Wide - 20 Mbps</option>
                            <option value="PRO - 50 Mbps" class="text-slate-700">PRO - 50 Mbps</option>
                        </select>
                    </div>
                    <input type="text" id="new-install-price" class="w-full text-base font-bold text-green-700 dark:text-emerald-200 border-2 border-green-200 dark:border-emerald-700 bg-green-50 dark:bg-emerald-900/30 rounded-xl p-3" placeholder="Rp. 0" onkeyup="this.value = formatRupiahTyping(this.value, 'Rp. ')">
                </div>
                <div class="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 space-y-3">
                    <h4 class="text-xs font-bold text-blue-600 uppercase border-b border-slate-200 dark:border-white/10 pb-2">Tim & Sales</h4>
                    <input type="text" id="new-install-sales-1" placeholder="Sales 1" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100">
                    <div class="grid grid-cols-2 gap-3">
                        <input type="text" id="new-install-sales-2" placeholder="Sales 2" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100">
                        <input type="text" id="new-install-sales-3" placeholder="Sales 3" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase block mb-1">Teknisi 1</label>
                            <select id="new-install-tech-1" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-2 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><option value="">-</option></select>
                        </div>
                        <div>
                            <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase block mb-1">Teknisi 2</label>
                            <select id="new-install-tech-2" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-2 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><option value="">-</option></select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase block mb-1">Teknisi 3</label>
                            <select id="new-install-tech-3" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-2 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><option value="">-</option></select>
                        </div>
                        <div>
                            <label class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase block mb-1">Teknisi 4</label>
                            <select id="new-install-tech-4" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-2 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><option value="">-</option></select>
                        </div>
                    </div>
                </div>
                <div class="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 space-y-3">
                    <h4 class="text-xs font-bold text-blue-600 uppercase border-b border-slate-200 dark:border-white/10 pb-2">Informasi Tambahan</h4>
                    <input type="date" id="new-install-date" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100">
                    <input type="text" id="new-install-coords" placeholder="Koordinat (lat,lng)" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100">
                    <textarea id="new-install-notes" rows="2" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="Catatan tambahan..."></textarea>
                </div>
            </div>
            <div class="p-5 border-t border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 grid grid-cols-2 gap-4">
                <button onclick="closeNewInstallModal()" class="btn-thumb bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 active:scale-95 transition">BATAL</button>
                <button id="btn-save-new" onclick="saveNewInstallManual()" class="btn-thumb bg-blue-600 text-white shadow-lg active:scale-95 transition">SIMPAN</button>
            </div>
        </div>
    </div>

    <div id="claim-modal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white dark:bg-slate-900 w-full max-w-sm rounded-3xl shadow-2xl p-6 animate-fade-in border-t-8 border-blue-600 border border-slate-200 dark:border-white/10">
            <div class="text-center mb-6"><h3 class="font-extrabold text-2xl text-slate-800 dark:text-slate-100">Ambil Tugas?</h3><p id="claim-recommendation-note" class="text-sm text-slate-500 dark:text-slate-400">Jadwal pasang direkomendasikan hari ini.</p></div>
            <input type="hidden" id="claim-id">
            <input type="hidden" id="claim-date">
            <div class="space-y-4 mb-6">
                <div class="bg-slate-50 dark:bg-slate-800 border-2 border-slate-300 dark:border-white/10 rounded-2xl px-4 py-3 text-center">
                    <div class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase">Rekomendasi Jadwal</div>
                    <div id="claim-date-label" class="text-lg font-bold text-slate-800 dark:text-slate-100">-</div>
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase">Teknisi Utama</label>
                    <select id="claim-tech-main" class="w-full h-12 text-base font-bold bg-slate-50 dark:bg-slate-800 border-2 border-slate-300 dark:border-white/10 rounded-2xl px-4 text-slate-800 dark:text-slate-100"></select>
                </div>
                <div class="space-y-2">
                    <label class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase">Tim Support (Opsional)</label>
                    <select id="claim-tech-2" class="w-full h-11 text-sm font-bold bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl px-3 text-slate-700 dark:text-slate-100"></select>
                    <div id="claim-tech-3-wrap" class="hidden">
                        <select id="claim-tech-3" class="w-full h-11 text-sm font-bold bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl px-3 text-slate-700 dark:text-slate-100"></select>
                    </div>
                    <div id="claim-tech-4-wrap" class="hidden">
                        <select id="claim-tech-4" class="w-full h-11 text-sm font-bold bg-white dark:bg-slate-900 border border-slate-300 dark:border-white/10 rounded-xl px-3 text-slate-700 dark:text-slate-100"></select>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4"><button onclick="closeClaimModal()" class="btn-thumb bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 active:scale-95 transition">BATAL</button><button onclick="submitClaim(this)" class="btn-thumb bg-blue-600 text-white shadow-lg active:scale-95 transition">AMBIL</button></div>
        </div>
    </div>

    <div id="review-modal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[95vh] border border-slate-200 dark:border-white/10">
            <div class="bg-slate-900 dark:bg-slate-800 px-5 py-4 border-b border-slate-800 dark:border-white/10 flex justify-between items-center text-white">
                <div><h3 class="font-bold text-lg">Tinjau Pembatalan</h3><p class="text-[10px] text-slate-300">Keputusan SVP</p></div>
                <button onclick="closeReviewModal()" aria-label="Tutup" class="h-9 w-9 flex items-center justify-center rounded-full bg-white/10 hover:bg-white/20 text-white transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-5 overflow-y-auto bg-slate-50 dark:bg-slate-900/60 space-y-4">
                <input type="hidden" id="review-id">
                <div class="bg-white dark:bg-slate-800 p-4 rounded-xl border border-slate-200 dark:border-white/10 shadow-sm space-y-2"><h4 class="text-[10px] font-bold text-blue-600 uppercase border-b border-slate-200 dark:border-white/10 pb-1 mb-2">Data Pelanggan</h4><div><div class="text-sm font-bold text-slate-800 dark:text-slate-100" id="review-name">-</div><div class="text-xs text-blue-600 font-bold" id="review-plan">-</div></div><div class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed" id="review-address">-</div></div>
                <div class="bg-red-50 dark:bg-red-900/20 p-4 rounded-xl border border-red-100 dark:border-red-500/20 shadow-sm"><h4 class="text-[10px] font-bold text-red-600 uppercase border-b border-red-200 dark:border-red-500/20 pb-1 mb-2">History & Alasan</h4><div id="review-history" class="text-xs text-slate-700 dark:text-slate-200 font-mono whitespace-pre-wrap max-h-32 overflow-y-auto p-2 bg-white/50 dark:bg-slate-800/60 rounded border border-red-100 dark:border-red-500/20"></div></div>
                <div><label class="text-xs font-bold text-slate-800 dark:text-slate-200 uppercase block mb-2">Catatan (Wajib jika Tolak)</label><textarea id="review-note" rows="3" class="w-full text-sm border-2 border-slate-300 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500" placeholder="Alasan penolakan / tambahan..."></textarea></div>
            </div>
            <div class="p-5 border-t border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 grid grid-cols-2 gap-4"><button onclick="submitReview('reject')" class="h-14 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-bold rounded-2xl text-sm border-2 border-slate-200 dark:border-white/10">TOLAK BATAL</button><button onclick="submitReview('approve')" class="h-14 bg-red-600 text-white font-bold rounded-2xl text-sm shadow-lg">ACC BATAL</button></div>
        </div>
    </div>

    <div id="pending-modal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white dark:bg-slate-900 w-full max-w-sm rounded-3xl shadow-2xl p-6 animate-fade-in border border-slate-200 dark:border-white/10">
            <div class="text-center mb-6"><h3 class="font-extrabold text-xl text-slate-800 dark:text-slate-100">Pending / Tunda</h3></div>
            <input type="hidden" id="pending-id">
            <div class="space-y-4 mb-6">
                <div><label class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase">Alasan</label><textarea id="pending-reason" rows="2" class="w-full text-base border-2 border-slate-300 dark:border-white/10 rounded-2xl px-4 py-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"></textarea></div>
                <div><label class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase">Jadwal Ulang</label><input type="date" id="pending-date" class="w-full h-12 text-base font-bold bg-slate-50 dark:bg-slate-800 border-2 border-slate-300 dark:border-white/10 rounded-2xl px-3 text-center text-slate-800 dark:text-slate-100"></div>
            </div>
            <div class="grid grid-cols-2 gap-4"><button onclick="closePendingModal()" class="btn-thumb bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 active:scale-95 transition">BATAL</button><button onclick="submitPending(this)" class="btn-thumb bg-orange-500 text-white shadow-lg active:scale-95 transition">SIMPAN</button></div>
        </div>
    </div>

    <div id="cancel-req-modal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white dark:bg-slate-900 w-full max-w-sm rounded-3xl shadow-2xl p-6 animate-fade-in border-t-8 border-red-500 border border-slate-200 dark:border-white/10">
            <div class="text-center mb-6"><h3 class="font-extrabold text-xl text-slate-800 dark:text-slate-100">Ajukan Batal</h3><p class="text-xs text-slate-500 dark:text-slate-400">Perlu persetujuan.</p></div>
            <input type="hidden" id="cancel-req-id">
            <div class="mb-6"><label class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase">Alasan</label><textarea id="cancel-req-reason" rows="3" class="w-full text-base border-2 border-slate-300 dark:border-white/10 rounded-2xl px-4 py-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"></textarea></div>
            <div class="grid grid-cols-2 gap-4"><button onclick="closeCancelReqModal()" class="btn-thumb bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 active:scale-95 transition">KEMBALI</button><button onclick="submitCancelReq(this)" class="btn-thumb bg-red-600 text-white shadow-lg active:scale-95 transition">AJUKAN</button></div>
        </div>
    </div>

    <div id="transfer-modal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white dark:bg-slate-900 w-full max-w-sm rounded-3xl shadow-2xl p-6 animate-fade-in border border-slate-200 dark:border-white/10">
            <div class="text-center mb-6"><h3 class="font-extrabold text-xl text-slate-800 dark:text-slate-100">Alihkan Tugas</h3></div>
            <input type="hidden" id="transfer-id">
            <div class="space-y-4 mb-6">
                <div><label class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase">Ke Teknisi</label><select id="transfer-target" class="w-full h-12 text-base font-bold bg-slate-50 dark:bg-slate-800 border-2 border-slate-300 dark:border-white/10 rounded-2xl px-3 text-slate-800 dark:text-slate-100"></select></div>
                <div><label class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase">Alasan</label><textarea id="transfer-reason" rows="2" class="w-full text-base border-2 border-slate-300 dark:border-white/10 rounded-2xl px-4 py-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"></textarea></div>
            </div>
            <div class="grid grid-cols-2 gap-4"><button onclick="closeTransferModal()" class="btn-thumb bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 active:scale-95 transition">BATAL</button><button onclick="submitTransfer(this)" class="btn-thumb bg-indigo-600 text-white shadow-lg active:scale-95 transition">KIRIM</button></div>
        </div>
    </div>

    <div id="finish-modal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[95vh] border border-slate-200 dark:border-white/10">
            <div class="bg-slate-900 dark:bg-slate-800 px-5 py-4 border-b border-slate-800 dark:border-white/10 flex justify-between items-center text-white">
                <h3 class="font-bold text-lg">Validasi Akhir</h3>
                <button onclick="closeFinishModal()" aria-label="Tutup" class="h-9 w-9 flex items-center justify-center rounded-full bg-white/10 hover:bg-white/20 text-white transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-5 overflow-y-auto bg-slate-50 dark:bg-slate-900/60 space-y-5">
                <input type="hidden" id="finish-id">
                <div class="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 space-y-3">
                    <h4 class="text-xs font-bold text-blue-600 uppercase border-b border-slate-200 dark:border-white/10 pb-2">Data Pelanggan</h4>
                    <input type="text" id="finish-name" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="Nama">
                    <input type="tel" id="finish-phone" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="WA">
                    <textarea id="finish-address" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="Alamat"></textarea>
                    <div class="grid grid-cols-2 gap-3">
                        <select id="finish-pop" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100">
                            <option value="">POP</option>
                        </select>

                        <select id="finish-plan" onchange="this.classList.remove('text-slate-400'); this.classList.remove('dark:text-slate-400'); this.classList.add('text-slate-700'); this.classList.add('dark:text-slate-100');" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 outline-none text-slate-400 dark:text-slate-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 dark:focus:ring-blue-500/30 transition">
                            <option value="" disabled selected>Paket</option>
                            <option value="Standar - 10 Mbps" class="text-slate-700">Standar - 10 Mbps</option>
                            <option value="Premium - 15 Mbps" class="text-slate-700">Premium - 15 Mbps</option>
                            <option value="Wide - 20 Mbps" class="text-slate-700">Wide - 20 Mbps</option>
                            <option value="PRO - 50 Mbps" class="text-slate-700">PRO - 50 Mbps</option>
                        </select>
                    </div>

                    <input type="text" id="finish-price" class="w-full text-base font-bold text-green-700 dark:text-emerald-200 border-2 border-green-200 dark:border-emerald-700 bg-green-50 dark:bg-emerald-900/30 rounded-xl p-3" placeholder="Rp. 0" onkeyup="this.value = formatRupiahTyping(this.value, 'Rp. ')">
                </div>
                <div class="bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 space-y-3">
                    <h4 class="text-xs font-bold text-blue-600 uppercase border-b pb-2">Tim & Sales</h4>

                    <input type="text" id="finish-sales-1" placeholder="Sales 1" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100">
                    <div class="grid grid-cols-2 gap-3">
                        <input type="text" id="finish-sales-2" placeholder="Sales 2" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100">
                        <input type="text" id="finish-sales-3" placeholder="Sales 3" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100">
                    </div>

                    <div class="pt-2 border-t border-dashed border-slate-200 dark:border-white/10">
                        <label class="text-[10px] text-slate-400 font-bold uppercase mb-1 block">Teknisi Utama (Lead)</label>
                        <select id="finish-tech-1" class="w-full text-sm border border-blue-200 dark:border-blue-500/30 rounded-xl p-3 bg-blue-50 dark:bg-blue-900/30 font-bold text-blue-800 dark:text-blue-200 outline-none">
                            <option value="">-- Pilih --</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-[10px] text-slate-400 font-bold uppercase mb-1 block">Tim Support</label>
                        <select id="finish-tech-2" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 mb-2"><option value="">-- Tech 2 --</option></select>
                        <div class="grid grid-cols-2 gap-3">
                            <select id="finish-tech-3" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><option value="">-- Tech 3 --</option></select>
                            <select id="finish-tech-4" class="w-full text-sm border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"><option value="">-- Tech 4 --</option></select>
                        </div>
                    </div>
                </div>
                <textarea id="finish-note" rows="3" class="w-full text-sm border-2 border-blue-200 dark:border-blue-500/30 rounded-2xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="Laporan..."></textarea>
            </div>
            <div class="p-5 border-t border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900"><button onclick="submitFinish(this)" class="btn-thumb w-full bg-slate-900 text-white shadow-xl hover:bg-slate-800 active:scale-95 transition">SIMPAN & SELESAI</button></div>
        </div>
    </div>

    <div id="rekap-modal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh] border border-slate-200 dark:border-white/10">
            <div class="bg-green-600 px-5 py-4 text-white flex justify-between items-center"><h3 class="font-bold text-lg">Rekap Harian</h3><button onclick="closeRekapModal()" aria-label="Tutup" class="h-9 w-9 flex items-center justify-center rounded-full bg-white/10 hover:bg-white/20 text-white transition"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button></div>
            <div class="p-5 overflow-y-auto bg-slate-50 dark:bg-slate-900/60 space-y-4">
                <div id="rekap-expense-toggle" class="flex items-center justify-end">
                    <button type="button" onclick="toggleRekapExpenseInput()" class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-bold">
                        Pengeluaran
                    </button>
                </div>

                <div id="rekap-expense-smart" class="hidden bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 space-y-2">
                    <div class="text-xs font-bold text-slate-700 dark:text-slate-200 uppercase">Input Pengeluaran</div>
                    <textarea id="rekap-expense-input" rows="4" class="w-full text-xs font-mono border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100" placeholder="Contoh:&#10;Makan 30000&#10;Rokok 30000&#10;Bensin 30000"></textarea>
                    <div class="text-[10px] text-slate-400">Tulis per baris atau dipisah koma.</div>
                    <div id="rekap-expense-status" class="text-[10px] text-slate-400"></div>
                    <button type="button" onclick="toggleRekapExpenseInput()" class="w-full h-10 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold">
                        Selesai
                    </button>
                </div>

                <div id="rekap-job-list" class="space-y-2 text-sm"></div>
                <div id="rekap-job-empty" class="text-sm text-slate-400 dark:text-slate-500 italic hidden text-center py-4">Belum ada job.</div>

                <div id="rekap-expense-display-wrap" class="hidden bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-white/10 space-y-2">
                    <div class="text-xs font-bold text-slate-700 dark:text-slate-200 uppercase">Pengeluaran</div>
                    <div id="rekap-expense-display" class="space-y-1 text-xs text-slate-600 dark:text-slate-300"></div>
                </div>

                <textarea id="rekap-preview" readonly class="w-full h-52 text-xs font-mono border border-slate-200 dark:border-white/10 rounded-xl p-3 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100"></textarea>
                <div class="bg-white dark:bg-slate-800 p-3 rounded-2xl border border-slate-200 dark:border-white/10">
                    <div class="text-[11px] font-bold text-slate-600 dark:text-slate-300 uppercase">Bukti Transfer</div>
                    <input id="rekap-proof-file" type="file" accept=".jpg,.jpeg,.png,.pdf" class="mt-2 w-full text-[11px] text-slate-600 dark:text-slate-300" />
                    <div class="text-[10px] text-slate-400 mt-1">Opsional. JPG/PNG/PDF. Disertakan pada pesan rekap.</div>
                </div>
            </div>
            <div class="p-5 border-t border-slate-200 dark:border-white/10 bg-white dark:bg-slate-900 grid grid-cols-2 gap-4"><button onclick="copyRekap()" class="btn-thumb bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-200 active:scale-95 transition">SALIN</button><button onclick="shareRekapWa()" class="btn-thumb bg-green-500 text-white shadow-lg active:scale-95 transition">WA GROUP</button></div>
        </div>
    </div>

    <div id="alert-modal" class="fixed inset-0 bg-black/50 z-[60] hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white dark:bg-slate-900 w-full max-w-sm rounded-3xl shadow-2xl p-8 text-center animate-fade-in border border-slate-200 dark:border-white/10">
            <div id="alert-icon-container" class="w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-5"></div>
            <h3 id="alert-title" class="text-2xl font-extrabold text-slate-800 dark:text-slate-100 mb-2">Info</h3><p id="alert-message" class="text-base text-slate-600 dark:text-slate-300 mb-8 font-medium"></p>
            <button onclick="closeAlert()" class="w-full h-14 bg-slate-900 text-white font-bold rounded-2xl text-lg shadow-xl active:scale-95 transition-transform">SIAP, MENGERTI</button>
        </div>
    </div>

    <div id="confirm-modal" class="fixed inset-0 bg-black/50 z-[60] hidden items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white dark:bg-slate-900 w-full max-w-sm rounded-3xl shadow-2xl p-8 text-center animate-fade-in border border-slate-200 dark:border-white/10">
            <div class="w-20 h-20 bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-300 rounded-full flex items-center justify-center mx-auto mb-5"><svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
            <h3 class="text-2xl font-extrabold text-slate-800 dark:text-slate-100 mb-2">Konfirmasi</h3><p id="confirm-message" class="text-base text-slate-600 dark:text-slate-300 mb-8 font-medium"></p>
            <div class="grid grid-cols-2 gap-4"><button onclick="closeConfirm()" class="h-14 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-bold rounded-2xl text-base border border-slate-200 dark:border-white/10 active:scale-95 transition">BATAL</button><button id="btn-confirm-yes" class="h-14 bg-blue-600 text-white font-bold rounded-2xl text-base shadow-xl active:scale-95 transition">YA, LANJUT</button></div>
        </div>
    </div>

</div>
