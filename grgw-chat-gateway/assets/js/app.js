(function(){
  'use strict';

  const cfg = window.GRGW_CC || {};
  const root = document.getElementById('grgw-cc-root');
  if (!root) return;

  const WEBHOOKS = (cfg.webhooks && cfg.webhooks.list) ? cfg.webhooks.list : [];
  const DEFAULT_WEBHOOK = (cfg.webhooks && cfg.webhooks.active !== undefined) ? cfg.webhooks.active : 0;

  const state = {
    providers: Array.isArray(cfg.providers) ? cfg.providers : [],
    selectedProvider: null,
    selectedModel: null,
    selectedWebhookIndex: null, // Verrà inizializzato da localStorage o default
    conversations: [],
    activeId: null,
    messages: [],
    offset: 0,
    hasMore: true,
    pendingFile: null,
    sidebarOpen: false,
    searchQuery: '',
    poll: {
      enabled: false,
      timer: null,
      etag: '',
      lastServerId: 0,
      intervalMs: (cfg.hitl && cfg.hitl.poll_interval_ms) ? Number(cfg.hitl.poll_interval_ms) : 1000,
      timeoutSec: (cfg.hitl && cfg.hitl.timeout_sec) ? Number(cfg.hitl.timeout_sec) : 3600,

      // Debug / stato runtime
      after: null,
      inFlight: false,
      lastOkAt: null,
      lastErrAt: null,
      consecutiveErrors: 0,
    },
    ui: {
      inputFocused: false,
      keyboardOpen: false,
      suppressAutoScroll: false,
    },
  };

  // Inizializza selectedWebhookIndex da localStorage o default
  function initWebhookSelection() {
    try {
      const stored = window.localStorage.getItem('grgw_cc_selected_webhook');
      if (stored !== null) {
        const idx = parseInt(stored, 10);
        if (!isNaN(idx)) {
          state.selectedWebhookIndex = idx;
          return;
        }
      }
    } catch(e) {}
    state.selectedWebhookIndex = DEFAULT_WEBHOOK;
  }

  function saveWebhookSelection() {
    try {
      window.localStorage.setItem('grgw_cc_selected_webhook', String(state.selectedWebhookIndex || 0));
    } catch(e) {}
  }

  const HELP_URL = (cfg.ui && cfg.ui.helpUrl) ? String(cfg.ui.helpUrl) : '';
  const PUBLIC = cfg.public || { enabled: false, token: '' };

  // Debug (abilitabile dal backend; visibile solo agli admin)
  const DEBUG_CFG = cfg.debug || { enabled: false, is_admin: false, plugin_version: '' };
  const DEBUG_ENABLED = !!(DEBUG_CFG && DEBUG_CFG.enabled);
  const debugState = {
    enabled: DEBUG_ENABLED,
    server: null,
    events: [],
    lastError: null,
  };

  function safeStringify(obj, maxLen = 12000){
    const seen = new WeakSet();
    let s = '';
    try {
      s = JSON.stringify(obj, (k, v) => {
        if (typeof v === 'object' && v !== null){
          if (seen.has(v)) return '[Circular]';
          seen.add(v);
        }
        if (typeof v === 'function') return '[Function]';
        return v;
      }, 2);
    } catch(e){
      s = String(e && e.message ? e.message : e);
    }
    if (s.length > maxLen) s = s.slice(0, maxLen) + '\n…(troncato)';
    return s;
  }

  function debugEvent(label, data){
    if (!debugState.enabled) return;
    const ev = { t: new Date().toISOString(), label: String(label), data };
    debugState.events.unshift(ev);
    if (debugState.events.length > 20) debugState.events.length = 20;
  }

  // --- Mobile keyboard: sposta la barra input sopra la tastiera (Android/iOS)
  function updateKeyboardOffset(){
    try{
      const vv = window.visualViewport;
      if (!vv){
        root.style.setProperty('--grgw-cc-kb', '0px');
        root.style.setProperty('--grgw-cc-vvh', window.innerHeight + 'px');
        root.style.setProperty('--grgw-cc-vvtop', '0px');
        state.ui.keyboardOpen = false;
        state.ui.suppressAutoScroll = state.ui.inputFocused || state.ui.keyboardOpen;
        return;
      }
      const offset = Math.max(0, (window.innerHeight - vv.height - vv.offsetTop));
      root.style.setProperty('--grgw-cc-kb', offset + 'px');
      root.style.setProperty('--grgw-cc-vvh', vv.height + 'px');
      root.style.setProperty('--grgw-cc-vvtop', vv.offsetTop + 'px');
      state.ui.keyboardOpen = offset > 120;
      state.ui.suppressAutoScroll = state.ui.inputFocused || state.ui.keyboardOpen;
    }catch(_){
      root.style.setProperty('--grgw-cc-kb', '0px');
      root.style.setProperty('--grgw-cc-vvh', window.innerHeight + 'px');
      root.style.setProperty('--grgw-cc-vvtop', '0px');
      state.ui.keyboardOpen = false;
      state.ui.suppressAutoScroll = state.ui.inputFocused || state.ui.keyboardOpen;
    }
  }

  function bindViewportListeners(){
    const vv = window.visualViewport;
    if (!vv || bindViewportListeners._bound) return;
    bindViewportListeners._bound = true;
    const handler = ()=> updateKeyboardOffset();
    vv.addEventListener('resize', handler);
    vv.addEventListener('scroll', handler);
    window.addEventListener('resize', handler);
  }

  // --- Utils
  function escHtml(s){
    return String(s ?? '')
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'",'&#039;');
  }

  function formatTime(s){
    if (!s) return '';
    try{
      const d = new Date(s.replace(' ', 'T'));
      if (Number.isNaN(d.getTime())) return s;
      return d.toLocaleString(undefined, { year:'2-digit', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit' });
    }catch(e){ return s; }
  }

  function toast(msg){
    const t = root.querySelector('.grgw-cc-toast');
    if (!t) return;
    t.textContent = msg;
    t.classList.add('show');
    window.clearTimeout(toast._tm);
    toast._tm = window.setTimeout(()=> t.classList.remove('show'), 1800);
  }


  // --- Speech-to-text (Web Speech API, lato browser)
  // Nota: funziona bene su Chrome/Edge. Su Safari/Firefox può non esserci.
  const speech = {
    supported: !!(window.SpeechRecognition || window.webkitSpeechRecognition),
    listening: false,
    rec: null,
    baseText: '',
    finalText: '',
  };

  function updateMicButton(){
    const btn = root.querySelector('[data-action="mic"]');
    if (!btn) return;
    btn.classList.toggle('recording', speech.listening);
    btn.textContent = speech.listening ? '⏹' : '🎙️';
    if (!speech.supported) btn.style.display = 'none';
  }

  function ensureSpeech(){
    if (!speech.supported) return null;
    if (speech.rec) return speech.rec;

    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    const r = new SR();
    r.lang = 'it-IT';
    r.interimResults = true;
    r.continuous = false;
    r.maxAlternatives = 1;

    const join = (a,b)=>{
      if (!a) return b;
      if (!b) return a;
      return a.replace(/\s+$/,'') + ' ' + b.replace(/^\s+/,'');
    };

    r.onresult = (ev)=>{
      const ta = root.querySelector('[data-role="input"]');
      if (!ta) return;

      let interim = '';
      for (let i = ev.resultIndex; i < ev.results.length; i++){
        const res = ev.results[i];
        const txt = (res?.[0]?.transcript || '').trim();
        if (!txt) continue;

        if (res.isFinal){
          speech.finalText = join(speech.finalText, txt);
        } else {
          interim = join(interim, txt);
        }
      }

      let val = speech.baseText || '';
      val = join(val, speech.finalText);
      val = join(val, interim);

      ta.value = val;
      ta.focus();
    };

    r.onerror = (ev)=>{
      // tipi comuni: "not-allowed", "service-not-allowed", "no-speech", "audio-capture"
      const err = (ev && ev.error) ? ev.error : 'errore';
      toast('Microfono: ' + err);
      speech.listening = false;
      updateMicButton();
    };

    r.onend = ()=>{
      speech.listening = false;
      updateMicButton();
    };

    speech.rec = r;
    return r;
  }

  function toggleMic(){
    if (!speech.supported){
      toast('Dettatura non supportata dal browser 😬');
      return;
    }

    const ta = root.querySelector('[data-role="input"]');
    if (!ta) return;

    const r = ensureSpeech();
    if (!r) return;

    if (speech.listening){
      try{ r.stop(); }catch(e){}
      speech.listening = false;
      updateMicButton();
      return;
    }

    // start: “fotografa” quello che c'è già nella textarea
    speech.baseText = ta.value || '';
    speech.finalText = '';

    try{
      r.start();
      speech.listening = true;
      updateMicButton();
      toast('Microfono ON');
    }catch(e){
      // start() chiamato due volte troppo in fretta → eccezione
      toast('Microfono: non parte (forse è già attivo)');
      speech.listening = false;
      updateMicButton();
    }
  }


  function firstProvider(){
    return state.providers?.[0] ?? { key:'google', label:'Google', models:[{value:'gemini-2.0-flash', label:'Gemini 2.0 Flash'}] };
  }

  function findProvider(key){
    return state.providers.find(p => p.key === key) || null;
  }

  function findModel(providerKey, modelValue){
    const p = findProvider(providerKey);
    if (!p) return null;
    return (p.models || []).find(m => m.value === modelValue) || null;
  }

  function ensureDefaults(){
    if (!state.selectedProvider){
      const p = firstProvider();
      state.selectedProvider = p.key;
      state.selectedModel = p.models?.[0]?.value || '';
    }
    const p = findProvider(state.selectedProvider) || firstProvider();
    if (!findModel(p.key, state.selectedModel)){
      state.selectedProvider = p.key;
      state.selectedModel = p.models?.[0]?.value || '';
    }
  }

  async function loadDebugInfo(){
    if (!debugState.enabled) return;
    try {
      const info = await api('/cc/debug');
      debugState.server = info;
      debugEvent('debug_server_info', { ok: !!info?.ok, plugin: info?.plugin?.version });
    } catch (e) {
      debugState.lastError = { where: 'debug_endpoint', message: String(e?.message || e) };
      debugEvent('debug_server_error', { message: String(e?.message || e) });
    }
  }

  function renderBrandHeaderHtml(){
    const name = (cfg.ui && cfg.ui.brandName) ? String(cfg.ui.brandName) : '';
    const logo = (cfg.ui && cfg.ui.brandLogoUrl) ? String(cfg.ui.brandLogoUrl) : '';
    if (!name && !logo) return '';
    const help = HELP_URL ? `<a class="grgw-cc-helpicon" href="${escHtml(HELP_URL)}" target="_blank" rel="noopener noreferrer" title="Istruzioni" aria-label="Istruzioni">?</a>` : '';
    return `
      <div class="grgw-cc-brand">
        <div class="grgw-cc-brandleft">
          ${logo ? `<img class="grgw-cc-brandlogo" src="${escHtml(logo)}" alt="logo" />` : ''}
          ${name ? `<div class="grgw-cc-brandname">${escHtml(name)}</div>` : ''}
        </div>
        ${help}
      </div>
    `;
  }

  function filterConversations(){
    const q = (state.searchQuery || '').trim().toLowerCase();
    if (!q) return state.conversations;
    return (state.conversations || []).filter(c => String(c?.title || '').toLowerCase().includes(q));
  }

  function renderChatListHtml(){
    const list = filterConversations();
    const q = (state.searchQuery || '').trim();
    const items = list.length ? list.map(c => `
      <div class="grgw-cc-chatitem ${c.id === state.activeId ? 'active':''}" data-chat="${c.id}">
        <div class="grgw-cc-chatmeta">
          <div class="grgw-cc-chattitle" title="${escHtml(c.title)}">${escHtml(c.title)}</div>
          <div class="grgw-cc-chatupdated">${escHtml(formatTime(c.updated_at))}</div>
        </div>
        <div class="grgw-cc-chatactions">
          <button type="button" class="grgw-cc-iconbtn" data-action="delete-chat" data-chat="${c.id}" title="Elimina">🗑</button>
        </div>
      </div>
    `).join('') : `
      <div class="grgw-cc-empty" style="margin:12px; border:1px dashed var(--border); background: transparent;">
        <div style="font-weight:700; margin-bottom:6px;">Nessuna chat trovata</div>
        <div>${q ? `Nessun titolo contiene <strong>${escHtml(q)}</strong>.` : 'Crea una nuova chat o attendi il caricamento.'}</div>
      </div>
    `;

    const loadMore = (state.hasMore && !q) ? `<button type="button" class="grgw-cc-btn" data-action="load-more">Carica altre</button>` : '';
    return items + loadMore;
  }

  function renderChatListOnly(){
    const el = root.querySelector('.grgw-cc-chatlist');
    if (!el) return;
    el.innerHTML = renderChatListHtml();
  }

  function isUserNearBottom(el, threshold = 120){
    if (!el) return false;
    return (el.scrollHeight - (el.scrollTop + el.clientHeight)) < threshold;
  }

  function dropOptimisticMessages(){
    const before = state.messages.length;
    state.messages = state.messages.filter(m => !(m && m.optimistic));
    return before - state.messages.length;
  }

  function renderMessagesOnly(){
    const el = root.querySelector('.grgw-cc-messages');
    if (!el) return;
    const nearBottom = isUserNearBottom(el);

    el.innerHTML = renderMessages();

    // Aspetta il layout: alcuni browser/edge case resettano lo scroll a 0 dopo innerHTML
    requestAnimationFrame(() => {
      if (nearBottom && !state.ui.suppressAutoScroll) {
        scrollToBottom(true);
      }
    });
  }
  async function api(path, options = {}){
    const url = (cfg.restBase || '/wp-json/grgrowth/v1') + path;
    const headers = options.headers || {};

    if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
    if (PUBLIC && PUBLIC.enabled && PUBLIC.token){
      headers['X-GRGW-PUBLIC-TOKEN'] = PUBLIC.token;
    }

    options.headers = headers;
    options.credentials = 'same-origin';

    debugEvent('api_raw_request', {
      method: (options.method || 'GET').toUpperCase(),
      path,
      url
    });

    const method = String(options.method || 'GET').toUpperCase();
    const bodyPreview = (typeof options.body === 'string') ? options.body.slice(0, 2000) : undefined;
    debugEvent('api_request', { method, path, url, body_preview: bodyPreview });

    const r = await fetch(url, options);
    const text = await r.text();

    let data;
    try { data = text ? JSON.parse(text) : {}; } catch(e){ data = { ok:false, raw:text }; }

    debugEvent('api_response', { method, path, status: r.status, ok: r.ok, body_bytes: (text ? text.length : 0) });

    if (!r.ok || data?.ok === false){
      const msg = data?.message || data?.error || ('Errore HTTP ' + r.status);
      debugState.lastError = { where: 'api', method, path, status: r.status, message: msg };
      debugEvent('api_error', { method, path, status: r.status, message: msg, raw: (typeof data?.raw === 'string' ? data.raw.slice(0, 2000) : undefined) });
      throw new Error(msg);
    }

    return data;
  }

  // Variante "raw" per gestire 304/ETag nel polling (non lancia errore su 304)
  async function apiRaw(path, options = {}){
    const url = (cfg.restBase || '/wp-json/grgrowth/v1') + path;
    const headers = options.headers || {};

    if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
    if (PUBLIC && PUBLIC.enabled && PUBLIC.token){
      headers['X-GRGW-PUBLIC-TOKEN'] = PUBLIC.token;
    }

    options.headers = headers;
    options.credentials = 'same-origin';

    debugEvent('api_raw_request', {
      method: (options.method || 'GET').toUpperCase(),
      path,
      url
    });

    const r = await fetch(url, options);
    const text = await r.text();

    let data = null;
    if (text){
      try { data = JSON.parse(text); } catch(e){ data = { ok:false, raw:text }; }
    }

    // 304: niente body
    if (r.status === 304){
      debugEvent('api_raw_response', { method: (options.method || 'GET').toUpperCase(), path, url, status: 304, ok: true, body_bytes: 0 });
      debugState.lastPoll = { status: 304, ok: true, at: new Date().toISOString(), path, url };
      return { status: 304, ok: true, headers: r.headers, data: null };
    }

    if (!r.ok || (data && data.ok === false)){
      const msg = (data && (data.message || data.error)) || ('Errore HTTP ' + r.status);
      debugEvent('api_raw_error', { method: (options.method || 'GET').toUpperCase(), path, url, status: r.status, message: msg });
      debugState.lastError = { where: 'api_raw', method: (options.method || 'GET').toUpperCase(), path, url, status: r.status, message: msg, at: new Date().toISOString() };
      throw new Error(msg);
    }

    debugEvent('api_raw_response', { method: (options.method || 'GET').toUpperCase(), path, url, status: r.status, ok: r.ok, body_bytes: text ? text.length : 0 });
    debugState.lastPoll = { status: r.status, ok: r.ok, at: new Date().toISOString(), path, url };
    return { status: r.status, ok: r.ok, headers: r.headers, data: data || {} };
  }

  // --- Markdown-ish safe renderer (niente HTML raw)
  function renderMarkdownSafe(raw){
    raw = String(raw ?? '');

    // 1) Estrai code blocks dal raw (non escappato)
    const blocks = [];
    raw = raw.replace(/```([a-zA-Z0-9_-]+)?\n([\s\S]*?)```/g, function(_, lang, code){
      blocks.push({ lang: (lang || '').trim(), code: code || '' });
      return `\u0000CB${blocks.length-1}\u0000`;
    });

    // 2) Escape tutto
    let s = escHtml(raw);

    // 3) Link automatici
    s = s.replace(/(https?:\/\/[^\s<]+)/g, function(m){
      const u = m;
      return `<a href="${u}" target="_blank" rel="noopener noreferrer">${u}</a>`;
    });

    // 4) Inline code
    s = s.replace(/`([^`]+)`/g, function(_, code){
      return `<code>${code}</code>`;
    });

    // 5) Bold
    s = s.replace(/\*\*([^*]+)\*\*/g, function(_, t){
      return `<strong>${t}</strong>`;
    });

    // 6) Newlines
    s = s.replace(/\n/g, '<br>');

    // 7) Reinserisci code blocks
    s = s.replace(/\u0000CB(\d+)\u0000/g, function(_, idx){
      const b = blocks[Number(idx)];
      if (!b) return '';
      const lang = escHtml(b.lang || 'code');
      const code = escHtml(b.code || '');
      return `
        <div class="grgw-cc-codeblock">
          <div class="grgw-cc-codebar">
            <span>${lang}</span>
            <button type="button" class="grgw-cc-copy">Copia</button>
          </div>
          <pre><code>${code}</code></pre>
        </div>
      `;
    });

    return s;
  }

  // --- UI Render
  function render(){
    ensureDefaults();

    // Preserve scroll position when re-rendering the SAME conversation.
    // Full render replaces DOM nodes and would otherwise reset scrollTop to 0.
    const targetConvId = state.activeId || '';
    const prevConvId = root.getAttribute('data-grgw-active-conv') || '';
    let savedScroll = null;
    if (prevConvId && prevConvId === targetConvId) {
      const el = root.querySelector('.grgw-cc-messages');
      if (el) {
        const nearBottom = (el.scrollHeight - (el.scrollTop + el.clientHeight)) < 80;
        savedScroll = { top: el.scrollTop, nearBottom };
      }
    }

    const sttSupported = !!(window.SpeechRecognition || window.webkitSpeechRecognition);

    root.innerHTML = `
      <div class="grgw-cc-sidebar ${state.sidebarOpen ? 'open':''}">
        <div class="grgw-cc-sidebar-top">
          <div class="grgw-cc-toprow">
            <button type="button" class="grgw-cc-hamburger" aria-label="Menu">☰</button>
            <div style="flex:1"></div>
          </div>

          ${renderBrandHeaderHtml()}

          <div style="display:grid; gap:8px; margin-top:10px;">
            ${WEBHOOKS.length > 0 ? '<select class="grgw-cc-webhook-select" data-role="webhook"></select>' : ''}
            <select class="grgw-cc-select" data-role="provider"></select>
            <select class="grgw-cc-select" data-role="model"></select>
          </div>

          <button type="button" class="grgw-cc-newchat">+ Nuova chat</button>
          <input type="text" class="grgw-cc-search" data-role="search" placeholder="Cerca chat..." value="${escHtml(state.searchQuery)}" />
        </div>

        <div class="grgw-cc-chatlist">
          ${renderChatListHtml()}
        </div>
      </div>

      <div class="grgw-cc-main">
        <div class="grgw-cc-main-top">
          <div class="grgw-cc-maintitle">
            <button type="button" class="grgw-cc-hamburger" aria-label="Menu">☰</button>
            <h3>${escHtml(activeConversation()?.title || 'Seleziona o crea una chat')}</h3>
            ${state.activeId ? `<button type="button" class="grgw-cc-iconbtn" data-action="edit-title" title="Rinomina">✏️</button>` : ''}
            <a class="grgw-cc-helpicon grgw-cc-helpicon--main" href="${escHtml(HELP_URL)}" target="_blank" rel="noopener noreferrer" title="Istruzioni">?</a>
          </div>
          <div class="grgw-cc-badge">
            ${escHtml(state.selectedProvider)} · ${escHtml(state.selectedModel)}
          </div>
        </div>

        <div class="grgw-cc-messages">
          ${state.activeId ? renderMessages() : `
            <div class="grgw-cc-empty">
              <div style="font-weight:700; margin-bottom:6px;">Nessuna chat selezionata</div>
              <div>Premi <strong>+ Nuova chat</strong> oppure scegli una conversazione a sinistra.</div>
            </div>
          `}
        </div>

        <div class="grgw-cc-inputbar">
          ${state.pendingFile ? `
            <div class="grgw-cc-filepill">
              <span class="grgw-cc-filepill-name">📄 ${escHtml(state.pendingFile.name)} (${Math.round(state.pendingFile.size/1024)} KB)</span>
              <button type="button" class="grgw-cc-iconbtn" data-action="clear-file" title="Rimuovi file" aria-label="Rimuovi file">✕</button>
            </div>
          ` : ``}

          <div class="grgw-cc-inputwrap">
            <button type="button" class="grgw-cc-iconcircle grgw-cc-attachbtn" data-action="attach" title="Allega file" aria-label="Allega file">📎</button>
            ${sttSupported ? `<button type="button" class="grgw-cc-iconcircle grgw-cc-micbtn" data-action="mic" title="Dettatura (microfono)" aria-label="Microfono">🎙️</button>` : ``}
            <textarea class="grgw-cc-textarea" placeholder="Scrivi qui... (invio = manda, shift+invio = a capo)" data-role="input"></textarea>
            <button type="button" class="grgw-cc-iconcircle grgw-cc-sendbtn" data-action="send" aria-label="Invia">➤</button>
            <input type="file" class="grgw-cc-fileinput" style="display:none" data-role="file" />
          </div>
        </div>

        <div class="grgw-cc-toast"></div>
      </div>

      <div class="grgw-cc-modal" data-role="modal">
        <div class="grgw-cc-modalbox">
          <h4 data-role="modal-title">Conferma</h4>
          <p data-role="modal-text"></p>
          <div class="grgw-cc-modalactions">
            <button type="button" class="grgw-cc-btn" data-action="modal-cancel">Annulla</button>
            <button type="button" class="grgw-cc-btn danger" data-action="modal-ok">Sì, elimina</button>
          </div>
        </div>
      </div>
    `;

    updateMicButton();

    fillWebhookSelect();
    fillProviderSelect();
    fillModelSelect();

    // set input behaviour
    const ta = root.querySelector('[data-role="input"]');
    if (ta){
      ta.addEventListener('keydown', (e)=>{
        if (e.key === 'Enter' && !e.shiftKey){
          e.preventDefault();
          onSend();
        }
      });

      // Mobile UX: quando scrivi, nascondi icone (rimane solo invio)
      ta.addEventListener('focus', ()=>{
        state.ui.inputFocused = true;
        state.ui.suppressAutoScroll = true;
        if (window.matchMedia('(max-width: 860px)').matches){
          root.classList.add('grgw-cc-typing');
        }
        updateKeyboardOffset();
      });
      ta.addEventListener('blur', ()=>{
        state.ui.inputFocused = false;
        root.classList.remove('grgw-cc-typing');
        updateKeyboardOffset();
        state.ui.suppressAutoScroll = state.ui.keyboardOpen;
      });
    }

    // Sidebar collapsabile su desktop
    root.classList.toggle('grgw-cc-sb-collapsed', !state.sidebarOpen);

    // Keyboard handler (mobile)
    bindViewportListeners();
    updateKeyboardOffset();

    // Remember which conversation is currently rendered (used for scroll preservation).
    root.setAttribute('data-grgw-active-conv', targetConvId);

    // Restore scroll position (only when re-rendering the same conversation).
    if (savedScroll) {
      requestAnimationFrame(() => {
        const el = root.querySelector('.grgw-cc-messages');
        if (!el) return;

        const maxTop = Math.max(0, el.scrollHeight - el.clientHeight);
        if (savedScroll.nearBottom) {
          el.scrollTop = maxTop;
        } else {
          el.scrollTop = Math.min(maxTop, Math.max(0, savedScroll.top));
        }
      });
    }
  }

  function activeConversation(){
    return state.conversations.find(c => c.id === state.activeId) || null;
  }

  function renderDebugBubble(){
    if (!debugState.enabled) return '';
    const active = activeConversation();
    const payload = {
      now: new Date().toISOString(),
      ui: {
        active_conversation_id: state.activeId || null,
        active_title: active ? (active.title || '') : '',
        provider: state.selectedProvider || null,
        model: state.selectedModel || null
      },
      polling: {
        enabled: !!state.poll.enabled,
        after: (state.poll.after !== undefined) ? state.poll.after : null,
        etag: state.poll.etag || null,
        interval_ms: state.poll.intervalMs || null,
        timeout_sec: state.poll.timeoutSec || null,
        in_flight: !!state.poll.inFlight,
        last_ok: state.poll.lastOkAt || null,
        last_err: state.poll.lastErrAt || null,
        consecutive_errors: state.poll.consecutiveErrors || 0
      },
      server: debugState.server,
      last_error: debugState.lastError,
      events: debugState.events.slice(0, 10)
    };
    const json = safeStringify(payload, 14000);
    return `
      <div class="grgw-cc-msg system">
        <div class="grgw-cc-bubble grgw-cc-bubble--debug">
          <details class="grgw-cc-debug-details">
            <summary>Debug (solo admin)</summary>
            <pre>${escHtml(json)}</pre>
          </details>
        </div>
      </div>
    `;
  }

  function renderMessages(){
    if (!state.messages.length){
      return `
        <div class="grgw-cc-empty">
          <div style="font-weight:700; margin-bottom:6px;">Chat vuota</div>
          <div>Scrivi un messaggio qui sotto e premi Invio.</div>
        </div>
      `;
    }
    return state.messages.map(m => {
      const role = m.role === 'user' ? 'user' : 'assistant';
      const attachments = (m.meta && m.meta.attachments) ? m.meta.attachments : [];
      const chips = Array.isArray(attachments) && attachments.length ? `
        <div class="grgw-cc-attachments">
          ${attachments.map(a => {
            const name = escHtml(a.name || 'file');
            const url = escHtml(a.url || '#');
            return `<a class="grgw-cc-chip" href="${url}" target="_blank" rel="noopener noreferrer">${name}</a>`;
          }).join('')}
        </div>
      ` : '';
      const html = renderMarkdownSafe(m.content || '');
      const hitlButtons = (role === 'assistant' && m.meta && m.meta.hitl && Array.isArray(m.meta.buttons) && m.meta.buttons.length)
        ? `
          <div class="grgw-cc-hitl-actions">
            ${m.meta.buttons.map(b => {
              const id = escHtml(b.id || '');
              const label = escHtml(b.label || b.id || 'OK');
              return `<button type="button" class="grgw-cc-hitl-btn" data-hitl-btn="${id}">${label}</button>`;
            }).join('')}
          </div>
        `
        : '';
      return `
        <div class="grgw-cc-msg ${role}">
          <div class="grgw-cc-bubble">
            ${html}
            ${chips}
            ${hitlButtons}
          </div>
        </div>
      `;
    }).join('') + renderDebugBubble();
  }

  function fillWebhookSelect(){
    const sel = root.querySelector('select[data-role="webhook"]');
    if (!sel) return;

    if (WEBHOOKS.length === 0) {
      sel.style.display = 'none';
      return;
    }

    sel.innerHTML = WEBHOOKS.map(w => `<option value="${escHtml(String(w.index))}">${escHtml(w.label)}</option>`).join('');
    sel.value = String(state.selectedWebhookIndex);

    sel.onchange = () => {
      const newIndex = parseInt(sel.value, 10);
      if (!isNaN(newIndex)) {
        state.selectedWebhookIndex = newIndex;
        saveWebhookSelection();
        toast('Webhook cambiato: ' + (WEBHOOKS.find(w => w.index === newIndex)?.label || 'N/A'));
      }
    };
  }

  function fillProviderSelect(){
    const sel = root.querySelector('select[data-role="provider"]');
    if (!sel) return;

    sel.innerHTML = state.providers.map(p => `<option value="${escHtml(p.key)}">${escHtml(p.label)}</option>`).join('');
    sel.value = state.selectedProvider;

    sel.onchange = async () => {
      const newProvider = sel.value;
      const p = findProvider(newProvider) || firstProvider();
      const newModel = p.models?.[0]?.value || '';
      state.selectedProvider = p.key;
      state.selectedModel = newModel;
      fillModelSelect();

      if (state.activeId){
        try {
          const out = await api(`/cc/conversations/${state.activeId}`, {
            method: 'PATCH',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ provider: state.selectedProvider, model: state.selectedModel })
          });
          patchConversation(out.conversation);
          toast('Modello aggiornato per questa chat ✅');
        } catch(e){
          toast('Errore aggiornando provider/modello: ' + e.message);
        }
      }
      renderBadgeOnly();
    };
  }

  function fillModelSelect(){
    const sel = root.querySelector('select[data-role="model"]');
    if (!sel) return;

    const p = findProvider(state.selectedProvider) || firstProvider();
    const models = p.models || [];
    sel.innerHTML = models.map(m => `<option value="${escHtml(m.value)}">${escHtml(m.label)}</option>`).join('');
    sel.value = state.selectedModel;

    sel.onchange = async () => {
      state.selectedModel = sel.value;

      if (state.activeId){
        try {
          const out = await api(`/cc/conversations/${state.activeId}`, {
            method: 'PATCH',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ provider: state.selectedProvider, model: state.selectedModel })
          });
          patchConversation(out.conversation);
          toast('Modello aggiornato per questa chat ✅');
        } catch(e){
          toast('Errore aggiornando modello: ' + e.message);
        }
      }
      renderBadgeOnly();
    };
  }

  function renderBadgeOnly(){
    const badge = root.querySelector('.grgw-cc-badge');
    if (badge) badge.textContent = `${state.selectedProvider} · ${state.selectedModel}`;
  }

  function patchConversation(conv){
    if (!conv || !conv.id) return;
    const idx = state.conversations.findIndex(c => c.id === conv.id);
    if (idx >= 0) state.conversations[idx] = { ...state.conversations[idx], ...conv };
  }

  function toggleInputDisabled(disabled) {
    const ta = root.querySelector('[data-role="input"]');
    const sendBtn = root.querySelector('[data-action="send"]');
    const attachBtn = root.querySelector('[data-action="attach"]');
    const micBtn = root.querySelector('[data-action="mic"]');

    if (ta) ta.disabled = disabled;
    if (sendBtn) sendBtn.disabled = disabled;
    if (attachBtn) attachBtn.disabled = disabled;
    if (micBtn) micBtn.disabled = disabled;
  }

  function closeSidebarIfMobile(){
    if (window.matchMedia('(max-width: 860px)').matches){
      state.sidebarOpen = false;
      const sb = root.querySelector('.grgw-cc-sidebar');
      if (sb) sb.classList.remove('open');
      root.classList.toggle('grgw-cc-sb-collapsed', !state.sidebarOpen);
      toggleInputDisabled(false);
    }
  }

  function scrollToBottom(force = false){
    const wrap = root.querySelector('.grgw-cc-messages');
    if (!wrap) return;
    if (!force && (!isUserNearBottom(wrap) || state.ui.suppressAutoScroll)) return;
    wrap.scrollTop = wrap.scrollHeight;
  }

  // --- Events (delegation)
  root.addEventListener('click', async (e)=>{
    const t = e.target;
    if (!(t instanceof Element)) return;

    // HITL quick buttons dentro bubble
    const hb = t.closest('button.grgw-cc-hitl-btn');
    if (hb){
      e.preventDefault();
      const conv = activeConversation();
      if (!conv){
        toast('Nessuna chat attiva.');
        return;
      }
      // evita doppi click (idempotenza c'è, ma meglio UX)
      hb.disabled = true;
      const group = hb.parentElement;
      if (group){
        Array.from(group.querySelectorAll('button.grgw-cc-hitl-btn')).forEach(b => { b.disabled = true; });
      }
      try{
        const buttonId = hb.getAttribute('data-hitl-btn') || '';
        await hitlSubmit(conv.id, { type:'button', button_id: buttonId });
        toast('Inviato a n8n (HITL) ✅');

        // Forza polling multiplo per intercettare la risposta di n8n
        await pollUpdates(true);
        setTimeout(() => pollUpdates(true), 1000);
        setTimeout(() => pollUpdates(true), 3000);
        setTimeout(() => pollUpdates(true), 5000);
      }catch(err){
        toast('Errore HITL: ' + err.message);
        if (group){
          Array.from(group.querySelectorAll('button.grgw-cc-hitl-btn')).forEach(b => { b.disabled = false; });
        } else {
          hb.disabled = false;
        }
      }
      return;
    }

    // hamburger
    if (t.classList.contains('grgw-cc-hamburger')){
      state.sidebarOpen = !state.sidebarOpen;
      const sb = root.querySelector('.grgw-cc-sidebar');
      if (sb) sb.classList.toggle('open', state.sidebarOpen);
      // desktop: collassa/espandi sidebar
      root.classList.toggle('grgw-cc-sb-collapsed', !state.sidebarOpen);
      // Disabilita input quando sidebar è aperta su mobile
      toggleInputDisabled(state.sidebarOpen && window.matchMedia('(max-width: 860px)').matches);
      return;
    }

    // Click overlay per chiudere sidebar su mobile
    if (state.sidebarOpen && t.closest('.grgw-cc-sidebar') === null && window.matchMedia('(max-width: 860px)').matches) {
      state.sidebarOpen = false;
      const sb = root.querySelector('.grgw-cc-sidebar');
      if (sb) sb.classList.remove('open');
      toggleInputDisabled(false);
      return;
    }

    // click chat item
    const chatEl = t.closest('[data-chat]');
    if (chatEl && chatEl.classList.contains('grgw-cc-chatitem')){
      const id = chatEl.getAttribute('data-chat');
      if (id) await openConversation(id);
      closeSidebarIfMobile();
      return;
    }

    // delete chat button
    if (t.matches('[data-action="delete-chat"]')){
      e.stopPropagation();
      const id = t.getAttribute('data-chat');
      if (!id) return;
      confirmModal(
        'Eliminare la chat?',
        'Questa azione cancella conversazione e messaggi. Non si torna indietro.',
        async ()=>{
          try{
            await api(`/cc/conversations/${id}`, { method:'DELETE' });
            state.conversations = state.conversations.filter(c => c.id !== id);
            if (state.activeId === id){
              state.activeId = state.conversations[0]?.id || null;
              state.messages = [];
              if (state.activeId) await openConversation(state.activeId);
            }
            render();
            toast('Chat eliminata 🗑');
          }catch(err){
            state.conversations = state.conversations.filter(c => c.id !== id);
            if (state.activeId === id){
              state.activeId = state.conversations[0]?.id || null;
              state.messages = [];
              if (state.activeId) await openConversation(state.activeId);
            }
            render();

            if (err.message && err.message.includes('Not found')){
              toast('Chat eliminata (non trovata sul server)');
            } else {
              toast('Chat rimossa dalla lista');
            }
          }
        }
      );
      return;
    }

    // new chat
    if (t.classList.contains('grgw-cc-newchat')){
      await createAndOpenConversation();
      closeSidebarIfMobile();
      return;
    }

    // send
    if (t.matches('[data-action="send"]')) {
      await onSend();
      return;
    }

    // attach
    if (t.matches('[data-action="attach"]')){
      const fi = root.querySelector('[data-role="file"]');
      if (!fi) return;

      // set accept based on allowed types (no zip)
      fi.setAttribute('accept', '.pdf,.doc,.docx,.txt,.csv,.xlsx,.png,.jpg,.jpeg,.webp');
      fi.click();
      return;
    }

    // mic
    if (t.matches('[data-action="mic"]')){
      toggleMic();
      return;
    }


    // clear file
    if (t.matches('[data-action="clear-file"]')){
      state.pendingFile = null;
      const fi = root.querySelector('[data-role="file"]');
      if (fi) fi.value = '';
      render();
      return;
    }

    // edit title
    if (t.matches('[data-action="edit-title"]')){
      const conv = activeConversation();
      if (!conv) return;

      const current = conv.title || '';
      const newTitle = prompt('Nuovo titolo chat:', current);
      if (newTitle === null) return;

      try{
        const out = await api(`/cc/conversations/${conv.id}`, {
          method:'PATCH',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ title: newTitle })
        });
        patchConversation(out.conversation);
        render();
        toast('Titolo aggiornato ✏️');
      }catch(err){
        toast('Errore aggiornando titolo: ' + err.message);
      }
      return;
    }

    // copy code
    if (t.classList.contains('grgw-cc-copy')){
      const block = t.closest('.grgw-cc-codeblock');
      if (!block) return;
      const codeEl = block.querySelector('code');
      if (!codeEl) return;
      const txt = codeEl.innerText || codeEl.textContent || '';
      try{
        await navigator.clipboard.writeText(txt);
        toast('Copiato ✅');
      }catch(err){
        toast('Clipboard bloccata dal browser 😅');
      }
      return;
    }

    // modal
    if (t.matches('[data-action="modal-cancel"]')){
      closeModal();
      return;
    }
    if (t.matches('[data-action="modal-ok"]')){
      const ok = modalState.onOk;
      closeModal();
      if (typeof ok === 'function') ok();
      return;
    }

    // load more
    if (t.matches('[data-action="load-more"]')){
      await loadConversations(true);
      return;
    }
  });

  root.addEventListener('input', (e)=>{
    const t = e.target;
    if (!(t instanceof HTMLInputElement)) return;
    if (t.matches('[data-role="search"]')){
      state.searchQuery = t.value || '';
      renderChatListOnly();
    }
  });

  root.addEventListener('change', (e)=>{
    const t = e.target;
    if (!(t instanceof HTMLInputElement)) return;
    if (t.matches('[data-role="file"]')){
      const f = t.files && t.files[0] ? t.files[0] : null;
      state.pendingFile = f;
      render();
    }
  });

  // --- Modal helper
  const modalState = { onOk: null };

  function confirmModal(title, text, onOk){
    const modal = root.querySelector('[data-role="modal"]');
    if (!modal) return;
    modalState.onOk = onOk;
    modal.querySelector('[data-role="modal-title"]').textContent = title;
    modal.querySelector('[data-role="modal-text"]').textContent = text;
    modal.classList.add('open');
  }

  function closeModal(){
    const modal = root.querySelector('[data-role="modal"]');
    if (!modal) return;
    modal.classList.remove('open');
    modalState.onOk = null;
  }

  // --- Data operations
  async function loadConversations(append = false){
    const limit = cfg.ui?.limitConversations || 50;
    const offset = append ? state.offset : 0;

    try{
      const out = await api(`/cc/conversations?limit=${encodeURIComponent(limit)}&offset=${encodeURIComponent(offset)}`);
      const rows = out.conversations || [];
      if (!append){
        state.conversations = rows;
        state.offset = rows.length;
      } else {
        state.conversations = state.conversations.concat(rows);
        state.offset += rows.length;
      }
      state.hasMore = rows.length === limit;
    }catch(err){
      toast('Errore caricando chat: ' + err.message);
      state.conversations = [];
      state.hasMore = false;
    }
  }

  async function openConversation(id){
    state.activeId = id;
    try{
      const out = await api(`/cc/conversations/${id}?limit=200`);
      const conv = out.conversation;
      const msgs = Array.isArray(out.messages) ? out.messages : [];
      // sync selectors from backend (regola: backend è la verità)
      if (conv?.provider) state.selectedProvider = conv.provider;
      if (conv?.model) state.selectedModel = conv.model;

      patchConversation(conv);
      state.messages = msgs.map(m => ({
        id: (m && (m.id !== undefined)) ? m.id : undefined,
        role: m.role,
        content: m.content,
        meta: m.meta || null,
        created_at: m.created_at
      }));

      render();
      scrollToBottom(true);

      // Aggiorna stato polling: spento di default, acceso solo se HITL attiva
      state.poll.lastServerId = getLastServerMessageId(state.messages);
      state.poll.etag = '';
      syncPollingForActiveConversation();
    }catch(err){
      toast('Errore aprendo chat: ' + err.message);
    }
  }

  async function createAndOpenConversation(){
    ensureDefaults();
    try{
      const out = await api('/cc/conversations', {
        method:'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ provider: state.selectedProvider, model: state.selectedModel })
      });
      const conv = out.conversation;
      state.conversations.unshift(conv);
      state.activeId = conv.id;
      state.messages = [];
      // nuova chat: polling OFF
      state.poll.lastServerId = 0;
      state.poll.etag = '';
      stopPolling();
      render();
      toast('Nuova chat creata ✅');
    }catch(err){
      toast('Errore creando chat: ' + err.message);
    }
  }

  async function onSend(){
    // se stavi dettando, stop (sennò ti scrive mentre mandi)
    if (typeof speech !== 'undefined' && speech.listening && speech.rec){
      try{ speech.rec.stop(); }catch(e){}
      speech.listening = false;
      updateMicButton();
    }
    const ta = root.querySelector('[data-role="input"]');
    const text = ta ? ta.value.trim() : '';
    if (!text && !state.pendingFile){
      toast('Scrivi qualcosa (o allega un file) 😉');
      return;
    }

    // se non c'è una chat attiva, creala al volo
    if (!state.activeId){
      await createAndOpenConversation();
      if (!state.activeId) return;
    }

    const conv = activeConversation();
    if (!conv) return;

    // Se HITL è attiva, usa /hitl/submit invece di /message
    if (isHitlActive(conv)){
      const userMeta = state.pendingFile ? { attachments: [{ name: state.pendingFile.name, url:'#', mime: state.pendingFile.type, size: state.pendingFile.size }] } : null;
      state.messages.push({ role:'user', content:text, meta:userMeta, created_at: new Date().toISOString(), optimistic: true });

      if (ta) ta.value = '';
      const pendingFile = state.pendingFile;
      state.pendingFile = null;

      render();
      scrollToBottom(true);

      try{
        await hitlSubmit(conv.id, {
          type: pendingFile ? 'file' : 'text',
          text: text,
          file: pendingFile
        });
        toast('Messaggio inviato a n8n ✅');

        // Forza polling multiplo per intercettare la risposta di n8n
        await pollUpdates(true);
        setTimeout(() => pollUpdates(true), 1000);
        setTimeout(() => pollUpdates(true), 3000);
        setTimeout(() => pollUpdates(true), 5000);
      }catch(err){
        toast('Errore HITL: ' + err.message);
      }
      return;
    }

    // Flusso normale (non HITL)
    // optimistic UI: aggiungi msg user
    const userMeta = state.pendingFile ? { attachments: [{ name: state.pendingFile.name, url:'#', mime: state.pendingFile.type, size: state.pendingFile.size }] } : null;
    const userIndex = state.messages.push({ role:'user', content:text, meta:userMeta, created_at: new Date().toISOString(), optimistic: true }) - 1;

    // placeholder assistant
    const placeholderIndex = state.messages.push({ role:'assistant', content:'Sto pensando', meta:null, created_at: new Date().toISOString(), optimistic: true }) - 1;

    // reset input
    if (ta) ta.value = '';
    const pendingFile = state.pendingFile;
    state.pendingFile = null;

    render();
    scrollToBottom(true);

    try{
      const out = await sendMessage(conv.id, text, state.selectedProvider, state.selectedModel, pendingFile);

      // fix user attachments chip URL if we had file
      if (Array.isArray(out.user_attachments) && out.user_attachments.length){
        if (state.messages[userIndex]) {
          state.messages[userIndex].meta = { attachments: out.user_attachments };
        }
      }

      // replace placeholder with reply
      state.messages[placeholderIndex] = {
        role:'assistant',
        content: out.reply || '',
        meta: { attachments: out.attachments || [], extra: out.extra || null },
        created_at: new Date().toISOString()
      };

      // update conversation title/provider/model from backend
      if (out.conversation){
        patchConversation(out.conversation);
      }

      // move conversation to top (updated)
      state.conversations = [ ...state.conversations.filter(c => c.id !== conv.id) ];
      state.conversations.unshift(out.conversation || conv);

      // se n8n ha acceso HITL, avvia polling
      syncPollingForActiveConversation();

      // fetch immediato per sincronizzare eventuali risposte asincrone
      const added = await pollUpdates(true);
      if (added > 0) {
        dropOptimisticMessages();
      }

      render();
      scrollToBottom(true);
    }catch(err){
      // show error in placeholder
      state.messages[placeholderIndex] = { role:'assistant', content: 'Errore: ' + err.message, meta:{}, created_at: new Date().toISOString() };
      render();
      scrollToBottom(true);
    }
  }

  async function sendMessage(conversationId, text, provider, model, file){
    const webhookIndex = state.selectedWebhookIndex;

    if (file){
      const fd = new FormData();
      fd.append('text', text);
      fd.append('provider', provider);
      fd.append('model', model);
      if (webhookIndex !== null && webhookIndex !== undefined) {
        fd.append('webhook_index', String(webhookIndex));
      }
      fd.append('file', file);
      return api(`/cc/conversations/${conversationId}/message`, {
        method:'POST',
        body: fd
      });
    }

    const payload = { text, provider, model };
    if (webhookIndex !== null && webhookIndex !== undefined) {
      payload.webhook_index = webhookIndex;
    }

    return api(`/cc/conversations/${conversationId}/message`, {
      method:'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
  }

  // ---------------------------------------------------------------------------
  // HITL + Polling
  // ---------------------------------------------------------------------------

  function isHitlActive(conv){
    if (!conv) return false;
    if (conv.hitl_active === 1 || conv.hitl_active === true) return true;
    if (conv.hitl && conv.hitl.active) return true;
    return false;
  }

  function getHitlIdForActiveConversation(){
    const conv = activeConversation();
    if (conv && conv.hitl_id) return conv.hitl_id;
    // fallback: cerca nell'ultimo hitl_request
    for (let i = state.messages.length - 1; i >= 0; i--){
      const m = state.messages[i];
      if (!m || !m.meta) continue;
      if (m.meta.hitl_id) return m.meta.hitl_id;
      if (m.meta.hitl && m.meta.hitl_id) return m.meta.hitl_id;
    }
    return '';
  }

  function genSubmitId(){
    try{
      if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    }catch(_e){}
    return `hitl_${Date.now()}_${Math.random().toString(16).slice(2)}`;
  }

  async function hitlSubmit(conversationId, payload){
    const hitl_id = payload.hitl_id || getHitlIdForActiveConversation();
    if (!hitl_id){
      throw new Error('HITL non attiva o hitl_id mancante');
    }

    const submit_id = payload.submit_id || genSubmitId();
    const type = payload.type || 'text';
    const button_id = payload.button_id || '';
    const text = payload.text || '';
    const file = payload.file || null;

    if (file){
      const fd = new FormData();
      fd.append('hitl_id', hitl_id);
      fd.append('submit_id', submit_id);
      fd.append('type', type);
      if (button_id) fd.append('button_id', button_id);
      fd.append('text', text);
      fd.append('files', file);
      return await api(`/cc/conversations/${conversationId}/hitl/submit`, {
        method:'POST',
        body: fd,
      });
    }

    return await api(`/cc/conversations/${conversationId}/hitl/submit`, {
      method:'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ hitl_id, submit_id, type, button_id, text })
    });
  }

  function getLastServerMessageId(messages){
    let max = 0;
    (messages || []).forEach(m => {
      const id = Number(m && m.id);
      if (Number.isFinite(id) && id > max) max = id;
    });
    return max;
  }

  function mergeServerMessages(newMessages){
    if (!Array.isArray(newMessages) || !newMessages.length) return 0;
    const existingIds = new Set(
      state.messages
        .map(m => Number(m && m.id))
        .filter(n => Number.isFinite(n) && n > 0)
    );

    let added = 0;
    for (const m of newMessages){
      const id = Number(m && m.id);
      if (!Number.isFinite(id) || id <= 0) continue;
      if (existingIds.has(id)) continue;
      state.messages.push({
        id,
        role: m.role,
        content: m.content,
        meta: (m.meta !== undefined) ? m.meta : null,
        created_at: m.created_at,
      });
      existingIds.add(id);
      added++;
      if (id > state.poll.lastServerId) state.poll.lastServerId = id;
    }
    return added;
  }

  function syncHitlStateFromUpdates(updatePayload){
    if (!updatePayload) return;
    const conv = activeConversation();
    if (!conv) return;

    const hitl = updatePayload.hitl || (updatePayload.conversation && updatePayload.conversation.hitl) || null;
    if (hitl && typeof hitl.active !== 'undefined'){
      const active = !!hitl.active;
      conv.hitl_active = active ? 1 : 0;
      conv.hitl_id = hitl.hitl_id || '';
      patchConversation(conv);
    }
  }

  function startPolling(){
    if (state.poll.enabled) return;
    state.poll.enabled = true;
    const tick = async ()=>{
      if (!state.poll.enabled) return;
      try{ await pollUpdates(false); }catch(_e){};
    };
    state.poll.timer = setInterval(tick, state.poll.intervalMs);
    // primo giro quasi subito (così non aspetti 1s quando sei in HITL)
    setTimeout(tick, 50);
  }

  function stopPolling(){
    state.poll.enabled = false;
    if (state.poll.timer){
      clearInterval(state.poll.timer);
      state.poll.timer = null;
    }
  }

  function syncPollingForActiveConversation(){
    const conv = activeConversation();
    if (!conv){
      stopPolling();
      return;
    }
    if (isHitlActive(conv)){
      startPolling();
    } else {
      stopPolling();
    }
  }

  async function pollUpdates(force = false){
    const conv = activeConversation();
    if (!conv) return;

    const conversationId = conv.id;
    const after = state.poll.lastServerId || getLastServerMessageId(state.messages) || 0;
    state.poll.after = after;

    const headers = {
      'X-GRGW-PREFER-304': '1',
    };
    if (state.poll.etag && !force) headers['If-None-Match'] = state.poll.etag;

    state.poll.inFlight = true;

    let res;
    try {
      res = await apiRaw(`/cc/conversations/${conversationId}/updates?after=${encodeURIComponent(String(after))}`, {
        method: 'GET',
        headers,
      });
    } catch (err) {
      state.poll.inFlight = false;
      state.poll.lastErrAt = new Date().toISOString();
      state.poll.consecutiveErrors = (state.poll.consecutiveErrors || 0) + 1;
      debugEvent('poll_error', { message: String(err?.message || err) });
      return 0;
    }

    state.poll.inFlight = false;

    const et = res.headers ? (res.headers.get('ETag') || res.headers.get('etag')) : '';
    if (et) state.poll.etag = et;

    if (res.status === 304){
      state.poll.lastOkAt = new Date().toISOString();
      state.poll.consecutiveErrors = 0;
      return 0;
    }

    if (!res.ok){
      state.poll.lastErrAt = new Date().toISOString();
      state.poll.consecutiveErrors = (state.poll.consecutiveErrors || 0) + 1;
      return 0;
    }

    state.poll.lastOkAt = new Date().toISOString();
    state.poll.consecutiveErrors = 0;

    const data = res.data;
    if (!data) return 0;

    if (data.not_modified === true){
      syncHitlStateFromUpdates(data);
      syncPollingForActiveConversation();
      return 0;
    }

    let added = 0;
    if (Array.isArray(data.messages) && data.messages.length){
      added = mergeServerMessages(data.messages);
      if (added > 0) {
        dropOptimisticMessages();
        renderMessagesOnly();
        debugEvent('poll_new_messages', { count: added });
      }
    }

    syncHitlStateFromUpdates(data);
    syncPollingForActiveConversation();
    return added;
  }

  // --- Init
  (async function init(){
    initWebhookSelection();
    ensureDefaults();
    await loadDebugInfo();
    await loadConversations(false);

    // choose first conversation by default
    state.activeId = state.conversations[0]?.id || null;

    render();

    if (state.activeId){
      await openConversation(state.activeId);
    }
  })();

})();
