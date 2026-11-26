/*
 * ==========================================================
 *  KONTEN PORTOFOLIO
 *  Semua teks di halaman diambil dari file ini.
 *
 *  - Cari tanda [GANTI] lalu isi dengan data Anda yang sebenarnya.
 *    Teks bertanda [GANTI] tampil dengan garis bawah putus-putus
 *    di halaman supaya mudah ditemukan.
 *  - Biarkan url / email kosong ("") bila belum ada. Tombol atau
 *    tautannya otomatis disembunyikan, jadi tidak ada tautan mati.
 *  - Jaga tanda kutip dan koma. Kalau file ini rusak, halaman akan
 *    menampilkan pesan galat di bagian atas.
 * ==========================================================
 */
window.PORTOFOLIO = {
  nama: "Alvis Syahril",

  hero: {
    sapaan: "Χαίρετε · salam kenal, saya",
    // [GANTI] bila profesi/fokus Anda berbeda.
    peran: "Web developer yang membangun aplikasi Laravel dan antarmuka JavaScript yang rapi dipakai."
  },

  tentang: {
    judul: "Pembangun di balik kolom-kolom ini",
    paragraf: [
      "Saya Alvis Syahril, web developer yang paling betah bekerja di antara Laravel, basis data, dan JavaScript. Proyek terbaru saya mengubah log traffic jaringan yang mentah menjadi ringkasan yang bisa dibaca, lengkap dengan peta topologi aset.",
      "[GANTI] Tulis latar belakang Anda di sini: kampus atau tempat kerja, bidang yang ingin didalami, dan jenis proyek yang sedang dicari."
    ],
    fakta: [
      { label: "Domisili", nilai: "[GANTI] Kota, Indonesia" },
      { label: "Fokus", nilai: "Laravel, JavaScript, antarmuka web" },
      { label: "Status", nilai: "[GANTI] Terbuka untuk proyek lepas" }
    ],
    // Isi dengan path foto Anda, misalnya "assets/foto-saya.jpg".
    // Selama kosong, ceruk menampilkan patung dada sebagai hiasan.
    foto: ""
  },

  // Empat pilar kuil. Ikon: helm, burung-hantu, lira, gulungan.
  keahlian: [
    {
      ikon: "helm",
      judul: "Backend",
      moto: "Penjaga logika di balik layar.",
      daftar: ["Laravel dan PHP", "REST API", "Antrean dan job", "Autentikasi"]
    },
    {
      ikon: "burung-hantu",
      judul: "Data",
      moto: "Membaca pola dari angka mentah.",
      daftar: ["MySQL dan SQLite", "Analisis log traffic", "Ekspor Excel", "Pemantauan SNMP"]
    },
    {
      ikon: "lira",
      judul: "Antarmuka",
      moto: "Tampilan yang enak dipakai.",
      daftar: ["JavaScript", "Tailwind CSS", "Vite", "Figma"]
    },
    {
      ikon: "gulungan",
      judul: "Kerja rapi",
      moto: "Kode yang bisa dirawat orang lain.",
      daftar: ["Git dan GitHub", "PHPUnit", "Dokumentasi README", "[GANTI] keahlian lain"]
    }
  ],

  // Karya pertama tampil paling besar. Tambah atau hapus objek sesuka Anda.
  karya: [
    {
      // [PERIKSA] Proyek ini diambil dari repo Anda. Hapus bila belum boleh dipublikasikan.
      judul: "Analisis Traffic & Topologi Aset",
      deskripsi: "Aplikasi Laravel untuk mengunggah log traffic, meringkas pemakaian per klien, dan memetakan aset jaringan dalam pohon topologi dengan zoom, riwayat aset, dan ekspor Excel.",
      teknologi: ["Laravel", "Tailwind CSS", "Vite", "PhpSpreadsheet", "SNMP"],
      gambar: "assets/foto/karya-topologi.webp",
      gambarAlt: "Prasasti terakota berukir kurung kurawal kode dan diagram topologi jaringan, dibingkai pola meander",
      tautan: [] // misalnya [{ label: "Lihat demo", url: "https://..." }]
    },
    {
      judul: "[GANTI] Nama proyek kedua",
      deskripsi: "Satu atau dua kalimat: masalah apa yang diselesaikan, peran Anda, dan hasilnya.",
      teknologi: ["[Teknologi]", "[Teknologi]"],
      gambar: "",
      tautan: []
    },
    {
      judul: "[GANTI] Nama proyek ketiga",
      deskripsi: "Satu atau dua kalimat: masalah apa yang diselesaikan, peran Anda, dan hasilnya.",
      teknologi: ["[Teknologi]", "[Teknologi]"],
      gambar: "",
      tautan: []
    }
  ],

  perjalanan: [
    {
      tahun: "2026",
      judul: "Analisis Traffic & Topologi Aset",
      deskripsi: "Membangun aplikasi Laravel untuk analisis log traffic dan inventaris aset jaringan: unggah log, ringkasan per klien, pohon topologi, dan ekspor Excel."
    },
    {
      tahun: "[GANTI] 20XX",
      judul: "[GANTI] Pengalaman kerja atau magang",
      deskripsi: "Peran, tempat, dan satu hasil yang bisa Anda tunjukkan."
    },
    {
      tahun: "[GANTI] 20XX",
      judul: "[GANTI] Pendidikan",
      deskripsi: "Kampus, jurusan, dan topik tugas akhir bila ada."
    }
  ],

  kontak: {
    judul: "Ada proyek yang perlu dibangun?",
    ajakan: "Ceritakan kebutuhannya lewat email, sertakan tenggat dan tautan referensi bila ada.",
    email: "", // [GANTI] misalnya "nama@domain.com"
    tautan: [
      { label: "GitHub", url: "https://github.com/alvis1234syahril-svg" },
      { label: "LinkedIn", url: "" } // [GANTI] isi URL profil LinkedIn Anda
    ]
  }
};
