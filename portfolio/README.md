# Senja di Akropolis · Portofolio Parallax

Situs portofolio satu halaman bertema Yunani kuno. Halaman bercerita dari senja ke malam: hero dibuka di istana Olympus di atas lautan awan saat senja, bagian tengah memakai marmer dan tembikar, kontak ditutup di bawah langit malam Akropolis.

Situs statis murni (HTML, CSS, JavaScript tanpa build) dan tidak bergantung pada aplikasi Laravel di repo ini.

- Desain Figma: https://www.figma.com/design/GHARUTXrcZveHbmxf3K1JN
  - `🖼 Sampul`: sampul file
  - `🏛 Desain Halaman`: mockup desktop 1440 px, mockup ponsel 390 px, dan overlay menu ponsel
  - `🧩 Komponen`: Tombol, Pilar keahlian, Kartu karya, Kartu lainnya, Singgah lini masa, Baris fakta, Tag teknologi, Pita meander, dan ikon
  - `🏺 Aset Parallax`: versi vektor (flat) dari semua lapisan dan ilustrasi
  - `📜 Panduan Gaya`: palet, tipografi, peta kecepatan parallax, motif
- Prototipe: buka halaman `🏛 Desain Halaman`, tekan Present, lalu pilih flow `Desktop · 1440` atau `Ponsel · 390`. Navigasi tetap di atas saat menggulir, tautan menggulir ke bagiannya, tombol GitHub membuka profil, dan tombol Menu di ponsel membuka overlay.

## Menjalankan

Buka `index.html` langsung di browser, atau jalankan server lokal:

```bash
cd portfolio
python3 -m http.server 8000
# buka http://localhost:8000
```

## Mengganti konten

Semua teks ada di **`js/data.js`**.

1. Cari `[GANTI]` lalu isi dengan data Anda. Selama masih ada, teks itu tampil dengan garis bawah putus-putus supaya mudah ditemukan.
2. `[PERIKSA]`: proyek "Analisis Traffic & Topologi Aset" diambil dari aplikasi di repo ini. Hapus dari `karya` dan `perjalanan` bila belum boleh dipublikasikan.
3. `kontak.email`: selama kosong, tombol utama mengarah ke GitHub. Setelah diisi, muncul tombol emas "Kirim email" dan GitHub menjadi tombol kedua "Lihat GitHub".
4. URL yang kosong (`""`) otomatis disembunyikan, jadi tidak ada tautan mati.
5. Foto: isi `tentang.foto` (misalnya `"assets/foto-saya.jpg"`). Foto akan berdiri di teras Delphi sebagai potret berpuncak lengkung, menggantikan patung Muse. Selama kosong, teras menampilkan patung sebagai hiasan, bukan sebagai foto Anda. Foto potret (tegak) paling pas.
6. Gambar proyek: isi `gambar` pada tiap karya, dan `gambarAlt` untuk deskripsi gambar bagi pembaca layar. Gambar mengisi seluruh panel (sebaiknya rasio sekitar 2:1) dan ikut bergeser halus di dalam bingkai. Selama `gambar` kosong, panel menampilkan amfora dan label "Tangkapan layar [GANTI]". Karya pertama memakai `assets/foto/karya-topologi.webp` (prasasti terakota dengan diagram topologi).

Kalau `data.js` rusak (misalnya kurang koma), halaman menampilkan pesan galat di bagian atas.

### Demo topologi di prasasti

Karya pertama memakai `topologi` di `data.js`: prasasti terakota tetap menjadi tampilannya, dan lapisan SVG interaktif menempel tepat di atas 10 lubang dan 15 alur ukirannya (`viewBox` seukuran gambar asli 1881 × 836 + `preserveAspectRatio="xMidYMid slice"`, sama dengan `object-fit: cover`). Cara kerjanya mengikuti modul Topologi Aset di aplikasi Laravel:

