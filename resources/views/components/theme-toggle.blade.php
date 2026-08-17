<div class="theme-fab" role="group" aria-label="Pilih tema tampilan" x-data="{
        tema: localStorage.getItem('sipinter-tema') || 'system',
        aturTema(t) {
            this.tema = t;
            if (t === 'system') {
                localStorage.removeItem('sipinter-tema');
                document.documentElement.removeAttribute('data-theme');
            } else {
                localStorage.setItem('sipinter-tema', t);
                document.documentElement.setAttribute('data-theme', t);
            }
        }
    }">
    <button type="button" :class="{ on: tema === 'system' }" @click="aturTema('system')" title="Ikut tema perangkat">🖥️</button>
    <button type="button" :class="{ on: tema === 'light' }" @click="aturTema('light')" title="Mode terang">☀️</button>
    <button type="button" :class="{ on: tema === 'dark' }" @click="aturTema('dark')" title="Mode gelap">🌙</button>
</div>
