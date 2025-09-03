<?php
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
?>
<!doctype html>
<html lang="ku" dir="auto">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Simple Recorder (WAV 48k mono)</title>
<style>
  :root{
    --bg:#f6f7f9; --text:#111827; --muted:#6b7280; --border:#e5e7eb; --card:#ffffff;
    --green:#2bbf6a; --green-dark:#249a56; --red:#e74c3c; --red-dark:#c73d2f;
    --olive:#8a7d36; --olive-dark:#73692d; --cyan:#11b5e5; --cyan-dark:#0e9ec9;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
  .wrap{max-width:900px;margin:0 auto;padding:20px}
  .card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:24px;text-align:center}
  .big{font-size:clamp(28px,5.4vw,48px);line-height:1.35;font-weight:800;margin:0 0 8px}
  .counter{color:var(--muted);font-weight:700;margin-bottom:14px}
  .row{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:10px}
  .btn{border:0;color:#fff;font-weight:800;cursor:pointer;padding:12px 18px;border-radius:12px;font-size:16px}
  .btn:active{transform:translateY(1px)}
  .btn.skip{background:var(--olive)} .btn.skip:hover{background:var(--olive-dark)}
  .btn.send{background:var(--cyan)}  .btn.send:hover{background:var(--cyan-dark)}
  .btn.rec{ background:var(--green)} .btn.rec:hover{ background:var(--green-dark)}
  .btn.stop{background:var(--red)}   .btn.stop:hover{background:var(--red-dark)}
  .btn.disabled{opacity:.55;pointer-events:none}
  audio{width:min(680px,100%);margin:12px auto 0;display:none}
  .meta{margin-top:8px;color:var(--muted);font-size:13px}
</style>
</head>
<body>
<div class="wrap">
  <section class="card">
    <p id="bigText" class="big">—</p>
    <div id="counter" class="counter">Recorded 0 / 0</div>

    <div class="row">
      <button id="btnSkip"  class="btn skip" type="button">Skip ▷</button>
      <button id="btnSend"  class="btn send disabled" type="button">Send ⬆</button>
      <button id="btnRecord" class="btn rec"   type="button"><span id="recLabel">Record</span> 🎙️</button>
    </div>

    <audio id="player" controls></audio>
    <div id="status" class="meta">Ready.</div>
  </section>
</div>

<script>
/* ---------------- Load texts.json ---------------- */
const FALLBACK_TEXTS = [
  "نمونەی دەقی گەورە کاتێک texts.json بەردەست نیە.",
  "The quick brown fox jumps over the lazy dog."
];
let TEXTS = FALLBACK_TEXTS, idx = 0;

// per-text completion flags (local only)
let FLAGS = [];

async function loadTexts(){
  try{
    const r = await fetch('texts.json', {cache:'no-store'});
    if(!r.ok) throw 0;
    const arr = await r.json();
    if(Array.isArray(arr) && arr.length) TEXTS = arr.map(x => String(x));
  }catch{}
  // init or restore flags
  const cached = JSON.parse(localStorage.getItem('sr_flags') || '[]');
  FLAGS = (Array.isArray(cached) && cached.length === TEXTS.length)
    ? cached
    : Array(TEXTS.length).fill(false);
  saveFlags();
}

function saveFlags(){ localStorage.setItem('sr_flags', JSON.stringify(FLAGS)); }
function countDone(){ return FLAGS.filter(Boolean).length; }
function updateCounter(){
  const el = document.getElementById('counter');
  el.textContent = `Recorded ${countDone()} / ${TEXTS.length}`;
}

function isRTL(s){ return /[\u0600-\u06FF]/.test(s); }
function showCurrent(){
  const t = TEXTS[idx] || "—";
  const el = document.getElementById('bigText');
  el.textContent = t; el.dir = isRTL(t) ? 'rtl' : 'ltr';
  player.style.display='none'; player.src='';
  btnSend.classList.add('disabled');
  statusEl.textContent = 'Ready.';
  updateCounter();
}

/* ---- SHA-1 helper so server can verify which text this audio belongs to ---- */
async function sha1Hex(str){
  const enc = new TextEncoder().encode(str);
  const buf = await crypto.subtle.digest('SHA-1', enc);
  const arr = Array.from(new Uint8Array(buf));
  return arr.map(b => b.toString(16).padStart(2,'0')).join('');
}

/* ---------------- Elements ---------------- */
const $ = id => document.getElementById(id);
const btnRec = $('btnRecord'), recLabel = $('recLabel'), btnSend = $('btnSend'), btnSkip = $('btnSkip');
const player = $('player'), statusEl = $('status');

/* ---------------- Recording state ---------------- */
let stream=null, rec=null, chunks=[], rawBlob=null, wavBlob=null;
let timer=null, startT=0;

/* ---------------- Helpers ---------------- */
function fmt(ms){ const s=Math.floor(ms/1000), m=Math.floor(s/60); return m+":"+String(s%60).padStart(2,'0'); }
function startTimer(){ startT=performance.now(); timer=setInterval(()=>{ statusEl.textContent="Recording… "+fmt(performance.now()-startT); }, 250); }
function stopTimer(){ clearInterval(timer); timer=null; }

/* WAV encoder (PCM16) */
function encodeWAV(samples, sampleRate){
  const numChannels = 1, bytesPerSample = 2;
  const blockAlign = numChannels * bytesPerSample;
  const byteRate   = sampleRate * blockAlign;
  const dataSize   = samples.length * bytesPerSample;

  const buffer = new ArrayBuffer(44 + dataSize);
  const view   = new DataView(buffer);
  let p = 0;
  function wstr(s){ for(let i=0;i<s.length;i++) view.setUint8(p++, s.charCodeAt(i)); }

  wstr('RIFF');
  view.setUint32(p, 36 + dataSize, true); p+=4;
  wstr('WAVE');
  wstr('fmt '); view.setUint32(p, 16, true); p+=4;
  view.setUint16(p, 1, true); p+=2;
  view.setUint16(p, numChannels, true); p+=2;
  view.setUint32(p, sampleRate, true); p+=4;
  view.setUint32(p, byteRate, true); p+=4;
  view.setUint16(p, blockAlign, true); p+=2;
  view.setUint16(p, 16, true); p+=2;
  wstr('data'); view.setUint32(p, dataSize, true); p+=4;

  for(let i=0;i<samples.length;i++, p+=2){
    const s = Math.max(-1, Math.min(1, samples[i]));
    view.setInt16(p, s < 0 ? s * 0x8000 : s * 0x7FFF, true);
  }
  return new Blob([view], {type:'audio/wav'});
}
function downmixToMono(buffer){
  const chs = buffer.numberOfChannels;
  if (chs === 1) return buffer.getChannelData(0).slice(0);
  const len = buffer.length;
  const tmp = new Float32Array(len);
  for(let c=0;c<chs;c++){
    const data = buffer.getChannelData(c);
    for(let i=0;i<len;i++) tmp[i] += data[i];
  }
  for(let i=0;i<len;i++) tmp[i] /= chs;
  return tmp;
}
async function resampleTo48k(mono, srcRate){
  const targetRate = 48000;
  if (srcRate === targetRate) return mono;
  if (window.OfflineAudioContext || window.webkitOfflineAudioContext){
    const OAC = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    const frames = Math.ceil(mono.length * targetRate / srcRate);
    const ctx = new OAC(1, frames, targetRate);
    const buf = ctx.createBuffer(1, mono.length, srcRate);
    buf.getChannelData(0).set(mono);
    const src = ctx.createBufferSource(); src.buffer = buf;
    src.connect(ctx.destination); src.start();
    const rendered = await ctx.startRendering();
    return rendered.getChannelData(0).slice(0);
  }
  const ratio = srcRate / targetRate;
  const outLen = Math.round(mono.length / ratio);
  const out = new Float32Array(outLen);
  for (let i=0; i<outLen; i++){
    const pos = i * ratio;
    const i1 = Math.floor(pos), i2 = Math.min(i1+1, mono.length-1);
    const frac = pos - i1;
    out[i] = mono[i1]*(1-frac) + mono[i2]*frac;
  }
  return out;
}
async function blobToWav48k(blob){
  const arr = await blob.arrayBuffer();
  const AC = window.AudioContext || window.webkitAudioContext;
  const audioCtx = new AC();
  const decoded = await audioCtx.decodeAudioData(arr);
  const mono = downmixToMono(decoded);
  const resamp = await resampleTo48k(mono, decoded.sampleRate);
  audioCtx.close();
  return encodeWAV(resamp, 48000);
}

/* Mic access */
async function ensureMic(){
  if (stream) return true;
  try{
    stream = await navigator.mediaDevices.getUserMedia({audio:true});
    return true;
  }catch(e){
    statusEl.textContent = 'Mic error: '+e.name;
    return false;
  }
}

/* ----------- Record / Stop ----------- */
btnRec.addEventListener('click', async () => {
  const ok = await ensureMic(); if (!ok) return;
  if (!rec || rec.state === 'inactive'){
    chunks = []; rawBlob=null; wavBlob=null;
    let mime = 'audio/webm;codecs=opus';
    try { rec = new MediaRecorder(stream, {mimeType:mime}); }
    catch (err) { rec = new MediaRecorder(stream); }

    rec.ondataavailable = e => { if (e.data && e.data.size>0) chunks.push(e.data); };
    rec.onstart = () => {
      btnRec.classList.remove('rec'); btnRec.classList.add('stop'); recLabel.textContent = 'Stop';
      player.style.display = 'none'; btnSend.classList.add('disabled');
      statusEl.textContent = 'Recording…'; startTimer();
    };
    rec.onstop = async () => {
      stopTimer();
      rawBlob = new Blob(chunks, {type: rec.mimeType || 'audio/webm'});
      try{
        wavBlob = await blobToWav48k(rawBlob);
        player.src = URL.createObjectURL(wavBlob);
        player.style.display = 'block';
        btnSend.classList.toggle('disabled', !wavBlob.size);
        statusEl.textContent = 'Stopped. Listen, then Send.';
      }catch(err){
        statusEl.textContent = 'Convert error: ' + err.message;
      }
      btnRec.classList.remove('stop'); btnRec.classList.add('rec'); recLabel.textContent = 'Record';
    };
    rec.start();
  } else {
    try { rec.requestData && rec.requestData(); } catch {}
    rec.stop();
  }
});

/* ----------- Send (fixed filename + Excel linkage) ----------- */
btnSend.addEventListener('click', async () => {
  if (btnSend.classList.contains('disabled')) return;
  if (!wavBlob || !wavBlob.size) { statusEl.textContent = 'No audio to send.'; return; }

  statusEl.textContent = 'Uploading…';
  const filename = 'audio-file.wav';   // fixed filename (will overwrite)

  // identifiers to map back to Excel: 1-based index and hash of text
  const text = TEXTS[idx] || '';
  const text_id = idx + 1;
  const text_sha1 = await sha1Hex(text);

  const fd = new FormData();
  fd.append('prompt_id', String(text_id));
  fd.append('prompt_text', text);
  fd.append('text_sha1', text_sha1);
  fd.append('filename', filename);
  fd.append('audio', wavBlob, filename);

  try{
    const r = await fetch('save_recording.php', {method:'POST', body:fd});
    const t = await r.text(); let j={}; try{ j=JSON.parse(t);}catch{}
    if(!r.ok || !j.ok){ statusEl.textContent = 'Send failed: ' + (j.error || ('HTTP '+r.status)); return; }

    // mark as recorded locally for the counter
    FLAGS[idx] = true; saveFlags(); updateCounter();

    statusEl.textContent = 'Saved ✓ ' + j.path;
    if (idx < TEXTS.length-1) idx++;
    showCurrent();
  }catch(e){
    statusEl.textContent = 'Send error: ' + e.message;
  }
});

/* ----------- Skip ----------- */
btnSkip.addEventListener('click', () => {
  if (idx < TEXTS.length-1) { idx++; showCurrent(); }
});

/* ----------- Boot ----------- */
(async () => { await loadTexts(); showCurrent(); })();
</script>
</body>
</html>
