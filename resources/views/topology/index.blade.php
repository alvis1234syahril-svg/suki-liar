@extends('layouts.app')

@section('title', 'Topologi Aset')
@section('header', 'Topologi Aset')
@section('subtitle', 'Telusuri jalur perangkat dan kelola inventaris jaringan')

@section('content')
    @vite('resources/js/topology/index.js')

    <div
        class="asset-topology"
        data-topology-app
        data-endpoint="{{ route('topology.data') }}"
        data-location-endpoint="{{ route('topology.locations.store') }}"
        data-import-endpoint="{{ route('topology.import') }}"
        data-port-endpoint="{{ route('topology.ports.assign') }}"
        data-asset-endpoint-template="{{ route('topology.assets.update', ['asset' => '__ASSET__']) }}"
        data-export-endpoint="{{ route('topology.export') }}"
        data-csrf-token="{{ csrf_token() }}"
        aria-busy="true"
    >
        <section class="at-context" aria-labelledby="at-location-heading">
            <div class="at-context-copy">
                <span class="at-context-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M12 21s6-5.4 6-11a6 6 0 1 0-12 0c0 5.6 6 11 6 11Z"/><circle cx="12" cy="10" r="2"/></svg>
                </span>
                <div>
                    <p class="at-eyebrow">Lokasi aktif</p>
                    <h2 id="at-location-heading" data-location-title>Memuat lokasi…</h2>
                    <p data-location-summary>Data topologi sedang disiapkan.</p>
                </div>
            </div>

            <div class="at-context-actions">
                <label class="at-field at-location-field">
                    <span>Pilih lokasi</span>
                    <select data-location-select aria-label="Pilih lokasi aktif" disabled>
                        <option value="">Memuat lokasi…</option>
                    </select>
                </label>
                <button class="at-button at-button-secondary" type="button" data-open-location-dialog>
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 4v12M4 10h12"/></svg>
                    Tambah lokasi
                </button>
                <button class="at-button at-button-secondary" type="button" data-open-import-dialog>
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 13V4m0 0L7 7m3-3 3 3M4 14v2h12v-2"/></svg>
                    Import Excel
                </button>
                <a class="at-button at-button-primary" href="{{ route('topology.export') }}" data-export-link>
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3v9m0 0 3-3m-3 3L7 9M4 15v2h12v-2"/></svg>
                    Ekspor Excel
                </a>
            </div>
        </section>

        <div class="at-page-status" data-page-status role="status" aria-live="polite">
            <span class="at-spinner" aria-hidden="true"></span>
            <span data-page-status-text>Memuat data topologi…</span>
            <button class="at-text-button" type="button" data-retry hidden>Coba lagi</button>
        </div>

        <section class="at-metrics" data-metrics aria-label="Ringkasan aset"></section>

        <section class="at-traffic-panel" aria-labelledby="at-traffic-heading" data-traffic-panel>
            <div class="at-traffic-heading">
                <div>
                    <p class="at-eyebrow">Aktivitas lokasi</p>
                    <h2 id="at-traffic-heading">Korelasi log terbaru</h2>
                    <p data-traffic-description>Memeriksa hasil analisis log untuk lokasi aktif.</p>
                </div>
                <a class="at-button at-button-secondary" href="#" data-traffic-analysis-link hidden>
                    Buka hasil analisis
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7 4h9v9M16 4 7 13M4 7v9h9"/></svg>
                </a>
            </div>
            <div class="at-traffic-content" data-traffic-content aria-live="polite">
                <div class="at-traffic-empty">
                    <span class="at-spinner" aria-hidden="true"></span>
                    <div><strong>Memuat korelasi log</strong><p>Status perangkat akan ditampilkan setelah data lokasi tersedia.</p></div>
                </div>
            </div>
        </section>

        <div class="at-tabs" role="tablist" aria-label="Tampilan topologi aset">
            <button class="at-tab is-active" id="at-tab-explorer" type="button" role="tab" aria-selected="true" aria-controls="at-panel-explorer" data-tab="explorer">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="6" height="5" rx="1"/><rect x="15" y="4" width="6" height="5" rx="1"/><rect x="9" y="15" width="6" height="5" rx="1"/><path d="M6 9v3h12V9M12 12v3"/></svg>
                Explorer
            </button>
            <button class="at-tab" id="at-tab-master" type="button" role="tab" aria-selected="false" aria-controls="at-panel-master" tabindex="-1" data-tab="master">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M8 9v11"/></svg>
                Master Aset
            </button>
            <button class="at-tab" id="at-tab-history" type="button" role="tab" aria-selected="false" aria-controls="at-panel-history" tabindex="-1" data-tab="history">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/></svg>
                Riwayat
            </button>
        </div>

        <section class="at-tab-panel" id="at-panel-explorer" role="tabpanel" aria-labelledby="at-tab-explorer" data-tab-panel="explorer">
            <div class="at-section-heading">
                <div>
                    <p class="at-eyebrow">Jalur aktif</p>
                    <h2>Tree topologi jaringan</h2>
                    <p>Klik perangkat untuk membuka seluruh child dan sibling pada cabang tersebut.</p>
                </div>
                <button class="at-button at-button-secondary" type="button" data-reset-tree>
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 7V3m0 0h4M4 3l3 3a6 6 0 1 1-1.2 8.3"/></svg>
                    Reset pilihan
                </button>
            </div>

            <div class="at-explorer-layout">
                @include('topology.partials.tree')
                @include('topology.partials.inspector')
            </div>
        </section>

        <section class="at-tab-panel" id="at-panel-master" role="tabpanel" aria-labelledby="at-tab-master" data-tab-panel="master" hidden>
            <div class="at-section-heading">
                <div>
                    <p class="at-eyebrow">Inventaris</p>
                    <h2>Master aset lokasi</h2>
                    <p>Cari perangkat, periksa status relasi, lalu buka tindakan aset.</p>
                </div>
            </div>

            <div class="at-master-toolbar" aria-label="Filter master aset">
                <label class="at-search-field">
                    <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="9" cy="9" r="5.5"/><path d="m13.5 13.5 3 3"/></svg>
                    <span class="at-sr-only">Cari aset</span>
                    <input type="search" data-master-search placeholder="Cari nama, model, ID, atau lokasi…">
                </label>
                <label class="at-field">
                    <span>Jenis aset</span>
                    <select data-master-type-filter><option value="">Semua jenis</option></select>
                </label>
                <label class="at-field">
                    <span>Status relasi</span>
                    <select data-master-relation-filter>
                        <option value="">Semua status</option>
                        <option value="verified">Parent cocok</option>
                        <option value="lld">Sesuai LLD</option>
                        <option value="inferred">Parent terduga</option>
                        <option value="unconnected">Belum terhubung</option>
                        <option value="conceptual">Konseptual</option>
                    </select>
                </label>
            </div>

            <div class="at-result-summary">
                <p data-master-count>Menampilkan 0 aset</p>
                <button class="at-text-button" type="button" data-clear-master-filter>Bersihkan filter</button>
            </div>

            <div class="at-table-shell">
                <table class="at-table">
                    <thead><tr><th>Aset</th><th>Jenis</th><th>Lokasi</th><th>Model / IP</th><th>Relasi</th><th>Log terbaru</th><th>Kondisi</th><th><span class="at-sr-only">Aksi</span></th></tr></thead>
                    <tbody data-master-body></tbody>
                </table>
                <div class="at-empty" data-master-empty hidden>
                    <strong>Tidak ada aset yang cocok</strong>
                    <p>Ubah kata pencarian atau bersihkan filter.</p>
                </div>
            </div>
        </section>

        <section class="at-tab-panel" id="at-panel-history" role="tabpanel" aria-labelledby="at-tab-history" data-tab-panel="history" hidden>
            <div class="at-section-heading">
                <div>
                    <p class="at-eyebrow">Audit perubahan</p>
                    <h2>Riwayat aset</h2>
                    <p>Penggantian, mutasi, dan penambahan lokasi tampil secara kronologis.</p>
                </div>
            </div>
            <label class="at-search-field at-history-search">
                <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="9" cy="9" r="5.5"/><path d="m13.5 13.5 3 3"/></svg>
                <span class="at-sr-only">Cari riwayat</span>
                <input type="search" data-history-search placeholder="Cari aset, lokasi, atau alasan…">
            </label>
            <div class="at-result-summary"><p data-history-count>Menampilkan 0 perubahan</p></div>
            <ol class="at-history-list" data-history-list></ol>
            <div class="at-empty" data-history-empty hidden>
                <strong>Belum ada riwayat</strong>
                <p>Perubahan aset akan dicatat dan ditampilkan di sini.</p>
            </div>
        </section>

        @include('topology.partials.dialogs')

        <div class="at-toast" data-toast role="status" aria-live="polite"></div>
    </div>
@endsection
