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

    // Zona unggah drag-and-drop — markup-nya di <x-zona-unggah>. Berkas yang diseret
    // dimasukkan ke <input type="file" wire:model> milik zona itu sendiri lalu dipicu
    // event "change", jadi unggahannya diproses Livewire persis seperti dipilih lewat
    // tombol (termasuk tujuan, validasi & simpan otomatis di server). Tiap zona punya
    // input sendiri, jadi berkas selalu masuk ke kegiatan/folder tempat ia dilepas.
    Alpine.data('zonaUnggah', ({ ekstensi = [], banyak = true } = {}) => ({
        diseret: false,
        sibuk: false,
        progres: 0,
        antrean: [],
        terakhir: [],
        ringkasan: '',
        urut: 0,

        pilih(e) {
            if (e?.target === this.$refs.input || this.sibuk) return;
            this.$refs.input.click();
        },

        adaBerkas(e) {
            return Array.from(e.dataTransfer?.types || []).includes('Files');
        },

        masuk(e) {
            if (! this.adaBerkas(e)) return;
            this.diseret = true;
            e.dataTransfer.dropEffect = this.sibuk ? 'none' : 'copy';
        },

        keluar(e) {
            if (! this.$el.contains(e.relatedTarget)) this.diseret = false;
        },

        jatuhkan(e) {
            this.diseret = false;
            if (this.adaBerkas(e)) this.kirim(Array.from(e.dataTransfer.files));
        },

        sesuai(f) {
            return ekstensi.length === 0 || ekstensi.includes((f.name.split('.').pop() || '').toLowerCase());
        },

        kirim(files) {
            if (this.sibuk) {
                this.ringkasan = 'Tunggu unggahan sebelumnya selesai dulu.';
                return;
            }
            files.filter((f) => ! this.sesuai(f)).forEach((f) => this.tambah(f, 'ditolak'));
            let sah = files.filter((f) => this.sesuai(f));
            if (! banyak) sah = sah.slice(0, 1);
            if (sah.length === 0) return;

            const dt = new DataTransfer();
            sah.forEach((f) => dt.items.add(f));
            this.$refs.input.files = dt.files;
            this.$refs.input.dispatchEvent(new Event('change', { bubbles: true }));
        },

        tambah(f, status) {
            this.antrean.push({ id: ++this.urut, nama: f.name, ukuran: f.size, status });
        },

        mulai() {
            this.terakhir = Array.from(this.$refs.input.files || []);
            this.antrean = this.antrean.filter((b) => b.status !== 'gagal' && b.status !== 'dibatalkan');
            this.terakhir.forEach((f) => this.tambah(f, 'mengunggah'));
            this.sibuk = true;
            this.progres = 0;
            this.ringkasan = '';
        },

        ubahStatus(ke) {
            this.antrean.forEach((b) => { if (b.status === 'mengunggah') b.status = ke; });
            this.sibuk = false;
            const n = (s) => this.antrean.filter((b) => b.status === s).length;
            const bagian = [
                n('tersimpan') && `${n('tersimpan')} terunggah`,
                n('gagal') && `${n('gagal')} gagal`,
                n('ditolak') && `${n('ditolak')} ditolak (bukan format yang diterima)`,
            ].filter(Boolean);
            this.ringkasan = bagian.length ? bagian.join(' · ') + (n('tersimpan') ? ' — status Google Drive tiap berkas tampil di daftar bukti.' : '') : '';
        },

        selesai() { this.progres = 100; this.ubahStatus('tersimpan'); },
        gagal() { this.ubahStatus('gagal'); },
        dibatalkan() { this.ubahStatus('dibatalkan'); },

        batal() {
            this.$wire.cancelUpload(this.$refs.input.getAttribute('wire:model'));
        },

        cobaLagi() {
            const ulang = this.terakhir;
            this.antrean = this.antrean.filter((b) => b.status !== 'gagal');
            this.kirim(ulang);
        },

        buang(i) {
            this.antrean.splice(i, 1);
        },

        ukuran(b) {
            if (b >= 1024 * 1024) return (b / 1024 / 1024).toFixed(1).replace('.', ',') + ' MB';
            return Math.max(1, Math.round(b / 1024)) + ' KB';
        },

        labelStatus(b) {
            return {
                mengunggah: 'mengunggah…',
                tersimpan: '✓ terunggah',
                gagal: '✗ gagal',
                ditolak: '✗ format tidak diterima',
                dibatalkan: 'dibatalkan',
            }[b.status] ?? '';
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

// Berkas yang terlanjur dilepas DI LUAR zona unggah jangan sampai dibuka browser
// sebagai halaman baru (isian yang belum tersimpan bisa hilang) — cukup diabaikan.
['dragover', 'drop'].forEach((jenis) => window.addEventListener(jenis, (e) => {
    if (! Array.from(e.dataTransfer?.types || []).includes('Files')) return;
    if (e.target.closest?.('[data-zona-unggah]')) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'none';
}));

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
