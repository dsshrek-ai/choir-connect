// Fill this in after deploying api/api.php (see SETUP.md).
const CONFIG = {
  API_URL: "https://seniorfamily.org/choir-connect-api/api.php",
};

function isConfigured() {
  return CONFIG.API_URL && !CONFIG.API_URL.includes("YOUR_");
}

// ---- Member login (My Apps Hub SSO token, or a manual fallback login) ----
// Every data action in api.php except login/logout/whoAmI checks this token
// against the shared sessions table plus an app_access grant for
// 'choir-connect'. localStorage (not sessionStorage) so members stay logged
// in across visits.

const MEMBER_TOKEN_KEY = 'ccMemberToken';
function getMemberToken() { return localStorage.getItem(MEMBER_TOKEN_KEY) || ''; }
function saveMemberToken(token) { localStorage.setItem(MEMBER_TOKEN_KEY, token); }
function clearMemberToken() { localStorage.removeItem(MEMBER_TOKEN_KEY); }

function memberAuthHeaders() {
  const token = getMemberToken();
  return token ? { Authorization: `Bearer ${token}` } : {};
}

// ---- Active choir (multi-choir picker in the header) ----

const ACTIVE_CHOIR_KEY = 'ccActiveChoir';
function getActiveChoir() {
  const raw = localStorage.getItem(ACTIVE_CHOIR_KEY);
  const n = parseInt(raw, 10);
  return Number.isFinite(n) && n > 0 ? n : null;
}
function setActiveChoir(n) { localStorage.setItem(ACTIVE_CHOIR_KEY, String(n)); }
function clearActiveChoir() { localStorage.removeItem(ACTIVE_CHOIR_KEY); }

// Reads one data domain, e.g. fetchData("choirs"). Returns parsed JSON, or
// throws -- "not-authorized" specifically for a 401/403 so callers can tell a
// login/access problem apart from a plain network/server failure.
async function fetchData(action) {
  if (!isConfigured()) {
    throw new Error("not-configured");
  }
  const url = `${CONFIG.API_URL}?action=${encodeURIComponent(action)}`;
  const res = await fetch(url, { headers: memberAuthHeaders() });
  if (res.status === 401 || res.status === 403) {
    throw new Error("not-authorized");
  }
  if (!res.ok) {
    throw new Error(`Request failed: ${res.status}`);
  }
  return res.json();
}

// Like fetchData, but scoped to the active choir: appends &choir=<n> plus any
// extra params. Throws "no-active-choir" if none is selected yet.
async function fetchChoirData(action, params = {}) {
  if (!isConfigured()) {
    throw new Error("not-configured");
  }
  const choir = getActiveChoir();
  if (!choir) {
    throw new Error("no-active-choir");
  }
  const qs = new URLSearchParams({ action, choir: String(choir), ...params });
  const res = await fetch(`${CONFIG.API_URL}?${qs.toString()}`, { headers: memberAuthHeaders() });
  if (res.status === 401 || res.status === 403) {
    throw new Error("not-authorized");
  }
  if (!res.ok) {
    throw new Error(`Request failed: ${res.status}`);
  }
  return res.json();
}

// Sends a write action, e.g. postAction("something", {...}).
async function postAction(action, payload) {
  if (!isConfigured()) {
    throw new Error("not-configured");
  }
  const res = await fetch(CONFIG.API_URL, {
    method: "POST",
    headers: { "Content-Type": "text/plain", ...memberAuthHeaders() },
    body: JSON.stringify({ action, ...payload }),
  });
  if (res.status === 401 || res.status === 403) {
    throw new Error("not-authorized");
  }
  if (!res.ok) {
    throw new Error(`Request failed: ${res.status}`);
  }
  return res.json();
}

// Loads the choirs this member can see and makes sure one is active:
//  - 0 choirs -> throws Error('no-choir')
//  - saved active choir not in the list (or none saved) -> picks the first
// Returns { choirs, active }.
async function ensureActiveChoir() {
  const choirs = await fetchData('choirs');
  if (!Array.isArray(choirs) || choirs.length === 0) {
    throw new Error('no-choir');
  }
  let active = getActiveChoir();
  if (!active || !choirs.some(c => c.ChoirNumber === active)) {
    active = choirs[0].ChoirNumber;
    setActiveChoir(active);
  }
  return { choirs, active };
}

// Renders a friendly placeholder into a container.
function showPlaceholder(container, message) {
  container.innerHTML = `<p class="placeholder-note">${message}</p>`;
}