- **Pohon parent–child:** setiap simpul punya `induk`. Memilih simpul menyalakan jalurnya sampai akar (FortiGate) mengikuti alur ukiran. Alur yang bukan induk–anak dianggap jalur cadangan.
- **Korelasi log:** cincin penuh = terlihat di log, putus-putus = ambigu (IP dipakai beberapa aset), redup = belum terlihat.
- **Status port:** tanda "!" untuk port "perlu diperiksa" (admin up, link down) atau "dinonaktifkan".
- **Simulasi traffic:** paket cahaya mengalir dari setiap aset ke induknya, banyaknya sesuai tingkat traffic. Animasi berhenti saat kartu tidak terlihat. Bila gerak dikurangi, jalurnya cukup disorot tanpa paket bergerak.
- **Legenda berukir (sisi kiri prasasti):** empat simbol status yang dipahat di batu, masing-masing dengan jumlah asetnya. Klik untuk menyaring simpul: yang cocok menyala, sisanya meredup.
- **Kolom Doria berukir (sisi kanan prasasti):** meter beban traffic. Alurnya terisi cahaya dari bawah sesuai traffic simpul yang dipilih, atau rata-rata jaringan saat simulasi berjalan.
- **Panel detail:** di layar lebar muncul sebagai kartu tepat di bawah prasasti dengan panah ke simpul atau legenda yang dipilih, dan bisa ditutup dengan tombol ×. Di ponsel panel berada di bawah gambar.
- **Keyboard:** Tab ke simpul atau legenda, Enter atau Spasi untuk memilih, Escape untuk melepas.

Semua isinya data contoh dan diberi label "Demo interaktif · data contoh". Ubah nama, IP, dan status sesuka Anda. Koordinat `x`/`y` hanya perlu diubah bila gambarnya diganti.

## Struktur

```
portfolio/
├── index.html
├── css/style.css      token warna = variabel Figma "Yunani / Warna"
├── js/data.js         konten
├── js/main.js         render, parallax, galeri geser, menu, kemunculan
├── assets/foto/       aset fotorealistik WebP (latar transparan) + favicon.png
└── assets/karya/      tangkapan layar aplikasi Topologi Aset
```

Aset di `assets/foto/` diturunkan dari paket "Senja di Akropolis, aset parallax fotorealistik" (versi 4K): area transparan kosong di sekelilingnya dipotong, lalu ukurannya disesuaikan dengan tampilan di web (benda pilar 640 px, lapisan hero sampai 1920 px). Ornamen, latar Keahlian, dan benda pilar dimuat saat bagiannya mendekati layar. Untuk mengganti salah satunya, simpan file baru dengan nama yang sama; bila proporsi isinya berbeda, sesuaikan posisi lapisan di `css/style.css` bagian "Hero".

## Parallax

Hero adalah panggung 1440 × 900 yang diskalakan seperti `background-size: cover`: istana Olympus di atas lautan awan saat senja. Lapisannya berasal dari paket "Olympus Parallax Assets" (semua dari satu bingkai 1920 × 1080). Setiap lapisan dipotong ke area isinya supaya file kecil, lalu diletakkan kembali pada posisi aslinya di bingkai, yang diskalakan ke tinggi panggung (lebar 1600, mulai di x -80). Setiap lapisan bergerak dengan kecepatan berbeda terhadap guliran, ditambah sedikit gerak mengikuti kursor di desktop.

| Lapisan | File | Kecepatan |
|---|---|---|
| Langit senja | gradasi CSS (warna dari pratinjau komposisi) | 0.05 |
| Pegunungan jauh | `olympus-puncak.webp` | 0.15 |
| Lautan awan belakang | `olympus-awan-belakang.webp` | 0.25 |
| Nama raksasa | teks HTML | 0.35 |
| Istana Olympus | `olympus-istana.webp` | 0.45 |
| Merpati | `olympus-merpati.webp` | 0.55 |
| Kabut depan | `olympus-awan-depan.webp` | 0.70 |
| Tiang Korintus dan zaitun (kiri) | `olympus-kiri.webp` | 0.88 |
| Patung Apollo dan tiang (kanan) | `olympus-kanan.webp` | 0.92 |

`layer-01-sky.png` dari paket tidak ikut terkirim, jadi langitnya dibuat dengan gradasi CSS. Bila file itu menyusul, cukup ganti `.layer--sky` dengan gambar tersebut.

