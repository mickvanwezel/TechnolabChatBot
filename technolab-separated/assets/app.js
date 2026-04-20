// ── STATE ──
let uploadedDocs = [];
let isLoading = false;

// ── AUTO-RESIZE TEXTAREA ──
const ta = document.getElementById('userInput');
ta.addEventListener('input', () => {
  ta.style.height = 'auto';
  ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';
});
ta.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});

// ── DRAG & DROP ──
const zone = document.getElementById('uploadZone');
zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
zone.addEventListener('drop', e => {
  e.preventDefault(); zone.classList.remove('dragover');
  handleFiles(e.dataTransfer.files);
});
document.getElementById('fileInput').addEventListener('change', e => handleFiles(e.target.files));

// ── UPLOAD ──
async function handleFiles(files) {
  for (const file of files) {
    if (!file.name.endsWith('.pdf')) {
      showToast('⚠️ Alleen PDF-bestanden zijn toegestaan', 'error'); continue;
    }
    if (uploadedDocs.find(d => d.name === file.name)) {
      showToast(`"${file.name}" is al geüpload`, 'warn'); continue;
    }
    await uploadFile(file);
  }
}

async function uploadFile(file) {
  showProcessing(`"${file.name}" verwerken...`);
  const formData = new FormData();
  formData.append('pdf', file);

  try {
    const res = await fetch('api/upload.php', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
      uploadedDocs.push({ name: file.name, id: data.doc_id, chunks: data.chunks });
      renderDocList();
      showToast(`✅ "${file.name}" toegevoegd (${data.chunks} stukken)`, 'success');
    } else {
      showToast(`❌ Fout: ${data.error}`, 'error');
    }
  } catch (e) {
    showToast('❌ Upload mislukt. Probeer opnieuw.', 'error');
  } finally {
    hideProcessing();
    document.getElementById('fileInput').value = '';
  }
}

function renderDocList() {
  const list = document.getElementById('docList');
  if (!uploadedDocs.length) {
    list.innerHTML = '<div class="empty-docs">Nog geen documenten<br>geüpload</div>';
    return;
  }
  list.innerHTML = uploadedDocs.map((d, i) => `
    <div class="doc-item">
      <span class="doc-icon">📄</span>
      <span class="doc-name" title="${d.name}">${d.name}</span>
      <span style="font-size:10px;color:var(--text-muted);font-weight:700">${d.chunks}</span>
      <button class="doc-delete" onclick="removeDoc(${i})" title="Verwijderen">✕</button>
    </div>
  `).join('');
}

function removeDoc(i) {
  const doc = uploadedDocs[i];
  fetch('api/delete.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ doc_id: doc.id }) });
  uploadedDocs.splice(i, 1);
  renderDocList();
}

// ── SEND MESSAGE ──
async function sendMessage() {
  const input = document.getElementById('userInput');
  const q = input.value.trim();
  if (!q || isLoading) return;
  if (!uploadedDocs.length) {
    addBotMsg('📄 Upload eerst een of meer PDF-documenten zodat ik kan antwoorden!', [], true);
    return;
  }

  addUserMsg(q);
  input.value = ''; input.style.height = 'auto';
  setLoading(true);
  showTyping();

  try {
    const res = await fetch('api/chat.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ question: q, doc_ids: uploadedDocs.map(d => d.id) })
    });
    const data = await res.json();
    hideTyping();
    if (data.answer) {
      addBotMsg(data.answer, data.sources || []);
    } else {
      addBotMsg('😕 ' + (data.error || 'Er ging iets mis. Probeer opnieuw.'), [], true);
    }
  } catch (e) {
    hideTyping();
    addBotMsg('❌ Verbindingsfout. Controleer of de server actief is.', [], true);
  } finally {
    setLoading(false);
  }
}

function sendQuick(btn) {
  document.getElementById('userInput').value = btn.textContent;
  sendMessage();
}

// ── MESSAGE RENDERING ──
let typingEl = null;

function addUserMsg(text) {
  const msgs = document.getElementById('messages');
  const w = msgs.querySelector('.welcome-msg');
  if (w) w.remove();
  const el = document.createElement('div');
  el.className = 'message user';
  el.innerHTML = `
    <div class="msg-avatar">🧑</div>
    <div class="msg-bubble">${escHtml(text)}</div>
  `;
  msgs.appendChild(el);
  scrollDown();
}

function addBotMsg(text, sources = [], isError = false) {
  const msgs = document.getElementById('messages');
  const el = document.createElement('div');
  el.className = 'message bot';
  const sourcesHtml = sources.length
    ? `<div class="msg-sources">${sources.map(s => `<span class="source-tag">📄 ${escHtml(s)}</span>`).join('')}</div>`
    : '';
  el.innerHTML = `
    <div class="msg-avatar">🤖</div>
    <div class="msg-bubble${isError ? ' error' : ''}">${formatText(text)}${sourcesHtml}</div>
  `;
  msgs.appendChild(el);
  scrollDown();
}

function showTyping() {
  const msgs = document.getElementById('messages');
  typingEl = document.createElement('div');
  typingEl.className = 'message bot';
  typingEl.id = 'typingIndicator';
  typingEl.innerHTML = `
    <div class="msg-avatar">🤖</div>
    <div class="msg-bubble">
      <div class="typing-indicator">
        <div class="typing-dot"></div>
        <div class="typing-dot"></div>
        <div class="typing-dot"></div>
      </div>
    </div>
  `;
  msgs.appendChild(typingEl);
  scrollDown();
}

function hideTyping() {
  const t = document.getElementById('typingIndicator');
  if (t) t.remove();
}

// ── HELPERS ──
function scrollDown() {
  const msgs = document.getElementById('messages');
  msgs.scrollTop = msgs.scrollHeight;
}

function setLoading(v) {
  isLoading = v;
  document.getElementById('sendBtn').disabled = v;
}

function showProcessing(msg) {
  const b = document.getElementById('processingBanner');
  document.getElementById('processingText').textContent = msg;
  b.classList.add('active');
}

function hideProcessing() {
  document.getElementById('processingBanner').classList.remove('active');
}

function escHtml(t) {
  return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function formatText(t) {
  return escHtml(t)
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\n/g, '<br>');
}

function showToast(msg, type = 'info') {
  const t = document.createElement('div');
  t.style.cssText = `
    position:fixed;bottom:24px;right:24px;z-index:9999;
    background:${type==='error'?'#fef2f2':type==='success'?'#f0fdf4':'#fffbeb'};
    border:2px solid ${type==='error'?'#fca5a5':type==='success'?'var(--green)':'var(--yellow)'};
    color:var(--text);padding:12px 18px;border-radius:12px;
    font-size:13px;font-weight:700;font-family:'Nunito',sans-serif;
    box-shadow:var(--shadow);animation:fadeUp 0.3s ease;
    max-width:320px;
  `;
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 3500);
}
