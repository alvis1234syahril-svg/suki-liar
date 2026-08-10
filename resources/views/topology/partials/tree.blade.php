<section class="at-tree-panel" aria-labelledby="at-tree-heading">
    <div class="at-tree-header">
        <div>
            <h3 id="at-tree-heading">Peta koneksi</h3>
            <p>Root, parent, children, dan sibling berada dalam satu struktur vertikal.</p>
        </div>
        <div class="at-tree-tools">
            <div class="at-legend-guide" aria-label="Panduan membaca peta koneksi">
                <div class="at-legend-block">
                    <div class="at-legend-heading">
                        <strong>Garis koneksi</strong>
                        <span>Hubungan parent ke child antar-aset</span>
                    </div>
                    <div class="at-legend" role="list" aria-label="Arti garis koneksi antar-aset">
                        <div class="at-legend-item" role="listitem">
                            <i class="is-verified" aria-hidden="true"></i>
                            <span class="at-legend-copy"><strong>Parent cocok</strong><small>Sesuai data sumber/LLD</small></span>
                        </div>
                        <div class="at-legend-item" role="listitem">
                            <i class="is-inferred" aria-hidden="true"></i>
                            <span class="at-legend-copy"><strong>Parent terduga</strong><small>Disusun otomatis oleh sistem</small></span>
                        </div>
                        <div class="at-legend-item" role="listitem">
                            <i class="is-gap" aria-hidden="true"></i>
                            <span class="at-legend-copy"><strong>Tahap dilewati</strong><small>Jenis aset tidak tersedia</small></span>
                        </div>
                    </div>
                </div>
                <div class="at-legend-block">
                    <div class="at-legend-heading">
                        <strong>Kotak port LAN</strong>
                        <span>Kondisi port fisik pada switch</span>
                    </div>
                    <div class="at-port-legend" role="list" aria-label="Arti warna kotak port fisik switch">
                        <div class="at-legend-item" role="listitem">
                            <i class="is-healthy" aria-hidden="true"></i>
                            <span class="at-legend-copy"><strong>Normal</strong><small>Terpantau dan koneksi aktif</small></span>
                        </div>
                        <div class="at-legend-item" role="listitem">
                            <i class="is-problematic" aria-hidden="true"></i>
                            <span class="at-legend-copy"><strong>Perlu diperiksa</strong><small>Koneksi port terputus atau bermasalah</small></span>
                        </div>
                        <div class="at-legend-item" role="listitem">
                            <i class="is-disabled" aria-hidden="true"></i>
                            <span class="at-legend-copy"><strong>Dinonaktifkan</strong><small>Port dimatikan oleh admin</small></span>
                        </div>
                        <div class="at-legend-item" role="listitem">
                            <i class="is-unmonitored" aria-hidden="true"></i>
                            <span class="at-legend-copy"><strong>Belum dipantau</strong><small>Belum ada data SNMP</small></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="at-zoom-controls" role="group" aria-label="Kontrol zoom tree">
                <button type="button" data-zoom-out aria-label="Perkecil tree" title="Perkecil">−</button>
                <button class="at-zoom-value" type="button" data-zoom-reset aria-label="Kembalikan zoom ke 100 persen"><span data-zoom-value>100%</span></button>
                <button type="button" data-zoom-in aria-label="Perbesar tree" title="Perbesar">+</button>
                <button class="at-zoom-fit" type="button" data-zoom-fit aria-pressed="false">Pas layar</button>
            </div>
        </div>
    </div>

    <div class="at-tree-filter">
        <label class="at-search-field">
            <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="9" cy="9" r="5.5"/><path d="m13.5 13.5 3 3"/></svg>
            <span class="at-sr-only">Cari node tree</span>
            <input type="search" data-tree-search placeholder="Cari nama, jenis, model, atau ID…">
        </label>
        <p data-tree-summary>Belum ada topologi.</p>
    </div>

    <div
        class="at-tree-viewport"
        data-tree-viewport
        role="region"
        aria-label="Tree topologi aset"
        tabindex="0"
    >
        <div class="at-tree-canvas" data-tree-canvas></div>
        <div class="at-tree-state" data-tree-state>
            <span class="at-spinner" aria-hidden="true"></span>
            <strong>Memuat topologi…</strong>
        </div>
    </div>
    <div class="at-tree-footer">
        <span>Geser area kosong untuk memindahkan kanvas.</span>
        <span data-tree-announcer class="at-sr-only" aria-live="polite" aria-atomic="true"></span>
    </div>
</section>
