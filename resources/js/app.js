import './bootstrap';

// Paginasi tabel di sisi klien (RF: "tabel bisa ditambahkan pagination") — dipasang
// lewat x-data="dataTable(perPage)" pada wrapper .table-scroll, dengan x-ref="tbody"
// pada <tbody>-nya. Sengaja di sisi klien (bukan Livewire::paginate di server) supaya
// bisa dipasang di tabel manapun tanpa mengubah query/agregat yang sudah ada di
// belakangnya — cukup menyembunyikan <tr> di luar halaman aktif.
document.addEventListener('alpine:init', () => {
    Alpine.data('dataTable', (perPage = 10) => ({
        perPage,
        page: 1,
        totalRows: 0,
        rows: [],

        init() {
            this.tbody = this.$refs.tbody;
            this.observer = new MutationObserver(() => this.refresh(true));
            this.observer.observe(this.tbody, { childList: true });
            this.refresh(false);
        },

        refresh(resetPage) {
            this.rows = Array.from(this.tbody.children).filter((el) => el.tagName === 'TR');
            this.totalRows = this.rows.length;
            if (resetPage) this.page = 1;
            this.apply();
        },

        get totalPages() {
            return Math.max(1, Math.ceil(this.totalRows / (this.perPage || 1)));
        },

        get startRow() {
            return this.totalRows === 0 ? 0 : (this.page - 1) * this.perPage + 1;
        },

        get endRow() {
            return Math.min(this.totalRows, this.page * this.perPage);
        },

        goToPage(p) {
            this.page = Math.min(Math.max(1, p), this.totalPages);
            this.apply();
        },

        apply() {
            const start = (this.page - 1) * this.perPage;
            const end = start + this.perPage;
            this.rows.forEach((row, i) => {
                row.style.display = i >= start && i < end ? '' : 'none';
            });
        },
    }));

    // State dialog konfirmasi global — markup-nya di <x-dialog-konfirmasi> (layout).
    Alpine.store('dialog', {
        buka: false,
        judul: '',
        pesan: '',
        labelOk: 'Ya',
        labelBatal: 'Batal',
        bahaya: false,
        _selesai: null,

        tanya(opsi) {
            // Dialog sebelumnya yang masih terbuka dianggap dibatalkan.
            if (this._selesai) this.jawab(false);
            Object.assign(this, {
                judul: 'Konfirmasi',
                labelOk: 'Ya',
                labelBatal: 'Batal',
                bahaya: false,
            }, opsi, { buka: true });
            requestAnimationFrame(() => document.querySelector('[data-dialog-ok]')?.focus());
            return new Promise((selesai) => { this._selesai = selesai; });
        },

        jawab(ok) {
            this.buka = false;
            const selesai = this._selesai;
            this._selesai = null;
            if (selesai) selesai(ok);
        },
    });
});

// Pengganti confirm() bawaan browser: tampil sebagai modal bertema SIPINTER (ikut
// light/dark mode). Mengembalikan Promise<bool> — pakai dengan await.
window.sipinterKonfirmasi = (opsi) => window.Alpine.store('dialog')
    .tanya(typeof opsi === 'string' ? { pesan: opsi } : opsi);

// Penjaga "jangan sampai perubahan hilang". <x-status-simpan> menandai dirinya dengan
// atribut data-kotor selama ada perubahan yang belum disimpan. Semua cara meninggalkan
// halaman yang bisa dicegat dari dalam halaman — menu (wire:navigate), tautan biasa,
// form (mis. Keluar), dan F5 / Ctrl+R — meminta konfirmasi lewat dialog bertema.
// Hanya tombol reload/tutup tab milik browser sendiri yang tidak bisa dicegat; untuk
// itu terpaksa tetap memakai peringatan bawaan browser (beforeunload) sebagai jaring
// pengaman terakhir — tampilan dialog tersebut memang tidak dapat diubah oleh situs.
let lewatiPenjaga = false;
const adaPerubahan = () => ! lewatiPenjaga && document.querySelector('.status-simpan[data-kotor]') !== null;
const tanyaTinggalkan = () => window.sipinterKonfirmasi({
    judul: 'Tinggalkan Halaman?',
    pesan: 'Ada perubahan yang belum disimpan. Bila halaman ini ditinggalkan, perubahan tersebut akan hilang.\nKlik "Simpan Draft" dulu bila ingin menyimpannya.',
    labelOk: 'Tinggalkan',
    labelBatal: 'Tetap di sini',
    bahaya: true,
});
const tinggalkan = (aksi) => tanyaTinggalkan().then((ok) => {
    if (! ok) return;
    lewatiPenjaga = true;
    aksi();
});

document.addEventListener('livewire:navigate', (e) => {
    if (! adaPerubahan()) return;
    e.preventDefault();
    tinggalkan(() => window.Livewire.navigate(e.detail.url.toString()));
});
document.addEventListener('livewire:navigated', () => { lewatiPenjaga = false; });

document.addEventListener('keydown', (e) => {
    const muatUlang = e.key === 'F5' || ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'r');
    if (! muatUlang || ! adaPerubahan()) return;
    e.preventDefault();
    tinggalkan(() => window.location.reload());
}, true);

document.addEventListener('click', (e) => {
    const a = e.target.closest?.('a[href]');
    if (! a || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    if (a.hasAttribute('wire:navigate') || a.hasAttribute('download') || a.closest('[data-abaikan-perubahan]')) return;
    if ((a.target && a.target !== '_self') || /^(#|javascript:|mailto:|tel:)/i.test(a.getAttribute('href'))) return;
    if (! adaPerubahan()) return;
    e.preventDefault();
    tinggalkan(() => { window.location.href = a.href; });
}, true);

document.addEventListener('submit', (e) => {
    const form = e.target;
    if (e.defaultPrevented || (form.target && form.target !== '_self')) return;
    if (Array.from(form.attributes).some((at) => at.name.startsWith('wire:submit'))) return;
    if (! adaPerubahan()) return;
    e.preventDefault();
    tinggalkan(() => form.submit());
}, true);

window.addEventListener('beforeunload', (e) => {
    if (! adaPerubahan()) return;
    e.preventDefault();
    e.returnValue = '';
});
