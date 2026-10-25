# Terserah! - Backend API

Aplikasi web untuk **mengambil keputusan kelompok** tentang apa saja (makan siang, film, tujuan liburan, hadiah, dan lainnya). Anggota ruang memasukkan preferensi dengan bahasa bebas, mengusulkan kandidat, memberi suara atau veto, lalu sistem menghitung ranking dengan **skor multi-kriteria**. AI membantu memahami preferensi, memberi ide opsi, dan menjelaskan alasan keputusan.

Proyek capstone mata kuliah Pengembangan Aplikasi Web Lanjut, Universitas ASA Indonesia.
Penulis: Ivan Muhamad Ilham (24107000711).

Repo frontend: `terserah-web` (https://github.com/ivanilham36/terserah-web).

## Fitur

- Registrasi, login, dan logout dengan JWT.
- Katalog opsi (CRUD, pencarian, filter kategori, paginasi) dan ulasan bintang 1-5.
- Ruang keputusan dengan kode undangan 6 karakter, preferensi per anggota (teks bebas, budget, lokasi).
- Voting (suka) dan veto, dengan hak mencabut suara.
- Ranking rekomendasi dengan rincian skor per kriteria.
- Keputusan ditetapkan oleh pemilik ruang, disertai alasan yang ditulis AI.
- AI (Groq, model terbuka): memahami preferensi, menyarankan ide opsi, menjelaskan keputusan. Jika AI gagal atau terkena limit, sistem otomatis pindah model lalu memakai fallback berbasis aturan, sehingga aplikasi tidak pernah berhenti.

## Teknologi

Laravel 12, PHP 8.2+, MySQL, JWT (`tymon/jwt-auth` 2.2.1), PHPUnit, Groq API (kompatibel OpenAI).

## Instalasi

Prasyarat: PHP 8.2 atau lebih baru, Composer, MySQL (misalnya lewat Laragon).

```bash
composer install
cp .env.example .env        # PowerShell: Copy-Item .env.example .env
php artisan key:generate
php artisan jwt:secret
```

Buat database kosong bernama `db_terserah`, sesuaikan `DB_*` di `.env` bila perlu, lalu:

```bash
php artisan migrate:fresh --seed
php artisan serve
```

API berjalan di `http://127.0.0.1:8000`.

### Akun demo (password semuanya: `password`)

| Email | Peran |
|---|---|
| admin@terserah.test | admin |
| amy@terserah.test | user (pemilik ruang demo) |
| budi@terserah.test | user |
| citra@terserah.test | user |

Ruang demo "Makan siang tim" memiliki kode undangan `TRSRH1`. Seluruh data seeder bersifat **fiktif**.

## Konfigurasi AI

Ambil API key gratis di https://console.groq.com lalu isi di `.env`:

```
GROQ_API_KEY=isi_key_kamu
GROQ_MODELS=openai/gpt-oss-20b,openai/gpt-oss-120b
```

- Model dicoba berurutan; bila satu model gagal atau terkena limit, sistem lanjut ke model berikutnya.
- Jika semua gagal atau key kosong, sistem memakai parser kata kunci dan templat penjelasan.
- Key hanya dipakai di backend dan tidak pernah dikirim ke frontend. Jangan commit file `.env`.
- Setiap panggilan AI dicatat di tabel `ai_logs` (jenis, status `ok`/`fallback`/`error`, latensi).

## Menjalankan tes

```bash
php artisan test
```

Tes memakai SQLite di memori (lihat `phpunit.xml`), sehingga tidak menyentuh database MySQL. Cakupan: autentikasi, katalog opsi dan ulasan, alur ruang (gabung, veto, ranking, keputusan), mesin skor (preferensi, pantangan, jarak), serta layanan AI (parsing, pindah model saat limit, fallback).

## Dokumentasi API

Koleksi Postman ada di `docs/Terserah.postman_collection.json` (Import di Postman, jalankan **Login** lebih dulu; token tersimpan otomatis). Semua endpoint selain register dan login memakai header `Authorization: Bearer <token>`.

| Metode | Endpoint | Keterangan |
|---|---|---|
| POST | /api/auth/register | Daftar dan langsung dapat token |
| POST | /api/auth/login | Login (field `login` boleh email atau username) |
| GET | /api/auth/me | Profil user login |
| POST | /api/auth/refresh | Perbarui token |
| POST | /api/auth/logout | Logout |
| GET | /api/options | Daftar opsi (`category`, `q`, `page`) |
| GET | /api/options/{id} | Detail opsi beserta ulasan |
| POST | /api/options | Buat opsi |
| PUT | /api/options/{id} | Ubah opsi (pembuat atau admin) |
| DELETE | /api/options/{id} | Hapus opsi (pembuat atau admin) |
| POST | /api/options/{id}/reviews | Beri atau perbarui ulasan |
| DELETE | /api/reviews/{id} | Hapus ulasan |
| GET | /api/rooms | Ruang yang saya ikuti |
| POST | /api/rooms | Buat ruang |
| POST | /api/rooms/join | Gabung dengan kode |
| GET | /api/rooms/{id} | Detail ruang |
| PUT | /api/rooms/{id}/me | Simpan preferensiku (diparse AI) |
| POST | /api/rooms/{id}/options | Tambah kandidat |
| DELETE | /api/rooms/{id}/options/{optionId} | Hapus kandidat |
| POST | /api/rooms/{id}/votes | Suka atau veto |
| DELETE | /api/rooms/{id}/votes/{optionId} | Cabut suara |
| GET | /api/rooms/{id}/recommendations | Ranking dan rincian skor |
| POST | /api/rooms/{id}/decide | Tetapkan keputusan (pemilik ruang) |
| POST | /api/rooms/{id}/ai/suggest | Ide opsi dari AI |
| DELETE | /api/rooms/{id} | Hapus ruang (pemilik) |
| DELETE | /api/rooms/{id}/members/me | Keluar dari ruang (anggota) |
| POST | /api/auth/google | Login/daftar dengan Google (credential); akun dengan email sama digabung |
| POST | /api/auth/forgot-password | Kirim kode reset 6 digit ke email |
| POST | /api/auth/reset-password | Ganti password dengan kode (email, code, password) |
| GET | /api/places/search?lat&lng&category&radius atau ?area=nama | Cari tempat nyata (OpenStreetMap) |
| POST | /api/rooms/{id}/places | Jadikan tempat nyata sebagai kandidat |
| GET | /api/options?scope=mine | Tempat yang pernah saya pakai |

## Cara kerja skor

Skor akhir (0-100) = jumlah dari bobot x skor tiap kriteria, ditambah bonus kecil untuk tiap suara suka:

- **preference**: proporsi anggota yang preferensinya cocok dengan judul atau tag opsi.
- **rating**: rata-rata ulasan dibagi 5 (netral 0,5 bila belum ada ulasan).
- **price**: kesesuaian tingkat harga dengan budget anggota terkecil.
- **distance**: jarak Haversine dari titik tengah lokasi anggota (hanya bila ruang memakai lokasi).

Opsi yang di-veto salah satu anggota, atau memuat pantangan anggota, disingkirkan sebelum penilaian. Bobot dapat diatur per ruang; bobot yang tidak aktif dinormalisasi otomatis.

## Struktur penting

```
app/Http/Controllers/Api   Auth, Option, Review, Room
app/Services               ScoringService (mesin skor), AiService (integrasi AI)
app/Models                 User, Room, Option, Review, Vote, AiLog
database/migrations        8 tabel aplikasi
database/seeders           Data demo (fiktif)
tests/Feature              Tes otomatis
docs                       Koleksi Postman
```



## Tempat nyata (OpenStreetMap)

`GET /api/places/search` memakai Geoapify Places (data OpenStreetMap, API key gratis di `GEOAPIFY_API_KEY`). Tanpa key atau saat Geoapify gagal, otomatis jatuh ke Overpass API dan Nominatim (gratis, tanpa key, tetapi server publiknya sering sibuk). Data OSM tidak punya rating,
jadi rating berasal dari ulasan pengguna aplikasi ini, sedangkan kecocokan preferensi dibaca dari tag nyata
(masakan, halal, wifi, AC, tempat duduk luar). Hasil di-cache 15 menit dan server cadangan dicoba bila yang utama sibuk.

## Email (lupa password)

Kode reset dikirim lewat email. Isi `MAIL_*` di `.env` (SMTP apa saja, mis. Gmail dengan App Password atau Brevo).
Selama `MAIL_MAILER=log`, kode hanya ditulis ke `storage/logs/laravel.log` (cukup untuk pengembangan lokal).

## Login Google

Isi `GOOGLE_CLIENT_ID` (OAuth client ID jenis Web dari Google Cloud Console). Token ID dari Google Identity Services
diverifikasi ke `oauth2.googleapis.com/tokeninfo` (audience harus cocok dan email harus terverifikasi). Akun dengan
email yang sama digabung; bila akun itu didaftarkan lewat form biasa (email belum terverifikasi), passwordnya dihapus
supaya orang yang mendaftar duluan dengan email orang lain tidak bisa mengambil alih akun. Password bisa dipasang
lagi lewat Lupa password.
