<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ninja Lost — Tantangan Cermin</title>
    <style>body{font-family:system-ui,sans-serif;max-width:960px;margin:auto;padding:16px;line-height:1.5}input,select,button{font:inherit;max-width:100%;padding:6px}label{display:block;margin:10px 0}fieldset{min-width:0;margin:16px 0}p{overflow-wrap:anywhere}[hidden]{display:none!important}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:600px){.grid{grid-template-columns:1fr}}progress{width:100%}</style>
    <script src="/js/trial-client.js" defer></script>
</head>
<body><main>
    <h1>Tantangan Cermin</h1>
    <p>Evaluasi reward terverifikasi. Mirror memakai snapshot stat/equipment Anda dan menyerang dengan weapon. Kemenangan harus dihitung ulang oleh server sebelum gold/XP diterbitkan. Bukan client native atau desain final.</p>
    <p><a href="/evaluation/v1/training/client">Masuk / atur equipment dan skill</a> · <a href="/portal/home">Portal pemain</a></p>
    <p id="notice" role="status" aria-live="polite">Memuat sesi…</p>
    <section id="session" hidden>
        <button id="refresh" type="button">Muat ulang</button>
        <button id="retry" type="button" hidden>Ulangi permintaan yang sama</button>
        <fieldset id="selection"><legend>Pilih tantangan</legend>
            <label>Karakter <select id="character"></select></label>
            <label>Aturan aktif <select id="trial"></select></label>
            <p id="loadout"></p>
            <button id="start" type="button">Mulai tantangan</button>
        </fieldset>
        <section id="battle" hidden>
            <h2 id="title"></h2><p id="status"></p><p id="rewards"></p>
            <div class="grid">
                <section><h3>Karakter Anda</h3><p id="player-hp"></p><progress id="player-bar"></progress><p id="player-cp"></p></section>
                <section><h3>Mirror</h3><p id="mirror-hp"></p><progress id="mirror-bar"></progress><p id="mirror-cp"></p></section>
            </div>
            <fieldset id="actions"><legend>Aksi</legend>
                <button type="button" data-action="weapon">Weapon</button><button type="button" data-action="charge">Charge</button>
                <button type="button" data-action="dodge">Dodge</button><button type="button" data-action="run">Menyerah</button>
                <div id="skill-actions"></div>
            </fieldset>
            <button id="finish" type="button">Verifikasi hasil dan selesaikan</button>
            <p id="receipt"></p>
        </section>
    </section>
</main></body></html>