// Renders a login-required or access-denied card in place of the page's real
// content. "no-token" includes a manual login form (reusing the `login`
// action) for a bookmarked visit that skipped the Hub's SSO handoff -- most
// members never see this. "denied" means the login is fine but has no
// app_access grant for Choir Connect yet.
function renderMemberGate(container, reason) {
  if (reason === 'denied') {
    container.innerHTML = `
      <div class="card">
        <p class="placeholder-note">Your login isn't set up for Choir Connect yet. Ask your director to grant you access, or open the app again from My Apps Hub.</p>
      </div>
    `;
    return;
  }
  container.innerHTML = `
    <div class="card">
      <h3>Log In</h3>
      <p class="placeholder-note">Normally you won't see this — opening the app from My Apps Hub logs you in automatically. Use this only if you got here another way.</p>
      <label for="member-login-email">Email</label>
      <input type="email" id="member-login-email" autocomplete="username">
      <label for="member-login-pw">Password</label>
      <input type="password" id="member-login-pw" autocomplete="current-password">
      <p><button type="button" class="btn" id="member-login-btn">Log In</button></p>
      <div id="member-login-msg"></div>
    </div>
  `;
  const emailInput = document.getElementById('member-login-email');
  const pwInput = document.getElementById('member-login-pw');
  const msgEl = document.getElementById('member-login-msg');
  const loginBtn = document.getElementById('member-login-btn');

  async function attemptLogin() {
    const username = emailInput.value.trim();
    const password = pwInput.value;
    if (!username || !password) return;
    loginBtn.disabled = true;
    msgEl.innerHTML = '';
    try {
      const res = await fetch(CONFIG.API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'text/plain' },
        body: JSON.stringify({ action: 'login', username, password }),
      });
      const data = await res.json();
      if (!res.ok || !data.token) {
        msgEl.innerHTML = `<p class="placeholder-note">${escapeHtml(data.error || 'Incorrect email or password.')}</p>`;
        loginBtn.disabled = false;
        return;
      }
      saveMemberToken(data.token);
      window.location.reload();
    } catch (e) {
      msgEl.innerHTML = `<p class="placeholder-note">Something went wrong logging in. Please try again.</p>`;
      loginBtn.disabled = false;
    }
  }

  loginBtn.addEventListener('click', attemptLogin);
  pwInput.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') attemptLogin(); });
}

// Pages call this from their fetch catch blocks so a login/access/choir
// problem shows the right message instead of a generic "couldn't load."
function handleFetchError(container, error) {
  const msg = error && error.message;
  if (msg === 'not-authorized') {
    renderMemberGate(container, getMemberToken() ? 'denied' : 'no-token');
  } else if (msg === 'no-choir') {
    showPlaceholder(container, "You're not in any choir yet. Ask your director to add you to a choir in Choir Connect.");
  } else if (msg === 'no-active-choir') {
    showPlaceholder(container, "Pick a choir at the top of the page to continue.");
  } else {
    showPlaceholder(container, "Couldn't load right now — check your connection and try refreshing.");
  }
}

// Escapes text before dropping it into innerHTML.
function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

// Lightweight Markdown-ish plain text -> safe HTML: **bold**, *italic*,
// ![alt](url) images, blank-line paragraphs, "- " bullets, "1. " numbered.
function formatText(raw) {
  const escaped = escapeHtml(raw ?? '');
  const blocks = escaped.split(/\n\s*\n/);
  return blocks
    .map(block => {
      const lines = block.split('\n').filter(line => line.trim() !== '');
      if (!lines.length) return '';
      if (lines.every(line => /^-\s+/.test(line))) {
        const items = lines.map(line => `<li>${inlineFormat(line.replace(/^-\s+/, ''))}</li>`).join('');
        return `<ul>${items}</ul>`;
      }
      if (lines.every(line => /^\d+\.\s+/.test(line))) {
        const items = lines.map(line => `<li>${inlineFormat(line.replace(/^\d+\.\s+/, ''))}</li>`).join('');
        return `<ol>${items}</ol>`;
      }
      return `<p>${lines.map(inlineFormat).join('<br>')}</p>`;
    })
    .join('');
}

function inlineFormat(text) {
  return text
    .replace(/!\[([^\]]*)\]\(([^)]+)\)/g, (match, alt, url) =>
      `<img src="${url.replace(/"/g, '&quot;')}" alt="${alt.replace(/"/g, '&quot;')}">`
    )
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>');
}

// Copies `text` to the clipboard and briefly changes `button`'s label. Falls
// back to onFallback(text) if the clipboard API is blocked.
async function copyToClipboard(text, button, onFallback) {
  const original = button.textContent;
  try {
    await navigator.clipboard.writeText(text);
    button.textContent = 'Copied!';
  } catch (e) {
    if (onFallback) onFallback(text);
  }
  setTimeout(() => { button.textContent = original; }, 1500);
}

