#!/usr/bin/env python3
"""Local controller and display pages for Ice & Field display nodes."""

from __future__ import annotations

import argparse
import html
import json
import os
import re
import socket
import subprocess
import tempfile
import threading
import time
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import unquote, urlparse

APP_NAME = "Ice & Field Display Controller"
VERSION = "0.1.0-dev"
OUTPUT_RE = re.compile(r"^[A-Za-z0-9._-]{1,64}$")
KINDS = {"standby", "web", "image", "video"}


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

    def assign(self, output: str, kind: str, source: str, label: str) -> dict:
        if not OUTPUT_RE.fullmatch(output):
            raise ValueError("Invalid output name.")
        if kind not in KINDS:
            raise ValueError("Invalid content type.")
        source = source.strip()
        if kind in {"web", "image", "video"}:
            parsed = urlparse(source)
            if parsed.scheme not in {"http", "https"} or not parsed.netloc:
                raise ValueError("Content must use a complete http:// or https:// address.")
        else:
            source = ""
        label = label.strip()[:80] or output
        state = self.load()
        state.setdefault("assignments", {})[output] = {
            "kind": kind,
            "source": source,
            "label": label,
            "updated_at": int(time.time()),
        }
        state["revision"] = int(state.get("revision", 0)) + 1
        self.save(state)
        return state


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
.grid{{display:grid;grid-template-columns:repeat(auto-fit,minmax(310px,1fr));gap:20px}}
.card{{background:white;border:1px solid var(--line);border-radius:16px;padding:20px;box-shadow:0 8px 24px #064b7510}}
.card h2{{margin:0 0 5px;color:var(--navy)}} .muted{{color:var(--muted)}} label{{display:block;font-weight:700;margin:14px 0 5px}}
input,select{{width:100%;font:inherit;padding:11px;border:1px solid #aebdc6;border-radius:8px;background:white}}
button{{border:0;border-radius:9px;padding:11px 16px;background:var(--orange);color:white;font:700 15px system-ui;cursor:pointer;margin-top:16px}}
button:hover{{filter:brightness(.95)}} .return{{display:inline-block;margin:16px 0 0 10px;color:var(--navy);font-weight:700}} .status{{min-height:23px;margin-top:10px;font-weight:650}} .error{{color:#a32b22}} .success{{color:#287d4d}}
code{{font-size:.9em;background:#eef3f6;padding:2px 5px;border-radius:5px}}
</style></head><body><header><h1>{html.escape(title)}</h1><p>Controller and Node 1</p></header><main>{body}</main><script>{script}</script></body></html>""".encode("utf-8")


def admin_page() -> bytes:
    body = """
<div class="summary"><span class="pill" id="node">Loading node…</span><span class="pill" id="health">Checking screens…</span></div>
<div id="screens" class="grid"></div>
<noscript><div class="card">JavaScript is required to manage screen assignments.</div></noscript>
"""
    script = r"""
const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
async function loadStatus(){
  const response = await fetch('/api/status', {cache:'no-store'});
  const data = await response.json();
  document.querySelector('#node').textContent = `${data.state.node.name} · ${data.state.node.id}`;
  document.querySelector('#health').textContent = `${data.outputs.length} connected screen${data.outputs.length === 1 ? '' : 's'}`;
  const root = document.querySelector('#screens'); root.innerHTML = '';
  if(!data.outputs.length){ root.innerHTML='<section class="card"><h2>No active screens</h2><p class="muted">Connect a display, then reload this page.</p></section>'; return; }
  data.outputs.forEach((output,index)=>{
    const saved=data.state.assignments[output.name] || {kind:'standby',source:'',label:`Screen ${index+1}`};
    const card=document.createElement('section'); card.className='card';
    card.innerHTML=`<h2>${esc(saved.label || `Screen ${index+1}`)}</h2><p class="muted"><code>${esc(output.name)}</code> · ${output.width}×${output.height}</p>
      <form><input type="hidden" name="output" value="${esc(output.name)}">
      <label>Friendly screen name</label><input name="label" maxlength="80" value="${esc(saved.label || `Screen ${index+1}`)}">
      <label>What should this screen show?</label><select name="kind">
        <option value="standby">Ice & Field standby screen</option><option value="web">Web page</option><option value="image">Image</option><option value="video">Video</option>
      </select><div class="source"><label>Complete web address</label><input name="source" inputmode="url" placeholder="https://…" value="${esc(saved.source || '')}"></div>
      <button type="submit">Apply to this screen</button><a class="return" href="/display/${encodeURIComponent(output.name)}">Return to screen</a><div class="status" aria-live="polite"></div></form>`;
    const select=card.querySelector('select'); select.value=saved.kind;
    const source=card.querySelector('.source'); const sync=()=>source.hidden=select.value==='standby'; select.addEventListener('change',sync); sync();
    card.querySelector('form').addEventListener('submit',saveAssignment); root.appendChild(card);
  });
}
async function saveAssignment(event){
  event.preventDefault(); const form=event.currentTarget; const status=form.querySelector('.status'); status.className='status'; status.textContent='Applying…';
  const payload=Object.fromEntries(new FormData(form).entries());
  try{ const response=await fetch('/api/assign',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}); const data=await response.json(); if(!response.ok) throw new Error(data.error || 'Could not save.'); status.classList.add('success'); status.textContent='Saved. The screen will update momentarily.'; setTimeout(loadStatus,900); }
  catch(error){status.classList.add('error'); status.textContent=error.message;}
}
loadStatus().catch(error=>{document.querySelector('#health').textContent='Controller unavailable'; document.querySelector('#screens').innerHTML=`<section class="card error">${esc(error.message)}</section>`;});
"""
    return page_shell(APP_NAME, body, script)


def display_page(output: str, assignment: dict, revision: int) -> bytes:
    kind = assignment.get("kind", "standby")
    source = html.escape(assignment.get("source", ""), quote=True)
    label = html.escape(assignment.get("label", output))
    if kind == "web":
        content = f'<iframe src="{source}" title="{label}" allow="autoplay; fullscreen"></iframe>'
    elif kind == "image":
        content = f'<img src="{source}" alt="{label}">'
    elif kind == "video":
        content = f'<video src="{source}" autoplay muted loop playsinline></video>'
    else:
        content = f'<div class="standby"><div class="brand"><img src="/assets/ice-and-field-logo-white.svg" alt="Ice &amp; Field" onerror="if(!this.dataset.fallback){{this.dataset.fallback=\'1\';this.src=\'/assets/ice-and-field-logo-white.png\'}}else{{this.hidden=true;this.nextElementSibling.hidden=false}}"><div class="mark" hidden>I&amp;F</div></div><h1>{label}</h1><p>Display Ready</p></div>'
    return f"""<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{label}</title><style>*{{box-sizing:border-box}}html,body{{margin:0;width:100%;height:100%;overflow:hidden;background:#031f31}}iframe,body>img,video{{border:0;width:100%;height:100%;object-fit:contain;background:#000}}.standby{{height:100%;display:grid;place-content:center;text-align:center;color:white;font:24px system-ui;background:radial-gradient(circle at 50% 30%,#087dbb,#043c5e 55%,#021923)}}.brand img{{display:block;width:min(46vw,560px);height:auto;margin:auto;background:transparent}}.mark{{margin:auto;width:120px;height:120px;border-radius:50%;display:grid;place-content:center;background:#ff7830;font-size:44px;font-weight:900}}[hidden]{{display:none!important}}h1{{margin:32px 0 0}}p{{opacity:.7}}</style></head>
<style>.manage{{position:fixed;z-index:10;right:0;top:0;width:76px;height:76px;display:grid;place-content:center;background:#031f31;color:white;text-decoration:none;font:700 13px system-ui;border-radius:0 0 0 14px;opacity:.015;transition:opacity .2s}}.manage:hover,.manage:focus{{opacity:.94}}</style>
<body>{content}<a class="manage" href="/" title="Manage displays">Manage</a><script>const revision={int(revision)};setInterval(async()=>{{try{{const r=await fetch('/api/revision',{{cache:'no-store'}});const d=await r.json();if(d.revision!==revision)location.reload();}}catch(e){{}}}},5000);</script></body></html>""".encode("utf-8")


class Handler(BaseHTTPRequestHandler):
    store: StateStore

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
            self.send_bytes(json_bytes({"version": VERSION, "state": self.store.load(), "outputs": detect_outputs()}), "application/json")
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
        if path.startswith("/display/"):
            output = unquote(path[len("/display/"):])
            if not OUTPUT_RE.fullmatch(output):
                self.send_error(HTTPStatus.NOT_FOUND)
                return
            state = self.store.load()
            assignment = state.get("assignments", {}).get(output, {"kind": "standby", "label": output, "source": ""})
            self.send_bytes(display_page(output, assignment, state.get("revision", 0)), "text/html; charset=utf-8")
            return
        self.send_error(HTTPStatus.NOT_FOUND)

    def do_POST(self) -> None:
        if self.path != "/api/assign":
            self.send_error(HTTPStatus.NOT_FOUND)
            return
        try:
            length = min(int(self.headers.get("Content-Length", "0")), 32_768)
            payload = json.loads(self.rfile.read(length).decode("utf-8"))
            state = self.store.assign(str(payload.get("output", "")), str(payload.get("kind", "")), str(payload.get("source", "")), str(payload.get("label", "")))
            self.send_bytes(json_bytes({"ok": True, "revision": state["revision"]}), "application/json")
        except (ValueError, TypeError, json.JSONDecodeError) as error:
            self.send_bytes(json_bytes({"ok": False, "error": str(error)}), "application/json", HTTPStatus.BAD_REQUEST)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8787)
    args = parser.parse_args()
    Handler.store = StateStore(state_directory() / "state.json")
    server = ThreadingHTTPServer((args.host, args.port), Handler)
    print(f"{APP_NAME} {VERSION} listening on http://{args.host}:{args.port}", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
