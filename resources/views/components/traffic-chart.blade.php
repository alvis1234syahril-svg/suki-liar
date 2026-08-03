@props([
    'title',
    'subtitle' => null,
    'type' => 'bar',
    'labels' => [],
    'series' => [],
    'unit' => null,
])
@php
    $chartConfig = json_encode(
        compact('type', 'labels', 'series', 'unit'),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    );
    $chartHelp = match($title) {
        'Tren aktivitas harian' => [
            'source' => 'Kolom tanggal dan waktu pada setiap event log yang valid.',
            'method' => 'Semua event dikelompokkan berdasarkan tanggal, kemudian jumlah event setiap tanggal dijumlahkan.',
            'meaning' => 'Puncak menunjukkan hari dengan aktivitas paling padat. Lonjakan belum tentu berbahaya karena dapat berasal dari kegiatan operasional.',
            'action' => 'Periksa hari yang jauh lebih tinggi, lalu lihat user dan aplikasi yang dominan pada tanggal tersebut.',
        ],
        'Status user' => [
            'source' => 'Gabungan skor risiko aplikasi dan skor anomali habit milik setiap user.',
            'method' => 'Normal: kedua skor di bawah 30. Risiko aplikasi: risiko minimal 30. Anomali habit: habit minimal 30. Prioritas tinggi: keduanya minimal 30.',
            'meaning' => 'Grafik memisahkan user normal, user dengan aplikasi berisiko, user dengan kebiasaan tidak biasa, dan user yang memenuhi kedua kondisi.',
            'action' => 'Mulai pemeriksaan dari Prioritas tinggi, lalu Anomali habit dan Risiko aplikasi.',
        ],
        'Tingkat risiko aplikasi', 'Komposisi event risiko' => [
            'source' => 'Kolom apprisk, action, deny, serta pengulangan event high dan critical.',
            'method' => 'Event diberi bobot menurut level risiko. Deny dan pengulangan high/critical menaikkan skor; keyakinan disesuaikan dengan jumlah event.',
            'meaning' => 'Skor tinggi berarti pola berisiko cukup sering muncul untuk diperiksa, bukan berarti user pasti melakukan pelanggaran.',
            'action' => 'Buka detail user untuk melihat aplikasi, action, tujuan, dan waktu yang menaikkan skor.',
        ],
        'Pola penggunaan user', 'Risiko dan anomali habit' => [
            'source' => 'Jam akses, volume traffic, deny/timeout/reset, port, aplikasi, negara, dan destination.',
            'method' => 'Skor menggabungkan aktivitas luar jam kerja, outlier traffic, action tidak normal, port nonstandar, penyebaran jam aktif, serta tujuan/aplikasi yang jarang.',
            'meaning' => 'Anomali berarti perilaku berbeda dari pola umum unit. Aplikasi dapat aman tetapi kebiasaan aksesnya tetap tidak biasa.',
            'action' => 'Cocokkan alasan habit dengan shift kerja, tugas dinas, backup, atau aktivitas resmi lainnya.',
        ],
        'Aktivitas per jam' => [
            'source' => 'Jam pada timestamp setiap event valid.',
            'method' => 'Event dikelompokkan ke jam 00:00–23:00 tanpa melihat besar traffic.',
            'meaning' => 'Menunjukkan jam operasional paling ramai dan membantu mengenali aktivitas malam atau dini hari.',
            'action' => 'Tinjau jam ramai di luar pola kerja, lalu lihat user yang dominan pada jam tersebut.',
        ],
        'Jam kerja vs luar jam' => [
            'source' => 'Timestamp event; jam kerja ditetapkan Senin–Jumat pukul 08.00–17.00.',
            'method' => 'Setiap event ditempatkan ke kelompok jam kerja atau luar jam kerja berdasarkan hari dan jamnya.',
            'meaning' => 'Porsi luar jam yang besar dapat wajar untuk unit dengan shift, tetapi menjadi indikator bila tidak sesuai jadwal.',
            'action' => 'Konfirmasi pola shift unit sebelum menyimpulkan aktivitas luar jam sebagai anomali.',
        ],
        'Aplikasi yang paling sering dipakai' => [
            'source' => 'Kolom app atau service terbaik yang tersedia di setiap event.',
            'method' => 'Nama aplikasi yang sama digabung dan diurutkan berdasarkan jumlah event terbanyak.',
            'meaning' => 'Top app menunjukkan aplikasi paling sering muncul, bukan otomatis paling boros bandwidth atau paling berisiko.',
            'action' => 'Bandingkan dengan fungsi unit dan buka detail user bila aplikasi terasa tidak relevan.',
        ],
        'Negara tujuan' => [
            'source' => 'Kolom dstcountry pada setiap koneksi.',
            'method' => 'Event dikelompokkan berdasarkan negara tujuan dan diurutkan dari jumlah koneksi terbanyak.',
            'meaning' => 'Negara asing tidak otomatis mencurigakan karena layanan cloud dapat berada di luar negeri.',
            'action' => 'Periksa negara tidak biasa bersama aplikasi, destination, waktu, dan volume traffic.',
        ],
        'Hasil koneksi' => [
            'source' => 'Kolom action: accept, deny, timeout, client-rst, server-rst, dan reset lain.',
            'method' => 'Action yang sama dihitung dan dikelompokkan. Deny diberi perhatian lebih tinggi daripada timeout atau reset.',
            'meaning' => 'Deny berarti koneksi ditolak. Timeout/reset sering normal, tetapi pengulangan berlebih dapat menandakan gangguan atau pola tidak biasa.',
            'action' => 'Fokus pada deny berulang dan lonjakan reset/timeout pada user atau tujuan yang sama.',
        ],
        'Arah traffic' => [
            'source' => 'Kolom sentbyte dan rcvdbyte dari semua event valid.',
            'method' => 'Sentbyte dijumlahkan sebagai upload dan rcvdbyte sebagai download.',
            'meaning' => 'Menunjukkan arah volume data. Nilai besar perlu dibandingkan dengan fungsi unit dan jumlah user.',
            'action' => 'Periksa upload sangat besar atau perubahan arah traffic yang tidak sesuai pola unit.',
        ],
        'Intensitas akses' => [
            'source' => 'Total event dan jumlah user unik pada hasil terbaru setiap unit.',
            'method' => 'Total event unit dibagi jumlah user agar unit besar dan kecil lebih adil dibandingkan.',
            'meaning' => 'Nilai tinggi berarti rata-rata user menghasilkan lebih banyak event, bukan otomatis lebih berisiko.',
            'action' => 'Baca bersama skor risiko, habit, jam aktif, dan fungsi operasional unit.',
        ],
        'Komposisi status user' => [
            'source' => 'Status gabungan risiko dan habit seluruh user pada hasil terbaru tiap unit.',
            'method' => 'User normal dibandingkan dengan user berstatus risiko, anomali habit, atau prioritas tinggi.',
            'meaning' => 'Menunjukkan proporsi user yang perlu ditinjau tanpa hanya mengikuti besarnya total event.',
            'action' => 'Buka daftar user pada unit dengan proporsi perhatian tinggi untuk membaca alasannya.',
        ],
        'Tren aktivitas user' => [
            'source' => 'Tanggal dan jumlah event milik user yang sedang dibuka.',
            'method' => 'Event user dikelompokkan per tanggal lalu dihitung jumlahnya. Data user lain tidak dicampurkan.',
            'meaning' => 'Lonjakan memperlihatkan hari ketika user jauh lebih aktif. Ini belum membuktikan aktivitas berbahaya.',
            'action' => 'Cocokkan tanggal lonjakan dengan tugas user, aplikasi utama, tujuan koneksi, dan event menonjol.',
        ],
        'Pola aktivitas per jam' => [
            'source' => 'Jam pada timestamp seluruh event milik user ini.',
            'method' => 'Event dibagi ke 24 kelompok jam dari 00:00 sampai 23:59.',
            'meaning' => 'Menunjukkan jam kebiasaan akses user dan membantu menemukan aktivitas di luar pola umumnya.',
            'action' => 'Periksa jam malam atau dini hari hanya bila tidak sesuai shift dan tugas user.',
        ],
        'Action koneksi' => [
            'source' => 'Kolom action pada seluruh event milik user.',
            'method' => 'Accept, deny, timeout, client-rst, server-rst, dan reset lain dihitung berdasarkan kemunculannya.',
            'meaning' => 'Deny adalah koneksi yang ditolak. Timeout atau reset dapat terjadi secara normal, tetapi perlu diperiksa jika berulang berlebihan.',
            'action' => 'Cari tujuan dan aplikasi yang menghasilkan deny atau reset paling sering.',
        ],
        'Aplikasi utama user' => [
            'source' => 'Kolom app dan kategori aplikasi pada event milik user ini.',
            'method' => 'Nama aplikasi yang sama digabung lalu diurutkan dari jumlah event terbanyak.',
            'meaning' => 'Aplikasi teratas adalah yang paling sering digunakan, bukan otomatis aplikasi paling berbahaya.',
            'action' => 'Pastikan aplikasi dominan sesuai kebutuhan pekerjaan user dan periksa level risikonya.',
        ],
        'Tujuan utama user' => [
            'source' => 'Destination IP, negara, dan service pada event milik user.',
            'method' => 'Destination IP yang sama digabung lalu diurutkan berdasarkan frekuensi koneksi.',
            'meaning' => 'Tujuan teratas adalah alamat yang paling sering dihubungi. Layanan cloud dapat membuat alamat asing tetap normal.',
            'action' => 'Periksa tujuan yang tidak dikenal bersama aplikasi, port, waktu, dan volume datanya.',
        ],
        default => [
            'source' => 'Ringkasan hasil analisis dari log yang berhasil dibaca.',
            'method' => 'Data dikelompokkan sesuai kategori grafik dan nilai setiap kelompok dijumlahkan.',
            'meaning' => 'Grafik menunjukkan pola dan perbandingan, tetapi tetap harus dibaca bersama konteks operasional.',
            'action' => 'Gunakan legenda dan angka ringkasan, lalu buka detail user bila perlu.',
        ],
    };
    $actionMeanings = [
        'accept' => ['label' => 'Accept', 'tone' => 'normal', 'badge' => 'Umumnya normal', 'description' => 'Koneksi diizinkan dan diteruskan oleh perangkat jaringan. Banyaknya Accept biasanya menunjukkan traffic berjalan normal.'],
        'close' => ['label' => 'Close', 'tone' => 'normal', 'badge' => 'Umumnya normal', 'description' => 'Sesi local traffic diizinkan lalu ditutup. Biasanya menandakan sesi sudah selesai, bukan serangan.'],
        'client-rst' => ['label' => 'Client-rst', 'tone' => 'context', 'badge' => 'Perlu konteks', 'description' => 'Koneksi dihentikan oleh perangkat pengguna. Bisa terjadi saat aplikasi ditutup, halaman dibatalkan, atau jaringan pengguna berubah.'],
        'timeout' => ['label' => 'Timeout', 'tone' => 'context', 'badge' => 'Perlu konteks', 'description' => 'Koneksi sempat diizinkan tetapi berakhir karena terlalu lama tidak selesai atau tidak aktif. Sesekali wajar; jumlah besar dapat menunjukkan gangguan.'],
        'ip-conn' => ['label' => 'Ip-conn', 'tone' => 'attention', 'badge' => 'Perlu diperiksa', 'description' => 'Percobaan membuat koneksi IP gagal. Periksa bila berulang pada user, aplikasi, atau alamat tujuan yang sama.'],
        'server-rst' => ['label' => 'Server-rst', 'tone' => 'context', 'badge' => 'Perlu konteks', 'description' => 'Koneksi dihentikan oleh server tujuan. Bisa disebabkan server menolak sesi, aplikasi selesai, atau gangguan di sisi server.'],
        'dns' => ['label' => 'DNS', 'tone' => 'attention', 'badge' => 'Perlu diperiksa', 'description' => 'Permintaan pencarian nama domain mengembalikan error. Bisa karena domain salah, DNS bermasalah, atau tujuan tidak tersedia.'],
        'deny' => ['label' => 'Deny', 'tone' => 'attention', 'badge' => 'Perlu diperiksa', 'description' => 'Koneksi ditolak oleh policy atau perangkat jaringan. Deny berulang dari sumber dan tujuan yang sama lebih penting untuk ditinjau.'],
        'start' => ['label' => 'Start', 'tone' => 'normal', 'badge' => 'Informasi sesi', 'description' => 'Catatan dibuat ketika sesi koneksi mulai berjalan. Ini bukan tanda bahwa koneksi gagal.'],
    ];
    $presentActionNames = collect($series)
        ->pluck('name')
        ->map(fn ($name) => strtolower(trim((string) $name)));
    $filterPresent = fn ($definitions) => collect($definitions)
        ->filter(fn ($definition, $key) => $presentActionNames->contains(strtolower($key)))
        ->values()
        ->all();
    $categoryHelp = match($title) {
        'Hasil koneksi', 'Action koneksi' => $filterPresent($actionMeanings),
        'Status user', 'Komposisi status user' => $filterPresent([
            'normal' => ['label' => 'Normal', 'tone' => 'normal', 'badge' => 'Kedua skor rendah', 'description' => 'Skor risiko aplikasi dan skor habit sama-sama di bawah 30. Artinya sistem belum menemukan indikator kuat yang perlu perhatian khusus.'],
            'risiko aplikasi' => ['label' => 'Risiko aplikasi', 'tone' => 'context', 'badge' => 'Risiko ≥ 30', 'description' => 'Aplikasi atau action koneksinya perlu dilihat, tetapi pola waktu dan kebiasaan user masih tergolong normal.'],
            'anomali habit' => ['label' => 'Anomali habit', 'tone' => 'context', 'badge' => 'Habit ≥ 30', 'description' => 'Aplikasinya dapat tergolong aman, tetapi waktu, volume, port, atau tujuan aksesnya berbeda dari pola umum unit.'],
            'prioritas tinggi' => ['label' => 'Prioritas tinggi', 'tone' => 'attention', 'badge' => 'Kedua skor ≥ 30', 'description' => 'Risiko aplikasi dan anomali kebiasaan muncul bersamaan. Status ini diperiksa lebih dahulu, tetapi tetap bukan bukti pelanggaran.'],
            'perlu perhatian' => ['label' => 'Perlu perhatian', 'tone' => 'attention', 'badge' => 'Gabungan status', 'description' => 'Jumlah seluruh user berstatus risiko aplikasi, anomali habit, atau prioritas tinggi.'],
        ]),
        'Tingkat risiko aplikasi' => $filterPresent([
            'tinggi' => ['label' => 'Tinggi', 'tone' => 'attention', 'badge' => 'Skor 60–100', 'description' => 'Indikator aplikasi berisiko, deny, atau pengulangan high/critical cukup kuat sehingga user masuk prioritas pemeriksaan.'],
            'sedang' => ['label' => 'Sedang', 'tone' => 'context', 'badge' => 'Skor 30–59', 'description' => 'Ada beberapa indikator yang perlu dibaca bersama aplikasi, tujuan, dan tugas user.'],
            'rendah' => ['label' => 'Rendah', 'tone' => 'normal', 'badge' => 'Skor 0–29', 'description' => 'Indikator risiko yang terdeteksi masih rendah. Ini bukan jaminan mutlak, tetapi belum menjadi prioritas pemeriksaan.'],
        ]),
        'Komposisi event risiko' => $filterPresent([
            'critical' => ['label' => 'Critical', 'tone' => 'attention', 'badge' => 'Risiko tertinggi', 'description' => 'Event berasal dari aplikasi dengan label risiko paling tinggi pada log. Pengulangan kategori ini memberi kontribusi skor terbesar.'],
            'high' => ['label' => 'High', 'tone' => 'attention', 'badge' => 'Risiko tinggi', 'description' => 'Event berasal dari aplikasi berisiko tinggi dan memiliki bobot lebih besar daripada Elevated atau Medium.'],
            'elevated' => ['label' => 'Elevated', 'tone' => 'context', 'badge' => 'Risiko meningkat', 'description' => 'Risiko aplikasi berada di atas tingkat menengah, tetapi di bawah High dan Critical.'],
            'medium' => ['label' => 'Medium', 'tone' => 'context', 'badge' => 'Risiko menengah', 'description' => 'Ada risiko tingkat menengah. Jumlah besar dapat berpengaruh, tetapi satu event saja tidak otomatis membuat user berisiko.'],
        ]),
        'Pola penggunaan user' => $filterPresent([
            'anomali tinggi' => ['label' => 'Anomali tinggi', 'tone' => 'attention', 'badge' => 'Skor 60–100', 'description' => 'Kebiasaan akses sangat berbeda dari pola umum unit pada beberapa indikator sekaligus.'],
            'anomali sedang' => ['label' => 'Anomali sedang', 'tone' => 'context', 'badge' => 'Skor 30–59', 'description' => 'Ada perbedaan pola yang perlu diberi konteks, misalnya shift, backup, atau tugas khusus.'],
            'habit normal' => ['label' => 'Habit normal', 'tone' => 'normal', 'badge' => 'Skor 0–29', 'description' => 'Jam, volume, action, port, dan tujuan masih cukup dekat dengan pola umum unit.'],
        ]),
        'Jam kerja vs luar jam' => $filterPresent([
            'jam kerja' => ['label' => 'Jam kerja', 'tone' => 'normal', 'badge' => 'Senin–Jumat', 'description' => 'Event yang terjadi pukul 08.00–17.00 pada hari kerja.'],
            'luar jam kerja' => ['label' => 'Luar jam kerja', 'tone' => 'context', 'badge' => 'Di luar periode kerja', 'description' => 'Event malam, dini hari, atau akhir pekan. Aktivitas ini dapat normal untuk unit yang memakai shift.'],
        ]),
        'Arah traffic' => $filterPresent([
            'upload' => ['label' => 'Upload', 'tone' => 'context', 'badge' => 'Data keluar', 'description' => 'Jumlah data yang dikirim user atau unit ke alamat tujuan.'],
            'download' => ['label' => 'Download', 'tone' => 'normal', 'badge' => 'Data masuk', 'description' => 'Jumlah data yang diterima user atau unit dari alamat tujuan.'],
        ]),
        'Risiko dan anomali habit' => $filterPresent([
            'risiko' => ['label' => 'Risiko', 'tone' => 'attention', 'badge' => 'Aspek aplikasi', 'description' => 'Membandingkan level risiko aplikasi, deny, dan pengulangan event antar-unit.'],
            'habit' => ['label' => 'Habit', 'tone' => 'context', 'badge' => 'Aspek kebiasaan', 'description' => 'Membandingkan pola waktu, volume, action, port, aplikasi, dan tujuan antar-unit.'],
        ]),
        'Intensitas akses' => [
            ['label' => 'Akses per user', 'tone' => 'context', 'badge' => 'Sudah dinormalisasi', 'description' => 'Total event dibagi jumlah user. Cara ini membuat unit besar dan kecil lebih adil saat dibandingkan.'],
        ],
        'Tren aktivitas harian', 'Tren aktivitas user' => [
            ['label' => 'Titik tanggal', 'tone' => 'normal', 'badge' => 'Jumlah per hari', 'description' => 'Setiap titik menunjukkan jumlah event pada satu tanggal. Titik lebih tinggi berarti aktivitas pada hari itu lebih ramai.'],
            ['label' => 'Garis tren', 'tone' => 'context', 'badge' => 'Perubahan waktu', 'description' => 'Garis membantu melihat kenaikan, penurunan, atau lonjakan dari hari ke hari.'],
        ],
        'Aktivitas per jam', 'Pola aktivitas per jam' => [
            ['label' => 'Jam 00–23', 'tone' => 'normal', 'badge' => 'Kelompok waktu', 'description' => 'Setiap batang mewakili satu jam. Nilainya adalah jumlah event yang terjadi selama jam tersebut.'],
            ['label' => 'Jam puncak', 'tone' => 'context', 'badge' => 'Aktivitas terbanyak', 'description' => 'Batang tertinggi menunjukkan jam paling ramai, bukan otomatis jam paling berisiko.'],
        ],
        'Aplikasi yang paling sering dipakai', 'Aplikasi utama user' => [
            ['label' => 'Nama aplikasi', 'tone' => 'normal', 'badge' => 'Kategori akses', 'description' => 'Setiap batang mewakili aplikasi yang terbaca pada log.'],
            ['label' => 'Jumlah akses', 'tone' => 'context', 'badge' => 'Frekuensi', 'description' => 'Nilainya menunjukkan seberapa sering aplikasi muncul, bukan besar bandwidth atau tingkat bahayanya.'],
        ],
        'Negara tujuan', 'Tujuan utama user' => [
            ['label' => 'Tujuan', 'tone' => 'normal', 'badge' => 'Lokasi atau alamat', 'description' => 'Setiap kategori menunjukkan negara atau alamat yang dihubungi.'],
            ['label' => 'Jumlah akses', 'tone' => 'context', 'badge' => 'Frekuensi koneksi', 'description' => 'Nilainya menunjukkan banyaknya koneksi. Negara asing tidak otomatis berarti mencurigakan karena layanan cloud dapat berada di luar negeri.'],
        ],
        default => [],
    };
    $categoryHelpTitle = match($title) {
        'Hasil koneksi', 'Action koneksi' => 'Arti setiap status',
        'Arah traffic' => 'Arti setiap arah traffic',
        'Risiko dan anomali habit', 'Intensitas akses' => 'Arti setiap indikator',
        'Tren aktivitas harian', 'Tren aktivitas user', 'Aktivitas per jam', 'Pola aktivitas per jam' => 'Cara membaca grafik',
        'Aplikasi yang paling sering dipakai', 'Aplikasi utama user', 'Negara tujuan', 'Tujuan utama user' => 'Cara membaca kategori',
        default => 'Arti setiap kategori',
    };
