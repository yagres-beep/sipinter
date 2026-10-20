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

    // Bilah lengket (sticky) berisi banner periode + tombol lompat ke tiap bagian form —
    // dipasang lewat x-data="navigasiBagian" pada wrapper .bilah-lengket yang menjadi
    // saudara (sibling) .page-head; tiap tombol membawa data-bagian="<id elemen tujuan>".
    // Wrapper ini lengket TEPAT di bawah .page-head (yang juga sticky), jadi tinggi
    // .page-head diukur langsung (bisa berubah: teks .page-sub membungkus di layar sempit)
    // dan ditaruh di variabel CSS — bukan angka tetap yang gampang meleset.
    // Variabel CSS dipasang di <html>, BUKAN di elemen milik komponen Livewire: atribut
    // style yang ditambahkan JS di elemen komponen akan dibuang lagi saat Livewire
    // me-morph DOM dengan hasil render server.
    Alpine.data('navigasiBagian', () => ({
        aktif: null,
        menempel: false,
        // true selama halaman bergulir akibat klik tombol — selama itu tombol yang
        // diklik tetap ditandai aktif (tidak "berkedip" melewati bagian di antaranya).
        scrollOtomatis: false,

        init() {
            this.judul = this.$el.parentElement.querySelector(':scope > .page-head');
            this.onScroll = () => {
                if (this._raf) return;
                this._raf = requestAnimationFrame(() => {
                    this._raf = null;
                    this.perbarui();
                });
            };
            this.onUserScroll = () => { this.scrollOtomatis = false; };
            this.onResize = () => { this.ukur(); this.perbarui(); };

            window.addEventListener('scroll', this.onScroll, { passive: true });
            window.addEventListener('resize', this.onResize);
            ['wheel', 'touchstart', 'keydown'].forEach((ev) => window.addEventListener(ev, this.onUserScroll, { passive: true }));
            this.ro = new ResizeObserver(this.onResize);
            this.ro.observe(this.$el);
            if (this.judul) this.ro.observe(this.judul);

            this.$watch('aktif', (id) => this.tampilkanTombol(id));
            this.ukur();
            this.perbarui();
        },

        destroy() {
            window.removeEventListener('scroll', this.onScroll);
            window.removeEventListener('resize', this.onResize);
            ['wheel', 'touchstart', 'keydown'].forEach((ev) => window.removeEventListener(ev, this.onUserScroll));
            this.ro?.disconnect();
            document.documentElement.style.removeProperty('--tinggi-judul');
            document.documentElement.style.removeProperty('--offset-lengket');
        },

        // .page-head bisa tidak sticky (layar sempit, lihat CSS) — tingginya lalu tidak
        // ikut dihitung, bilah ini langsung lengket di bawah topbar.
        ukur() {
            const html = document.documentElement.style;
            const judulLengket = this.judul && getComputedStyle(this.judul).position === 'sticky';
            html.setProperty('--tinggi-judul', (judulLengket ? this.judul.offsetHeight : 0) + 'px');
            html.setProperty('--offset-lengket', Math.ceil(this.atasLengket() + this.$el.offsetHeight + 12) + 'px');
        },

        atasLengket() {
            return parseFloat(getComputedStyle(this.$el).top) || 0;
        },

        daftarBagian() {
            return Array.from(this.$el.querySelectorAll('[data-bagian]'))
                .map((tombol) => document.getElementById(tombol.dataset.bagian))
                // bagian yang sedang disembunyikan (x-show) tidak ikut dihitung
                .filter((el) => el && el.getClientRects().length > 0);
        },

        perbarui() {
            this.menempel = this.$el.getBoundingClientRect().top <= this.atasLengket() + 0.5;
            if (! this.scrollOtomatis) this.aktif = this.hitungAktif();
        },

        // Bagian aktif = bagian terakhir yang judulnya sudah melewati batas bawah bilah
        // lengket. Saat halaman mentok di bawah, bagian pendek di akhir tidak akan pernah
        // sampai ke batas itu — maka bagian terakhir yang sudah terlihat dianggap aktif.
        hitungAktif() {
            const bagian = this.daftarBagian();
            if (bagian.length === 0) return null;
            const batas = this.atasLengket() + this.$el.offsetHeight + 16;
            let aktif = bagian[0];
            for (const el of bagian) {
                if (el.getBoundingClientRect().top > batas) break;
                aktif = el;
            }
            const mentokBawah = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2;
            if (mentokBawah) {
                for (const el of bagian) {
                    if (el.getBoundingClientRect().top < window.innerHeight) aktif = el;
                }
            }
            return aktif.id;
        },

        lompatKe(id) {
            const el = document.getElementById(id);
            if (! el || el.getClientRects().length === 0) return;
            this.ukur();
            this.aktif = id;
            this.scrollOtomatis = true;
            // Beri tahu penjaga posisi scroll komponen Livewire (koreksiScroll) supaya
            // tidak menarik balik halaman — klik tombol ini bisa memicu blur pada field
            // wire:model.live.blur yang sedang fokus, yang mengirim request.
            this.$dispatch('lompat-bagian');
            // scroll-margin-top (= --offset-lengket) menjaga judul bagian tidak tertutup
            // bilah lengket.
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            this.tungguScrollSelesai();
        },

        tungguScrollSelesai() {
            let timer;
            const selesai = () => {
                window.removeEventListener('scroll', tunda);
                this.scrollOtomatis = false;
            };
            const tunda = () => { clearTimeout(timer); timer = setTimeout(selesai, 150); };
            window.addEventListener('scroll', tunda, { passive: true });
            tunda();
        },

        // Di layar sempit bilah tombol bisa digeser horizontal — pastikan tombol aktif
        // ikut terlihat. Sengaja tidak memakai scrollIntoView (ikut menggeser halaman).
        tampilkanTombol(id) {
            const daftar = this.$refs.daftar;
            const tombol = daftar?.querySelector(`[data-bagian="${id}"]`);
            if (! tombol) return;
            const kiri = tombol.offsetLeft;
            const kanan = kiri + tombol.offsetWidth;
            if (kiri < daftar.scrollLeft || kanan > daftar.scrollLeft + daftar.clientWidth) {
                daftar.scrollTo({ left: kiri - 16, behavior: 'smooth' });
            }
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