// Renders Call/Text/Email tap buttons for a phone/email pair. Omits whichever
// action has no target. (Used by the planned Member List page.)
function contactButtonsHtml(phone, email) {
  const cleanPhone = String(phone ?? '').trim();
  const cleanEmail = String(email ?? '').trim();
  if (!cleanPhone && !cleanEmail) return '';
  const parts = [];
  if (cleanPhone) {
    const tel = encodeURIComponent(cleanPhone);
    parts.push(`<a class="contact-btn contact-btn-call" href="tel:${tel}">Call</a>`);
    parts.push(`<a class="contact-btn contact-btn-text" href="sms:${tel}">Text</a>`);
  }
  if (cleanEmail) {
    parts.push(`<a class="contact-btn contact-btn-email" href="mailto:${encodeURIComponent(cleanEmail)}">Email</a>`);
  }
  return `<div class="contact-actions">${parts.join('')}</div>`;
}

// ---- My Apps Hub SSO handoff ----
// Launching from the Hub passes ?token=... -- a real row in the shared
// sessions table -- which we adopt directly as this app's Bearer token
// (saveMemberToken) and also resolve to an email (saveEmail) for any
// email-keyed features. Always strips the token from the URL. Run-once via
// ssoCapturePromise so multiple callers on one page load are safe.

const MEMBER_EMAIL_KEY = 'ccMemberEmail';
function getSavedEmail() { return localStorage.getItem(MEMBER_EMAIL_KEY) || ''; }
function saveEmail(email) { localStorage.setItem(MEMBER_EMAIL_KEY, email); }
function clearSavedEmail() { localStorage.removeItem(MEMBER_EMAIL_KEY); }

let ssoCapturePromise = null;
function captureSsoEmail() {
  if (ssoCapturePromise) return ssoCapturePromise;
  ssoCapturePromise = (async () => {
    const token = new URLSearchParams(window.location.search).get('token');
    if (!token) return;
    window.history.replaceState({}, document.title, window.location.pathname);
    if (!isConfigured()) return;
    saveMemberToken(token);
    try {
      const res = await fetch(`${CONFIG.API_URL}?action=whoAmI&token=${encodeURIComponent(token)}`);
      const data = await res.json();
      if (data.ok && data.email) {
        saveEmail(data.email);
      }
    } catch (e) {
      // Best-effort -- a failed resolve just means no email pre-fill.
    }
  })();
  return ssoCapturePromise;
}

// ---- Shared header: nav + choir picker ----

// Core build uses a fixed nav. A DB-driven nav (cc_nav_items) can be added
// later, mirroring the member app's choir_nav_items.
const NAV_ITEMS = [
  { Label: 'Home', PageFile: 'index.html' },
  { Label: 'Rehearsal Tracks', PageFile: 'programs.html' },
];

function renderNav() {
  const nav = document.getElementById('site-nav');
  if (!nav) return;
  const currentPage = window.location.pathname.split('/').pop() || 'index.html';
  nav.innerHTML = NAV_ITEMS.map(item => {
    const isCurrent = item.PageFile === currentPage
      || (currentPage === 'program.html' && item.PageFile === 'programs.html');
    return `<a href="${escapeHtml(item.PageFile)}"${isCurrent ? ' aria-current="page"' : ''}>${escapeHtml(item.Label)}</a>`;
  }).join('');
}

// Renders the choir name (1 choir) or a <select> switcher (2+) into
// #choir-picker. Switching stores the choice and reloads so every page
// re-fetches for the new choir. Silent no-op if the element isn't present
// or the choirs call fails (the page's own gate handles that).
async function renderChoirPicker() {
  const mount = document.getElementById('choir-picker');
  if (!mount) return;
  let choirs;
  try {
    choirs = await fetchData('choirs');
  } catch (e) {
    return;
  }
  if (!Array.isArray(choirs) || choirs.length === 0) return;

  let active = getActiveChoir();
  if (!active || !choirs.some(c => c.ChoirNumber === active)) {
    active = choirs[0].ChoirNumber;
    setActiveChoir(active);
  }

  if (choirs.length === 1) {
    mount.innerHTML = `<span class="choir-picker-single">${escapeHtml(choirs[0].Name)}</span>`;
    return;
  }

  mount.innerHTML = `
    <label for="choir-select">Choir</label>
    <select id="choir-select">
      ${choirs.map(c => `<option value="${c.ChoirNumber}" ${c.ChoirNumber === active ? 'selected' : ''}>${escapeHtml(c.Name)}</option>`).join('')}
    </select>
  `;
  document.getElementById('choir-select').addEventListener('change', (ev) => {
    setActiveChoir(parseInt(ev.target.value, 10));
    window.location.reload();
  });
}

// Auto-bootstrap: any page with #site-nav captures a Hub SSO token first (so
// it's saved before any page fetch runs -- they all require it), then renders
// the nav and the choir picker.
if (document.getElementById('site-nav')) {
  (async () => {
    await captureSsoEmail();
    renderNav();
    await renderChoirPicker();
  })();
}
