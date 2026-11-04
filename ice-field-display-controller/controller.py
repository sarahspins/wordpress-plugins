#!/usr/bin/env python3
"""Local controller and display pages for Ice & Field display nodes."""

from __future__ import annotations

import argparse
import hashlib
import html
import json
import os
import re
import shutil
import socket
import subprocess
import tempfile
import threading
import time
import uuid
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, unquote, urlparse

APP_NAME = "Ice & Field Display Controller"
VERSION = "0.1.0-dev"
OUTPUT_RE = re.compile(r"^[A-Za-z0-9._-]{1,64}$")
KINDS = {"standby", "web", "image", "video"}
CHANNELS = {
    "schedule": "Schedule",
    "pricing": "Pricing",
    "rules": "Public Skating Rules",
    "video_for_screens": "Video for Screens",
}
MEDIA_ID_RE = re.compile(r"^[a-f0-9]{32}$")
MEDIA_TYPES = {
    ".mp4": ("video", "video/mp4"),
    ".webm": ("video", "video/webm"),
    ".png": ("image", "image/png"),
    ".jpg": ("image", "image/jpeg"),
    ".jpeg": ("image", "image/jpeg"),
    ".webp": ("image", "image/webp"),
    ".svg": ("image", "image/svg+xml"),
}
MAX_UPLOAD_BYTES = 8 * 1024 * 1024 * 1024
STORAGE_RESERVE_BYTES = 1024 * 1024 * 1024


def state_directory() -> Path:
    configured = os.environ.get("IFDC_STATE_DIR")
    return Path(configured).expanduser() if configured else Path.home() / ".local/share/ice-field-display-controller"