Nama raksasa diletakkan di antara lapisan, sehingga atap istana menutupi kaki hurufnya. Kabut gelap lembut di belakang nama menjaga kontras di atas awan terang. `main.js` menghitung posisi nama dari tepi bawah sapaan. Di layar lebar tapi pendek bagian atas panggung terpotong, jadi nama dan cakrawala (awan, istana, merpati) ikut turun sejauh potongan itu (`--hz-auto`). Saat digulir, nama makin kabur dan pudar, panggung membesar pelan, dan hero tetap di tempat (`position: sticky`) sementara bagian Tentang naik menutupinya seperti tirai.

Di layar tegak, nama tampil sebagai judul biasa di atas langit gelap. Cakrawala turun 150 px, lalu tiang kiri dan patung kanan mengecil menjadi bingkai di dua sudut bawah, ditambatkan ke tepi layar (`--vis-l` / `--vis-r`). Patung hanya terlihat dari kepala sampai dada.

### Α · Tentang: teras kuil Delphi menghadap Laut Aegea

Bagian Tentang adalah adegan teras Delphi dari paket "S2" (empat lapisan, satu bingkai 1920 × 1080). Latar laut dan tebingnya semi-transparan (sisi kanannya ±24%), jadi warna gading bagian ini (`#F3EFE6`) ikut membentuk pemandangannya, dan pemandangan tetap samar di belakang teks. Untuk menghemat ukuran, latar itu sudah digabung dengan `#F3EFE6` (hasilnya sama persis). Lapisan lain dipotong ke area isinya lalu diletakkan kembali dalam persen bingkai. Kabut cahayanya disimpan setengah resolusi karena memang lembut.

| Lapisan | File | Kecepatan |
|---|---|---|
| Laut Aegea dan tebing Delphi | `tentang-delphi.webp` | -0.06 |
| Teras balustrade dan tangga marmer | `tentang-teras.webp` | 0.04 |
| Patung Muse memegang gulungan naskah | `tentang-muse.webp` | 0.10 |
| Sinar matahari pagi dan kabut laut | `tentang-kabut.webp` | 0.16 |

Geseran dihitung relatif terhadap tengah layar (`(tinggi layar / 2 - tengah bagian) × kecepatan`), sehingga semua lapisan tepat di posisinya saat bagian ini berada di tengah. Bingkai dipusatkan seperti `object-fit: cover`, jadi ujung teras selalu berhenti sebelum kolom teks yang mulai di 49%. Tepi atas dan bawah bingkai memudar ke gading. Di layar sempit atau tegak (lebar di bawah 768 px atau rasio di bawah 7:5), adegan menjadi ilustrasi diam di atas teks, berpusat pada patung, dan parallax dimatikan (`data-depth-wide`). Begitu pula saat gerak dikurangi.

### Δ · Perjalanan: pelayaran di atas peta Yunani kuno

Bagian Perjalanan memakai paket "Odisei": peta Yunani karya Ortelius (tepi abu-abu pindaiannya dipotong) mengisi seluruh bagian. Tebing berkuil, astrolab, kapal, ombak, dan merpati diletakkan pada posisi aslinya di bingkai 1920 × 1080 (`.journey__frame`, posisi dalam persen bingkai), jadi komposisinya sama dengan rancangan. Di layar lebar bingkai itu menutupi bagian seperti "cover" (memakai unit `cqw`/`cqh`) dan ditambatkan di kiri atas. Judul berada di 12% dari kiri, dan lini masa mulai di 46% lalu berhenti di 80%.

| Lapisan | File | Gerak |
|---|---|---|
| Peta | `odisei-peta.webp` | 0.05 |
| Tebing dan kuil | `odisei-tebing.webp` | 0.04 |
| Astrolab | `odisei-astrolab.webp` | 0.10, berputar pelan (`data-spin`) |
| Kapal | `odisei-kapal.webp` | 0.02, berlayar ke samping seiring lini masa digulir (`data-pan-x`) |
| Ombak | `odisei-ombak.webp` | -0.05 |
| Merpati | `odisei-merpati.webp` | 0.16, terbang ke samping |

