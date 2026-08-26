{{--
    Notifikasi mengambang global (pojok kanan bawah) — satu instance dipasang di
    layouts/app.blade.php sehingga aktif di SEMUA halaman, bukan cuma satu Livewire
    component. Dua sumber:
    1. Toast "memuat…" otomatis selama ADA request Livewire aktif di halaman mana pun
       (wire:loading tanpa wire:target di luar boundary component = berlaku global).
    2. Toast success/error/warning/info yang dipicu manual lewat event window `notify`,
       mis. $this->dispatch('notify', type: 'success', message: '...') dari Livewire,
       atau $dispatch('notify', { type: 'info', message: '...' }) dari Alpine (dipakai
       PengisianKegiatan untuk progres unggah berkas).
--}}
<div class="toast-stack" x-data="{
        toasts: [],
        toastSeq: 0,
        pushToast(type, message) {
            const id = ++this.toastSeq;
            this.toasts.push({ id, type, message });
            setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, 6000);
        },
    }"
    x-on:notify.window="pushToast($event.detail.type, $event.detail.message)"
>
    <div class="toast info" wire:loading.flex>
        <span class="ic"><i class="spin"></i></span>
        <span class="msg">Memuat…</span>
    </div>

    <template x-for="toast in toasts" :key="toast.id">
        <div class="toast" :class="toast.type">
            <span class="ic" x-text="toast.type === 'success' ? '✅' : (toast.type === 'error' ? '⚠️' : (toast.type === 'warning' ? '⚠️' : 'ℹ️'))"></span>
            <span class="msg" x-text="toast.message"></span>
            <button type="button" class="x" @click="toasts = toasts.filter(t => t.id !== toast.id)">✕</button>
        </div>
    </template>
</div>
