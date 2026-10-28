<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ninja Lost — Training</title>
    <style>
        body{font-family:system-ui,sans-serif;max-width:960px;margin:auto;padding:20px;line-height:1.5}button,input,select{font:inherit;max-width:100%;padding:6px}button{cursor:pointer}fieldset{margin:16px 0;min-width:0}label{display:block;margin:8px 0}select[multiple]{width:100%}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.grid>*{min-width:0}table{width:100%;border-collapse:collapse}td,th{text-align:left;border-bottom:1px solid;padding:6px}#notice{overflow-wrap:anywhere}progress{width:100%}[hidden]{display:none!important}@media(max-width:600px){.grid{grid-template-columns:1fr}body{padding:12px}}
    </style>
    <script src="/js/training-client.js" defer></script>
</head>
<body>
<main>
    <h1>Ninja Lost — Training</h1>
    <p>Client latihan fungsional. Lawan adalah mirror dari snapshot karakter dengan aksi weapon otomatis. Bukan misi, PvP live, client native, atau desain final. Tidak ada hadiah.</p>
    <p id="notice" role="status" aria-live="polite">Memuat sesi…</p>
    <form id="login-form" hidden>
        <fieldset><legend>Login pemain evaluasi</legend>
            <label>Username <input id="username" name="username" autocomplete="username" required maxlength="100"></label>
            <label>Password <input id="password" type="password" name="password" autocomplete="current-password" required maxlength="255"></label>
            <button type="submit">Masuk</button>
        </fieldset>
    </form>
    <section id="session" hidden>
        <p><button id="refresh" type="button">Muat ulang state</button> <button id="logout" type="button">Keluar</button></p>
        <button id="retry" type="button" hidden>Ulangi permintaan yang sama</button>
        <fieldset id="configuration"><legend>Karakter dan loadout</legend>
            <label>Karakter <select id="character"></select></label>
            <div id="gear" class="grid"></div>
            <label>Skill terpasang (maksimal delapan) <select id="skills" multiple size="5"></select></label>
            <p id="loadout-note"></p>
            <button id="save" type="button">Simpan loadout</button>
            <button id="start" type="button">Mulai latihan</button>
        </fieldset>
        <section id="battle" hidden>
            <h2>Battle latihan</h2>
            <p id="run-status"></p>
            <div class="grid">
                <section><h3>Karakter Anda</h3><p id="player-hp"></p><progress id="player-bar"></progress><p id="player-cp"></p><p id="player-effects"></p></section>
                <section><h3>Mirror latihan</h3><p id="mirror-hp"></p><progress id="mirror-bar"></progress><p id="mirror-cp"></p><p id="mirror-effects"></p></section>
            </div>
            <table><thead><tr><th>Stat awal equipment</th><th>Nilai</th></tr></thead><tbody id="stats"></tbody></table>
            <p id="sets"></p>
            <fieldset id="actions"><legend>Aksi giliran Anda</legend>
                <button type="button" data-action="weapon">Weapon</button>
                <button type="button" data-action="charge">Charge</button>
                <button type="button" data-action="dodge">Dodge</button>
                <button type="button" data-action="run">Run / akhiri latihan</button>
                <div id="skill-actions"></div>
            </fieldset>
            <p>Loadout dan versi mekanik dibekukan saat mulai. Perubahan equipment di luar battle hanya berlaku untuk latihan berikutnya. Giliran menunggu input; sesi kedaluwarsa setelah 30 menit.</p>
        </section>
    </section>
</main>
</body>
</html>
