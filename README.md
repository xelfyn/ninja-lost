# Ninja Lost

Pengembangan game komunitas dengan pendekatan GitHub-first dan web-first.

## Status

B00: bootstrap repository dan CI ringan. Belum ada backend game, website aktif, bot Discord, atau APK yang dirilis. Kelulusan bootstrap bukan kelulusan gameplay.

Urutan implementasi: B00 workspace; B01 source intake terkontrol; B02 runtime cloud; B03 web admin; B04 portal pemain; B05 reward transaksional; B06 Discord; B07 mystery box dan sinkronisasi; B08 market/gacha; B09 staging persisten; B10 Android/updater; B11 stabilisasi.

## Pemeriksaan

```sh
python3 -m unittest discover -s tests -v
python3 scripts/ci/check_bootstrap.py
```

Workflow `B00 bootstrap` menggunakan Ubuntu standar, satu job dengan timeout 3 menit, permission baca, checkout terpin, serta laporan melalui log dan job summary. Tidak memakai artifact upload, dependency cache, Packages, LFS, Codespaces, atau hosting otomatis.

## Batas

Repository adalah sumber acuan, bukan server game 24 jam. Visibilitas tidak diubah oleh bootstrap. Tidak ada source/aset komunitas, credential, atau database pemain yang diimpor pada B00. Tinjau privasi dan hak komponen sebelum B01.

Batas pengeluaran akun belum diverifikasi atau diubah; kebijakan repository tidak menggantikannya. Hasil CI harus ditelusuri ke commit/run yang benar, bukan disimpulkan dari keberadaan file workflow. Pemeriksaan bootstrap menggunakan allowlist kecil, bukan audit keamanan aplikasi atau pemindai secret lengkap.

Lihat [kebijakan storage](docs/STORAGE_POLICY.md) dan [baseline kandidat](project/source-lock.json). Setelah B01, pemeriksaan bootstrap perlu diperluas secara terarah untuk source yang disetujui, tidak sekadar dimatikan.
