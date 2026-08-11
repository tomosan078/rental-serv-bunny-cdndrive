(() => {
  const state = {
    ...window.CDN_DRIVE_STATE,
    folder: null,
    items: { folders: [], files: [] },
    selected: null,
    trash: false,
    query: '',
    theme: localStorage.getItem('cdn-drive-theme') || 'dark'
  };

  const app = document.getElementById('app');
  document.documentElement.classList.toggle('dark', state.theme === 'dark');

  const icons = {
    folder: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H10l2 2h6.5A2.5 2.5 0 0 1 21 9.5v7A2.5 2.5 0 0 1 18.5 19h-13A2.5 2.5 0 0 1 3 16.5z"/></svg>',
    file: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 3v5a2 2 0 0 0 2 2h5"/><path d="M6 3h8l7 7v11H6z"/></svg>',
    upload: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M20 16v3a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-3"/></svg>',
    copy: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="9" y="9" width="11" height="11" rx="2"/><rect x="4" y="4" width="11" height="11" rx="2"/></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 15H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>',
    moon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 14.5A8.5 8.5 0 0 1 9.5 3 9 9 0 1 0 21 14.5z"/></svg>',
    sun: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>',
    settings: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 15.5A3.5 3.5 0 1 0 12 8a3.5 3.5 0 0 0 0 7.5z"/><path d="M19.4 15a1.8 1.8 0 0 0 .36 2l.05.05a2 2 0 0 1-2.83 2.83l-.05-.05a1.8 1.8 0 0 0-2-.36 1.8 1.8 0 0 0-1.1 1.65V21a2 2 0 0 1-4 0v-.08a1.8 1.8 0 0 0-1.1-1.65 1.8 1.8 0 0 0-2 .36l-.05.05a2 2 0 1 1-2.83-2.83l.05-.05a1.8 1.8 0 0 0 .36-2 1.8 1.8 0 0 0-1.65-1.1H3a2 2 0 0 1 0-4h.08a1.8 1.8 0 0 0 1.65-1.1 1.8 1.8 0 0 0-.36-2l-.05-.05a2 2 0 1 1 2.83-2.83l.05.05a1.8 1.8 0 0 0 2 .36A1.8 1.8 0 0 0 10.3 3V3a2 2 0 0 1 4 0v.08a1.8 1.8 0 0 0 1.1 1.65 1.8 1.8 0 0 0 2-.36l.05-.05a2 2 0 1 1 2.83 2.83l-.05.05a1.8 1.8 0 0 0-.36 2 1.8 1.8 0 0 0 1.65 1.1H21a2 2 0 0 1 0 4h-.08A1.8 1.8 0 0 0 19.4 15z"/></svg>'
  };

  function h(strings, ...values) {
    return strings.map((s, i) => s + (values[i] ?? '')).join('');
  }

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));
  }

  function route(path) {
    if (/^(https?:|data:|blob:)/.test(String(path))) return path;
    const base = state.baseUrl || '/';
    return base.replace(/\/+$/, '/') + String(path).replace(/^\/+/, '');
  }

  async function api(path, options = {}) {
    const headers = options.headers || {};
    headers['X-CSRF-Token'] = state.csrf;
    if (options.json) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.json);
    }
    const res = await fetch(route(path), { ...options, headers });
    const data = await res.json().catch(() => ({ ok: false, error: '通信レスポンスを解析できません。' }));
    if (!res.ok || !data.ok) throw new Error(data.error || '処理に失敗しました。');
    return data;
  }

  async function copyText(text) {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      await navigator.clipboard.writeText(text);
      return true;
    }
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    textarea.style.top = '0';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    let copied = false;
    try {
      copied = document.execCommand('copy');
    } finally {
      textarea.remove();
    }
    if (!copied) {
      throw new Error('コピーできませんでした。URLを手動で選択してコピーしてください。');
    }
    return true;
  }

  function button(label, icon, attrs = '') {
    return `<button ${attrs} class="inline-flex h-10 items-center gap-2 rounded-lg border border-slate-300/60 bg-white/80 px-3 text-sm font-medium text-slate-700 shadow-sm hover:bg-white dark:border-white/10 dark:bg-white/10 dark:text-slate-100 dark:hover:bg-white/15"><span class="h-4 w-4">${icon || ''}</span><span>${label}</span></button>`;
  }

  function iconButton(label, icon, attrs = '') {
    return `<button ${attrs} title="${esc(label)}" class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-300/60 bg-white/80 text-slate-700 shadow-sm hover:bg-white dark:border-white/10 dark:bg-white/10 dark:text-slate-100 dark:hover:bg-white/15"><span class="h-4 w-4">${icon}</span></button>`;
  }

  function render() {
    app.innerHTML = h`
      <div class="min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <div class="fixed inset-0 pointer-events-none bg-[radial-gradient(circle_at_20%_0%,rgba(34,211,238,.18),transparent_34%),radial-gradient(circle_at_90%_10%,rgba(16,185,129,.14),transparent_28%)]"></div>
        <div class="relative grid min-h-screen lg:grid-cols-[260px_1fr]">
          <aside class="hidden border-r border-slate-200/80 bg-white/70 p-4 backdrop-blur-xl dark:border-white/10 dark:bg-slate-950/70 lg:block">
            <div class="flex items-center gap-3">
              <div class="grid h-10 w-10 place-items-center rounded-xl bg-cyan-400 font-bold text-slate-950">CD</div>
              <div><div class="font-semibold">CDN Drive</div><div class="text-xs text-slate-500 dark:text-slate-400">${esc(state.user.email)}</div></div>
            </div>
            <nav class="mt-8 grid gap-2">
              <button data-nav="files" class="rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-200/70 dark:hover:bg-white/10 ${!state.trash ? 'bg-cyan-400/20 text-cyan-700 dark:text-cyan-200' : ''}">ファイル</button>
              <button data-nav="trash" class="rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-200/70 dark:hover:bg-white/10 ${state.trash ? 'bg-cyan-400/20 text-cyan-700 dark:text-cyan-200' : ''}">ゴミ箱</button>
              ${state.user.role === 'admin' ? '<button data-nav="admin" class="rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-200/70 dark:hover:bg-white/10">設定と監査</button>' : ''}
            </nav>
            <div class="mt-8 rounded-xl border border-slate-200 bg-white/70 p-4 dark:border-white/10 dark:bg-white/5">
              <div class="mb-2 flex justify-between text-xs text-slate-500 dark:text-slate-400"><span>Storage</span><span id="usageText"></span></div>
              <div class="h-2 rounded-full bg-slate-200 dark:bg-slate-800"><div id="usageBar" class="h-2 rounded-full bg-cyan-400"></div></div>
            </div>
          </aside>
          <main class="p-3 sm:p-5">
            <header class="glass sticky top-3 z-10 rounded-xl p-3">
              <div class="flex flex-wrap items-center gap-3">
                <button class="lg:hidden rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-white/10" data-nav="files">CDN Drive</button>
                <div class="min-w-0 flex-1">
                  <div class="text-xs text-slate-500 dark:text-slate-400">${state.trash ? 'Trash' : 'Files'}</div>
                  <h1 class="truncate text-xl font-semibold">${state.folder ? esc(state.folder.name) : state.trash ? 'ゴミ箱' : 'マイドライブ'}</h1>
                </div>
                <input id="search" value="${esc(state.query)}" placeholder="検索" class="h-10 w-full rounded-lg border border-slate-300 bg-white/80 px-3 text-sm outline-none focus:border-cyan-400 dark:border-white/10 dark:bg-slate-900 sm:w-64">
                ${iconButton(state.theme === 'dark' ? 'ライトモード' : 'ダークモード', state.theme === 'dark' ? icons.sun : icons.moon, 'data-action="theme"')}
                <a href="${route('logout')}" class="inline-flex h-10 items-center rounded-lg border border-slate-300/60 bg-white/80 px-3 text-sm font-medium text-slate-700 shadow-sm hover:bg-white dark:border-white/10 dark:bg-white/10 dark:text-slate-100">ログアウト</a>
              </div>
              <div class="mt-3 flex flex-wrap items-center gap-2">
                ${!state.trash ? button('アップロード', icons.upload, 'data-action="pick"') : ''}
                ${!state.trash ? button('新規フォルダ', icons.folder, 'data-action="folder"') : ''}
                ${state.folder && !state.trash ? '<button data-action="up" class="h-10 rounded-lg px-3 text-sm hover:bg-slate-200 dark:hover:bg-white/10">上へ</button>' : ''}
                <input id="fileInput" type="file" multiple class="hidden">
              </div>
            </header>
            <section id="dropZone" class="mt-4 rounded-xl border border-slate-200 bg-white/70 p-2 shadow-sm backdrop-blur dark:border-white/10 dark:bg-white/5">
              <div id="progress" class="hidden border-b border-slate-200 p-3 dark:border-white/10"></div>
              <div class="overflow-hidden rounded-lg">
                <div class="grid grid-cols-[1fr_112px_148px] gap-3 border-b border-slate-200 px-3 py-2 text-xs font-medium uppercase text-slate-500 dark:border-white/10 dark:text-slate-400">
                  <span>Name</span><span>Size</span><span>Actions</span>
                </div>
                <div id="list"></div>
              </div>
            </section>
          </main>
        </div>
      </div>
      <div id="modalRoot"></div>
      <div id="toastRoot" class="fixed bottom-4 right-4 z-50 grid gap-2"></div>`;
    bind();
    renderList();
    updateUsage(state.usage || { bytes: 0, limit: null });
  }

  function renderList() {
    const list = document.getElementById('list');
    const rows = [];
    if (!state.trash) {
      state.items.folders.forEach(folder => rows.push(rowHtml('folder', folder)));
    }
    state.items.files.forEach(file => rows.push(rowHtml('file', file)));
    list.innerHTML = rows.length ? rows.join('') : `<div class="p-10 text-center text-sm text-slate-500 dark:text-slate-400">ここにファイルをドラッグ＆ドロップできます。</div>`;
    list.querySelectorAll('[data-open-folder]').forEach(el => el.addEventListener('click', () => openFolder(Number(el.dataset.openFolder))));
    list.querySelectorAll('[data-preview]').forEach(el => el.addEventListener('click', () => previewFile(Number(el.dataset.preview))));
    list.querySelectorAll('[data-op]').forEach(el => el.addEventListener('click', () => operate(el.dataset.op, el.dataset.type, Number(el.dataset.id))));
  }

  function rowHtml(type, item) {
    const isFile = type === 'file';
    const open = isFile ? `data-preview="${item.id}"` : `data-open-folder="${item.id}"`;
    const size = isFile ? formatBytes(item.size) : '';
    const actions = isFile ? fileActions(item) : folderActions(item);
    return `<div class="file-row grid grid-cols-[1fr_112px_148px] items-center gap-3 border-b border-slate-200 px-3 py-2 last:border-b-0 dark:border-white/10">
      <button ${open} class="flex min-w-0 items-center gap-3 text-left">
        <span class="grid h-10 w-10 flex-none place-items-center rounded-lg ${isFile ? 'bg-emerald-400/15 text-emerald-500' : 'bg-cyan-400/15 text-cyan-500'}"><span class="h-5 w-5">${isFile ? icons.file : icons.folder}</span></span>
        <span class="min-w-0"><span class="block truncate font-medium">${esc(item.name)}</span><span class="block truncate text-xs text-slate-500 dark:text-slate-400">${isFile ? esc(item.mime) : 'Folder'}</span></span>
      </button>
      <div class="text-sm text-slate-500 dark:text-slate-400">${size}</div>
      <div class="flex items-center justify-end gap-1">${actions}</div>
    </div>`;
  }

  function fileActions(file) {
    if (state.trash) {
      return `${iconButton('復元', icons.copy, `data-op="restore" data-type="file" data-id="${file.id}"`)}${iconButton('完全削除', icons.trash, `data-op="purge" data-type="file" data-id="${file.id}"`)}`;
    }
    return `${iconButton('URLコピー', icons.copy, `data-op="copyUrl" data-type="file" data-id="${file.id}"`)}${iconButton('コピー', icons.file, `data-op="copy" data-type="file" data-id="${file.id}"`)}${iconButton('移動', icons.folder, `data-op="move" data-type="file" data-id="${file.id}"`)}${iconButton('名前変更', icons.settings, `data-op="rename" data-type="file" data-id="${file.id}"`)}${iconButton('共有', icons.file, `data-op="share" data-type="file" data-id="${file.id}"`)}${iconButton('削除', icons.trash, `data-op="delete" data-type="file" data-id="${file.id}"`)}`;
  }

  function folderActions(folder) {
    return `${iconButton('名前変更', icons.settings, `data-op="rename" data-type="folder" data-id="${folder.id}"`)}${iconButton('移動', icons.folder, `data-op="move" data-type="folder" data-id="${folder.id}"`)}${iconButton('削除', icons.trash, `data-op="delete" data-type="folder" data-id="${folder.id}"`)}`;
  }

  function bind() {
    document.querySelectorAll('[data-nav]').forEach(el => el.addEventListener('click', async () => {
      if (el.dataset.nav === 'trash') { state.trash = true; state.folder = null; await load(); }
      else if (el.dataset.nav === 'admin') showAdmin();
      else { state.trash = false; await load(); }
    }));
    document.querySelector('[data-action="theme"]').addEventListener('click', () => {
      state.theme = state.theme === 'dark' ? 'light' : 'dark';
      localStorage.setItem('cdn-drive-theme', state.theme);
      document.documentElement.classList.toggle('dark', state.theme === 'dark');
      render();
    });
    const input = document.getElementById('fileInput');
    document.querySelector('[data-action="pick"]')?.addEventListener('click', () => input.click());
    input.addEventListener('change', () => upload(input.files));
    document.querySelector('[data-action="folder"]')?.addEventListener('click', createFolder);
    document.querySelector('[data-action="up"]')?.addEventListener('click', () => { state.folder = null; load(); });
    const search = document.getElementById('search');
    let timer;
    search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { state.query = search.value; load(); }, 250); });
    const drop = document.getElementById('dropZone');
    ['dragenter', 'dragover'].forEach(evt => drop.addEventListener(evt, e => { e.preventDefault(); drop.classList.add('drop-active'); }));
    ['dragleave', 'drop'].forEach(evt => drop.addEventListener(evt, e => { e.preventDefault(); drop.classList.remove('drop-active'); }));
    drop.addEventListener('drop', e => upload(e.dataTransfer.files));
  }

  async function load() {
    const params = new URLSearchParams();
    if (state.folder && !state.trash) params.set('folder', state.folder.id);
    if (state.query) params.set('q', state.query);
    if (state.trash) params.set('trash', '1');
    const data = await api(`api/items?${params}`);
    state.items = { folders: data.folders, files: data.files };
    state.folder = data.folder || state.folder;
    state.usage = data.usage;
    render();
  }

  async function openFolder(id) {
    state.folder = state.items.folders.find(f => f.id === id) || { id, name: 'Folder' };
    await load();
  }

  async function upload(files) {
    if (!files || !files.length) return;
    const progress = document.getElementById('progress');
    progress.classList.remove('hidden');
    progress.innerHTML = `<div class="text-sm font-medium">アップロード中...</div><div class="mt-2 h-2 rounded-full bg-slate-200 dark:bg-slate-800"><div id="progressBar" class="h-2 w-0 rounded-full bg-cyan-400"></div></div>`;
    const form = new FormData();
    [...files].forEach(file => form.append('files[]', file));
    if (state.folder) form.append('folder_id', state.folder.id);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', route('api/upload'));
    xhr.setRequestHeader('X-CSRF-Token', state.csrf);
    xhr.upload.onprogress = e => {
      if (e.lengthComputable) document.getElementById('progressBar').style.width = `${Math.round(e.loaded / e.total * 100)}%`;
    };
    xhr.onload = async () => {
      const data = JSON.parse(xhr.responseText || '{}');
      if (xhr.status >= 200 && xhr.status < 300 && data.ok) {
        toast('アップロードしました。');
        await load();
      } else {
        toast(data.error || 'アップロードに失敗しました。', true);
      }
      progress.classList.add('hidden');
    };
    xhr.onerror = () => { toast('通信に失敗しました。', true); progress.classList.add('hidden'); };
    xhr.send(form);
  }

  async function createFolder() {
    const name = await promptModal('新規フォルダ', 'フォルダ名');
    if (!name) return;
    await api('api/folders', { method: 'POST', json: { name, parent_id: state.folder?.id || null } });
    toast('フォルダを作成しました。');
    await load();
  }

  async function operate(op, type, id) {
    const item = type === 'file' ? state.items.files.find(x => x.id === id) : state.items.folders.find(x => x.id === id);
    if (!item && op !== 'restore') return;
    try {
      if (op === 'copyUrl') {
        await copyText(item.url);
        toast('CDN URL をコピーしました。');
      } else if (op === 'rename') {
        const name = await promptModal('名前変更', '新しい名前', item.name);
        if (name) { await api('api/rename', { method: 'POST', json: { type, id, name } }); await load(); }
      } else if (op === 'delete') {
        if (await confirmModal('削除しますか？')) { await api('api/delete', { method: 'POST', json: { type, id } }); await load(); }
      } else if (op === 'purge') {
        if (await confirmModal('完全に削除しますか？')) { await api('api/delete', { method: 'POST', json: { type, id } }); await load(); }
      } else if (op === 'restore') {
        await api('api/restore', { method: 'POST', json: { id } }); await load();
      } else if (op === 'share') {
        showShare(item);
      } else if (op === 'copy') {
        const folderId = await chooseFolder('コピー先フォルダ');
        if (folderId === undefined) return;
        await api('api/copy', { method: 'POST', json: { id, folder_id: folderId } });
        await load();
      } else if (op === 'move') {
        const folderId = await chooseFolder('移動先フォルダ', type === 'folder' ? id : null);
        if (folderId === undefined) return;
        await api('api/move', { method: 'POST', json: { type, id, folder_id: folderId } });
        await load();
      }
    } catch (e) {
      toast(e.message, true);
    }
  }

  function previewFile(id) {
    const file = state.items.files.find(f => f.id === id);
    if (!file) return;
    const mediaUrl = file.preview_url || file.url;
    const previewSourceLabel = mediaUrl === file.url ? 'CDN' : 'Origin';
    const preview = file.mime.startsWith('image/') ? `<img src="${esc(mediaUrl)}" alt="${esc(file.name)}" class="max-h-[60vh] w-full rounded-lg object-contain" data-preview-media><div data-preview-error class="hidden rounded-lg bg-red-50 p-4 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-200">${previewSourceLabel} URL から画像を読み込めません。Origin URL、保存パス、ファイル権限を確認してください。</div>`
      : file.mime.startsWith('video/') ? `<video src="${esc(mediaUrl)}" controls class="max-h-[60vh] w-full rounded-lg"></video>`
      : `<div class="rounded-lg bg-slate-100 p-10 text-center dark:bg-slate-900">ブラウザ表示またはダウンロードを利用してください。</div>`;
    const sourceButton = mediaUrl === file.url ? '' : `<a target="_blank" rel="noopener" class="rounded-lg border border-slate-300 px-3 py-2 dark:border-white/10" href="${esc(mediaUrl)}">Origin確認</a>`;
    modal(`<h2 class="text-lg font-semibold">${esc(file.name)}</h2><div class="mt-4">${preview}</div><div class="mt-4 grid gap-2 rounded-lg bg-slate-100 p-3 text-sm dark:bg-slate-900"><div class="text-xs font-medium text-slate-500 dark:text-slate-400">CDN URL</div><div class="break-all">${esc(file.url)}</div>${mediaUrl === file.url ? '' : `<div class="text-xs font-medium text-slate-500 dark:text-slate-400">Origin Preview URL</div><div class="break-all">${esc(mediaUrl)}</div>`}<div class="flex flex-wrap gap-2"><a target="_blank" rel="noopener" class="rounded-lg bg-cyan-400 px-3 py-2 font-semibold text-slate-950" href="${esc(file.url)}">CDNを開く</a>${sourceButton}<a download class="rounded-lg border border-slate-300 px-3 py-2 dark:border-white/10" href="${esc(mediaUrl)}">ダウンロード</a><button class="rounded-lg border border-slate-300 px-3 py-2 dark:border-white/10" data-copy-current>CDN URLコピー</button><button class="rounded-lg border border-slate-300 px-3 py-2 dark:border-white/10" data-download-qr>QR保存</button></div><div id="qrBox" class="h-40 w-40 rounded-lg bg-white p-2"></div></div>`);
    document.querySelector('[data-copy-current]').addEventListener('click', async () => {
      try {
        await copyText(file.url);
        toast('コピーしました。');
      } catch (e) {
        toast(e.message, true);
      }
    });
    document.querySelectorAll('[data-preview-media]').forEach(media => {
      media.addEventListener('error', () => {
        media.classList.add('hidden');
        document.querySelector('[data-preview-error]')?.classList.remove('hidden');
      });
    });
    if (window.QRCode) {
      const box = document.getElementById('qrBox');
      new QRCode(box, { text: file.url, width: 144, height: 144, correctLevel: QRCode.CorrectLevel.M });
      document.querySelector('[data-download-qr]').addEventListener('click', () => {
        const img = box.querySelector('img') || box.querySelector('canvas');
        const href = img.tagName === 'IMG' ? img.src : img.toDataURL('image/png');
        const a = document.createElement('a');
        a.href = href;
        a.download = `${file.name}.qr.png`;
        a.click();
      });
    } else {
      document.getElementById('qrBox').textContent = 'QR ライブラリを読み込めませんでした。';
    }
  }

  function showShare(file) {
    modal(`<h2 class="text-lg font-semibold">共有リンク</h2><div class="mt-4 grid gap-3"><label class="text-sm">有効日数<input id="shareDays" type="number" min="1" max="365" value="7" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900"></label><label class="text-sm">権限<select id="sharePerm" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900"><option value="view">表示</option><option value="download">ダウンロード</option></select></label><label class="text-sm">パスワード<input id="sharePassword" type="password" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900"></label><button id="createShare" class="rounded-lg bg-cyan-400 px-4 py-2 font-semibold text-slate-950">作成</button><div id="shareResult"></div></div>`);
    document.getElementById('createShare').addEventListener('click', async () => {
      try {
        const data = await api('api/share', { method: 'POST', json: { file_id: file.id, days: document.getElementById('shareDays').value, permission: document.getElementById('sharePerm').value, password: document.getElementById('sharePassword').value } });
        document.getElementById('shareResult').innerHTML = `<div class="grid gap-2 rounded-lg bg-slate-100 p-3 text-sm dark:bg-slate-900"><div class="break-all">${esc(data.share.url)}</div><div class="flex flex-wrap gap-2"><button class="rounded-lg border border-slate-300 px-3 py-2 dark:border-white/10" data-share-copy>コピー</button><a target="_blank" rel="noopener" class="rounded-lg bg-cyan-400 px-3 py-2 font-semibold text-slate-950" href="${esc(data.share.url)}">開く</a></div></div>`;
        document.querySelector('[data-share-copy]').addEventListener('click', async () => {
          try {
            await copyText(data.share.url);
            toast('共有リンクをコピーしました。');
          } catch (copyError) {
            toast(copyError.message, true);
          }
        });
        try {
          await copyText(data.share.url);
          toast('共有リンクをコピーしました。');
        } catch {
          toast('共有リンクを作成しました。表示されたURLをコピーできます。');
        }
      } catch (e) { toast(e.message, true); }
    });
  }

  async function showAdmin() {
    try {
      const users = await api('api/users');
      const logs = await api('api/logs');
      const s = state.settings;
      modal(`<h2 class="text-lg font-semibold">設定と監査</h2>
        <div class="mt-4 grid gap-5 lg:grid-cols-2">
          <form id="settingsForm" class="grid gap-3">
            <input name="cdn_hostname" value="${esc(s.cdn_hostname)}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900" placeholder="CDN Hostname">
            <input name="origin_url" value="${esc(s.origin_url)}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900" placeholder="Origin URL">
            <input name="pull_zone_id" value="${esc(s.pull_zone_id)}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900" placeholder="Pull Zone ID">
            <input name="bunny_api_key" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900" placeholder="${s.bunny_api_key_set ? 'API Key 設定済み' : 'Bunny API Key'}">
            <div class="grid gap-2 rounded-lg border border-slate-200 p-3 dark:border-white/10">
              <div class="text-xs font-medium text-slate-500 dark:text-slate-400">WordPress連携</div>
              <input name="wordpress_api_token" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900" placeholder="${s.wordpress_api_token_set ? 'API Token 設定済み' : 'WordPress API Token'}">
              <div class="break-all text-xs text-slate-500 dark:text-slate-400">Endpoint: ${esc(s.wordpress_api_base || '')}</div>
              <button type="button" id="generateWpToken" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-white/10">Token生成</button>
            </div>
            <input name="max_upload_mb" value="${esc(s.max_upload_mb)}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900" placeholder="最大アップロードMB">
            <select name="preview_source" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900"><option value="cdn" ${s.preview_source === 'cdn' ? 'selected' : ''}>プレビュー元: CDN</option><option value="origin" ${s.preview_source === 'origin' ? 'selected' : ''}>プレビュー元: Origin</option></select>
            <label class="flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-white/10"><input type="checkbox" name="sync_bunny" value="1">保存時に Bunny Pull Zone へ同期する</label>
            <div class="flex flex-wrap gap-2"><button class="rounded-lg bg-cyan-400 px-4 py-2 font-semibold text-slate-950">保存</button><button type="button" id="bunnyTest" class="rounded-lg border border-slate-300 px-4 py-2 dark:border-white/10">接続確認</button><button type="button" id="purge" class="rounded-lg border border-slate-300 px-4 py-2 dark:border-white/10">キャッシュ削除</button><button type="button" id="repairPaths" class="rounded-lg border border-slate-300 px-4 py-2 dark:border-white/10">保存パス修復</button></div>
          </form>
          <div class="grid gap-3">
            <form id="userForm" class="grid gap-2 rounded-lg border border-slate-200 p-3 dark:border-white/10">
              <input name="name" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900" placeholder="ユーザー名">
              <input name="email" type="email" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900" placeholder="メールアドレス">
              <input name="password" type="password" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900" placeholder="初期パスワード">
              <select name="role" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900"><option value="user">user</option><option value="admin">admin</option></select>
              <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="active" value="1" checked>有効</label>
              <input name="storage_limit_gb" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900" placeholder="容量上限GB">
              <button class="rounded-lg bg-cyan-400 px-4 py-2 font-semibold text-slate-950">ユーザー作成</button>
            </form>
            ${users.users.map(u => `<div class="rounded-lg border border-slate-200 p-3 text-sm dark:border-white/10"><b>${esc(u.name)}</b><div>${esc(u.email)} / ${esc(u.role)} / ${u.active ? 'active' : 'inactive'}</div></div>`).join('')}
          </div>
        </div>
        <div class="mt-5 max-h-72 overflow-auto rounded-lg border border-slate-200 dark:border-white/10">${logs.logs.map(l => `<div class="border-b border-slate-200 p-2 text-xs last:border-0 dark:border-white/10"><b>${esc(l.action)}</b> ${esc(l.email || '')}<span class="text-slate-500"> ${esc(l.created_at)}</span></div>`).join('')}</div>`, 'max-w-5xl');
      document.getElementById('settingsForm').addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const json = Object.fromEntries(fd.entries());
        json.sync_bunny = fd.has('sync_bunny');
        const data = await api('api/settings', { method: 'POST', json });
        state.settings = data.settings;
        toast(data.bunny_synced ? '設定を保存し、Bunnyへ同期しました。' : '設定を保存しました。');
      });
      document.getElementById('generateWpToken').addEventListener('click', async () => {
        try {
          const data = await api('api/settings/wordpress-token', { method: 'POST', json: {} });
          state.settings = data.settings;
          document.querySelector('[name="wordpress_api_token"]').value = data.token;
          copyText(data.token).then(() => toast('Tokenを生成して保存し、コピーしました。')).catch(() => toast('Tokenを生成して保存しました。'));
        } catch (e) {
          toast(e.message, true);
        }
        return;
        const bytes = new Uint8Array(32);
        if (window.crypto && crypto.getRandomValues) {
          crypto.getRandomValues(bytes);
        } else {
          for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
        }
        const token = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
        document.querySelector('[name="wordpress_api_token"]').value = token;
        copyText(token).then(() => toast('Tokenを生成してコピーしました。')).catch(() => toast('Tokenを生成しました。'));
      });
      document.getElementById('userForm').addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const json = Object.fromEntries(fd.entries());
        json.active = fd.has('active');
        await api('api/users', { method: 'POST', json });
        toast('ユーザーを作成しました。');
        document.getElementById('modalRoot').innerHTML = '';
        showAdmin();
      });
      document.getElementById('bunnyTest').addEventListener('click', async () => {
        const data = await api('api/bunny/test', { method: 'POST', json: {} });
        toast(`Bunny 接続 OK: HTTP ${data.status}`);
      });
      document.getElementById('purge').addEventListener('click', async () => {
        if (await confirmModal('Pull Zone のキャッシュを削除しますか？')) {
          const data = await api('api/bunny/purge', { method: 'POST', json: {} });
          toast(`キャッシュ削除要求を送信しました: HTTP ${data.status}`);
        }
      });
      document.getElementById('repairPaths').addEventListener('click', async () => {
        if (await confirmModal('既存ファイルの保存パスを修復しますか？')) {
          const data = await api('api/maintenance/repair-paths', { method: 'POST', json: {} });
          toast(`保存パス修復: ${data.repaired} 件、スキップ ${data.skipped} 件`);
          await load();
        }
      });
    } catch (e) { toast(e.message, true); }
  }

  function modal(content, width = 'max-w-xl') {
    const root = document.getElementById('modalRoot');
    root.innerHTML = `<div class="fixed inset-0 z-40 grid place-items-center bg-slate-950/60 p-4 backdrop-blur-sm"><div class="${width} max-h-[90vh] w-full overflow-auto rounded-xl bg-white p-5 text-slate-900 shadow-2xl dark:bg-slate-950 dark:text-slate-100"><div class="flex justify-end"><button data-close class="rounded-lg px-2 py-1 text-sm hover:bg-slate-100 dark:hover:bg-white/10">閉じる</button></div>${content}</div></div>`;
    root.querySelector('[data-close]').addEventListener('click', () => root.innerHTML = '');
  }

  function chooseFolder(title, excludeId = null) {
    return new Promise(resolve => {
      const options = [`<option value="">マイドライブ直下</option>`]
        .concat(state.items.folders.filter(f => f.id !== excludeId).map(f => `<option value="${f.id}">${esc(f.name)}</option>`))
        .join('');
      modal(`<h2 class="text-lg font-semibold">${esc(title)}</h2><label class="mt-4 block text-sm">フォルダ<select id="folderChoice" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900">${options}</select></label><div class="mt-4 flex justify-end gap-2"><button id="folderCancel" class="rounded-lg border border-slate-300 px-4 py-2 dark:border-white/10">キャンセル</button><button id="folderOk" class="rounded-lg bg-cyan-400 px-4 py-2 font-semibold text-slate-950">OK</button></div>`);
      document.getElementById('folderCancel').onclick = () => { document.getElementById('modalRoot').innerHTML = ''; resolve(undefined); };
      document.getElementById('folderOk').onclick = () => {
        const value = document.getElementById('folderChoice').value;
        document.getElementById('modalRoot').innerHTML = '';
        resolve(value ? Number(value) : null);
      };
    });
  }

  function promptModal(title, label, value = '') {
    return new Promise(resolve => {
      modal(`<h2 class="text-lg font-semibold">${esc(title)}</h2><label class="mt-4 block text-sm">${esc(label)}<input id="modalInput" value="${esc(value)}" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900"></label><div class="mt-4 flex justify-end gap-2"><button id="modalCancel" class="rounded-lg border border-slate-300 px-4 py-2 dark:border-white/10">キャンセル</button><button id="modalOk" class="rounded-lg bg-cyan-400 px-4 py-2 font-semibold text-slate-950">OK</button></div>`);
      document.getElementById('modalInput').focus();
      document.getElementById('modalCancel').onclick = () => { document.getElementById('modalRoot').innerHTML = ''; resolve(null); };
      document.getElementById('modalOk').onclick = () => { const v = document.getElementById('modalInput').value.trim(); document.getElementById('modalRoot').innerHTML = ''; resolve(v); };
    });
  }

  function confirmModal(message) {
    return new Promise(resolve => {
      modal(`<h2 class="text-lg font-semibold">${esc(message)}</h2><div class="mt-4 flex justify-end gap-2"><button id="no" class="rounded-lg border border-slate-300 px-4 py-2 dark:border-white/10">キャンセル</button><button id="yes" class="rounded-lg bg-red-500 px-4 py-2 font-semibold text-white">実行</button></div>`);
      document.getElementById('no').onclick = () => { document.getElementById('modalRoot').innerHTML = ''; resolve(false); };
      document.getElementById('yes').onclick = () => { document.getElementById('modalRoot').innerHTML = ''; resolve(true); };
    });
  }

  function toast(message, error = false) {
    const root = document.getElementById('toastRoot');
    const el = document.createElement('div');
    el.className = `toast rounded-lg px-4 py-3 text-sm shadow-lg ${error ? 'bg-red-600 text-white' : 'bg-slate-900 text-white dark:bg-white dark:text-slate-950'}`;
    el.textContent = message;
    root.appendChild(el);
    setTimeout(() => el.remove(), 3600);
  }

  function updateUsage(usage) {
    const text = document.getElementById('usageText');
    const bar = document.getElementById('usageBar');
    if (!text || !bar) return;
    text.textContent = usage.limit ? `${formatBytes(usage.bytes)} / ${formatBytes(usage.limit)}` : formatBytes(usage.bytes);
    bar.style.width = usage.limit ? `${Math.min(100, usage.bytes / usage.limit * 100)}%` : '8%';
  }

  function formatBytes(bytes) {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let n = Number(bytes || 0), i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return `${n.toFixed(i ? 1 : 0)} ${units[i]}`;
  }

  load().catch(e => { render(); toast(e.message, true); });
})();
