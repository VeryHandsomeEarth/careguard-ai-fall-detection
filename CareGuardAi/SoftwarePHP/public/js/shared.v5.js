/*
 * js/shared.js — Shared utility for all pages
 * - BASE URL, Connection status, Number format, Fall alert
 * - Settings panel: ข้อมูลผู้ใช้ + การแสดงผล (theme/font/size)
 */

const BASE = window.location.pathname
  .replace(/\/(index|health|chat|graphs|report)\.html$/, '')
  .replace(/\/$/, '');

// ==================== Connection ====================
function setConnected(ok) {
  const dot = document.getElementById('statusDot');
  const text = document.getElementById('statusText');
  if (!dot || !text) return;
  dot.className = ok ? 'status-dot connected' : 'status-dot';
  text.textContent = ok ? 'Connected' : 'Connecting...';
}

function fmtNum(n) { return new Intl.NumberFormat('th-TH').format(n || 0); }

// ==================== Fall Alert ====================
function triggerFallAlert() { const el = document.getElementById('fallAlert'); if (el) el.classList.remove('hidden'); }
function dismissAlert() { const el = document.getElementById('fallAlert'); if (el) el.classList.add('hidden'); }

// ==================== Settings Panel ====================
let _profile = {};

(function initSettings() {
  const nav = document.querySelector('.nav');
  if (!nav) return;

  // Inject ⚙️ button
  const spacer = nav.querySelector('.nav-spacer');
  if (spacer) {
    const btn = document.createElement('button');
    btn.className = 'nav-link'; btn.id = 'settingsToggleBtn';
    btn.innerHTML = '⚙️'; btn.title = 'ตั้งค่า';
    btn.style.cssText = 'cursor:pointer;background:none;border:none;font-size:18px;padding:6px 10px;';
    btn.addEventListener('click', toggleSettings);
    spacer.insertAdjacentElement('afterend', btn);

    // Removed standalone logout button based on user request
  }

  // Inject settings modal
  const modal = document.createElement('div');
  modal.id = 'settingsModal';
  modal.className = 'settings-overlay hidden';
  modal.innerHTML = `
    <div class="settings-backdrop" onclick="toggleSettings()"></div>
    <div class="settings-panel">
      <div class="settings-header">
        <h3>⚙️ ตั้งค่า</h3>
        <button class="settings-close" onclick="toggleSettings()">✕</button>
      </div>

      <!-- Tabs -->
      <div class="stabs" style="overflow-x:auto; white-space:nowrap; padding-bottom:12px;">
        <button class="stab active" onclick="switchTab('profile')">👤 ข้อมูลผู้ใช้</button>
        <button class="stab" onclick="switchTab('display')">🎨 การแสดงผล</button>
        <button class="stab" style="color:var(--danger)" onclick="doLogout()">🚪 ออกจากระบบ</button>
      </div>

      <!-- Tab: Profile -->
      <div id="tabProfile" class="stab-content">
        <div class="sg">
          <label class="sl">👤 ชื่อ-สกุล</label>
          <input class="si" id="fName" placeholder="ชื่อผู้ใช้">
        </div>
        <div class="sg-row">
          <div class="sg"><label class="sl">🎂 อายุ</label><input class="si" id="fAge" type="number" min="1" max="150" placeholder="ปี"></div>
          <div class="sg"><label class="sl">⚧ เพศ</label>
            <select class="si" id="fGender"><option value="">-- เลือก --</option><option value="ชาย">ชาย</option><option value="หญิง">หญิง</option><option value="อื่นๆ">อื่นๆ</option></select>
          </div>
        </div>
        <div class="sg-row">
          <div class="sg"><label class="sl">⚖️ น้ำหนัก (กก.)</label><input class="si" id="fWeight" type="number" step="0.1" min="1"></div>
          <div class="sg"><label class="sl">📏 ส่วนสูง (ซม.)</label><input class="si" id="fHeight" type="number" step="0.1" min="1"></div>
        </div>
        <div class="sg">
          <label class="sl">🏥 โรคประจำตัว</label>
          <input class="si" id="fConditions" placeholder="เบาหวาน, ความดัน...">
        </div>
        <div class="sg-row">
          <div class="sg"><label class="sl">🎯 เป้าก้าว/วัน</label><input class="si" id="fStepGoal" type="number" min="1000" step="500" value="10000"></div>
          <div class="sg"><label class="sl">📍 เป้าระยะทาง (ม.)</label><input class="si" id="fDistGoal" type="number" min="100" step="100" value="5000"></div>
        </div>
        <div class="sg-row">
          <div class="sg"><label class="sl">📞 ผู้ติดต่อฉุกเฉิน</label><input class="si" id="fEmName" placeholder="ชื่อ"></div>
          <div class="sg"><label class="sl">📱 เบอร์โทร</label><input class="si" id="fEmPhone" placeholder="08x-xxx-xxxx"></div>
        </div>
        <div class="sg">
          <label class="sl">📝 หมายเหตุ</label>
          <input class="si" id="fNotes" placeholder="ข้อมูลเพิ่มเติม...">
        </div>
        <button class="sbtn-save" onclick="saveProfile()">💾 บันทึกข้อมูลผู้ใช้</button>
        <div id="profileStatus" style="text-align:center;font-size:13px;margin-top:8px;color:var(--accent2);display:none">✅ บันทึกแล้ว</div>
      </div>

      <!-- Tab: Display -->
      <div id="tabDisplay" class="stab-content" style="display:none">
        <div class="sg">
          <label class="sl">🎨 ธีม</label>
          <div class="theme-btns">
            <button class="theme-btn" data-t="dark" onclick="setTheme('dark')"><span class="theme-swatch" style="background:#0d1117"></span>Dark</button>
            <button class="theme-btn" data-t="light" onclick="setTheme('light')"><span class="theme-swatch" style="background:#f6f8fa"></span>Light</button>
            <button class="theme-btn" data-t="ocean" onclick="setTheme('ocean')"><span class="theme-swatch" style="background:#071726"></span>Ocean</button>
          </div>
        </div>
        <div class="sg">
          <label class="sl">🔤 ฟอนต์</label>
          <select id="fontSelect" class="si" onchange="setFont(this.value)">
            <option value="Inter">Inter</option>
            <option value="Kanit">Kanit (กันทิต)</option>
            <option value="Sarabun">Sarabun (สารบรรณ)</option>
            <option value="Prompt">Prompt (พร้อมท์)</option>
          </select>
        </div>
        <div class="sg">
          <label class="sl">📏 ขนาดฟอนต์</label>
          <div class="font-size-ctrl">
            <button class="fs-btn" onclick="changeFontSize(-1)">A−</button>
            <span id="fontSizeDisplay" class="fs-display">15px</span>
            <button class="fs-btn" onclick="changeFontSize(1)">A+</button>
          </div>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);

  // Bind edit profile button (for card in chat page) to open settings tab
  // Expose helper for any UI link/button
  window.openSettingsProfile = function(event) {
    console.log('[shared.js] openSettingsProfile', event && event.type);
    if (event && typeof event.preventDefault === 'function') event.preventDefault();
    toggleSettings();
    switchTab('profile');
    return false;
  };

  // Bind both button and anchor targets (fallback if render differs)
  const editProfileBtn = document.getElementById('editProfileBtn');
  if (editProfileBtn) {
    editProfileBtn.type = 'button';
    editProfileBtn.addEventListener('click', window.openSettingsProfile);
    console.log('[shared.v5.js] Bound editProfileBtn');
  }
  const editProfileLink = document.getElementById('editProfileLink');
  if (editProfileLink) {
    editProfileLink.type = 'button';
    editProfileLink.addEventListener('click', window.openSettingsProfile);
    console.log('[shared.v5.js] Bound editProfileLink');
  }

  // Global fallback: intercept any "แก้ไข →" card-action to avoid navigation by old href
  document.body.addEventListener('click', (event) => {
    const actionEl = event.target.closest('.card-action');
    if (!actionEl) return;
    const text = (actionEl.textContent || '').trim();
    if (text === 'แก้ไข →' || text === 'แก้ไข' || text.toLowerCase().includes('แก้ไข')) {
      console.log('[shared.v5.js] Global fallback triggered for:', text);
      event.preventDefault();
      window.openSettingsProfile();
    }
  });

  // Apply saved display settings
  setTheme(localStorage.getItem('theme') || 'dark', true);
  setFont(localStorage.getItem('font') || 'Inter', true);
  changeFontSize(0, parseInt(localStorage.getItem('fontSize') || '15'));

  // Load profile
  loadProfile().then(() => {
    // Check if redirecting from registration
    if (window.location.search.includes('setup=1')) {
      toggleSettings();
      switchTab('profile');
      window.history.replaceState({}, '', window.location.pathname);
    }
  });
})();

// ==================== Tab switching ====================
function switchTab(tab) {
  console.log('[shared.v5.js] switchTab called with:', tab);
  document.getElementById('tabProfile').style.display = tab === 'profile' ? 'block' : 'none';
  document.getElementById('tabDisplay').style.display = tab === 'display' ? 'block' : 'none';
  document.querySelectorAll('.stab').forEach((b, i) => {
    b.classList.toggle('active', (i === 0 && tab === 'profile') || (i === 1 && tab === 'display'));
  });
}

// ==================== Profile ====================
async function loadProfile() {
  try {
    const res = await fetch(BASE + '/api/profile');
    const data = await res.json();
    if (data.success && data.data) {
      _profile = data.data;
      fillProfileForm();
      // Dispatch event for other pages
      window.dispatchEvent(new CustomEvent('profileLoaded', { detail: _profile }));
    }
  } catch {}
}

function fillProfileForm() {
  const p = _profile;
  const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
  set('fName', p.name); set('fAge', p.age); set('fGender', p.gender);
  set('fWeight', p.weight); set('fHeight', p.height);
  set('fConditions', p.health_conditions);
  set('fStepGoal', p.step_goal || 10000); set('fDistGoal', p.distance_goal || 5000);
  set('fEmName', p.emergency_name); set('fEmPhone', p.emergency_contact);
  set('fNotes', p.notes);
}

async function saveProfile() {
  const body = {
    name: document.getElementById('fName')?.value || '',
    age: parseInt(document.getElementById('fAge')?.value) || 0,
    gender: document.getElementById('fGender')?.value || '',
    weight: parseFloat(document.getElementById('fWeight')?.value) || 0,
    height: parseFloat(document.getElementById('fHeight')?.value) || 0,
    health_conditions: document.getElementById('fConditions')?.value || '',
    step_goal: parseInt(document.getElementById('fStepGoal')?.value) || 10000,
    distance_goal: parseFloat(document.getElementById('fDistGoal')?.value) || 5000,
    emergency_name: document.getElementById('fEmName')?.value || '',
    emergency_contact: document.getElementById('fEmPhone')?.value || '',
    notes: document.getElementById('fNotes')?.value || '',
  };
  try {
    const res = await fetch(BASE + '/api/profile', {
      method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body)
    });
    const data = await res.json();
    if (data.success) {
      _profile = { ..._profile, ...body };
      const st = document.getElementById('profileStatus');
      if (st) { st.style.display = 'block'; setTimeout(() => st.style.display = 'none', 2500); }
      window.dispatchEvent(new CustomEvent('profileLoaded', { detail: _profile }));
    }
  } catch (e) { alert('บันทึกไม่สำเร็จ'); }
}

function getProfile() { return _profile; }

// ==================== Theme ====================
function setTheme(theme, skip) {
  document.documentElement.setAttribute('data-theme', theme);
  if (!skip) localStorage.setItem('theme', theme);
  document.querySelectorAll('.theme-btn').forEach(b => b.classList.toggle('active', b.dataset.t === theme));
}

// ==================== Font ====================
function setFont(family, skip) {
  document.body.style.fontFamily = `'${family}', sans-serif`;
  if (!skip) localStorage.setItem('font', family);
  const sel = document.getElementById('fontSelect');
  if (sel) sel.value = family;
}

// ==================== Font Size ====================
let currentFontSize = parseInt(localStorage.getItem('fontSize') || '15');
function changeFontSize(delta, abs) {
  if (abs !== undefined) currentFontSize = abs;
  else currentFontSize = Math.max(12, Math.min(22, currentFontSize + delta));
  document.body.style.fontSize = currentFontSize + 'px';
  localStorage.setItem('fontSize', currentFontSize);
  const disp = document.getElementById('fontSizeDisplay');
  if (disp) disp.textContent = currentFontSize + 'px';
}

// ==================== Toggle ====================
function toggleSettings() {
  console.log('[shared.v5.js] toggleSettings called');
  const m = document.getElementById('settingsModal');
  if (m) m.classList.toggle('hidden');
}

// ==================== Global Logout ====================
window.doLogout = async function() {
  if (!confirm('ยืนยันออกจากระบบ?')) return;
  await fetch(BASE + '/api/auth/logout', { method: 'POST' });
  window.location.href = BASE + '/login.html';
};