@endphp
<section data-traffic-chart class="chart-card relative overflow-hidden p-4 sm:p-5">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-blue-500">Grafik</p>
            <h3 class="mt-1 truncate font-extrabold tracking-tight text-slate-900" title="{{ $title }}">{{ $title }}</h3>
            @if($subtitle)<p class="mt-1 text-xs leading-5 text-slate-500">{{ $subtitle }}</p>@endif
        </div>
        <x-explanation
            :title="$title"
            :source="$chartHelp['source']"
            :method="$chartHelp['method']"
            :meaning="$chartHelp['meaning']"
            :action="$chartHelp['action']"
            :details="$categoryHelp"
            :details-title="$categoryHelpTitle"
        />
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-sm text-blue-600" aria-hidden="true">↗</span>
    </div>
    <div data-chart-legend class="mt-3 flex flex-wrap gap-1.5" aria-label="Kontrol seri grafik"></div>
    <div class="{{ $type === 'donut' ? 'mt-2 flex h-[260px] items-center justify-center overflow-hidden' : 'mt-3 max-h-[430px] overflow-auto' }}">
        <svg class="{{ $type === 'donut' ? 'h-[250px] w-full max-w-[440px]' : 'h-auto min-h-[220px] w-full' }}" role="img" aria-label="{{ $title }}"></svg>
    </div>
    <div data-chart-empty hidden class="mt-3 rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">
        Belum ada data untuk grafik ini.
    </div>
    <div data-chart-fallback class="mt-3 flex flex-wrap gap-1.5" aria-label="Ringkasan angka grafik"></div>
    <div data-chart-tooltip hidden class="pointer-events-none absolute z-10 max-w-64 rounded-lg bg-slate-900 px-3 py-2 text-xs text-white shadow-xl">
        <strong data-tooltip-title class="block truncate"></strong>
        <span data-tooltip-detail class="mt-0.5 block text-slate-300"></span>
    </div>
    <script type="application/json">{!! $chartConfig !!}</script>
</section>
