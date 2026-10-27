# B00 — Storage-light CI

Keputusan implementasi: development dilanjutkan tanpa menjadikan artifact storage sebagai ketergantungan bootstrap.

## Implementasi

- Satu job pada runner Ubuntu standar, timeout 3 menit, tanpa matrix atau jadwal berulang.
- Push `main` dan pull request diperiksa; perubahan lama pada ref yang sama dapat dibatalkan.
- Tidak menggunakan upload/download artifact, cache persisten, GitHub Packages, LFS, atau custom runner image.
- Hasil tersedia dalam log dan job summary. Tidak mengunggah vendor, node_modules, database, APK atau ZIP per commit.
- Tidak mengubah billing, menghapus artifact lama, atau membuat runtime berbayar.
- CI hanya memverifikasi bootstrap. Laravel, database, browser, Discord dan Android belum termasuk.

Artifact storage, cache, repository Git, Codespaces, dan disk sementara runner bukan satu kuota yang sama. Menghapus cache bukan otomatis solusi untuk artifact storage yang penuh. Kuota berjalan dan accrued storage billing juga berbeda: pergantian periode tidak menghapus file yang masih disimpan.

Repository saat bootstrap sudah public; visibilitas dipertahankan, bukan diubah demi menit gratis. Sebelum source komunitas atau komponen internal diimpor, tinjau ulang kebutuhan private, attribution dan hak aset. Jangan mengimpor credential meskipun repository menjadi private.

## Paket unduhan berikutnya

Gunakan source snapshot pada commit tertentu. Untuk software milestone yang benar-benar diuji, siapkan GitHub Release dengan manifest dan checksum. Releases bukan tempat menumpuk log CI atau backup database; jangan menerbitkan aset tanpa izin. Snapshot source bukan APK, paket runtime siap pakai, atau backup seluruh riwayat Git.

## Hal yang belum diverifikasi

Budget tingkat akun, metode pembayaran, sisa kuota Codespaces, penyebab artifact storage akun penuh, dan pengaturan retensi repository lain. Tidak ada jaminan total tagihan akun nol karena workflow/repository lain bisa tetap memakai layanan.

## Referensi resmi (ditinjau 27 Oktober 2026)

- https://docs.github.com/en/billing/concepts/product-billing/github-actions
- https://docs.github.com/en/actions/reference/limits
- https://docs.github.com/en/billing/reference/product-usage-included
- https://docs.github.com/en/actions/how-tos/manage-workflow-runs/remove-workflow-artifacts
- https://docs.github.com/en/repositories/releasing-projects-on-github/about-releases
