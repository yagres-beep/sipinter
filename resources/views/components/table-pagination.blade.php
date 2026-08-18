{{--
    Kaki paginasi untuk tabel yang dibungkus x-data="dataTable(perPage)" (lihat
    resources/js/app.js). Taruh komponen ini di dalam wrapper yang sama (mis. di
    bawah </table>, masih dalam .table-scroll) supaya scope Alpine-nya nyambung.
--}}
<div class="table-pagination" x-show="totalRows > 0" x-cloak>
    <div>Menampilkan <b x-text="startRow"></b>–<b x-text="endRow"></b> dari <b x-text="totalRows"></b></div>
    <div class="tp-size">
        <label>Baris/halaman</label>
        <select x-model.number="perPage" @change="goToPage(1)">
            <option value="10">10</option>
            <option value="25">25</option>
            <option value="50">50</option>
            <option :value="totalRows || 1">Semua</option>
        </select>
    </div>
    <div class="tp-nav">
        <button type="button" @click="goToPage(1)" :disabled="page === 1">«</button>
        <button type="button" @click="goToPage(page - 1)" :disabled="page === 1">‹</button>
        <span class="tp-page"><span x-text="page"></span>/<span x-text="totalPages"></span></span>
        <button type="button" @click="goToPage(page + 1)" :disabled="page === totalPages">›</button>
        <button type="button" @click="goToPage(totalPages)" :disabled="page === totalPages">»</button>
    </div>
</div>