Efek teks di bagian ini:

- **Tinta** (`data-ink`): judul "Odisei saya" lalu pengantarnya muncul dari kiri ke kanan dengan tepi lembut, seperti ditulis dengan tinta di atas peta.
- **Rute pelayaran** (`data-route`): garis terakota lini masa tergambar dari persinggahan pertama ke terakhir mengikuti guliran (ujungnya di 86% tinggi layar). Setiap kali rute tiba, penandanya berubah dari belah ketupat kosong menjadi penuh, lalu tahun, judul (kata naik bergiliran), dan keterangannya muncul. Rute hanya bertambah, jadi teks tidak hilang saat menggulir kembali ke atas.

Di layar sempit atau tegak, urutannya menjadi judul, ilustrasi selebar layar (jendela kiri bawah bingkai, merpati pindah ke atas laut), lalu lini masa. Lapisan diam di sana. Bila gerak dikurangi, semua teks dan garis rute langsung tampil penuh tanpa animasi.

### Ε · Kontak: malam di Akropolis

| Lapisan | File | Gerak |
|---|---|---|
| Langit berbima sakti | `kontak-langit.webp` | 0.12 |
| Bulan sabit dan cahayanya | `kontak-bulan.webp` | 0.22 (tertinggal paling lambat, terasa paling jauh) |
| Cincin zodiak di belakang judul | `kontak-zodiak.webp` | 0.06, berputar pelan (`data-spin`) |
| Akropolis bercahaya | `akropolis-malam.webp` | -0.18 (paling depan) |

Cincin zodiak dari paket aslinya hampir tak terlihat (alfa maksimal 6 dari 255), jadi alfanya diperkuat sembilan kali dan diberi opasitas 75%: terlihat sebagai ukiran samar, tidak bersaing dengan judul. Tombol utama emas adalah "Kirim email" bila `kontak.email` diisi, dan tautan pertama menjadi tombol "Lihat GitHub".

### Animasi teks dan efek gulir

| Elemen | Efek |
|---|---|
| Nama di hero | huruf naik dari kabur ke tajam satu per satu saat halaman dibuka |
| Teks peran | kata muncul bergiliran setelah nama |
| Judul bagian (`data-split`) | kata naik dari balik garis saat masuk layar (blur hanya di hero) |
| Judul dan pengantar Perjalanan (`data-ink`) | ditulis dari kiri ke kanan seperti tinta di atas peta |
| Lini masa (`data-route`) | rute tergambar saat digulir; tiap persinggahan muncul saat rute tiba |
| Label bagian (`data-carve`) | jarak huruf merapat seperti pahatan prasasti |
| Kartu keahlian | naik bergantian; benda di panelnya turun lalu mendarat di alas batu |
| Latar Keahlian | halaman kuil saat senja diam di tempat (sticky) sementara kartu digulir di atasnya |
| Frieze prasasti (dasar bagian Tentang) | dua baris pepatah Delphi bergeser berlawanan arah, di atas pita gading tipis |
| Amfora di galeri | bergeser lebih lambat daripada panelnya |
| Langit malam, bulan, cincin zodiak, Parthenon | bergerak dengan kedalaman berbeda; cincin zodiak berputar pelan |
| Garis terakota di atas | penanda kemajuan baca halaman |

Galeri karya dipin dan bergeser ke samping saat digulir di layar lebar; di ponsel kartunya bertumpuk biasa.

### Ornamen parallax

Setiap bagian punya sepasang ornamen yang bergerak dengan kedalaman berbeda. Aturannya: `data-depth` positif tertinggal dari halaman (terasa jauh), negatif mendahului (terasa dekat). Benda yang ditumpuk di depan selalu bergerak lebih cepat daripada benda di belakangnya, supaya kedalamannya terasa wajar. Ornamen tidak pernah berada di belakang teks atau tombol.