class StateStore:
    def __init__(self, path: Path):
        self.path = path
        self.lock = threading.RLock()
        self.path.parent.mkdir(parents=True, exist_ok=True)

    def default(self) -> dict:
        return {
            "schema": 1,
            "revision": 1,
            "node": {"id": socket.gethostname(), "name": "Node 1"},
            "channels": {
                key: {"label": label, "kind": "standby", "source": ""}
                for key, label in CHANNELS.items()
            },
            "assignments": {},
        }

    def load(self) -> dict:
        with self.lock:
            if not self.path.exists():
                state = self.default()
                self.save(state)
                return state
            try:
                state = json.loads(self.path.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                broken = self.path.with_suffix(f".broken-{int(time.time())}.json")
                try:
                    self.path.replace(broken)
                except OSError:
                    pass
                state = self.default()
                self.save(state)
            channels = state.setdefault("channels", {})
            for key, label in CHANNELS.items():
                channels.setdefault(key, {"label": label, "kind": "standby", "source": ""})
            return state

    def save(self, state: dict) -> None:
        with self.lock:
            self.path.parent.mkdir(parents=True, exist_ok=True)
            fd, tmp_name = tempfile.mkstemp(prefix="state-", suffix=".json", dir=self.path.parent)
            try:
                with os.fdopen(fd, "w", encoding="utf-8") as handle:
                    json.dump(state, handle, indent=2, sort_keys=True)
                    handle.write("\n")
                    handle.flush()
                    os.fsync(handle.fileno())
                os.replace(tmp_name, self.path)
            finally:
                if os.path.exists(tmp_name):
                    os.unlink(tmp_name)

    @staticmethod
    def validate_content(kind: str, source: str) -> tuple[str, str]:
        if kind not in KINDS:
            raise ValueError("Invalid content type.")
        source = source.strip()
        if kind in {"web", "image", "video"}:
            local_media = source.startswith("/media/") and MEDIA_ID_RE.fullmatch(source[len("/media/"):])
            parsed = urlparse(source)
            if not local_media and (parsed.scheme not in {"http", "https"} or not parsed.netloc):
                raise ValueError("Content must use a complete http:// or https:// address.")
        else:
            source = ""
        return kind, source

    def assign(self, output: str, kind: str, source: str, label: str, channel: str = "") -> dict:
        if not OUTPUT_RE.fullmatch(output):
            raise ValueError("Invalid output name.")
        channel = channel.strip()
        if channel and channel not in CHANNELS:
            raise ValueError("Invalid display channel.")
        kind, source = self.validate_content(kind, source) if not channel else ("standby", "")
        label = label.strip()[:80] or output
        state = self.load()
        state.setdefault("assignments", {})[output] = {
            "kind": kind,
            "source": source,
            "label": label,
            "channel": channel,
            "updated_at": int(time.time()),
        }
        state["revision"] = int(state.get("revision", 0)) + 1
        self.save(state)
        return state

    def save_channel(self, channel: str, kind: str, source: str) -> dict:
        if channel not in CHANNELS:
            raise ValueError("Invalid display channel.")
        kind, source = self.validate_content(kind, source)
        state = self.load()
        state.setdefault("channels", {})[channel] = {
            "label": CHANNELS[channel], "kind": kind, "source": source,
            "updated_at": int(time.time()),
        }
        state["revision"] = int(state.get("revision", 0)) + 1
        self.save(state)
        return state

    @staticmethod
    def resolve_assignment(state: dict, assignment: dict) -> dict:
        resolved = dict(assignment)
        channel = resolved.get("channel", "")
        if channel in CHANNELS:
            content = state.get("channels", {}).get(channel, {})
            resolved["kind"] = content.get("kind", "standby")
            resolved["source"] = content.get("source", "")
            resolved["channel_label"] = CHANNELS[channel]
        return resolved


class MediaStore:
    def __init__(self, directory: Path):
        self.directory = directory
        self.index_path = directory / "media.json"
        self.lock = threading.RLock()
        self.directory.mkdir(parents=True, exist_ok=True)

    def _load(self) -> dict:
        with self.lock:
            if not self.index_path.exists():
                return {"schema": 1, "items": {}}
            try:
                return json.loads(self.index_path.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                return {"schema": 1, "items": {}}

    def _save(self, index: dict) -> None:
        with self.lock:
            fd, temp_name = tempfile.mkstemp(prefix="media-index-", suffix=".json", dir=self.directory)
            try:
                with os.fdopen(fd, "w", encoding="utf-8") as handle:
                    json.dump(index, handle, indent=2, sort_keys=True)
                    handle.write("\n")
                    handle.flush()
                    os.fsync(handle.fileno())
                os.replace(temp_name, self.index_path)
            finally:
                if os.path.exists(temp_name):
                    os.unlink(temp_name)

    def list(self) -> list[dict]:
        index = self._load()
        items = []
        changed = False
        for media_id, item in list(index.get("items", {}).items()):
            path = self.directory / item.get("stored_name", "")
            if not path.is_file():
                del index["items"][media_id]
                changed = True
                continue
            item = dict(item)
            item["url"] = f"/media/{media_id}"
            items.append(item)
        if changed:
            self._save(index)
        return sorted(items, key=lambda item: item.get("uploaded_at", 0), reverse=True)

    def get(self, media_id: str) -> tuple[dict, Path] | None:
        if not MEDIA_ID_RE.fullmatch(media_id):
            return None
        item = self._load().get("items", {}).get(media_id)
        if not item:
            return None
        path = self.directory / item.get("stored_name", "")
        return (item, path) if path.is_file() else None

    def upload(self, source, original_name: str, length: int) -> dict:
        original_name = Path(original_name).name.strip()[:180]
        suffix = Path(original_name).suffix.lower()
        if suffix not in MEDIA_TYPES:
            raise ValueError("Supported files: MP4, WebM, PNG, JPEG, WebP, and SVG.")
        if length <= 0 or length > MAX_UPLOAD_BYTES:
            raise ValueError("The upload is empty or exceeds the 8 GB safety limit.")
        free = shutil.disk_usage(self.directory).free
        if length + STORAGE_RESERVE_BYTES > free:
            raise ValueError("Not enough free storage. The node always reserves at least 1 GB.")
        media_id = uuid.uuid4().hex
        stored_name = f"{media_id}{suffix}"
        final_path = self.directory / stored_name
        fd, temp_name = tempfile.mkstemp(prefix="upload-", suffix=".partial", dir=self.directory)
        digest = hashlib.sha256()
        header = bytearray()
        remaining = length
        try:
            with os.fdopen(fd, "wb") as handle:
                while remaining:
                    chunk = source.read(min(1024 * 1024, remaining))
                    if not chunk:
                        raise ValueError("The upload ended before the complete file arrived.")
                    handle.write(chunk)
                    digest.update(chunk)
                    if len(header) < 4096:
                        header.extend(chunk[:4096 - len(header)])
                    remaining -= len(chunk)
                handle.flush()
                os.fsync(handle.fileno())
            if not valid_media_signature(suffix, bytes(header)):
                raise ValueError("The file contents do not match its file extension.")
            os.replace(temp_name, final_path)
        finally:
            if os.path.exists(temp_name):
                os.unlink(temp_name)
        kind, content_type = MEDIA_TYPES[suffix]
        item = {"id": media_id, "name": original_name, "stored_name": stored_name, "kind": kind, "content_type": content_type, "size": length, "sha256": digest.hexdigest(), "uploaded_at": int(time.time()), "url": f"/media/{media_id}"}
        with self.lock:
            index = self._load()
            index.setdefault("items", {})[media_id] = {key: value for key, value in item.items() if key != "url"}
            self._save(index)
        return item

    def delete(self, media_id: str) -> bool:
        with self.lock:
            index = self._load()
            item = index.get("items", {}).pop(media_id, None)
            if not item:
                return False
            path = self.directory / item.get("stored_name", "")
            try:
                path.unlink(missing_ok=True)
            finally:
                self._save(index)
            return True


def valid_media_signature(suffix: str, header: bytes) -> bool:
    if suffix == ".mp4":
        return len(header) >= 12 and header[4:8] == b"ftyp"
    if suffix == ".webm":
        return header.startswith(b"\x1a\x45\xdf\xa3")
    if suffix == ".png":
        return header.startswith(b"\x89PNG\r\n\x1a\n")
    if suffix in {".jpg", ".jpeg"}:
        return header.startswith(b"\xff\xd8\xff")
    if suffix == ".webp":
        return len(header) >= 12 and header[:4] == b"RIFF" and header[8:12] == b"WEBP"
    if suffix == ".svg":
        cleaned = header.lstrip(b"\xef\xbb\xbf\x00\t\r\n ").lower()
        return b"<svg" in cleaned
    return False


def detect_outputs() -> list[dict]:
    try:
        result = subprocess.run(
            ["wlr-randr"], capture_output=True, text=True, timeout=5, check=False
        )
    except (OSError, subprocess.SubprocessError):
        return []
    if result.returncode != 0:
        return []
    outputs: list[dict] = []
    current: dict | None = None
    mode_re = re.compile(r"^\s+(\d+)x(\d+) px, .*\(.*current.*\)")
    for line in result.stdout.splitlines():
        if line and not line[0].isspace():
            name = line.split(" ", 1)[0]
            current = {"name": name, "description": line[len(name):].strip(), "enabled": False, "width": 0, "height": 0, "x": 0, "y": 0}
            outputs.append(current)
            continue
        if current is None:
            continue
        stripped = line.strip()
        if stripped == "Enabled: yes":
            current["enabled"] = True
        elif stripped.startswith("Position:"):
            try:
                position = stripped.split(":", 1)[1].strip().split(",")
                current["x"], current["y"] = int(position[0]), int(position[1])
            except (ValueError, IndexError):
                pass
        else:
            match = mode_re.match(line)
            if match:
                current["width"], current["height"] = int(match.group(1)), int(match.group(2))
    return [item for item in outputs if item["enabled"]]


def json_bytes(value: object) -> bytes:
    return json.dumps(value, separators=(",", ":")).encode("utf-8")


def page_shell(title: str, body: str, script: str = "") -> bytes:
    return f"""<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{html.escape(title)}</title>
<style>
:root{{--navy:#064b75;--blue:#087dbb;--orange:#ff7830;--ink:#183241;--muted:#667986;--paper:#f4f7f9;--line:#d9e2e7}}
*{{box-sizing:border-box}} body{{margin:0;background:var(--paper);color:var(--ink);font:16px/1.45 system-ui,-apple-system,sans-serif}}
header{{background:linear-gradient(135deg,var(--navy),var(--blue));color:white;padding:26px clamp(20px,5vw,64px)}}
header h1{{margin:0;font-size:clamp(25px,4vw,40px)}} header p{{margin:5px 0 0;opacity:.86}}
main{{max-width:1100px;margin:0 auto;padding:28px 20px 60px}} .summary{{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}}
.pill{{background:white;border:1px solid var(--line);border-radius:999px;padding:8px 14px}}
.grid{{display:grid;grid-template-columns:repeat(auto-fit,minmax(310px,1fr));gap:20px}} .wide{{margin-bottom:20px}}
.card{{background:white;border:1px solid var(--line);border-radius:16px;padding:20px;box-shadow:0 8px 24px #064b7510}}
.card h2{{margin:0 0 5px;color:var(--navy)}} .muted{{color:var(--muted)}} label{{display:block;font-weight:700;margin:14px 0 5px}}
input,select{{width:100%;font:inherit;padding:11px;border:1px solid #aebdc6;border-radius:8px;background:white}}
button{{border:0;border-radius:9px;padding:11px 16px;background:var(--orange);color:white;font:700 15px system-ui;cursor:pointer;margin-top:16px}}
button:hover{{filter:brightness(.95)}} .return{{display:inline-block;margin:16px 0 0 10px;color:var(--navy);font-weight:700}} .status{{min-height:23px;margin-top:10px;font-weight:650}} .error{{color:#a32b22}} .success{{color:#287d4d}}
.secondary{{background:var(--navy)}} .danger{{background:#a32b22}} button:disabled{{opacity:.55;cursor:not-allowed}}
.upload-row{{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end}} .upload-row button{{margin:0}} progress{{width:100%;height:18px;margin-top:14px;accent-color:var(--orange)}}
.media-grid{{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px;margin-top:18px}} .media-item{{border:1px solid var(--line);border-radius:12px;overflow:hidden;background:#f8fafb}}
.media-preview{{height:130px;background:#031f31;display:grid;place-items:center}} .media-preview img,.media-preview video{{width:100%;height:100%;object-fit:contain}} .media-info{{padding:12px}} .media-name{{font-weight:800;overflow-wrap:anywhere}}
.media-actions{{display:flex;gap:8px;flex-wrap:wrap}} .media-actions button{{margin-top:10px;padding:8px 10px;font-size:13px}}
code{{font-size:.9em;background:#eef3f6;padding:2px 5px;border-radius:5px}}
@media(max-width:620px){{.upload-row{{grid-template-columns:1fr}}}}
</style></head><body><header><h1>{html.escape(title)}</h1><p>Controller and Node 1</p></header><main>{body}</main><script>{script}</script></body></html>""".encode("utf-8")


def admin_page() -> bytes:
    body = """
<div class="summary"><span class="pill" id="node">Loading node…</span><span class="pill" id="health">Checking screens…</span></div>
<section class="card wide"><h2>Local Media Library</h2><p class="muted">Upload once and play directly from this Pi, even if the website is unavailable. Supported: MP4, WebM, PNG, JPEG, WebP, and SVG.</p>
<div class="upload-row"><input id="media-file" type="file" accept=".mp4,.webm,.png,.jpg,.jpeg,.webp,.svg"><button id="media-upload" type="button">Upload to this node</button></div>
<progress id="upload-progress" max="100" value="0" hidden></progress><div id="upload-status" class="status" aria-live="polite"></div><p class="muted" id="storage"></p><div id="media-items" class="media-grid"></div></section>
<section class="wide"><h2>Display Content</h2><p class="muted">Define each familiar Ice &amp; Field display once, then choose its name on any connected screen.</p><div id="channels" class="grid"></div></section>
<h2>Connected Screens</h2>
<div id="screens" class="grid"></div>
<noscript><div class="card">JavaScript is required to manage screen assignments.</div></noscript>
"""
    script = r"""
const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
const size = bytes => {const units=['B','KB','MB','GB'];let n=Number(bytes)||0,i=0;while(n>=1024&&i<3){n/=1024;i++;}return `${n.toFixed(i?1:0)} ${units[i]}`;};
let currentData={media:[],outputs:[],state:{assignments:{}}};
async function loadStatus(){
  const response = await fetch('/api/status', {cache:'no-store'});
  const data = await response.json(); currentData=data;
  document.querySelector('#node').textContent = `${data.state.node.name} · ${data.state.node.id}`;
  document.querySelector('#health').textContent = `${data.outputs.length} connected screen${data.outputs.length === 1 ? '' : 's'}`;
  document.querySelector('#storage').textContent=`Local storage: ${size(data.storage.free)} free of ${size(data.storage.total)}`;
  renderMedia(data); renderChannels(data); renderScreens(data);
}
function renderMedia(data){
  const root=document.querySelector('#media-items');root.innerHTML='';
  if(!data.media.length){root.innerHTML='<p class="muted">No local media has been uploaded yet.</p>';return;}
  data.media.forEach(item=>{const card=document.createElement('article');card.className='media-item';const preview=item.kind==='video'?`<video src="${esc(item.url)}" muted controls preload="metadata"></video>`:`<img src="${esc(item.url)}" alt="">`;
    card.innerHTML=`<div class="media-preview">${preview}</div><div class="media-info"><div class="media-name">${esc(item.name)}</div><div class="muted">${esc(item.kind)} · ${size(item.size)}</div><div class="media-actions"><button class="secondary all">Use on all screens</button><button class="danger remove">Remove</button></div><div class="status" aria-live="polite"></div></div>`;
    card.querySelector('.all').addEventListener('click',()=>assignAll(item,card));card.querySelector('.remove').addEventListener('click',()=>removeMedia(item,card));root.appendChild(card);});
}
function mediaOptions(data){return data.media.map(item=>`<option value="${esc(item.url)}" data-kind="${esc(item.kind)}">${esc(item.name)} (${esc(item.kind)})</option>`).join('');}
function renderChannels(data){
  const labels={schedule:'Schedule',pricing:'Pricing',rules:'Public Skating Rules',video_for_screens:'Video for Screens'},root=document.querySelector('#channels');root.innerHTML='';
  Object.entries(labels).forEach(([key,label])=>{const saved=data.state.channels[key]||{kind:'standby',source:''},card=document.createElement('section');card.className='card';
    card.innerHTML=`<h2>${esc(label)}</h2><form><input type="hidden" name="channel" value="${esc(key)}"><label>Content source</label><select name="kind"><option value="standby">Not configured yet</option><option value="web">Web page</option><option value="image">Local or web image</option><option value="video">Local or web video</option></select><div class="channel-source"><label>Saved local media (optional)</label><select class="media-choice"><option value="">Enter an external address below</option>${mediaOptions(data)}</select><label>Complete web address or local media address</label><input name="source" inputmode="url" placeholder="https://…" value="${esc(saved.source||'')}"></div><button type="submit">Save ${esc(label)}</button><div class="status" aria-live="polite"></div></form>`;
    const kind=card.querySelector('select[name=kind]'),media=card.querySelector('.media-choice'),source=card.querySelector('.channel-source'),input=card.querySelector('input[name=source]');kind.value=saved.kind;if(saved.source.startsWith('/media/'))media.value=saved.source;
    const sync=()=>{source.hidden=kind.value==='standby';[...media.options].forEach((option,i)=>{if(i)option.hidden=option.dataset.kind!==kind.value;});};media.addEventListener('change',()=>{if(media.value){input.value=media.value;kind.value=media.selectedOptions[0].dataset.kind;sync();}});kind.addEventListener('change',sync);sync();card.querySelector('form').addEventListener('submit',saveChannel);root.appendChild(card);});
}
function renderScreens(data){
  const root = document.querySelector('#screens'); root.innerHTML = '';
  if(!data.outputs.length){ root.innerHTML='<section class="card"><h2>No active screens</h2><p class="muted">Connect a display, then reload this page.</p></section>'; return; }
  data.outputs.forEach((output,index)=>{
    const saved=data.state.assignments[output.name] || {kind:'standby',source:'',label:`Screen ${index+1}`};
    const card=document.createElement('section'); card.className='card';
    const choices=mediaOptions(data),channel=saved.channel||'';
    card.innerHTML=`<h2>${esc(saved.label || `Screen ${index+1}`)}</h2><p class="muted">Hardware port <code>${esc(output.name)}</code> · ${output.width}×${output.height}</p>
      <form><input type="hidden" name="output" value="${esc(output.name)}">
      <label>Screen name</label><input name="label" maxlength="80" placeholder="Lobby 1 or Warm Room 1" value="${esc(saved.label || `Screen ${index+1}`)}"><p class="muted">Use a location name people will recognize. The hardware port above remains unchanged.</p>
      <label>What should this screen show?</label><select name="channel"><option value="">Custom content</option><option value="schedule">Schedule</option><option value="pricing">Pricing</option><option value="rules">Public Skating Rules</option><option value="video_for_screens">Video for Screens</option></select>
      <div class="custom-content"><label>Custom content type</label><select name="kind">
        <option value="standby">Ice & Field standby screen</option><option value="web">Web page</option><option value="image">Image</option><option value="video">Video</option>
      </select><div class="source"><label>Saved local media (optional)</label><select class="media-choice"><option value="">Enter an external address below</option>${choices}</select><label>Complete web address or local media address</label><input name="source" inputmode="url" placeholder="https://…" value="${esc(saved.source || '')}"></div>
      </div><button type="submit">Apply to this screen</button><a class="return" href="/display/${encodeURIComponent(output.name)}">Return to screen</a><div class="status" aria-live="polite"></div></form>`;
    const channelSelect=card.querySelector('select[name=channel]'),custom=card.querySelector('.custom-content'),select=card.querySelector('select[name=kind]');channelSelect.value=channel;select.value=saved.kind;
    const source=card.querySelector('.source'),media=card.querySelector('.media-choice'),sourceInput=card.querySelector('input[name=source]');if(saved.source.startsWith('/media/'))media.value=saved.source;
    const sync=()=>{custom.hidden=!!channelSelect.value;source.hidden=select.value==='standby';[...media.options].forEach((option,i)=>{if(i)option.hidden=option.dataset.kind!==select.value;});};media.addEventListener('change',()=>{if(media.value){sourceInput.value=media.value;select.value=media.selectedOptions[0].dataset.kind;sync();}});channelSelect.addEventListener('change',sync);select.addEventListener('change',sync);sync();
    card.querySelector('form').addEventListener('submit',saveAssignment); root.appendChild(card);
  });
}
async function saveAssignment(event){
  event.preventDefault(); const form=event.currentTarget; const status=form.querySelector('.status'); status.className='status'; status.textContent='Applying…';
  const payload=Object.fromEntries(new FormData(form).entries());
  try{ const response=await fetch('/api/assign',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}); const data=await response.json(); if(!response.ok) throw new Error(data.error || 'Could not save.'); status.classList.add('success'); status.textContent='Saved. The screen will update momentarily.'; setTimeout(loadStatus,900); }
  catch(error){status.classList.add('error'); status.textContent=error.message;}
}
async function saveChannel(event){event.preventDefault();const form=event.currentTarget,status=form.querySelector('.status'),payload=Object.fromEntries(new FormData(form).entries());status.className='status';status.textContent='Saving…';try{const response=await fetch('/api/channel',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}),data=await response.json();if(!response.ok)throw new Error(data.error||'Could not save.');status.classList.add('success');status.textContent='Saved. Screens using this content will update momentarily.';setTimeout(loadStatus,900);}catch(error){status.classList.add('error');status.textContent=error.message;}}
function uploadMedia(){const file=document.querySelector('#media-file').files[0],button=document.querySelector('#media-upload'),progress=document.querySelector('#upload-progress'),status=document.querySelector('#upload-status');status.className='status';if(!file){status.classList.add('error');status.textContent='Choose a file first.';return;}button.disabled=true;progress.hidden=false;progress.value=0;status.textContent=`Uploading ${file.name}…`;const request=new XMLHttpRequest();request.open('POST',`/api/media/upload?filename=${encodeURIComponent(file.name)}`);request.setRequestHeader('Content-Type','application/octet-stream');request.upload.onprogress=event=>{if(event.lengthComputable)progress.value=Math.round(event.loaded/event.total*100);};request.onload=async()=>{button.disabled=false;let data={};try{data=JSON.parse(request.responseText);}catch(e){}if(request.status<200||request.status>=300){status.classList.add('error');status.textContent=data.error||'Upload failed.';return;}status.classList.add('success');status.textContent='Upload complete and stored locally.';document.querySelector('#media-file').value='';await loadStatus();};request.onerror=()=>{button.disabled=false;status.classList.add('error');status.textContent='The connection ended before the upload completed. Existing displays were not changed.';};request.send(file);}
async function assignAll(item,card){const status=card.querySelector('.status');status.className='status';status.textContent='Applying…';try{for(const output of currentData.outputs){const saved=currentData.state.assignments[output.name]||{};const response=await fetch('/api/assign',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({output:output.name,kind:item.kind,source:item.url,label:saved.label||output.name})});if(!response.ok){const data=await response.json();throw new Error(data.error||'Could not update every screen.');}}status.classList.add('success');status.textContent='Assigned to all connected screens.';setTimeout(loadStatus,900);}catch(error){status.classList.add('error');status.textContent=error.message;}}
async function removeMedia(item,card){if(!confirm(`Remove “${item.name}” from this node?`))return;const status=card.querySelector('.status');status.textContent='Removing…';const response=await fetch(`/api/media/${item.id}`,{method:'DELETE'});const data=await response.json();if(!response.ok){status.classList.add('error');status.textContent=data.error||'Could not remove this file.';return;}await loadStatus();}
document.querySelector('#media-upload').addEventListener('click',uploadMedia);
loadStatus().catch(error=>{document.querySelector('#health').textContent='Controller unavailable'; document.querySelector('#screens').innerHTML=`<section class="card error">${esc(error.message)}</section>`;});
"""
    return page_shell(APP_NAME, body, script)


def display_page(output: str, assignment: dict, revision: int) -> bytes:
    kind = assignment.get("kind", "standby")
    source = html.escape(assignment.get("source", ""), quote=True)
    label = html.escape(assignment.get("label", output))
    current_channel = assignment.get("channel", "")
    if kind == "web":
        content = f'<div class="web-frame"><iframe src="{source}" title="{label}" allow="autoplay; fullscreen"></iframe></div>'
    elif kind == "image":
        content = f'<img src="{source}" alt="{label}">'
    elif kind == "video":
        content = f'<video src="{source}" autoplay muted loop playsinline></video>'
    else:
        content = f'<div class="standby"><div class="brand"><img src="/assets/ice-and-field-logo-white.svg" alt="Ice &amp; Field" onerror="if(!this.dataset.fallback){{this.dataset.fallback=\'1\';this.src=\'/assets/ice-and-field-logo-white.png\'}}else{{this.hidden=true;this.nextElementSibling.hidden=false}}"><div class="mark" hidden>I&amp;F</div></div><h1>{label}</h1><p>Display Ready</p></div>'
    channel_buttons = "".join(
        f'<button type="button" data-channel="{key}"{(" disabled" if current_channel == key else "")}>{html.escape(channel_label)}</button>'
        for key, channel_label in CHANNELS.items()
    )
    output_json = json.dumps(output).replace("<", "\\u003c")
    label_json = json.dumps(assignment.get("label", output)).replace("<", "\\u003c")
    return f"""<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{label}</title><style>*{{box-sizing:border-box}}html,body{{margin:0;width:100%;height:100%;overflow:hidden;background:#031f31;cursor:none}}.web-frame{{position:fixed;inset:0;overflow:hidden;background:#000}}.web-frame iframe{{display:block;border:0;width:calc(100% + 24px);height:100%;background:#000;pointer-events:none}}body>img,video{{border:0;width:100%;height:100%;object-fit:contain;background:#000}}.standby{{height:100%;display:grid;place-content:center;text-align:center;color:white;font:24px system-ui;background:radial-gradient(circle at 50% 30%,#087dbb,#043c5e 55%,#021923)}}.brand img{{display:block;width:min(46vw,560px);height:auto;margin:auto;background:transparent}}.mark{{margin:auto;width:120px;height:120px;border-radius:50%;display:grid;place-content:center;background:#ff7830;font-size:44px;font-weight:900}}[hidden]{{display:none!important}}h1{{margin:32px 0 0}}p{{opacity:.7}}</style></head>
<style>.quick-menu{{position:fixed;z-index:10;right:0;top:0;font:700 14px system-ui;cursor:pointer}}.quick-toggle{{width:76px;height:76px;border:0;border-radius:0 0 0 14px;background:#031f31;color:white;font:700 13px system-ui;opacity:.015;transition:opacity .2s;cursor:pointer}}.quick-panel{{position:absolute;display:none;right:10px;top:66px;width:230px;padding:10px;border:1px solid #ffffff35;border-radius:12px;background:#031f31ee;box-shadow:0 12px 36px #0008}}.quick-menu:hover .quick-toggle,.quick-menu:focus-within .quick-toggle{{opacity:.96}}.quick-menu:hover .quick-panel,.quick-menu:focus-within .quick-panel{{display:grid;gap:7px}}.quick-panel button,.quick-panel a{{display:block;width:100%;padding:11px 12px;border:0;border-radius:8px;background:#075d8d;color:white;text-align:left;text-decoration:none;font:700 14px system-ui;cursor:pointer}}.quick-panel button:hover,.quick-panel a:hover{{background:#087dbb}}.quick-panel button:disabled{{background:#ff7830;opacity:1;cursor:default}}.quick-status{{min-height:18px;padding:2px 4px;color:white;font:12px system-ui}}</style>
<body>{content}<div class="quick-menu"><button class="quick-toggle" type="button" aria-haspopup="true">Manage</button><div class="quick-panel">{channel_buttons}<a href="/">Open full controller</a><div class="quick-status" aria-live="polite"></div></div></div><script>const revision={int(revision)},screenOutput={output_json},screenLabel={label_json};document.querySelectorAll('[data-channel]').forEach(button=>button.addEventListener('click',async()=>{{const status=document.querySelector('.quick-status');status.textContent='Switching…';try{{const response=await fetch('/api/assign',{{method:'POST',headers:{{'Content-Type':'application/json'}},body:JSON.stringify({{output:screenOutput,label:screenLabel,channel:button.dataset.channel,kind:'standby',source:''}})}});if(!response.ok)throw new Error('Could not switch this screen.');location.reload();}}catch(error){{status.textContent=error.message;}}}}));setInterval(async()=>{{try{{const r=await fetch('/api/revision',{{cache:'no-store'}});const d=await r.json();if(d.revision!==revision)location.reload();}}catch(e){{}}}},5000);</script></body></html>""".encode("utf-8")


class Handler(BaseHTTPRequestHandler):
    store: StateStore
    media: MediaStore

    def log_message(self, fmt: str, *args: object) -> None:
        print(f"{self.address_string()} - {fmt % args}", flush=True)

    def send_bytes(self, data: bytes, content_type: str, status: int = 200) -> None:
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Content-Security-Policy", "frame-ancestors 'none'")
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self) -> None:
        path = self.path.split("?", 1)[0]
        if path == "/":
            self.send_bytes(admin_page(), "text/html; charset=utf-8")
            return
        if path == "/api/status":
            storage = shutil.disk_usage(self.media.directory)
            self.send_bytes(json_bytes({"version": VERSION, "state": self.store.load(), "outputs": detect_outputs(), "media": self.media.list(), "storage": {"total": storage.total, "used": storage.used, "free": storage.free}}), "application/json")
            return
        if path == "/api/revision":
            self.send_bytes(json_bytes({"revision": self.store.load().get("revision", 0)}), "application/json")
            return
        logo_assets = {
            "/assets/ice-and-field-logo-white.svg": ("ice-and-field-logo-white.svg", "image/svg+xml"),
            "/assets/ice-and-field-logo.svg": ("ice-and-field-logo.svg", "image/svg+xml"),
            "/assets/ice-and-field-logo-white.png": ("ice-and-field-logo-white.png", "image/png"),
        }
        if path in logo_assets:
            filename, content_type = logo_assets[path]
            asset = Path(__file__).resolve().parent / "assets" / filename
            try:
                self.send_bytes(asset.read_bytes(), content_type)
            except OSError:
                self.send_error(HTTPStatus.NOT_FOUND)
            return
        if path.startswith("/media/"):
            self.serve_media(path[len("/media/"):])
            return
        if path.startswith("/display/"):
            output = unquote(path[len("/display/"):])
            if not OUTPUT_RE.fullmatch(output):
                self.send_error(HTTPStatus.NOT_FOUND)
                return
            state = self.store.load()
            assignment = state.get("assignments", {}).get(output, {"kind": "standby", "label": output, "source": ""})
            assignment = self.store.resolve_assignment(state, assignment)
            self.send_bytes(display_page(output, assignment, state.get("revision", 0)), "text/html; charset=utf-8")
            return
        self.send_error(HTTPStatus.NOT_FOUND)

    def do_POST(self) -> None:
        parsed = urlparse(self.path)
        if parsed.path == "/api/media/upload":
            try:
                length = int(self.headers.get("Content-Length", "0"))
                filename = parse_qs(parsed.query).get("filename", [""])[0]
                item = self.media.upload(self.rfile, filename, length)
                self.send_bytes(json_bytes({"ok": True, "media": item}), "application/json", HTTPStatus.CREATED)
            except (ValueError, TypeError, OSError) as error:
                self.send_bytes(json_bytes({"ok": False, "error": str(error)}), "application/json", HTTPStatus.BAD_REQUEST)
            return
        if parsed.path == "/api/channel":
            try:
                length = min(int(self.headers.get("Content-Length", "0")), 32_768)
                payload = json.loads(self.rfile.read(length).decode("utf-8"))
                state = self.store.save_channel(str(payload.get("channel", "")), str(payload.get("kind", "")), str(payload.get("source", "")))
                self.send_bytes(json_bytes({"ok": True, "revision": state["revision"]}), "application/json")
            except (ValueError, TypeError, json.JSONDecodeError) as error:
                self.send_bytes(json_bytes({"ok": False, "error": str(error)}), "application/json", HTTPStatus.BAD_REQUEST)
            return
        if parsed.path != "/api/assign":
            self.send_error(HTTPStatus.NOT_FOUND)
            return
        try:
            length = min(int(self.headers.get("Content-Length", "0")), 32_768)
            payload = json.loads(self.rfile.read(length).decode("utf-8"))
            state = self.store.assign(str(payload.get("output", "")), str(payload.get("kind", "")), str(payload.get("source", "")), str(payload.get("label", "")), str(payload.get("channel", "")))
            self.send_bytes(json_bytes({"ok": True, "revision": state["revision"]}), "application/json")
        except (ValueError, TypeError, json.JSONDecodeError) as error:
            self.send_bytes(json_bytes({"ok": False, "error": str(error)}), "application/json", HTTPStatus.BAD_REQUEST)

    def do_DELETE(self) -> None:
        path = self.path.split("?", 1)[0]
        if not path.startswith("/api/media/"):
            self.send_error(HTTPStatus.NOT_FOUND)
            return
        media_id = path[len("/api/media/"):]
        if not MEDIA_ID_RE.fullmatch(media_id):
            self.send_bytes(json_bytes({"ok": False, "error": "Invalid media item."}), "application/json", HTTPStatus.BAD_REQUEST)
            return
        media_url = f"/media/{media_id}"
        state = self.store.load()
        users = [assignment.get("label", output) for output, assignment in state.get("assignments", {}).items() if assignment.get("source") == media_url]
        users.extend(content.get("label", CHANNELS.get(key, key)) for key, content in state.get("channels", {}).items() if content.get("source") == media_url)
        if users:
            self.send_bytes(json_bytes({"ok": False, "error": f"This file is currently assigned to {', '.join(users)}. Change those screens before removing it."}), "application/json", HTTPStatus.CONFLICT)
            return
        if not self.media.delete(media_id):
            self.send_bytes(json_bytes({"ok": False, "error": "Media item not found."}), "application/json", HTTPStatus.NOT_FOUND)
            return
        self.send_bytes(json_bytes({"ok": True}), "application/json")

    def do_HEAD(self) -> None:
        path = self.path.split("?", 1)[0]
        if path.startswith("/media/"):
            self.serve_media(path[len("/media/"):], head_only=True)
            return
        self.send_error(HTTPStatus.NOT_FOUND)

    def serve_media(self, media_id: str, head_only: bool = False) -> None:
        found = self.media.get(media_id)
        if not found:
            self.send_error(HTTPStatus.NOT_FOUND)
            return
        item, path = found
        size = path.stat().st_size
        start, end, status = 0, max(0, size - 1), HTTPStatus.OK
        range_header = self.headers.get("Range", "")
        if range_header:
            match = re.fullmatch(r"bytes=(\d*)-(\d*)", range_header.strip())
            if not match or not size:
                self.send_response(HTTPStatus.REQUESTED_RANGE_NOT_SATISFIABLE)
                self.send_header("Content-Range", f"bytes */{size}")
                self.end_headers()
                return
            first, last = match.groups()
            if first:
                start = int(first)
                end = int(last) if last else size - 1
            elif last:
                start = max(0, size - int(last))
                end = size - 1
            if start >= size or end < start:
                self.send_response(HTTPStatus.REQUESTED_RANGE_NOT_SATISFIABLE)
                self.send_header("Content-Range", f"bytes */{size}")
                self.end_headers()
                return
            end = min(end, size - 1)
            status = HTTPStatus.PARTIAL_CONTENT
        length = end - start + 1 if size else 0
        self.send_response(status)
        self.send_header("Content-Type", item["content_type"])
        self.send_header("Content-Length", str(length))
        self.send_header("Accept-Ranges", "bytes")
        self.send_header("Cache-Control", "private, max-age=31536000, immutable")
        self.send_header("X-Content-Type-Options", "nosniff")
        if status == HTTPStatus.PARTIAL_CONTENT:
            self.send_header("Content-Range", f"bytes {start}-{end}/{size}")
        self.end_headers()
        if head_only:
            return
        with path.open("rb") as handle:
            handle.seek(start)
            remaining = length
            while remaining:
                chunk = handle.read(min(1024 * 1024, remaining))
                if not chunk:
                    break
                self.wfile.write(chunk)
                remaining -= len(chunk)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8787)
    args = parser.parse_args()
    Handler.store = StateStore(state_directory() / "state.json")
    Handler.media = MediaStore(state_directory() / "media")
    server = ThreadingHTTPServer((args.host, args.port), Handler)
    print(f"{APP_NAME} {VERSION} listening on http://{args.host}:{args.port}", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
