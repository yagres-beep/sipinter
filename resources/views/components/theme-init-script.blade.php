<script>
    // Terapkan pilihan tema tersimpan SEBELUM CSS dirender (cegah FOUC — kedip balik
    // ke terang sesaat sebelum berpindah ke gelap). Dipanggil lagi setiap kali
    // 'livewire:navigated' terjadi (perpindahan menu lewat wire:navigate) karena
    // Livewire Navigate MENYAMAKAN atribut <html> dengan HTML mentah hasil fetch
    // (lihat replaceHtmlAttributes() di vendor/livewire/livewire/dist/livewire.js)
    // — HTML mentah itu TIDAK PERNAH membawa data-theme (murni ditulis lewat JS,
    // tidak dirender server), jadi tanpa listener ini atribut ini "dicopot" oleh
    // Livewire di setiap perpindahan menu dan tema balik mengikuti preferensi OS.
    (function () {
        function terapkanTema() {
            var tema = localStorage.getItem('sipinter-tema');
            if (tema === 'light' || tema === 'dark') {
                document.documentElement.setAttribute('data-theme', tema);
            } else {
                document.documentElement.removeAttribute('data-theme');
            }
        }

        terapkanTema();
        document.addEventListener('livewire:navigated', terapkanTema);
    })();
</script>
