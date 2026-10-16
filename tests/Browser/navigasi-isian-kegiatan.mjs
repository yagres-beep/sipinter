// Uji browser: konfirmasi meninggalkan Isian Kegiatan & respons Livewire yang terlambat.
//
// Bukan bagian dari `php artisan test` — skenarionya bergantung pada waktu & navigasi
// browser sungguhan (Alpine + wire:navigate), yang tidak bisa ditiru test PHP.
//
// Cara menjalankan (HANYA terhadap aplikasi lokal dengan database uji terpisah):
//   1. .env lokal memakai DB_CONNECTION=sqlite ke berkas uji, lalu `php artisan migrate`,
//      buat satu akun Ketua Tim terverifikasi & satu IKU.
//   2. `npm run build` lalu `php artisan serve --port=8124`
//   3. `npm i --no-save playwright` (sekali saja), lalu:
//      BASE_URL=http://127.0.0.1:8124 UJI_USERNAME=... UJI_PASSWORD=... UJI_IKU=1111 \
//        node tests/Browser/navigasi-isian-kegiatan.mjs
//   Opsional CHROME_PATH bila Chromium bawaan Playwright tidak terpasang.
//
// Server "lambat" ditiru dengan menahan request /update Livewire 1,5 detik — kondisi
// production (Supabase) tempat ketikan masih diproses saat pengguna pindah halaman.
import { chromium } from 'playwright';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8124';
const { hostname } = new URL(BASE);
if (! ['127.0.0.1', 'localhost'].includes(hostname)) {
    console.error(`Menolak berjalan terhadap ${BASE} — uji ini hanya untuk aplikasi lokal.`);
    process.exit(2);
}
const USERNAME = process.env.UJI_USERNAME ?? 'ketua@uji.test';
const PASSWORD = process.env.UJI_PASSWORD ?? 'rahasia123';
const KODE_IKU = process.env.UJI_IKU ?? '1111';
const TUNDA_MS = 1500;

const hasil = [];
const cek = (nama, ok, info = '') => hasil.push({ nama, ok, info });

const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

const galat = [];
page.on('pageerror', (e) => galat.push(e.message));
// Semua console.error/console.warn dihitung galat (termasuk "Alpine Expression Error"),
// kecuali penanda DEBUG milik skrip ini sendiri dan saran performa Chrome soal CSS yang
// di-preload (bukan galat JavaScript; muncul karena wire:navigate menukar halaman).
const bukanGalatJs = (t) => t.startsWith('UNHANDLED') || /was preloaded using link preload but not used/.test(t);
page.on('console', (m) => {
    if (['error', 'warning'].includes(m.type()) && ! bukanGalatJs(m.text())) galat.push(`console.${m.type()}: ${m.text().split('\n')[0]}`);
});
// DEBUG=1 → cetak urutan waktu navigasi, request Livewire & galat.
const t0 = Date.now();
const linimasa = [];
const catat = (s) => process.env.DEBUG && linimasa.push(`[${String(Date.now() - t0).padStart(6)}ms] ${s}`);
page.on('framenavigated', (f) => f === page.mainFrame() && catat('NAV  ' + f.url()));
page.on('request', (r) => r.url().includes('/update') && catat('REQ  update'));
page.on('requestfinished', (r) => r.url().includes('/update') && catat('DONE update'));
page.on('pageerror', (e) => catat('ERR  ' + e.message));
if (process.env.DEBUG) {
    await page.addInitScript(() => window.addEventListener('unhandledrejection', (e) => {
        const a = e.promise?._livewireAction;
        console.log('UNHANDLED', a ? `action ${a.name} cancelled=${a.cancelled}` : 'bukan-promise-aksi', JSON.stringify(e.reason)?.slice(0, 120), new Error().stack.split('\n').slice(1, 4).join(' / '));
    }));
    page.on('console', (m) => m.text().startsWith('UNHANDLED') && catat(m.text()));
}
let dialogBawaan = 0;
page.on('dialog', async (d) => { dialogBawaan++; await d.dismiss(); });