| Bagian | Ornamen | Gerak |
|---|---|---|
| Β · Keahlian | latar halaman kuil · benda di kartu | latar sticky dan tertinggal 0.08 saat bagian masuk; benda -0.07 lalu diam di alasnya (`data-settle`) |
| Γ · Karya | pecahan palmet · pelita terakota | 0.18 dan -0.1; saat galeri dipin keduanya berdiri di atas petunjuk gulir dan bergeser berlawanan arah mengikuti galeri (`data-pan-x`) |

Semua ornamen memakai `alt=""` di dalam pembungkus `aria-hidden="true"`, tidak bisa diklik (`pointer-events: none`), dan dimuat malas (`loading="lazy"`). Bila gerak dikurangi, ornamen diam di posisinya.

Karya pertama punya tautan "Lihat tangkapan layar aplikasi" ke `assets/karya/topologi-aset.webp`, tangkapan layar aplikasi aslinya, sebagai pelengkap demo yang memakai data contoh.

Pengguna yang mengaktifkan *reduce motion* di sistem operasinya mendapat halaman tanpa parallax, tanpa pin, dan semua konten langsung terlihat.

## Keputusan desain

- **Warna**: dua warna inti (biru Aegea untuk langit dan malam, terakota untuk tembikar), netral marmer dan tinta, dan satu aksen emas yang **hanya** dipakai untuk aksi (tombol utama, tautan, tombol simulasi) serta keadaan aktif atau fokus (simpul terpilih, cincin fokus). Hiasan seperti pita meander, penanda lini masa, label bagian, dan garis kemajuan memakai terakota atau marmer, supaya emas tetap berarti "bisa diklik". Teks terakota memakai `#9A4322` agar kontrasnya 5.7:1 di atas marmer.
- **Jarak huruf**: label berhuruf kapital Cinzel memakai jarak 0.06 sampai 0.12em, cukup untuk kesan pahatan tanpa terlihat seperti label template yang direnggangkan.
- **Tema tetap, tanpa mode gelap**: halaman memang bergerak dari senja ke malam, jadi terang dan gelap sudah menjadi bagian cerita.
- **Tipografi**: Cinzel berbentuk huruf pahatan prasasti untuk judul; EB Garamond nyaman dibaca untuk paragraf; GFS Didot menangani huruf Yunani (Α Β Γ Δ Ε, Χαίρετε).
- **Motif identitas**: pita meander di setiap peralihan, penomoran bagian dengan huruf Yunani, panel karya bergaya tembikar black-figure, keahlian sebagai empat pilar, masing-masing dengan benda perunggu, kayu, atau papirus di atas alas batu.
- **Benda pilar**: helm hoplite (backend menjaga sistem), burung hantu Athena (data), lira (harmoni antarmuka), gulungan papirus (dokumentasi).
- **Gerak**: parallax untuk kedalaman, kartu keahlian yang naik bergantian di atas halaman kuil yang diam, galeri yang bergeser untuk menjelajah karya. Tidak ada animasi yang berulang terus-menerus.

## Aksesibilitas

- Tautan "Lewati ke isi" muncul saat menekan Tab pertama kali.
- Semua kontrol bisa dipakai dengan keyboard, dengan indikator fokus yang jelas; menu mobile bisa ditutup dengan Escape.
- Saat kartu galeri difokus dengan keyboard, halaman menggulir sampai kartu itu terlihat.
- Target sentuh minimal 44 px; tidak ada guliran horizontal di ponsel.

## Deploy

Folder ini bisa diunggah apa adanya ke hosting statis mana pun, misalnya Netlify (seret folder `portfolio` ke app.netlify.com/drop) atau Vercel. GitHub Pages mode "Deploy from a branch" hanya melayani root atau `/docs`, jadi untuk Pages pindahkan isi folder ini ke repo tersendiri atau ke `/docs`.

## Kredit

- Aset fotorealistik (lapisan Olympus, teras Delphi, pelayaran Odisei, amfora, mahkota laurel, benda pilar, latar halaman kuil) berasal dari gambar generatif yang di-upscale, disediakan pemilik situs.
- Versi vektor awal dibuat dengan Figma AI dan masih tersimpan di file Figma di atas. Pita meander digambar langsung sebagai pola CSS.
- Font: Cinzel, EB Garamond, GFS Didot dari Google Fonts (SIL Open Font License).
