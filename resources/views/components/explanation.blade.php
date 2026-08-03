@props([
    'title' => 'Cara membaca hasil ini',
    'source' => null,
    'method' => null,
    'meaning' => null,
    'action' => null,
    'details' => [],
    'detailsTitle' => 'Arti setiap status',
])
@php
    $explanationId = 'explanation-'.uniqid();
    $glossaryContext = mb_strtolower(implode(' ', array_filter([$title, $source, $method, $meaning])));
    $glossaryItems = collect([
        ['term' => 'Event', 'definition' => 'Satu catatan aktivitas di dalam log.', 'keywords' => ['event', 'baris log', 'catatan aktivitas']],
        ['term' => 'User', 'definition' => 'Pengguna atau perangkat yang terdeteksi dari log.', 'keywords' => ['user', 'pengguna', 'client', 'identity']],
        ['term' => 'Habit', 'definition' => 'Pola kebiasaan penggunaan jaringan.', 'keywords' => ['habit', 'kebiasaan', 'anomali', 'pola penggunaan']],
        ['term' => 'Skor', 'definition' => 'Angka untuk menentukan tingkat perhatian, bukan bukti kesalahan.', 'keywords' => ['skor', 'nilai 0', 'poin']],
        ['term' => 'App risk', 'definition' => 'Label tingkat risiko aplikasi yang tercatat pada log.', 'keywords' => ['apprisk', 'app risk', 'risiko aplikasi', 'level risiko']],
        ['term' => 'Deny', 'definition' => 'Percobaan koneksi yang ditolak oleh policy atau perangkat jaringan.', 'keywords' => ['deny', 'ditolak']],
        ['term' => 'Action', 'definition' => 'Hasil koneksi, misalnya accept, deny, timeout, atau reset.', 'keywords' => ['action', 'timeout', 'reset', 'accept']],
        ['term' => 'Traffic', 'definition' => 'Jumlah data yang dikirim dan diterima melalui jaringan.', 'keywords' => ['traffic', 'volume data', 'upload', 'download', 'sentbyte', 'rcvdbyte', 'byte']],
        ['term' => 'Destination', 'definition' => 'Alamat atau negara tujuan yang dihubungi.', 'keywords' => ['destination', 'tujuan', 'dstip', 'dstcountry']],
        ['term' => 'Port', 'definition' => 'Nomor jalur layanan jaringan yang digunakan suatu koneksi.', 'keywords' => ['port', 'dstport']],
        ['term' => 'Service', 'definition' => 'Jenis layanan jaringan, seperti HTTPS, DNS, atau SSH.', 'keywords' => ['service', 'layanan jaringan']],
        ['term' => 'Timestamp', 'definition' => 'Catatan tanggal dan jam terjadinya aktivitas.', 'keywords' => ['timestamp', 'jam akses', 'waktu akses', 'tanggal dan waktu']],
        ['term' => 'Jam kerja', 'definition' => 'Periode Senin–Jumat pukul 08.00–17.00 yang dipakai sistem.', 'keywords' => ['jam kerja', 'luar jam']],
        ['term' => 'Baseline', 'definition' => 'Nilai pembanding yang berasal dari pola rata-rata unit.', 'keywords' => ['baseline', 'rata-rata unit', 'pola umum unit']],
        ['term' => 'Persentil', 'definition' => 'Posisi skor user dibandingkan dengan user lain dalam unit yang sama.', 'keywords' => ['persentil']],
        ['term' => 'Outlier', 'definition' => 'Aktivitas yang paling menonjol atau berbeda dari aktivitas lainnya.', 'keywords' => ['outlier', 'paling berbeda', 'menonjol', 'ekstrem']],
        ['term' => 'Policy', 'definition' => 'Aturan jaringan yang menentukan apakah koneksi diizinkan atau ditolak.', 'keywords' => ['policy']],
    ])->filter(
        fn ($item) => collect($item['keywords'])->contains(
            fn ($keyword) => str_contains($glossaryContext, $keyword)
        )
    )->take(5);
@endphp

<div class="result-explanation">
    <button
        type="button"
        popovertarget="{{ $explanationId }}"
        aria-label="Buka penjelasan: {{ $title }}"
        title="Lihat penjelasan"
    >?</button>
    <div id="{{ $explanationId }}" popover class="result-explanation-panel">
        <div class="result-explanation-heading">
            <div>
                <span>PENJELASAN HASIL</span>
                <h4>{{ $title }}</h4>
                <p class="result-explanation-intro">Angka ini berasal dari data log dan perhitungan sistem yang dijelaskan secara ringkas di bawah.</p>
            </div>
            <button
                type="button"
                class="result-explanation-close"
                popovertarget="{{ $explanationId }}"
                popovertargetaction="hide"
                aria-label="Tutup penjelasan"
            >&times;</button>
        </div>
        <dl>
            @if($source)
                <div><dt>Data yang dipakai</dt><dd>{{ $source }}</dd></div>
            @endif
            @if($method)
                <div><dt>Cara sistem menghitung</dt><dd>{{ $method }}</dd></div>
            @endif
            @if($meaning)
                <div class="is-meaning"><dt>Interpretasi hasil</dt><dd>{{ $meaning }}</dd></div>
            @endif
        </dl>
        @if(! empty($details))
            <div class="result-explanation-details">
                <strong>{{ $detailsTitle }}</strong>
                <div>
                    @foreach($details as $detail)
                        <article>
                            <div>
                                <b>{{ $detail['label'] }}</b>
                                @if(! empty($detail['tone']))
                                    <span class="is-{{ $detail['tone'] }}">{{ $detail['badge'] ?? 'Perlu konteks' }}</span>
                                @endif
                            </div>
                            <p>{{ $detail['description'] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        @endif
        @if($glossaryItems->isNotEmpty())
            <div class="result-explanation-glossary">
                <strong>Istilah pada penjelasan ini</strong>
                @foreach($glossaryItems as $item)
                    <span><b>{{ $item['term'] }}</b> = {{ $item['definition'] }}</span>
                @endforeach
            </div>
        @endif
    </div>
</div>
