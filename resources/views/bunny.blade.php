<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Bunny sandbox</title>
    <script src="https://cdn.jsdelivr.net/npm/tus-js-client@4.1.0/dist/tus.min.js"></script>
    <style>
        :root { --bg:#f6f7f9; --card:#fff; --ink:#1c2430; --mute:#667085; --line:#e4e7ec; --accent:#d9480f; --ok:#2b8a3e; --bad:#c92a2a; }
        * { box-sizing: border-box; }
        body { margin:0; font:14px/1.45 system-ui, sans-serif; background:var(--bg); color:var(--ink); }
        main { max-width:980px; margin:0 auto; padding:24px 16px 64px; }
        h1 { font-size:20px; margin:0 0 4px; }
        p.sub { color:var(--mute); margin:0 0 20px; }
        section { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:16px; margin-bottom:16px; }
        h2 { font-size:15px; margin:0 0 12px; }
        label { display:block; font-size:12px; color:var(--mute); margin:8px 0 4px; }
        input[type=text], select { width:100%; padding:8px; border:1px solid var(--line); border-radius:6px; font:inherit; }
        input[type=file] { width:100%; }
        button { padding:7px 12px; border:1px solid var(--line); background:#fff; border-radius:6px; cursor:pointer; font:inherit; }
        button.primary { background:var(--accent); color:#fff; border-color:var(--accent); }
        button:disabled { opacity:.5; cursor:default; }
        .row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .grow { flex:1; min-width:180px; }
        .tag { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; background:#eef0f3; }
        .tag.ok { background:#e6f4ea; color:var(--ok); } .tag.bad { background:#fdecea; color:var(--bad); }
        table { width:100%; border-collapse:collapse; }
        th, td { text-align:left; padding:8px 6px; border-bottom:1px solid var(--line); vertical-align:middle; }
        th { font-size:12px; color:var(--mute); font-weight:500; }
        img.thumb { width:96px; height:54px; object-fit:cover; border-radius:4px; background:#eef0f3; display:block; }
        .bar { height:8px; background:#eef0f3; border-radius:4px; overflow:hidden; margin-top:8px; }
        .bar > i { display:block; height:100%; width:0; background:var(--accent); transition:width .2s; }
        pre { background:#0f172a; color:#e2e8f0; padding:10px; border-radius:6px; overflow:auto; font-size:12px; margin:8px 0 0; max-height:200px; }
        iframe { width:100%; aspect-ratio:16/9; border:0; border-radius:8px; background:#000; }
        .mute { color:var(--mute); }
    </style>
</head>
<body>
<main>
    <h1>Bunny Stream: sandbox</h1>
    <p class="sub">Testa envio direto (TUS), conversão, miniatura, link assinado, webhook e exclusão.</p>

    <section>
        <h2>1. Configuração (.env)</h2>
        <div id="config" class="row"></div>
    </section>

    <section>
        <h2>2. Enviar vídeo</h2>
        <div class="row">
            <div class="grow"><label for="title">Título</label><input type="text" id="title" placeholder="Aula de teste"></div>
            <div class="grow"><label for="collection">Coleção (opcional)</label><select id="collection"><option value="">sem coleção</option></select></div>
        </div>
        <label for="file">Arquivo</label>
        <input type="file" id="file" accept="video/*">
        <div class="row" style="margin-top:12px">
            <button class="primary" id="send">Enviar</button>
            <button id="pause" disabled>Pausar</button>
            <button id="resume" disabled>Retomar</button>
            <span id="upload-state" class="mute"></span>
        </div>
        <div class="bar"><i id="progress"></i></div>
        <div class="row" style="margin-top:12px">
            <input type="text" id="new-collection" class="grow" placeholder="Nome de uma nova coleção">
            <button id="create-collection">Criar coleção</button>
        </div>
    </section>

    <section>
        <div class="row" style="justify-content:space-between"><h2>3. Vídeos da biblioteca</h2><button id="reload">Atualizar</button></div>
        <table>
            <thead><tr><th></th><th>Título</th><th>Status</th><th>Duração</th><th>Resoluções</th><th></th></tr></thead>
            <tbody id="videos"><tr><td colspan="6" class="mute">Carregando...</td></tr></tbody>
        </table>
    </section>

    <section>
        <h2>4. Player com link assinado</h2>
        <p class="mute" id="player-info">Clique em "Assistir" em um vídeo pronto.</p>
        <div id="player"></div>
    </section>

    <section>
        <div class="row" style="justify-content:space-between"><h2>5. Eventos do webhook</h2><button id="reload-events">Atualizar</button></div>
        <p class="mute">Só chegam se o Bunny alcançar esta máquina por uma URL pública (ver a aba API da biblioteca).</p>
        <table>
            <thead><tr><th>Recebido</th><th>Vídeo</th><th>Status</th><th>Assinatura</th></tr></thead>
            <tbody id="events"></tbody>
        </table>
    </section>

    <section>
        <h2>Última resposta da API</h2>
        <pre id="log">Nada ainda.</pre>
    </section>
</main>

<script>
const csrf = document.querySelector('meta[name=csrf-token]').content;
const $ = (id) => document.getElementById(id);
let currentUpload = null;
let pollTimer = null;

async function api(path, options = {}) {
    const res = await fetch('/api' + path, {
        ...options,
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, ...(options.headers || {}) },
    });
    const body = await res.json().catch(() => ({}));
    $('log').textContent = options.method === 'DELETE' || options.method === 'POST' || path.includes('/play')
        ? JSON.stringify(redact(body), null, 2) : $('log').textContent;
    if (!res.ok) throw Object.assign(new Error(body.error || res.statusText), { body });
    return body;
}

// Nunca mostra a assinatura de envio inteira na tela de log.
function redact(value) {
    return JSON.parse(JSON.stringify(value, (k, v) => (k === 'AuthorizationSignature' ? v.slice(0, 8) + '…' : v)));
}

function fmtDuration(s) {
    if (s == null) return '—';
    const m = Math.floor(s / 60), r = Math.round(s % 60);
    return m + 'min ' + String(r).padStart(2, '0') + 's';
}

async function loadConfig() {
    const cfg = await api('/config');
    $('config').innerHTML = Object.entries(cfg)
        .map(([k, ok]) => `<span class="tag ${ok ? 'ok' : 'bad'}">${k}: ${ok ? 'preenchido' : 'faltando'}</span>`).join('');
}

async function loadCollections() {
    try {
        const res = await api('/collections');
        const select = $('collection');
        select.innerHTML = '<option value="">sem coleção</option>' +
            (res.items || []).map(c => `<option value="${c.guid}">${c.name}</option>`).join('');
    } catch (e) { /* sem chave ainda */ }
}

async function loadVideos() {
    try {
        const res = await api('/videos');
        const rows = res.items.map(v => `
            <tr>
                <td>${v.thumbnail ? `<img class="thumb" src="${v.thumbnail}" alt="">` : '<span class="thumb"></span>'}</td>
                <td>${v.title ?? ''}<br><span class="mute">${v.guid}</span></td>
                <td><span class="tag ${v.status === 4 ? 'ok' : (v.status >= 5 ? 'bad' : '')}">${v.status_label} (${v.status})${v.encode_progress != null && v.status < 4 ? ' ' + v.encode_progress + '%' : ''}</span></td>
                <td>${fmtDuration(v.length_seconds)}</td>
                <td class="mute">${v.resolutions ?? '—'}</td>
                <td class="row">
                    <button data-play="${v.guid}">Assistir</button>
                    <button data-del="${v.guid}">Excluir</button>
                </td>
            </tr>`).join('');
        $('videos').innerHTML = rows || '<tr><td colspan="6" class="mute">Nenhum vídeo ainda.</td></tr>';

        const pending = res.items.some(v => v.status < 4);
        clearTimeout(pollTimer);
        if (pending) pollTimer = setTimeout(loadVideos, 5000);
    } catch (e) {
        $('videos').innerHTML = `<tr><td colspan="6" class="mute">${e.message}</td></tr>`;
    }
}

async function loadEvents() {
    const events = await api('/webhook-events');
    $('events').innerHTML = events.map(e => `
        <tr><td>${e.received_at}</td><td class="mute">${e.video_guid ?? '—'}</td><td>${e.status_label} (${e.status})</td>
        <td><span class="tag ${e.signature_valid ? 'ok' : 'bad'}">${e.signature_valid ? 'válida' : 'inválida'}</span></td></tr>`).join('')
        || '<tr><td colspan="4" class="mute">Nenhum evento recebido.</td></tr>';
}

$('send').onclick = async () => {
    const file = $('file').files[0];
    const title = $('title').value.trim() || (file ? file.name : '');
    if (!file) { $('upload-state').textContent = 'Escolha um arquivo.'; return; }

    $('send').disabled = true;
    $('upload-state').textContent = 'Criando o vídeo no Bunny...';
    $('progress').style.width = '0';

    try {
        const created = await api('/videos', { method: 'POST', body: JSON.stringify({ title, collection_id: $('collection').value || null }) });

        currentUpload = new tus.Upload(file, {
            endpoint: created.upload.endpoint,
            retryDelays: [0, 3000, 5000, 10000, 20000],
            headers: created.upload.headers,
            metadata: { filetype: file.type, title },
            onError: (err) => { $('upload-state').textContent = 'Falhou: ' + err; $('send').disabled = false; $('pause').disabled = true; },
            onProgress: (sent, total) => {
                $('progress').style.width = (sent / total * 100).toFixed(1) + '%';
                $('upload-state').textContent = `Enviando ${(sent / 1048576).toFixed(1)} de ${(total / 1048576).toFixed(1)} MB`;
            },
            onSuccess: () => {
                $('upload-state').textContent = 'Enviado. O Bunny está convertendo.';
                $('send').disabled = false; $('pause').disabled = true; $('resume').disabled = true;
                loadVideos();
            },
        });
        currentUpload.start();
        $('pause').disabled = false;
        loadVideos();
    } catch (e) {
        $('upload-state').textContent = 'Erro: ' + e.message;
        $('send').disabled = false;
    }
};

$('pause').onclick = () => { currentUpload?.abort(); $('pause').disabled = true; $('resume').disabled = false; $('upload-state').textContent = 'Pausado.'; };
$('resume').onclick = () => { currentUpload?.start(); $('pause').disabled = false; $('resume').disabled = true; };

$('create-collection').onclick = async () => {
    const name = $('new-collection').value.trim();
    if (!name) return;
    await api('/collections', { method: 'POST', body: JSON.stringify({ name }) });
    $('new-collection').value = '';
    loadCollections();
};

$('videos').onclick = async (ev) => {
    const play = ev.target.dataset.play, del = ev.target.dataset.del;
    if (play) {
        const res = await api(`/videos/${play}/play`);
        $('player-info').textContent = `Link válido por ${res.expires_in} segundos.`;
        $('player').innerHTML = `<iframe src="${res.url}" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>`;
    }
    if (del && confirm('Excluir este vídeo no Bunny?')) {
        await api(`/videos/${del}`, { method: 'DELETE' });
        loadVideos();
    }
};

$('reload').onclick = loadVideos;
$('reload-events').onclick = loadEvents;

loadConfig(); loadCollections(); loadVideos(); loadEvents();
</script>
</body>
</html>
