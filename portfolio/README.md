# Senja di Akropolis · Portofolio Parallax

Situs portofolio satu halaman bertema Yunani kuno. Halaman bercerita dari senja ke malam: hero dibuka saat matahari terbenam di Laut Aegea (dan ikut tenggelam saat digulir), bagian tengah memakai marmer dan tembikar, kontak ditutup di bawah langit malam Akropolis.

Situs statis murni (HTML, CSS, JavaScript tanpa build) dan tidak bergantung pada aplikasi Laravel di repo ini.

- Desain Figma: https://www.figma.com/design/GHARUTXrcZveHbmxf3K1JN
  - `🖼 Sampul`: sampul file
  - `🏛 Desain Halaman`: mockup desktop 1440 px, mockup ponsel 390 px, dan overlay menu ponsel
  - `🧩 Komponen`: Tombol, Pilar keahlian, Kartu karya, Kartu lainnya, Singgah lini masa, Baris fakta, Tag teknologi, Pita meander, dan ikon
  - `🏺 Aset Parallax`: semua lapisan dan ilustrasi (sumber file di `assets/figma/`)
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
3. `kontak.email`: selama kosong, tombol utama mengarah ke GitHub. Setelah diisi, muncul tombol "Tulis email".
4. URL yang kosong (`""`) otomatis disembunyikan, jadi tidak ada tautan mati.
5. Foto: isi `tentang.foto` (misalnya `"assets/foto-saya.jpg"`). Selama kosong, ceruk menampilkan patung dada sebagai hiasan, bukan sebagai foto Anda.
6. Tangkapan layar proyek: isi `gambar` pada tiap karya. Selama kosong, panel menampilkan amfora dan label "Tangkapan layar [GANTI]".

Kalau `data.js` rusak (misalnya kurang koma), halaman menampilkan pesan galat di bagian atas.

## Struktur

```
portfolio/
├── index.html
├── css/style.css      token warna = variabel Figma "Yunani / Warna"
├── js/data.js         konten
├── js/main.js         render, parallax, galeri geser, menu, kemunculan
└── assets/figma/      SVG hasil ekspor Figma (latar transparan)
```

## Parallax

Hero adalah panggung 1440 × 900 (sama dengan frame Figma) yang diskalakan seperti `background-size: cover`. Setiap lapisan bergerak dengan kecepatan berbeda terhadap guliran, ditambah sedikit gerak mengikuti kursor di desktop.

| Lapisan | Kecepatan |
|---|---|
| Langit | 0.05 |
| Matahari | 0.10 (tenggelam ke laut saat digulir) |
| Garis awan | 0.12 |
| Pulau jauh | 0.18 |
| Laut + kilau | 0.20 |
| Burung camar | 0.25 |
| Pulau dekat | 0.30 |
| Akropolis | 0.42 |
| Pepohonan | 0.62 |
| Reruntuhan | 1.00 (menyambung ke pita meander) |
| Kolom serambi | 1.12 |

Di luar hero: patung dada, ranting zaitun, bintang, dan Parthenon malam memakai `data-depth`. Galeri karya dipin dan bergeser ke samping saat digulir di layar lebar; di ponsel kartunya bertumpuk biasa.

Pengguna yang mengaktifkan *reduce motion* di sistem operasinya mendapat halaman tanpa parallax, tanpa pin, dan semua konten langsung terlihat.

## Keputusan desain

- **Warna**: dua warna inti (biru Aegea untuk langit dan malam, terakota untuk tembikar), satu aksen emas (tombol utama, mahkota laurel, penanda lini masa), netral marmer dan tinta. Teks terakota memakai `#9A4322` agar kontrasnya 5.7:1 di atas marmer.
- **Tema tetap, tanpa mode gelap**: halaman memang bergerak dari senja ke malam, jadi terang dan gelap sudah menjadi bagian cerita.
- **Tipografi**: Cinzel berbentuk huruf pahatan prasasti untuk judul; EB Garamond nyaman dibaca untuk paragraf; GFS Didot menangani huruf Yunani (Α Β Γ Δ Ε, Χαίρετε).
- **Motif identitas**: pita meander di setiap peralihan, penomoran bagian dengan huruf Yunani, panel karya bergaya tembikar black-figure, keahlian sebagai empat pilar kuil.
- **Ikon**: helm hoplite (backend menjaga sistem), burung hantu Athena (data), lira (harmoni antarmuka), gulungan papirus (dokumentasi).
- **Gerak**: parallax untuk kedalaman, pilar yang berdiri satu per satu seperti kuil dibangun, galeri yang bergeser untuk menjelajah karya. Tidak ada animasi yang berulang terus-menerus.

## Aksesibilitas

- Tautan "Lewati ke isi" muncul saat menekan Tab pertama kali.
- Semua kontrol bisa dipakai dengan keyboard, dengan indikator fokus yang jelas; menu mobile bisa ditutup dengan Escape.
- Saat kartu galeri difokus dengan keyboard, halaman menggulir sampai kartu itu terlihat.
- Target sentuh minimal 44 px; tidak ada guliran horizontal di ponsel.

## Deploy

Folder ini bisa diunggah apa adanya ke hosting statis mana pun, misalnya Netlify (seret folder `portfolio` ke app.netlify.com/drop) atau Vercel. GitHub Pages mode "Deploy from a branch" hanya melayani root atau `/docs`, jadi untuk Pages pindahkan isi folder ini ke repo tersendiri atau ke `/docs`.

## Kredit

- Ilustrasi parallax, patung, amfora, ranting zaitun, mahkota laurel, dan ikon dibuat dengan Figma AI di file Figma di atas; pulau, kilau laut, burung, garis awan, dan pita meander digambar langsung sebagai vektor.
- Font: Cinzel, EB Garamond, GFS Didot dari Google Fonts (SIL Open Font License).