// Jumlah request Livewire yang sedang berjalan — dipakai menunggu respons terlambat tiba.
let requestJalan = 0;
page.on('request', (r) => { if (r.url().includes('/update')) requestJalan++; });
['requestfinished', 'requestfailed'].forEach((ev) => page.on(ev, (r) => { if (r.url().includes('/update')) requestJalan--; }));
async function tungguRequestSelesai(batas = 20000) {
    const mulai = Date.now();
    await page.waitForTimeout(100);
    while (requestJalan > 0 && Date.now() - mulai < batas) await page.waitForTimeout(100);
    await page.waitForTimeout(300); // beri waktu morph/render setelah respons tiba
}
const tahan = (r) => (r.request().method() === 'POST' ? setTimeout(() => r.continue().catch(() => {}), TUNDA_MS) : r.continue());
const lambat = async (aktif) => (aktif ? page.route('**/update', tahan) : page.unroute('**/update', tahan));
const dialog = page.locator('text=Tinggalkan Halaman?');
const uraian = () => page.locator('input[x-ref="uraianInput"]').first();
const munculDialog = () => dialog.waitFor({ state: 'visible', timeout: 5000 }).then(() => true, () => false);
const diIsian = () => /\/pengisian/.test(new URL(page.url()).pathname);
const tanpaGalatSejak = (n, nama) => cek(nama, galat.length === n, [...new Set(galat.slice(n))].join(' | '));
const langkah = (s) => catat('===== ' + s);
const menu = (label) => page.click(`aside a:has-text("${label}"), nav a:has-text("${label}")`).catch(() => page.click(`a:has-text("${label}")`));

async function pilihIku() {
    const pilih = page.locator(`select:has(option:has-text("${KODE_IKU}"))`).first();
    await pilih.selectOption(await pilih.locator(`option:has-text("${KODE_IKU}")`).getAttribute('value'));
}
// URL sudah berganti (pushState) sebelum wire:navigate selesai menukar halaman &
// Alpine siap — tunggu event livewire:navigated dulu sebelum berinteraksi.
async function navigasiKe(label, pola) {
    const selesai = page.evaluate(() => new Promise((ok) => document.addEventListener('livewire:navigated', ok, { once: true })));
    await menu(label);
    await selesai;
    await page.waitForURL(pola);
    await page.waitForLoadState('networkidle');
}
async function bukaIsian() {
    await navigasiKe('Isian Kegiatan', /\/pengisian/);
    await pilihIku();
    // Dikonfirmasi server: iku_id komponen sudah terisi setelah respons Livewire.
    await page.waitForFunction(() => {
        const el = document.querySelector('select[data-abaikan-perubahan]')?.closest('[wire\\:id]');
        return el && window.Livewire.find(el.getAttribute('wire:id'))?.iku_id;
    }, null, { timeout: 15000 });
    await uraian().waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForLoadState('networkidle');
}
const nilaiServer = () => page.evaluate(() => {
    const el = document.querySelector('input[x-ref="uraianInput"]')?.closest('[wire\\:id]');
    return el ? window.Livewire.find(el.getAttribute('wire:id')).blocks[0].uraian_kegiatan : null;
});

await page.goto(BASE + '/login');
await page.fill('input[name=username]', USERNAME);
await page.fill('input[name=password]', PASSWORD);
await Promise.all([page.waitForURL((u) => ! u.pathname.startsWith('/login')), page.click('button[type=submit]')]);
await bukaIsian();

// 1. Ketik lalu LANGSUNG pindah menu selagi server masih memproses ketikan.
langkah('1');
let n = galat.length;
await lambat(true);
await uraian().click();
await uraian().pressSequentially('uji folder baru', { delay: 20 });
await menu('Dasbor');
cek('1. Ketik lalu langsung klik menu → dialog bertema muncul', await munculDialog());
const pesan = await page.locator('[x-text="$store.dialog.pesan"]').innerText().catch(() => '');
cek('2. Pesan dialog menyebut folder yang baru dibentuk', /folder/i.test(pesan));

// 2. Tetap di sini → ketikan tetap ada di layar DAN sampai ke server.
langkah('2');
await page.click('button:has-text("Tetap di sini")');
await tungguRequestSelesai();
cek('3. Tetap di sini → masih di Isian Kegiatan', diIsian(), page.url());
cek('4. Tetap di sini → uraian di layar utuh', (await uraian().inputValue()) === 'uji folder baru');
cek('5. Nilai uraian di komponen Livewire sama dengan ketikan', (await nilaiServer()) === 'uji folder baru', String(await nilaiServer()));
const status = await page.evaluate(() => {
    const el = document.querySelector('.status-simpan');
    const wire = window.Livewire.find(el.closest('[wire\\:id]').getAttribute('wire:id'));
    return { adaPerubahan: wire.adaPerubahan, kotor: el.hasAttribute('data-kotor'), teks: el.innerText.trim().slice(0, 80) };
});
cek('6. Respons terlambat diterapkan: server mencatat ada perubahan & bilah "belum disimpan" tampil', status.adaPerubahan === true && status.kotor && /belum disimpan/i.test(status.teks), JSON.stringify(status));
tanpaGalatSejak(n, '7. Tanpa galat JS selama ketik + Tetap di sini');
await lambat(false);

