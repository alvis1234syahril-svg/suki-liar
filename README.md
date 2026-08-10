<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Traffic Log & Asset Topology

Aplikasi ini menggabungkan analisis traffic dengan modul inventaris dan tree topologi aset jaringan. Modul **Topologi Aset** berada di `/asset-topology` dan menyediakan pemilihan lokasi, tree parent–child, zoom/pan, master aset, riwayat lifecycle, tambah lokasi dari workbook, pergantian/mutasi aset, serta ekspor Excel.

### Menyiapkan modul topologi

1. Aktifkan ekstensi PHP `zip` karena PhpSpreadsheet membutuhkannya untuk membaca dan menulis XLSX.
2. Simpan sumber privat di `storage/app/private/asset-topology/`:
   - `Rekap_Data_Aset_PT_PLN_tahap1.xlsx`
   - `legacy-state-v1.json`
   - `base-assets.json`
   - `baturaja-topology.json`
3. Jangan commit file tersebut. Folder private dan database SQLite lokal sudah diabaikan Git.
4. Jalankan migrasi dan impor awal:

```bash
php artisan migrate
php artisan topology:import-legacy storage/app/private/asset-topology/legacy-state-v1.json --dry-run
php artisan topology:import-legacy storage/app/private/asset-topology/legacy-state-v1.json
npm ci
npm run build
```

Untuk instalasi PHP yang belum mengaktifkan ZIP di `php.ini`, perintah impor/test lokal dapat dijalankan sementara dengan `php -d extension=zip ...`.

### Korelasi traffic dengan aset

Setelah sebuah analisis log selesai dan dipublikasikan, halaman Topologi Aset otomatis membaca ringkasan analisis terbaru untuk lokasi yang sama. Sistem mencocokkan IP sumber yang valid dengan IP inventaris atau IP interface LLD pada aset aktif.

- Kecocokan hanya berlaku di unit/lokasi yang sama.
- Satu IP yang menunjuk tepat ke satu aset ditandai **Terlihat di log**.
- IP yang digunakan beberapa aset ditandai **Ambigu** dan tidak dipilih otomatis.
- Perangkat internal yang belum cocok ditampilkan untuk ditinjau.
- IP terkait pada identity ikut diperiksa. Angka byte/upload/download tetap merupakan total identity, bukan pembagian traffic presisi per IP.
- Korelasi ini bersifat observasi; tidak mengubah parent asset, edge topologi, kondisi, atau lifecycle aset.
- Ringkasan tetap dapat dipakai setelah raw traffic dipurge.

### Port LAN dan pemantauan switch

Port tidak disalin dari lokasi contoh. Sistem membaca hubungan dan nomor port milik sheet lokasi yang sedang diimpor. Workbook dapat menambahkan kolom opsional berikut pada baris header yang sama:

- `Jumlah Port LAN` untuk kapasitas switch;
- `Parent Port` atau `Port LAN` untuk nomor port pada perangkat parent;
- `Port Aset` untuk nomor port pada perangkat child.

Jika kapasitas tidak dicatat, jumlah port yang sangat jelas pada nama model (misalnya `USW-24` atau `24 PoE`) ditampilkan sebagai **perkiraan model**, bukan fakta telemetry. Status port berasal dari IF-MIB SNMP. Aplikasi menandai `admin up + link up` sebagai normal, `admin up + link down` sebagai perlu diperiksa, dan `admin down` sebagai dinonaktifkan. Status link down bukan bukti tunggal kerusakan petir; kabel, daya, dan endpoint tetap perlu diperiksa.

Aktifkan ekstensi PHP `snmp`, lalu simpan community hanya di `.env`:

```dotenv
ASSET_TOPOLOGY_SNMP_ENABLED=true
ASSET_TOPOLOGY_SNMP_COMMUNITY=community-rahasia
```

Polling dapat diuji dengan `php artisan topology:poll-ports --asset=ID-ASET-SWITCH` dan dijadwalkan otomatis setiap menit oleh Laravel scheduler.

### Import Excel dan penempatan access point

Pada halaman **Topologi Aset**, pilih lokasi lalu tekan **Import Excel**. Sistem membaca sheet yang namanya sama dengan lokasi aktif dan melakukan upsert master aset beserta parent/port yang tersedia. User tidak perlu mengetik ulang ID aset, model, IP, atau lokasi. Untuk dataset LLD, jalur inti tetap dipertahankan; aset baru dari Excel ditambahkan sebagai overlay dan langsung muncul pada tree.

Untuk menempatkan AP, klik bentuk fisik port pada node switch lalu pilih access point yang sudah ada di database. Sistem menolak port yang telah digunakan perangkat lain, memperbarui parent AP, membuat edge switchâ€“AP, dan mencatat perubahan pada riwayat aset.

### Verifikasi

```bash
php -d extension=zip vendor/bin/phpunit --do-not-cache-result
node scripts/browser-topology-smoke.mjs
```

Repository dapat tetap publik karena data inventaris asli tidak disimpan dalam source code. Namun route topologi menampilkan data operasional dari database, sehingga tambahkan middleware autentikasi/otorisasi sebelum aplikasi diterbitkan ke internet.

### Worker antrean di Laragon

Upload log memakai antrean database agar request web tidak menunggu parsing file besar. Laragon harus menjalankan worker berikut bersama Nginx/PHP:

```text
Traffic Log Queue: autorun php artisan queue:work database --queue=default --sleep=1 --tries=1 --timeout=3600 --memory=512 PWD="C:\path\ke\log-traffic-analytics"
Traffic Log Scheduler: autorun php artisan schedule:work PWD="C:\path\ke\log-traffic-analytics"
```

Tambahkan baris tersebut ke `C:\laragon\usr\Procfile` dan sesuaikan `PWD`. Pada mesin pengembangan non-Laragon, `composer dev` sudah menjalankan server, worker, dan Vite secara bersamaan. Jika parsing gagal, halaman progres menyediakan tombol **Proses ulang file ini** yang menggunakan file tersimpan tanpa mengunggah ulang.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
