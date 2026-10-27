# Ninja Lost

Pengembangan game komunitas dengan pendekatan GitHub-first dan web-first.

## Status

B00: bootstrap repository. Belum ada backend game, website aktif, bot Discord, atau APK yang dirilis. Kelulusan pemeriksaan bootstrap bukan kelulusan gameplay.

Urutan implementasi: B00 workspace; B01 source intake terkontrol; B02 runtime cloud; B03 web admin; B04 portal pemain; B05 reward transaksional; B06 Discord; B07 mystery box dan sinkronisasi; B08 market/gacha; B09 staging persisten; B10 Android/updater; B11 stabilisasi.

## Batas bootstrap

- Repository adalah sumber acuan; tidak membutuhkan desktop pengguna.
- CI awal memakai Linux standar, timeout pendek, tanpa artifact upload, cache, Packages, LFS, atau Codespaces otomatis.
- Tidak mengimpor source/aset komunitas, database pemain, atau credential pada B00.
- Visibilitas repository yang sudah ada tidak diubah. Tinjau privasi dan hak komponen sebelum B01.
- Pengaturan budget akun belum diverifikasi atau diubah. Kebijakan repository bukan pengganti batas pengeluaran GitHub.

## Pemeriksaan

Konfigurasi dan script pemeriksaan ditambahkan dalam bootstrap berikutnya. Hasil eksekusi yang sah harus menunjuk commit dan workflow run yang sesuai; jangan menganggap file workflow sebagai bukti CI telah berjalan.