// 3. Tombol Back browser.
langkah('3');
n = galat.length;
const urlIsian = page.url();
await page.evaluate(() => history.back());
cek('8. Tombol Back → dialog bertema muncul', await munculDialog());
await page.click('button:has-text("Tetap di sini")');
await page.waitForTimeout(800);
cek('9. Back + Tetap di sini → alamat kembali ke halaman isian', page.url() === urlIsian, page.url());
cek('10. Back + Tetap di sini → uraian utuh', (await uraian().inputValue()) === 'uji folder baru');
tanpaGalatSejak(n, '11. Tanpa galat JS selama Back + Tetap di sini');

// 4. Ketik lagi, server lambat, lalu Tinggalkan — respons tiba setelah halaman berganti.
langkah('4');
n = galat.length;
await lambat(true);
await uraian().click();
await uraian().pressSequentially(' lagi', { delay: 20 });
await menu('Dasbor');
await munculDialog();
await page.click('button:has-text("Tinggalkan")');
await page.waitForURL((u) => ! /\/pengisian/.test(u.pathname), { timeout: 15000 }).catch(() => {});
await tungguRequestSelesai();
cek('12. Tinggalkan → pindah ke Dasbor', ! diIsian(), page.url());
tanpaGalatSejak(n, '13. Respons terlambat setelah Tinggalkan tidak memicu galat JS');
await lambat(false);

// 5. Kembali ke Isian Kegiatan: draf yang tidak disimpan memang dibuang, halaman normal.
langkah('5');
n = galat.length;
await bukaIsian();
cek('14. Buka lagi → isian bersih (yang tidak disimpan tidak setengah tersimpan)', (await uraian().inputValue()) === '');
await menu('Dasbor');
await Promise.race([
    page.waitForURL((u) => ! /\/pengisian/.test(u.pathname), { timeout: 15000 }),
    dialog.waitFor({ state: 'visible', timeout: 15000 }),
]).catch(() => {});
cek('15. Tanpa perubahan → pindah menu tanpa dialog', ! (await dialog.isVisible()) && ! diIsian(), page.url());
tanpaGalatSejak(n, '16. Tanpa galat JS saat buka lagi & pindah menu');

// 6. Tanpa perubahan isian, tetapi request Livewire (pilih IKU) masih jalan saat pindah menu.
langkah('6');
n = galat.length;
await navigasiKe('Isian Kegiatan', /\/pengisian/);
await lambat(true);
await pilihIku();
await menu('Dasbor');
await page.waitForURL((u) => ! /\/pengisian/.test(u.pathname), { timeout: 15000 }).catch(() => {});
await tungguRequestSelesai();
cek('17. Request masih jalan + pindah menu (tanpa perubahan) → pindah tanpa dialog', ! diIsian() && ! (await dialog.isVisible()), page.url());
tanpaGalatSejak(n, '18. Respons pilih IKU yang terlambat tidak memicu galat JS');
await lambat(false);

// 7. Ketik, server lambat, lalu tombol Back → Tinggalkan.
langkah('7');
n = galat.length;
await bukaIsian();
await lambat(true);
await uraian().click();
await uraian().pressSequentially('uji back', { delay: 20 });
await page.evaluate(() => history.back());
cek('19. Back saat ketikan masih diproses → dialog bertema muncul', await munculDialog());
await page.click('button:has-text("Tinggalkan")');
await page.waitForURL((u) => ! /\/pengisian/.test(u.pathname), { timeout: 15000 }).catch(() => {});
await page.waitForLoadState('load');
await page.waitForTimeout(TUNDA_MS * 2);
cek('20. Back + Tinggalkan → pindah ke halaman sebelumnya', ! diIsian(), page.url());
tanpaGalatSejak(n, '21. Tanpa galat JS setelah Back + Tinggalkan');
await lambat(false);

cek('22. Tidak ada dialog bawaan browser', dialogBawaan === 0, String(dialogBawaan));
await browser.close();

if (process.env.DEBUG) console.log(linimasa.join('\n'));
for (const h of hasil) console.log(`${h.ok ? 'LULUS' : 'GAGAL'}  ${h.nama}${h.info && ! h.ok ? ' — ' + h.info : ''}`);
const gagal = hasil.filter((h) => ! h.ok).length;
console.log(`\n${hasil.length - gagal}/${hasil.length} lulus${galat.length ? ` · galat JS total: ${galat.length}` : ''}`);
process.exit(gagal ? 1 : 0);
