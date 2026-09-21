/*
 * js/shared.js — Shared utility for CareGuard AI
 */

const BASE = window.location.pathname
  .replace(/\/(index|health|graphs|report|chat|login|admin|overview)(\.html)?$/, '')
  .replace(/\/$/, '');

let currentDeviceOnline = false;
let currentDeviceName = 'ESP32';
let currentDeviceId = 'ESP32_HW2';

function setConnected(ok, deviceName) {
  const dot = document.getElementById('statusDot');
  const text = document.getElementById('statusText');
  const badge = dot ? dot.closest('.status-badge') : null;
  if (!dot || !text) return;

  currentDeviceOnline = !!ok;
  if (deviceName && deviceName.trim()) currentDeviceName = deviceName.trim();

  if (currentDeviceName === 'ยังไม่ได้เชื่อมต่ออุปกรณ์') {
    dot.className = 'status-dot offline';
    text.textContent = 'ยังไม่ได้เชื่อมต่ออุปกรณ์';
    if (badge) badge.title = 'บัญชีของคุณยังไม่ได้ผูกอุปกรณ์ ESP32';
    return;
  }

  dot.className = ok ? 'status-dot online' : 'status-dot offline';
  text.textContent = `${currentDeviceName} (${ok ? 'ออนไลน์' : 'ออฟไลน์'})`;
  if (badge) {
    badge.title = ok ? `อุปกรณ์ ${currentDeviceName} เชื่อมต่อ Wi-Fi และส่งข้อมูลอยู่` : `อุปกรณ์ ${currentDeviceName} ไม่ได้เชื่อมต่อ Wi-Fi หรือออฟไลน์`;
  }
}

async function checkDeviceStatusGlobal() {
  try {
    const res = await fetch(BASE + '/api/gps/latest?_=' + Date.now());
    const data = await res.json();
    if (data && data.success) {
      const dev = data.device || {};
      if (dev.device_id) currentDeviceId = dev.device_id;
      const name = dev.name || currentDeviceName || 'ESP32';
      const isOnline = !!dev.is_online;
      if (!dev.has_device) {
        setConnected(false, 'ยังไม่ได้เชื่อมต่ออุปกรณ์');
      } else {
        setConnected(isOnline, name);
      }
    } else {
      setConnected(false, currentDeviceName);
    }
  } catch (e) {
    setConnected(false, currentDeviceName);
  }
}

function fmtNum(n) { return new Intl.NumberFormat('th-TH').format(n || 0); }

function formatDateTime(ts) {
  if (!ts) return '--';
  try {
    const cleanTs = String(ts).replace(' ', 'T');
    const d = new Date(cleanTs);
    if (isNaN(d.getTime())) return String(ts);
    return d.toLocaleString('th-TH', {
      hour: '2-digit', minute: '2-digit', second: '2-digit',
      day: 'numeric', month: 'short', year: 'numeric'
    });
  } catch (e) {
    return String(ts);
  }
}

// ==================== Resonant Acoustic Alarm Chime ====================
let audioCtx = null;
let alarmChimeInterval = null;

function playResonantChime() {
  try {
    if (!audioCtx) {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (AudioCtx) audioCtx = new AudioCtx();
    }
    if (!audioCtx) return;
    if (audioCtx.state === 'suspended') audioCtx.resume();

    const t = audioCtx.currentTime;

    // Filter เพื่อตัดเสียงแหลมบาดหู (Warm Low-Pass Filter ที่ 1600 Hz นุ่มลึก)
    const filter = audioCtx.createBiquadFilter();
    filter.type = 'lowpass';
    filter.frequency.setValueAtTime(1600, t);
    filter.connect(audioCtx.destination);

    // Dynamic Stereo Panning ให้เสียงมีมิติและระบุตำแหน่งทิศทางได้ชัดเจน
    const panner = (typeof audioCtx.createStereoPanner === 'function') ? audioCtx.createStereoPanner() : null;
    const targetNode = panner ? panner : filter;
    if (panner) panner.connect(filter);

    // คอร์ดคู่เสียงระฆัง 1: D5 (587.33Hz) + A4 (440.00Hz) นุ่มนวล กังวาน
    playBellTone(587.33, 440.00, t, 0.45, targetNode, -0.25);

    // คอร์ดคู่เสียงระฆัง 2: G5 (783.99Hz) + D5 (587.33Hz) กังวานชัดเจน นำสายตา
    playBellTone(783.99, 587.33, t + 0.26, 0.65, targetNode, 0.25);
  } catch (e) {}
}

function playBellTone(freq1, freq2, startTime, duration, destination, panVal) {
  try {
    const osc1 = audioCtx.createOscillator();
    const gain1 = audioCtx.createGain();
    osc1.type = 'sine'; // เสียงหลัก นุ่มลึก
    osc1.frequency.setValueAtTime(freq1, startTime);

    const osc2 = audioCtx.createOscillator();
    const gain2 = audioCtx.createGain();
    osc2.type = 'triangle'; // เติมฮาร์โมนิกความกังวานแบบระฆังอะคูสติก
    osc2.frequency.setValueAtTime(freq2, startTime);

    gain1.gain.setValueAtTime(0.0001, startTime);
    gain1.gain.linearRampToValueAtTime(0.28, startTime + 0.02);
    gain1.gain.exponentialRampToValueAtTime(0.0001, startTime + duration);

    gain2.gain.setValueAtTime(0.0001, startTime);
    gain2.gain.linearRampToValueAtTime(0.12, startTime + 0.02);
    gain2.gain.exponentialRampToValueAtTime(0.0001, startTime + duration);

    osc1.connect(gain1);
    osc2.connect(gain2);
    gain1.connect(destination);
    gain2.connect(destination);

    osc1.start(startTime);
    osc2.start(startTime);
    osc1.stop(startTime + duration);
    osc2.stop(startTime + duration);
  } catch (e) {}
}

function playAlarmChime() {
  playResonantChime();
}

function startAlarmLoop() {
  stopAlarmLoop();
  playResonantChime();
  alarmChimeInterval = setInterval(playResonantChime, 2500);
}

function stopAlarmLoop() {
  if (alarmChimeInterval) {
    clearInterval(alarmChimeInterval);
    alarmChimeInterval = null;
  }
}

// ==================== Trigger Fall Emergency (Modal & Banner) ====================
function triggerFallAlert(info, fallObj) {
  if (fallObj && fallObj.id) {
    activeFallId = fallObj.id;
  }
  // 1. เปิดแบนเนอร์แจ้งเตือนด้านบน
  const el = document.getElementById('fallAlert');
  if (el) {
    el.classList.remove('hidden');
    if (info && document.getElementById('fallAlertText')) {
      document.getElementById('fallAlertText').innerHTML = `⚠️ <strong>ตรวจพบการล้ม!</strong> (${info})`;
    }
  }

  // 2. แสดงหน้าต่างแจ้งเตือนฉุกเฉินทันที (Emergency Modal Window)
  openEmergencyFallModal(fallObj || { info });

  // 3. เริ่มเสียงเตือนกังวานวนซ้ำจนกว่าจะปิดหรือกดช่วยเหลือ
  startAlarmLoop();
}

function openEmergencyFallModal(f) {
  const modal = document.getElementById('emergencyFallModal');
  if (!modal) return;

  const d = (typeof deduceFallDirection === 'function' && f) ? deduceFallDirection(f) : { name: f.info || 'ล้มไปข้างหน้า (Forward)', icon: '🏃‍♂️' };
  const { gTotal, magMs2 } = (typeof calcGForce === 'function' && f) ? calcGForce(f) : { gTotal: 2.5, magMs2: 24.5 };

  const iconEl = document.getElementById('emerModalIcon');
  const dirEl = document.getElementById('emerModalDir');
  const gEl = document.getElementById('emerModalG');
  const userEl = document.getElementById('emerModalUser');
  const confEl = document.getElementById('emerModalConf');
  const sevEl = document.getElementById('emerModalSev');
  const timeEl = document.getElementById('emerModalTime');
  const coordsEl = document.getElementById('emerModalCoords');
  const gmapsBtn = document.getElementById('emerModalGmapsBtn');
  const contactBox = document.getElementById('emerModalContactBox');
  const phoneEl = document.getElementById('emerModalPhone');
  const callBtn = document.getElementById('emerModalCallBtn');

  if (iconEl) iconEl.textContent = d.icon || '🏃‍♂️';
  if (dirEl) dirEl.innerHTML = `<span style="font-size:13.5px;color:rgba(255,255,255,0.7);font-weight:500;">คาดการณ์: </span><span style="color:#00d2ff;font-weight:800;">${d.name || 'การล้ม'}</span>`;
  if (gEl) gEl.innerHTML = `แรงกระแทก: <strong>${gTotal > 0 ? gTotal.toFixed(2) : '2.00'} G</strong> (${magMs2 > 0 ? magMs2.toFixed(1) : '19.6'} m/s²)`;
  const isHw2 = (f && (f.device_id === 'ESP32_HW2' || (f.device_id && f.device_id.indexOf('HW2') !== -1)));
  const devLabel = isHw2 ? 'CareGuardH2' : 'CareGuardH1';
  if (userEl) userEl.textContent = (currentUserProfile?.name || 'ผู้ใช้งาน') + ` (${devLabel})`;
  if (confEl) confEl.textContent = f.confidence ? `${Math.round(f.confidence * 100)}%` : '85%';

  const sev = (f.severity || 'medium').toUpperCase();
  const sevClass = sev === 'HIGH' ? 'pill-red' : (sev === 'LOW' ? 'pill-green' : 'pill-yellow');
  if (sevEl) sevEl.innerHTML = `<span class="pill ${sevClass}">${sev}</span>`;

  if (timeEl) timeEl.textContent = typeof formatDateTime === 'function' ? formatDateTime(f.timestamp) : new Date().toLocaleTimeString('th-TH');

  if (f.lat && f.lng && +f.lat !== 0 && +f.lng !== 0) {
    if (coordsEl) coordsEl.textContent = `${parseFloat(f.lat).toFixed(4)}, ${parseFloat(f.lng).toFixed(4)}`;
    if (gmapsBtn) gmapsBtn.href = `https://maps.google.com/?q=${f.lat},${f.lng}`;
  } else {
    if (coordsEl) coordsEl.textContent = 'Wi-Fi ภายในอาคาร';
    if (gmapsBtn) gmapsBtn.href = 'https://maps.google.com';
  }

  const emerPhone = currentUserProfile?.emergency_phone || currentUserProfile?.phone || '';
  if (emerPhone && contactBox && phoneEl && callBtn) {
    phoneEl.textContent = emerPhone;
    callBtn.href = `tel:${emerPhone}`;
    contactBox.style.display = 'block';
  } else if (contactBox) {
    contactBox.style.display = 'none';
  }

  modal.style.display = 'flex';
}

function closeEmergencyFallModal() {
  const modal = document.getElementById('emergencyFallModal');
  if (modal) modal.style.display = 'none';
  stopAlarmLoop();
}

function dismissAlert(userInitiated = true) {
  const el = document.getElementById('fallAlert');
  if (el) el.classList.add('hidden');
  closeEmergencyFallModal();
  if (userInitiated && typeof activeFallId !== 'undefined' && activeFallId > 0) {
    try { sessionStorage.setItem('dismissed_fall_' + activeFallId, '1'); } catch (e) {}
  }
}

function handleModalAssist() {
  closeEmergencyFallModal();
  if (typeof activeFallId !== 'undefined' && activeFallId > 0) {
    markAssisted(activeFallId);
  }
}

// ==================== Fall Direction Helper ====================
function deduceFallDirection(f) {
  if (!f) return { type: 'fall_forward', name: 'ล้มไปข้างหน้า (Forward)', icon: '🏃‍♂️', badgeClass: 'badge-forward' };
  
  const type = (f.fall_type || '').toLowerCase();
  const name = f.fall_type_name || '';

  if (type.includes('forward') || name.includes('หน้า')) {
    return { type: 'fall_forward', name: 'ล้มไปข้างหน้า (Forward)', icon: '🏃‍♂️', badgeClass: 'badge-forward' };
  } else if (type.includes('backward') || name.includes('หลัง')) {
    return { type: 'fall_backward', name: 'ล้มไปข้างหลัง (Backward)', icon: '🚶‍♂️', badgeClass: 'badge-backward' };
  } else if (type.includes('lateral_left') || name.includes('ซ้าย')) {
    return { type: 'fall_lateral_left', name: 'ล้มไปด้านซ้าย (Left)', icon: '⬅️', badgeClass: 'badge-lateral' };
  } else if (type.includes('lateral_right') || name.includes('ขวา')) {
    return { type: 'fall_lateral_right', name: 'ล้มไปด้านขวา (Right)', icon: '➡️', badgeClass: 'badge-lateral' };
  } else if (type.includes('vertical') || name.includes('ดิ่ง') || name.includes('ทรุด')) {
    return { type: 'fall_vertical', name: 'ล้มแนวดิ่ง/ทรุดตัว (Vertical)', icon: '⬇️', badgeClass: 'badge-vertical' };
  }

  // คำนวณจากแรงกระแทกความเร่ง 3 แกน
  const ax = parseFloat(f.acceleration_x) || 0;
  const ay = parseFloat(f.acceleration_y) || 0;
  const az = parseFloat(f.acceleration_z) || 0;
  const absX = Math.abs(ax);
  const absY = Math.abs(ay);
  const absZ = Math.abs(az);

  if (absY >= absX && absY >= 1.0) {
    if (ay < 0) return { type: 'fall_forward', name: 'คาดการณ์: ล้มไปข้างหน้า (Forward)', icon: '🏃‍♂️', badgeClass: 'badge-forward' };
    else return { type: 'fall_backward', name: 'คาดการณ์: ล้มไปข้างหลัง (Backward)', icon: '🚶‍♂️', badgeClass: 'badge-backward' };
  }
  if (absX >= absY && absX >= 1.0) {
    if (ax < 0) return { type: 'fall_lateral_left', name: 'คาดการณ์: ล้มไปด้านซ้าย (Left)', icon: '⬅️', badgeClass: 'badge-lateral' };
    else return { type: 'fall_lateral_right', name: 'คาดการณ์: ล้มไปด้านขวา (Right)', icon: '➡️', badgeClass: 'badge-lateral' };
  }
  if (absZ >= 15.0 || az < -3.0) {
    return { type: 'fall_vertical', name: 'คาดการณ์: ล้มแนวดิ่ง/ทรุดตัว', icon: '⬇️', badgeClass: 'badge-vertical' };
  }

  return { type: 'fall_forward', name: 'คาดการณ์: ล้มไปข้างหน้า (Forward)', icon: '🏃‍♂️', badgeClass: 'badge-forward' };
}

function getFallTypeBadge(fallType, fallTypeName, fallObj) {
  let f = fallObj;
  if (!f && typeof fallType === 'object') {
    f = fallType;
  } else if (!f) {
    f = { fall_type: fallType, fall_type_name: fallTypeName };
  }

  const d = deduceFallDirection(f);
  return `<span class="pill ${d.badgeClass}" style="font-size:12px;padding:4px 10px;">${d.icon} <strong>${d.name}</strong></span>`;
}

function getAssistedBadge(assisted, assistedAt, fallId) {
  if (assisted == 1 || assisted === true) {
    const timeStr = assistedAt ? ` (${new Date(String(assistedAt).replace(' ','T')).toLocaleTimeString('th-TH', {hour:'2-digit', minute:'2-digit'})})` : '';
    return `<span class="pill badge-assisted">✅ ช่วยเหลือแล้ว${timeStr}</span>`;
  } else {
    const idVal = fallId || 0;
    return `<button class="btn btn-success btn-sm" onclick="markAssisted(${idVal}, event)" style="padding:3px 10px;font-size:11.5px;">🚨 กดช่วยเหลือ</button>`;
  }
}

function getOfflineBadge(offlineRecorded) {
  if (offlineRecorded == 1 || offlineRecorded === true) {
    return `<span class="pill badge-offline-sync" title="บันทึกในโหมดออฟไลน์และส่งย้อนหลัง">📶 ซิงค์ออฟไลน์</span>`;
  }
  return '';
}

async function markAssisted(fallId, event) {
  if (event) {
    event.stopPropagation();
    event.preventDefault();
  }
  dismissAlert(); // ปิดแบนเนอร์แจ้งเตือนทันที!
  
  try {
    const res = await fetch(BASE + `/api/falls?action=assist&id=${fallId || 0}`, { method: 'POST' });
    const data = await res.json();
    if (data.success) {
      if (typeof poll === 'function') poll();
      if (typeof loadReport === 'function') loadReport();
    }
  } catch (e) {
    console.error('Assist error:', e);
  }
}

// ==================== G-Force Calculation Helper ====================
function calcGForce(f) {
  if (!f) return { gTotal: 0, magMs2: 0, ax: 0, ay: 0, az: 0 };
  const ax = parseFloat(f.acceleration_x) || 0;
  const ay = parseFloat(f.acceleration_y) || 0;
  const az = parseFloat(f.acceleration_z) || 0;
  const magMs2 = Math.sqrt(ax * ax + ay * ay + az * az);
  // สูตร Vector Magnitude: G_total = sqrt(Ax^2 + Ay^2 + Az^2) / 9.80665 (จาก m/s^2 เป็น G)
  const gTotal = magMs2 / 9.80665;
  return { gTotal, magMs2, ax, ay, az };
}

function getImpactGForceHtml(f) {
  const { gTotal, magMs2, ax, ay, az } = calcGForce(f);
  if (magMs2 === 0) return '';
  return `
    <div style="margin-top:4px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
      <span class="pill pill-red" style="font-weight:700;font-size:12px;padding:3px 8px;background:rgba(255,82,82,0.18);border:1px solid #ff5252;color:#ff5252;">
        💥 แรงกระแทก: ${gTotal.toFixed(2)} G
      </span>
      <span style="color:var(--text-muted);font-size:11.5px;font-family:var(--font-mono);">
        (Ax=${ax.toFixed(1)}, Ay=${ay.toFixed(1)}, Az=${az.toFixed(1)} m/s²)
      </span>
    </div>
  `;
}

// ==================== Toast Notification ====================
function showToast(msg, type = 'success') {
  let toast = document.querySelector('.cg-toast');
  if (!toast) {
    toast = document.createElement('div');
    toast.className = 'cg-toast';
    document.body.appendChild(toast);
  }
  const icons = { success: '✅', error: '⚠️', warning: '⚡' };
  toast.className = `cg-toast ${type}`;
  toast.innerHTML = `<span>${icons[type] || 'ℹ️'}</span> <div>${msg}</div>`;
  toast.style.display = 'flex';
  setTimeout(() => { if (toast) toast.style.display = 'none'; }, 3500);
}

// ==================== Theme & Display Settings ====================
function initThemeSettings() {
  const savedTheme = localStorage.getItem('careguard_theme') || 'dark';
  const savedFont = localStorage.getItem('careguard_font') || 'prompt';
  const savedFontSize = localStorage.getItem('careguard_font_size') || 'medium';

  document.documentElement.setAttribute('data-theme', savedTheme);
  if (savedFont && savedFont !== 'default') {
    document.documentElement.setAttribute('data-font', savedFont);
  }
  document.documentElement.setAttribute('data-font-size', savedFontSize);
}

function setTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  localStorage.setItem('careguard_theme', theme);
}

function setFont(font) {
  if (font === 'default') document.documentElement.removeAttribute('data-font');
  else document.documentElement.setAttribute('data-font', font);
  localStorage.setItem('careguard_font', font);
}

function setFontSize(size) {
  document.documentElement.setAttribute('data-font-size', size);
  localStorage.setItem('careguard_font_size', size);
}

// ==================== User Profile & Navbar Dropdown ====================
let currentUserProfile = null;
let currentUserRole = 'user';

async function loadUserProfileNav() {
  try {
    const res = await fetch(BASE + '/api/profile.php');
    const data = await res.json();
    if (data.success && data.data) {
      currentUserProfile = data.data;
      currentUserRole = data.role || (data.data.role || 'user');
      
      const uid = (currentUserProfile && (currentUserProfile.user_id || currentUserProfile.id)) || data.username || '1';
      const prevUid = sessionStorage.getItem('careguard_active_uid');
      sessionStorage.setItem('careguard_active_uid', String(uid));
      
      // อัปเดตประวัติแชทให้ตรงกับผู้ใช้งานรายนี้โดยเฉพาะ (ป้องกันการปนกันระหว่าง admin/user)
      if (prevUid !== String(uid) || !chatHistory.length) {
        initFloatingChat();
      }

      const avatar = currentUserProfile.avatar || '👤';
      const name = currentUserProfile.name || currentUserProfile.username || 'ผู้ใช้งาน';

      // อัปเดตแสดงผลบน Navbar
      const elAvatar = document.getElementById('navAvatar');
      const elName = document.getElementById('navUsername');
      const elMenuAvatar = document.getElementById('menuAvatar');
      const elMenuName = document.getElementById('menuUsername');
      const elMenuRole = document.getElementById('menuUserRole');

      if (elAvatar) elAvatar.textContent = avatar;
      if (elName) elName.textContent = name;
      if (elMenuAvatar) elMenuAvatar.textContent = avatar;
      if (elMenuName) elMenuName.textContent = name;
      if (elMenuRole) elMenuRole.textContent = currentUserRole === 'admin' ? '👑 ผู้ดูแลระบบ (Admin)' : '👤 ผู้ใช้งานทั่วไป';

      // จัดการแสดงปุ่ม Admin ใน Navbar และ Dropdown แบบสมบูรณ์
      syncAdminVisibility();

      // อัปเดตในหน้าสุขภาพ
      const greetName = document.getElementById('greetName');
      if (greetName) greetName.textContent = name;
      
      const bmiPill = document.getElementById('bmiPill');
      if (bmiPill && currentUserProfile.weight > 0 && currentUserProfile.height > 0) {
        const hM = currentUserProfile.height / 100;
        const bmi = (currentUserProfile.weight / (hM * hM)).toFixed(1);
        bmiPill.textContent = `BMI: ${bmi}`;
      }
    }
  } catch (e) {
    console.error('Error loading user profile:', e);
  }
}

// ==================== Admin Controls Synchronizer ====================
function syncAdminVisibility() {
  const isAdmin = (currentUserRole === 'admin');

  // 1. จัดการปุ่ม Admin ใน Top Navbar
  let navAdmin = document.getElementById('navAdminLink');
  const navBar = document.querySelector('.nav');
  if (isAdmin) {
    if (!navAdmin && navBar) {
      navAdmin = document.createElement('a');
      navAdmin.id = 'navAdminLink';
      navAdmin.href = 'admin.html';
      navAdmin.className = 'nav-link nav-admin-link' + (window.location.pathname.includes('admin') ? ' active' : '');
      navAdmin.style.cssText = 'color:#fbbf24 !important;font-weight:600 !important;display:inline-flex !important;align-items:center;gap:6px;';
      navAdmin.innerHTML = '👑 ผู้ดูแลระบบ';
      const spacer = navBar.querySelector('.nav-spacer');
      if (spacer) {
        navBar.insertBefore(navAdmin, spacer);
      } else {
        navBar.appendChild(navAdmin);
      }
    } else if (navAdmin) {
      navAdmin.style.setProperty('display', 'inline-flex', 'important');
    }
  } else {
    if (navAdmin) navAdmin.style.setProperty('display', 'none', 'important');
  }

  // 2. จัดการปุ่ม Admin ใน Profile Dropdown Menu
  let elMenuAdmin = document.getElementById('menuAdminLink');
  const pMenu = document.getElementById('profileDropdownMenu');
  if (isAdmin) {
    if (!elMenuAdmin && pMenu) {
      const dividers = pMenu.querySelectorAll('.dropdown-divider');
      const firstDivider = dividers[0];
      elMenuAdmin = document.createElement('a');
      elMenuAdmin.id = 'menuAdminLink';
      elMenuAdmin.href = 'admin.html';
      elMenuAdmin.className = 'dropdown-item menu-admin-link';
      elMenuAdmin.style.cssText = 'color:#fbbf24 !important;font-weight:600 !important;display:flex !important;align-items:center;gap:8px;';
      elMenuAdmin.innerHTML = '<span>👑</span> แผงควบคุมระบบ (Admin)';
      if (firstDivider && firstDivider.nextSibling) {
        pMenu.insertBefore(elMenuAdmin, firstDivider.nextSibling);
      } else {
        pMenu.appendChild(elMenuAdmin);
      }
    } else if (elMenuAdmin) {
      elMenuAdmin.style.setProperty('display', 'flex', 'important');
    }
  } else {
    if (elMenuAdmin) elMenuAdmin.style.setProperty('display', 'none', 'important');
  }
}

function updateProfileDropdownPos() {
  const btn = document.getElementById('navProfileBtn') || document.getElementById('navProfileDropdown');
  const menu = document.getElementById('profileDropdownMenu');
  if (!btn || !menu || !menu.classList.contains('open')) return;

  const btnRect = btn.getBoundingClientRect();
  const isMobile = window.innerWidth <= 768;

  menu.style.position = 'fixed';
  menu.style.zIndex = '999999';

  if (isMobile) {
    menu.style.top = Math.max(50, Math.round(btnRect.bottom + 6)) + 'px';
    menu.style.right = '10px';
    menu.style.left = 'auto';
    menu.style.width = '280px';
    menu.style.maxWidth = 'calc(100vw - 20px)';
  } else {
    const topPos = Math.round(btnRect.bottom + 8);
    const rightPos = Math.max(10, Math.round(window.innerWidth - btnRect.right));
    menu.style.top = topPos + 'px';
    menu.style.right = rightPos + 'px';
    menu.style.left = 'auto';
    menu.style.width = '260px';
  }
}

function toggleProfileDropdown(e) {
  if (e) { e.preventDefault(); e.stopPropagation(); }
  const dd = document.getElementById('navProfileDropdown');
  const menu = document.getElementById('profileDropdownMenu');
  if (!menu) return;

  // Move to document.body so it is NEVER clipped by .nav's overflow-x/overflow-y or backdrop-filter
  if (menu.parentElement !== document.body) {
    document.body.appendChild(menu);
  }

  const isOpen = menu.classList.contains('open');
  if (isOpen) {
    closeProfileDropdown();
  } else {
    if (dd) dd.classList.add('open');
    menu.classList.add('open');
    syncAdminVisibility();
    menu.style.display = 'block';
    updateProfileDropdownPos();
  }
}

function closeProfileDropdown() {
  const dd = document.getElementById('navProfileDropdown');
  const menu = document.getElementById('profileDropdownMenu');
  if (dd) dd.classList.remove('open');
  if (menu) {
    menu.classList.remove('open');
    menu.style.display = 'none';
  }
}

window.addEventListener('click', (e) => {
  const dd = document.getElementById('navProfileDropdown');
  const btn = document.getElementById('navProfileBtn');
  const menu = document.getElementById('profileDropdownMenu');
  if (menu && menu.classList.contains('open')) {
    const clickedInsideBtn = (dd && dd.contains(e.target)) || (btn && btn.contains(e.target));
    const clickedInsideMenu = menu && menu.contains(e.target);
    if (!clickedInsideBtn && !clickedInsideMenu) {
      closeProfileDropdown();
    }
  }
});

document.addEventListener('touchstart', (e) => {
  const dd = document.getElementById('navProfileDropdown');
  const btn = document.getElementById('navProfileBtn');
  const menu = document.getElementById('profileDropdownMenu');
  if (menu && menu.classList.contains('open')) {
    const clickedInsideBtn = (dd && dd.contains(e.target)) || (btn && btn.contains(e.target));
    const clickedInsideMenu = menu && menu.contains(e.target);
    if (!clickedInsideBtn && !clickedInsideMenu) {
      closeProfileDropdown();
    }
  }
}, { passive: true });

window.addEventListener('resize', () => {
  updateProfileDropdownPos();
});

window.addEventListener('scroll', () => {
  if (document.getElementById('profileDropdownMenu')?.classList.contains('open')) {
    updateProfileDropdownPos();
  }
}, { passive: true });

// ==================== Modal Controls ====================
function openModal(id) {
  closeProfileDropdown();
  const el = document.getElementById(id);
  if (el) el.classList.add('open');
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove('open');
}

// 1. Profile Modal
function openProfileModal() {
  if (currentUserProfile) {
    document.getElementById('profName').value = currentUserProfile.name || '';
    document.getElementById('profEmail').value = currentUserProfile.email || '';
    document.getElementById('profPhone').value = currentUserProfile.phone || '';
    document.getElementById('profAge').value = currentUserProfile.age || '';
    document.getElementById('profWeight').value = currentUserProfile.weight || '';
    document.getElementById('profHeight').value = currentUserProfile.height || '';
    document.getElementById('profGender').value = currentUserProfile.gender || 'male';
    document.getElementById('profEmerName').value = currentUserProfile.emergency_name || '';
    document.getElementById('profEmerPhone').value = currentUserProfile.emergency_phone || currentUserProfile.emergency_contact || '';
    document.getElementById('profEmerEmail').value = currentUserProfile.emergency_email || '';
    const tgEl = document.getElementById('profTelegramChatId');
    if (tgEl) tgEl.value = currentUserProfile.telegram_chat_id || '';

    const selectedAvatar = currentUserProfile.avatar || '👤';
    document.querySelectorAll('.avatar-option').forEach(opt => {
      opt.classList.toggle('selected', opt.dataset.avatar === selectedAvatar);
    });
  }
  openModal('profileModal');
}

function selectAvatar(el) {
  document.querySelectorAll('.avatar-option').forEach(opt => opt.classList.remove('selected'));
  el.classList.add('selected');
}

async function saveProfile(e) {
  if (e) e.preventDefault();
  const name = document.getElementById('profName').value.trim();
  const email = document.getElementById('profEmail').value.trim();
  const phone = document.getElementById('profPhone').value.trim();
  const emerName = document.getElementById('profEmerName').value.trim();
  const emerPhone = document.getElementById('profEmerPhone').value.trim();
  const emerEmail = document.getElementById('profEmerEmail').value.trim();
  const tgChatId = document.getElementById('profTelegramChatId')?.value.trim() || '';
  const age = parseInt(document.getElementById('profAge').value) || 0;
  const weight = parseFloat(document.getElementById('profWeight').value) || 0;
  const height = parseFloat(document.getElementById('profHeight').value) || 0;
  const gender = document.getElementById('profGender').value;
  
  const selectedOpt = document.querySelector('.avatar-option.selected');
  const avatar = selectedOpt ? selectedOpt.dataset.avatar : '👤';

  // ตรวจสอบข้อมูลบังคับ (Required Fields)
  if (!name) {
    showToast('กรุณากรอกชื่อผู้ใช้งาน', 'warning');
    return;
  }
  if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    showToast('กรุณากรอกอีเมลผู้ใช้งานที่ถูกต้อง', 'error');
    return;
  }
  if (!phone || phone.length < 9) {
    showToast('กรุณากรอกเบอร์โทรศัพท์ผู้ใช้งานให้ครบถ้วน', 'error');
    return;
  }
  if (!emerPhone || emerPhone.length < 9) {
    showToast('กรุณากรอกเบอร์โทรศัพท์ญาติ/ผู้ดูแล (สำหรับติดต่อฉุกเฉิน)', 'error');
    return;
  }
  if (emerEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emerEmail)) {
    showToast('กรุณากรอกอีเมลญาติ/ผู้ดูแลให้ถูกต้อง', 'error');
    return;
  }

  try {
    const res = await fetch(BASE + '/api/profile.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name, email, phone, avatar, age, weight, height, gender,
        emergency_name: emerName,
        emergency_phone: emerPhone,
        emergency_email: emerEmail,
        emergency_contact: emerPhone,
        telegram_chat_id: tgChatId
      })
    });
    const data = await res.json();
    if (data.success) {
      showToast('บันทึกข้อมูลโปรไฟล์และข้อมูลญาติเรียบร้อยแล้ว');
      closeModal('profileModal');
      await loadUserProfileNav();
    } else {
      showToast(data.error || 'เกิดข้อผิดพลาดในการบันทึกโปรไฟล์', 'error');
    }
  } catch (err) {
    console.error('Save profile error:', err);
    showToast('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
  }
}

// 2. Devices Modal
async function openDevicesModal() {
  openModal('devicesModal');
  await loadUserDevices();
}

async function loadUserDevices() {
  const listEl = document.getElementById('deviceListContainer');
  if (!listEl) return;
  listEl.innerHTML = '<div style="text-align:center;padding:16px;color:var(--text-muted);">กำลังโหลดรายการอุปกรณ์...</div>';

  try {
    const url = (currentUserRole === 'admin') ? (BASE + '/api/devices.php?all=1') : (BASE + '/api/devices.php');
    const res = await fetch(url);
    const data = await res.json();
    if (data.success && data.data && data.data.length > 0) {
      listEl.innerHTML = data.data.map(dev => `
        <div style="background:var(--bg-secondary);border:1px solid var(--border);border-radius:10px;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
          <div>
            <div style="font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:6px;">
              <span>${dev.device_type === 'hw1_gps' ? '🛰️' : '📶'}</span>
              <span>${escapeHtml(dev.device_name || 'CareGuard Device')}</span>
              ${dev.is_primary == 1 ? '<span class="pill pill-green" style="font-size:10px;padding:2px 6px;">อุปกรณ์หลัก</span>' : ''}
            </div>
            <div style="font-size:12px;color:var(--accent);font-family:var(--font-mono);margin-top:2px;">
              MAC: ${dev.mac_address}
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
              โหมด: ${dev.device_type === 'hw1_gps' ? 'Hardware 1 (GPS NEO-7M)' : dev.device_type === 'hw2_wifi' ? 'Hardware 2 (Wi-Fi Geolocation)' : 'ตรวจจับอัตโนมัติ'}
              ${dev.username ? ` • ผู้ใช้: ${escapeHtml(dev.username)}` : ''}
            </div>
          </div>
          <div>
            <button class="btn btn-outline btn-sm" onclick="deleteDevice(${dev.id})" style="color:var(--danger);border-color:var(--danger);padding:4px 8px;" title="ยกเลิกการผูกอุปกรณ์">
              🗑️ ลบ
            </button>
          </div>
        </div>
      `).join('');
    } else {
      listEl.innerHTML = '<div class="empty-state" style="padding:20px 0;"><div class="empty-icon">📱</div><div>ยังไม่มีอุปกรณ์ที่ผูกไว้ กรุณาเพิ่มอุปกรณ์ด้านล่าง</div></div>';
    }
  } catch (e) {
    listEl.innerHTML = '<div style="color:var(--danger);text-align:center;padding:12px;">ไม่สามารถดึงข้อมูลอุปกรณ์ได้</div>';
  }
}

async function saveNewDevice(e) {
  if (e) e.preventDefault();
  const mac = document.getElementById('devMacAddress').value.trim();
  const name = document.getElementById('devName').value.trim() || 'CareGuard ESP32';
  const type = document.getElementById('devType').value;
  const isPrimary = document.getElementById('devIsPrimary').checked ? 1 : 0;

  if (!mac || !/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/.test(mac)) {
    showToast('รูปแบบ MAC Address ไม่ถูกต้อง (ตัวอย่าง: 24:6F:28:AB:CD:EF)', 'error');
    return;
  }

  let devId = 'ESP32_001';
  if (type === 'hw2_wifi' || name.toLowerCase().includes('hw2') || name.toLowerCase().includes('hardware2') || name.toLowerCase().includes('hardware 2')) {
    devId = 'ESP32_HW2';
  }

  try {
    const res = await fetch(BASE + '/api/devices.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        mac_address: mac,
        device_name: name,
        device_type: type,
        device_id: devId,
        is_primary: isPrimary
      })
    });
    const data = await res.json();
    if (data.success) {
      showToast('ผูกอุปกรณ์เรียบร้อยแล้ว');
      document.getElementById('devMacAddress').value = '';
      await loadUserDevices();
    } else {
      showToast(data.error || 'เกิดข้อผิดพลาดในการผูกอุปกรณ์', 'error');
    }
  } catch (err) {
    showToast('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
  }
}

async function deleteDevice(id) {
  if (!confirm('คุณแน่ใจหรือไม่ว่าต้องการยกเลิกการผูกอุปกรณ์นี้?')) return;
  try {
    const res = await fetch(BASE + `/api/devices.php?id=${id}`, { method: 'DELETE' });
    const data = await res.json();
    if (data.success) {
      showToast('ยกเลิกการผูกอุปกรณ์แล้ว');
      await loadUserDevices();
    } else {
      showToast(data.error || 'เกิดข้อผิดพลาด', 'error');
    }
  } catch (err) {
    showToast('เกิดข้อผิดพลาดในการลบอุปกรณ์', 'error');
  }
}

// 3. Theme Modal
function openThemeModal() {
  const currentTheme = localStorage.getItem('careguard_theme') || 'dark';
  const currentFont = localStorage.getItem('careguard_font') || 'default';
  const currentSize = localStorage.getItem('careguard_font_size') || 'medium';

  const themeRadio = document.querySelector(`input[name="themeChoice"][value="${currentTheme}"]`);
  if (themeRadio) themeRadio.checked = true;

  const fontSelect = document.getElementById('fontChoice');
  if (fontSelect) fontSelect.value = currentFont;

  const sizeSelect = document.getElementById('fontSizeChoice');
  if (sizeSelect) sizeSelect.value = currentSize;

  openModal('themeModal');
}

function applyThemeModal() {
  const selTheme = document.querySelector('input[name="themeChoice"]:checked')?.value || 'dark';
  const selFont = document.getElementById('fontChoice')?.value || 'default';
  const selSize = document.getElementById('fontSizeChoice')?.value || 'medium';

  setTheme(selTheme);
  setFont(selFont);
  setFontSize(selSize);

  showToast('บันทึกการตั้งค่าการแสดงผลแล้ว');
  closeModal('themeModal');
}

// ==================== Floating AI Chat Widget with Cross-Page Persistence ====================
let chatHistory = [];

// กำหนดกุญแจเก็บประวัติแชทแยกตาม User ID หรือ Username ป้องกันแชทปนกันระหว่างแอดมินและผู้ใช้
function getChatStorageKey() {
  const uid = (currentUserProfile && (currentUserProfile.user_id || currentUserProfile.id))
    || sessionStorage.getItem('careguard_active_uid')
    || 'guest';
  return 'careguard_chat_history_u' + uid;
}

function initFloatingChat() {
  // ลบ key เก่ารวมที่ไม่ได้แยก user ออก ป้องกันการรั่วไหลของข้อมูลเก่า
  try { localStorage.removeItem('careguard_chat_history'); } catch (e) {}

  // 1. โหลดประวัติการแชทจาก localStorage เฉพาะบัญชีผู้ใช้ปัจจุบัน
  const storageKey = getChatStorageKey();
  try {
    const saved = localStorage.getItem(storageKey);
    if (saved) {
      chatHistory = JSON.parse(saved);
    } else {
      chatHistory = [];
    }
  } catch (e) {
    chatHistory = [];
  }

  // 2. ถ้ายังไม่มีข้อความ ให้สร้างข้อความทักทายอัตโนมัติที่รู้ข้อมูลสุขภาพของผู้ใช้รายนี้
  if (chatHistory.length === 0) {
    const greetingMsg = getAIGreetingWithHealthContext();
    chatHistory.push({
      sender: 'bot',
      text: greetingMsg,
      time: new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' })
    });
    saveChatHistory();
  }

  renderChatMessages();

  // 3. กู้คืนสถานะเปิด/ปิดหน้าต่างแชท (ถ้าเคยเปิดไว้หน้าก่อน หน้าใหม่จะเปิดต่อทันที)
  const wasOpen = localStorage.getItem('careguard_chat_open') === 'true';
  const chatWin = document.getElementById('chatFloatingWindow');
  if (chatWin) {
    if (wasOpen) {
      chatWin.classList.remove('minimized');
    } else {
      chatWin.classList.add('minimized');
    }
  }
}

function getAIGreetingWithHealthContext() {
  const name = currentUserProfile?.name || 'คุณ';
  const stepEl = document.getElementById('stepCount');
  const steps = stepEl ? stepEl.innerText.replace(/[^0-9]/g, '') : '0';
  const fallEl = document.getElementById('fallCount');
  const falls = fallEl ? fallEl.innerText.replace(/[^0-9]/g, '') : '0';

  let greeting = `สวัสดีครับคุณ ${name} 👋 ผมคือ **CareGuard AI** ผู้ช่วยดูแลสุขภาพและความปลอดภัยส่วนตัวของคุณ`;
  if (parseInt(falls) > 0) {
    greeting += `\n\n⚠️ วันนี้ตรวจพบเหตุการณ์การล้มสะสม **${falls} ครั้ง** กรุณาระมัดระวังเป็นพิเศษนะครับ`;
  } else {
    greeting += `\n\n🎉 วันนี้ยอดเยี่ยมมากครับ ยังไม่พบเหตุการณ์การล้ม`;
  }
  if (parseInt(steps) > 0) {
    greeting += `\n👣 คุณเดินไปแล้ว **${fmtNum(steps)} ก้าว** สุขภาพร่างกายแข็งแรงมากครับ มีอะไรให้ผมช่วยเหลือสอบถามได้เลยครับ!`;
  }
  return greeting;
}

function toggleFloatingChat() {
  const chatWin = document.getElementById('chatFloatingWindow');
  if (!chatWin) return;
  const isMinimized = chatWin.classList.toggle('minimized');
  localStorage.setItem('careguard_chat_open', isMinimized ? 'false' : 'true');
  if (!isMinimized) {
    scrollChatBottom();
    const input = document.getElementById('chatFloatingInput');
    if (input) input.focus();
  }
}

function closeFloatingChat() {
  const chatWin = document.getElementById('chatFloatingWindow');
  if (chatWin) {
    chatWin.classList.add('minimized');
    localStorage.setItem('careguard_chat_open', 'false');
  }
}

function clearChatHistory() {
  if (!confirm('คุณต้องการล้างประวัติการสนทนานี้หรือไม่?')) return;
  chatHistory = [];
  try {
    localStorage.removeItem(getChatStorageKey());
    fetch(BASE + '/api/chat.php', { method: 'DELETE' });
  } catch (e) {}
  initFloatingChat();
}

function saveChatHistory() {
  try {
    // เก็บสูงสุด 50 ข้อความล่าสุด เฉพาะของผู้ใช้นี้
    if (chatHistory.length > 50) chatHistory = chatHistory.slice(-50);
    localStorage.setItem(getChatStorageKey(), JSON.stringify(chatHistory));
  } catch (e) {}
}

function renderChatMessages() {
  const body = document.getElementById('chatFloatingBody');
  if (!body) return;

  body.innerHTML = chatHistory.map(msg => `
    <div class="chat-msg ${msg.sender}">
      <div>${formatMarkdownBasic(msg.text)}</div>
      <div class="chat-msg-time">${msg.time || ''}</div>
    </div>
  `).join('');

  scrollChatBottom();
}

function scrollChatBottom() {
  const body = document.getElementById('chatFloatingBody');
  if (body) {
    setTimeout(() => { body.scrollTop = body.scrollHeight; }, 50);
  }
}

function formatMarkdownBasic(text) {
  if (!text) return '';
  return escapeHtml(text)
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.*?)\*/g, '<em>$1</em>')
    .replace(/\n/g, '<br>');
}

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

async function sendFloatingChatMessage(prefilledText) {
  const input = document.getElementById('chatFloatingInput');
  const text = (prefilledText || input?.value || '').trim();
  if (!text) return;

  if (input) input.value = '';

  const timeNow = new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });

  // 1. เพิ่มข้อความผู้ใช้
  chatHistory.push({ sender: 'user', text, time: timeNow });
  saveChatHistory();
  renderChatMessages();

  // 2. แสดง Typing Indicator
  const body = document.getElementById('chatFloatingBody');
  const typingEl = document.createElement('div');
  typingEl.className = 'chat-msg bot chat-typing';
  typingEl.id = 'chatTypingIndicator';
  typingEl.innerHTML = '<span></span><span></span><span></span>';
  if (body) {
    body.appendChild(typingEl);
    scrollChatBottom();
  }

  // 3. เตรียม Context สุขภาพปัจจุบัน
  const healthContext = {
    steps: document.getElementById('stepCount')?.innerText || '0',
    falls: document.getElementById('fallCount')?.innerText || '0',
    distance: document.getElementById('distDisplay')?.innerText || '0 ม.',
    userName: currentUserProfile?.name || 'ผู้ใช้',
    userAge: currentUserProfile?.age || 0,
    emergencyPhone: currentUserProfile?.phone || ''
  };

  try {
    const res = await fetch(BASE + '/api/chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        message: text,
        context: healthContext,
        history: chatHistory.slice(-6)
      })
    });
    const data = await res.json();
    
    // ลบ typing indicator
    const typingIndicator = document.getElementById('chatTypingIndicator');
    if (typingIndicator) typingIndicator.remove();

    const botReply = (data.success && data.reply) ? data.reply : (data.response || 'ขออภัยครับ ไม่สามารถประมวลผลคำตอบได้ในขณะนี้');
    
    chatHistory.push({
      sender: 'bot',
      text: botReply,
      time: new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' })
    });
    saveChatHistory();
    renderChatMessages();

  } catch (err) {
    const typingIndicator = document.getElementById('chatTypingIndicator');
    if (typingIndicator) typingIndicator.remove();

    chatHistory.push({
      sender: 'bot',
      text: '⚠️ ขออภัยครับ ไม่สามารถเชื่อมต่อกับบริการ AI ได้ในขณะนี้ โปรดตรวจสอบการเชื่อมต่ออินเทอร์เน็ต',
      time: new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' })
    });
    saveChatHistory();
    renderChatMessages();
  }
}

// ==================== Injection of Modals & Floating Chat HTML ====================
function injectSharedComponents() {
  // 1. ตรวจสอบ Navbar Profile Dropdown ถ้ายังไม่มีให้แทรกเข้าไปใน .nav
  const nav = document.querySelector('.nav');
  if (nav && !document.getElementById('navProfileDropdown')) {
    const navDropdownHtml = `
      <div class="nav-profile-dropdown" id="navProfileDropdown">
        <button class="nav-profile-btn" onclick="toggleProfileDropdown(event)" id="navProfileBtn">
          <span class="nav-avatar" id="navAvatar">👤</span>
          <span class="nav-username" id="navUsername">ผู้ใช้งาน</span>
          <span class="nav-dropdown-caret">▼</span>
        </button>
        <div class="dropdown-menu" id="profileDropdownMenu" style="display:none;">
          <div class="dropdown-header">
            <div class="dropdown-user-avatar" id="menuAvatar">👤</div>
            <div class="dropdown-user-info">
              <div class="dropdown-user-name" id="menuUsername">ผู้ใช้งาน</div>
              <div class="dropdown-user-role" id="menuUserRole">ผู้ใช้งาน</div>
            </div>
          </div>
          <div class="dropdown-divider"></div>
          <a href="admin.html" class="dropdown-item" id="menuAdminLink" style="display:none;color:#fbbf24;font-weight:600;">
            <span>👑</span> แผงควบคุมระบบ (Admin)
          </a>
          <a href="javascript:void(0)" class="dropdown-item" onclick="openProfileModal()">
            <span>👤</span> ข้อมูลโปรไฟล์ & ติดต่อฉุกเฉิน
          </a>
          <a href="javascript:void(0)" class="dropdown-item" onclick="openDevicesModal()">
            <span>⚙️</span> จัดการอุปกรณ์ (MAC Address)
          </a>
          <a href="javascript:void(0)" class="dropdown-item" onclick="openThemeModal()">
            <span>🎨</span> ธีม & การแสดงผล
          </a>
          <div class="dropdown-divider"></div>
          <a href="login.html?logout=1" class="dropdown-item dropdown-item-danger">
            <span>🚪</span> ออกจากระบบ
          </a>
        </div>
      </div>
    `;
    nav.insertAdjacentHTML('beforeend', navDropdownHtml);
  }

  // ย้าย #profileDropdownMenu ไปอยู่ใต้ document.body ทันที เพื่อไม่ให้โดน overflow-x ของ .nav ตัดขาด/บัง
  const pMenu = document.getElementById('profileDropdownMenu');
  if (pMenu && pMenu.parentElement !== document.body) {
    document.body.appendChild(pMenu);
  }

  // 2. แทรก Floating Chatbot Widget (ไม่มี Tooltip Speech Bubble ตามคำสั่งของคุณ)
  if (!document.getElementById('chatFloatingBtn')) {
    const floatingChatHtml = `
      <!-- Floating AI Chat Button (Clean, No Tooltip) -->
      <button class="chat-floating-btn" id="chatFloatingBtn" onclick="toggleFloatingChat()" title="ปรึกษาผู้ช่วย CareGuard AI">
        <span class="chat-pulse-ring"></span>
        💬
      </button>

      <!-- Floating AI Chat Window -->
      <div class="chat-floating-window minimized" id="chatFloatingWindow">
        <!-- Header -->
        <div class="chat-header">
          <div class="chat-header-info">
            <div class="chat-bot-icon">🤖</div>
            <div>
              <div class="chat-title">CareGuard AI</div>
              <div class="chat-status">
                <span class="chat-status-dot"></span> ผู้ช่วยสุขภาพอัจฉริยะ
              </div>
            </div>
          </div>
          <div class="chat-header-actions">
            <button class="chat-header-btn" onclick="clearChatHistory()" title="ล้างประวัติการแชท">🗑️</button>
            <button class="chat-header-btn" onclick="closeFloatingChat()" title="ย่อหน้าต่าง">✕</button>
          </div>
        </div>

        <!-- Suggestion Chips -->
        <div class="chat-chips">
          <span class="chat-chip" onclick="sendFloatingChatMessage('สรุปสุขภาพและการเคลื่อนไหววันนี้ให้หน่อย')">📊 สรุปสุขภาพวันนี้</span>
          <span class="chat-chip" onclick="sendFloatingChatMessage('วันนี้มีเหตุการณ์การล้มหรือไม่?')">🚨 เช็กการล้มวันนี้</span>
          <span class="chat-chip" onclick="sendFloatingChatMessage('ขอคำแนะนำการเดินออกกำลังกายสำหรับผู้สูงอายุ')">💡 คำแนะนำออกกำลังกาย</span>
          <span class="chat-chip" onclick="sendFloatingChatMessage('วิธีปฐมพยาบาลเบื้องต้นเมื่อผู้สูงอายุล้ม')">🏥 วิธีปฐมพยาบาล</span>
        </div>

        <!-- Chat Body -->
        <div class="chat-body" id="chatFloatingBody"></div>

        <!-- Chat Footer / Input -->
        <form class="chat-footer" onsubmit="event.preventDefault(); sendFloatingChatMessage();">
          <input type="text" class="chat-input" id="chatFloatingInput" placeholder="ถามสุขภาพ, ก้าวเดิน, การล้ม..." autocomplete="off">
          <button type="submit" class="chat-send-btn" title="ส่งข้อความ">➤</button>
        </form>
      </div>
    `;
    document.body.insertAdjacentHTML('beforeend', floatingChatHtml);
  }

  // 3. แทรก Modals (Profile, Devices, Theme)
  if (!document.getElementById('profileModal')) {
    const modalsHtml = `
      <!-- 1. Profile Modal (Required Email & Phone) -->
      <div class="modal-overlay" id="profileModal">
        <div class="modal-content">
          <div class="modal-header">
            <div class="modal-title"><span>👤</span> ข้อมูลโปรไฟล์ & เบอร์ติดต่อฉุกเฉิน</div>
            <button class="modal-close-btn" onclick="closeModal('profileModal')">✕</button>
          </div>
          <form onsubmit="saveProfile(event)" class="modal-body">
            <div style="background:rgba(0,210,255,0.08);border-left:3px solid var(--accent);padding:10px 14px;border-radius:6px;font-size:12.5px;color:var(--text-secondary);margin-bottom:16px;">
              ℹ️ <strong>จำเป็นต้องระบุ:</strong> อีเมลและเบอร์โทรติดต่อฉุกเฉิน เพื่อใช้ส่งแจ้งเตือนและติดต่อช่วยเหลือทันทีเมื่อเกิดการล้มรุนแรง (High Severity Fall)
            </div>

            <div class="form-group">
              <label class="form-label">เลือกรูป Avatar</label>
              <div class="avatar-picker">
                <div class="avatar-option selected" data-avatar="👤" onclick="selectAvatar(this)">👤</div>
                <div class="avatar-option" data-avatar="👴" onclick="selectAvatar(this)">👴</div>
                <div class="avatar-option" data-avatar="👵" onclick="selectAvatar(this)">👵</div>
                <div class="avatar-option" data-avatar="👨‍⚕️" onclick="selectAvatar(this)">👨‍⚕️</div>
                <div class="avatar-option" data-avatar="👩‍⚕️" onclick="selectAvatar(this)">👩‍⚕️</div>
                <div class="avatar-option" data-avatar="🏃‍♂️" onclick="selectAvatar(this)">🏃‍♂️</div>
                <div class="avatar-option" data-avatar="🧘‍♀️" onclick="selectAvatar(this)">🧘‍♀️</div>
                <div class="avatar-option" data-avatar="⚡" onclick="selectAvatar(this)">⚡</div>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">ชื่อ - นามสกุล ผู้ใช้งาน <span style="color:var(--danger)">*</span></label>
              <input type="text" id="profName" class="form-input" required placeholder="เช่น สมชาย ใจดี">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
              <div class="form-group">
                <label class="form-label">อีเมลผู้ใช้งาน <span style="color:var(--danger)">*</span></label>
                <input type="email" id="profEmail" class="form-input" required placeholder="user@example.com">
              </div>
              <div class="form-group">
                <label class="form-label">เบอร์โทรศัพท์ผู้ใช้งาน <span style="color:var(--danger)">*</span></label>
                <input type="tel" id="profPhone" class="form-input" required placeholder="0812345678">
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:16px;">
              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">อายุ (ปี)</label>
                <input type="number" id="profAge" class="form-input" min="1" max="130" placeholder="65">
              </div>
              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">น้ำหนัก (กก.)</label>
                <input type="number" id="profWeight" class="form-input" step="0.1" placeholder="62.5">
              </div>
              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">ส่วนสูง (ซม.)</label>
                <input type="number" id="profHeight" class="form-input" step="0.5" placeholder="165">
              </div>
              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">เพศ</label>
                <select id="profGender" class="form-select">
                  <option value="male">ชาย</option>
                  <option value="female">หญิง</option>
                </select>
              </div>
            </div>

            <!-- ข้อมูลญาติ / ผู้ติดต่อฉุกเฉิน (เพิ่มเมล และ เบอร์ญาติ) -->
            <div style="background:rgba(0,210,255,0.05);border:1px solid rgba(0,210,255,0.25);border-radius:10px;padding:14px;margin-bottom:16px;">
              <div style="font-weight:700;color:var(--accent);font-size:13.5px;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                <span>👨‍👩‍👧</span> ข้อมูลญาติ / ผู้ติดต่อฉุกเฉิน (แจ้งเตือนทันทีเมื่อเกิดเหตุ)
              </div>

              <div class="form-group">
                <label class="form-label">ชื่อ - นามสกุล ญาติหรือผู้ดูแล</label>
                <input type="text" id="profEmerName" class="form-input" placeholder="เช่น ลูกสาว (คุณสมใจ ใจดี)">
              </div>

              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:start;">
                <div class="form-group" style="margin-bottom:0;display:flex;flex-direction:column;">
                  <label class="form-label" style="min-height:38px;display:flex;align-items:flex-end;margin-bottom:6px;">
                    <span>เบอร์โทรศัพท์ญาติ <span style="color:var(--danger)">* (สำหรับโทรด่วน)</span></span>
                  </label>
                  <input type="tel" id="profEmerPhone" class="form-input" required placeholder="0898765432" style="width:100%;">
                </div>
                <div class="form-group" style="margin-bottom:0;display:flex;flex-direction:column;">
                  <label class="form-label" style="min-height:38px;display:flex;align-items:flex-end;margin-bottom:6px;">
                    <span>อีเมลญาติ (สำหรับรับแจ้งเตือนฉุกเฉิน)</span>
                  </label>
                  <input type="email" id="profEmerEmail" class="form-input" placeholder="relative@example.com" style="width:100%;">
                </div>
              </div>

              <div class="form-group" style="margin-top:10px;margin-bottom:0;">
                <label class="form-label">✈️ Telegram Chat ID (รับแจ้งเตือนการล้มอัตโนมัติผ่าน Telegram)</label>
                <input type="text" id="profTelegramChatId" class="form-input" placeholder="เช่น 123456789 หรือ @username (ดูได้จาก @userinfobot)">
                <div style="font-size:11.5px;color:var(--text-muted);margin-top:3px;">
                  💡 พิมพ์ <strong>/start</strong> ในบอท Telegram (HW1: <a href="https://t.me/CareGuardAI1_bot" target="_blank" style="color:var(--primary);text-decoration:underline;">@CareGuardAI1_bot</a> หรือ HW2: <a href="https://t.me/CareGuardAI2_bot" target="_blank" style="color:var(--primary);text-decoration:underline;">@CareGuardAI2_bot</a>) แล้วใส่ Chat ID ของคุณที่นี่
                </div>
              </div>
            </div>

            <div class="modal-footer" style="padding:12px 0 0 0;background:transparent;">
              <button type="button" class="btn btn-outline" onclick="closeModal('profileModal')">ยกเลิก</button>
              <button type="submit" class="btn btn-primary">💾 บันทึกข้อมูลโปรไฟล์</button>
            </div>
          </form>
        </div>
      </div>

      <!-- 2. Devices MAC Management Modal -->
      <div class="modal-overlay" id="devicesModal">
        <div class="modal-content" style="max-width:580px;">
          <div class="modal-header">
            <div class="modal-title"><span>⚙️</span> จัดการอุปกรณ์ฮาร์ดแวร์ & ผูก MAC Address</div>
            <button class="modal-close-btn" onclick="closeModal('devicesModal')">✕</button>
          </div>
          <div class="modal-body">
            <div style="margin-bottom:14px;font-size:13px;color:var(--text-secondary);">
              ผูก MAC Address บอร์ด ESP32 เพื่อให้ระบบส่งพิกัดและการล้มเข้าบัญชีของคุณโดยอัตโนมัติ
            </div>

            <!-- List of bound devices -->
            <div style="font-size:13px;font-weight:700;margin-bottom:8px;color:var(--text-primary);">📱 อุปกรณ์ที่ผูกอยู่ในระบบ</div>
            <div id="deviceListContainer" style="display:flex;flex-direction:column;gap:8px;max-height:180px;overflow-y:auto;margin-bottom:18px;"></div>

            <!-- Form to add new device -->
            <div style="background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:10px;padding:14px;">
              <div style="font-size:13.5px;font-weight:700;margin-bottom:10px;color:var(--accent);">➕ เพิ่มและผูกบอร์ดใหม่ด้วย MAC Address</div>
              <form onsubmit="saveNewDevice(event)">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                  <div class="form-group">
                    <label class="form-label">MAC Address ของ ESP32 <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="devMacAddress" class="form-input" placeholder="24:6F:28:AB:CD:EF" required style="font-family:var(--font-mono)">
                  </div>
                  <div class="form-group">
                    <label class="form-label">ชื่ออุปกรณ์</label>
                    <input type="text" id="devName" class="form-input" placeholder="เช่น เข็มขัดคุณตา (HW1)">
                  </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:center;">
                  <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">ประเภทฮาร์ดแวร์</label>
                    <select id="devType" class="form-select">
                      <option value="auto">ตรวจจับอัตโนมัติ (แนะนำ)</option>
                      <option value="hw1_gps">Hardware 1: GPS NEO-7M (กลางแจ้ง)</option>
                      <option value="hw2_wifi">Hardware 2: Wi-Fi Geolocation (ในบ้าน)</option>
                    </select>
                  </div>
                  <div style="padding-top:18px;">
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;color:var(--text-primary);">
                      <input type="checkbox" id="devIsPrimary" checked style="accent-color:var(--accent);">
                      ตั้งเป็นอุปกรณ์หลักที่ใช้งาน
                    </label>
                  </div>
                </div>

                <div style="margin-top:14px;text-align:right;">
                  <button type="submit" class="btn btn-success btn-sm" style="padding:7px 16px;">
                    🔗 บันทึกและผูกอุปกรณ์
                  </button>
                </div>
              </form>
            </div>
          </div>

        </div>
      </div>

      <!-- 3. Theme & Display Modal -->
      <div class="modal-overlay" id="themeModal">
        <div class="modal-content" style="max-width:460px;">
          <div class="modal-header">
            <div class="modal-title"><span>🎨</span> ปรับแต่งธีม & รูปแบบการแสดงผล</div>
            <button class="modal-close-btn" onclick="closeModal('themeModal')">✕</button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label class="form-label">🎨 เลือกธีมสีหลัก</label>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px;">
                <label style="background:#0f0f23;border:1px solid #00d2ff;border-radius:8px;padding:10px;display:flex;align-items:center;gap:8px;cursor:pointer;color:#f0f3f6;">
                  <input type="radio" name="themeChoice" value="dark" checked style="accent-color:#00d2ff;">
                  <span>🌙 Cyber Dark</span>
                </label>
                <label style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:8px;padding:10px;display:flex;align-items:center;gap:8px;cursor:pointer;color:#0f172a;">
                  <input type="radio" name="themeChoice" value="light" style="accent-color:#0284c7;">
                  <span>☀️ Clean Light</span>
                </label>
                <label style="background:#0a192f;border:1px solid #64ffda;border-radius:8px;padding:10px;display:flex;align-items:center;gap:8px;cursor:pointer;color:#e6f1ff;">
                  <input type="radio" name="themeChoice" value="ocean" style="accent-color:#64ffda;">
                  <span>🌊 Deep Ocean</span>
                </label>
                <label style="background:#120b24;border:1px solid #a855f7;border-radius:8px;padding:10px;display:flex;align-items:center;gap:8px;cursor:pointer;color:#f8fafc;">
                  <input type="radio" name="themeChoice" value="purple" style="accent-color:#a855f7;">
                  <span>🔮 Royal Purple</span>
                </label>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">🔤 เลือกฟอนต์ตัวอักษร</label>
              <select id="fontChoice" class="form-select">
                <option value="default">ค่าเริ่มต้น (Segoe UI / Noto Sans Thai)</option>
                <option value="sarabun">Sarabun (ทางการ อ่านง่าย)</option>
                <option value="kanit">Kanit (ทันสมัย หรูหรา)</option>
                <option value="prompt">Prompt (เรียบโมเดิร์น)</option>
                <option value="inter">Inter (สากล)</option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">🔍 ขนาดตัวอักษร</label>
              <select id="fontSizeChoice" class="form-select">
                <option value="small">เล็ก (13.5px)</option>
                <option value="medium" selected>ปานกลาง (15px - แนะนำ)</option>
                <option value="large">ใหญ่พิเศษ (16.5px - เหมาะกับผู้สูงอายุ)</option>
              </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('themeModal')">ยกเลิก</button>
            <button type="button" class="btn btn-primary" onclick="applyThemeModal()">💾 บันทึกและปรับใช้</button>
          </div>
        </div>
      </div>

      <!-- 4. Emergency Fall Popup Modal Window (แสดงผลทันทีเมื่อตรวจพบการล้ม) -->
      <div class="modal-overlay emergency-overlay" id="emergencyFallModal" style="z-index:99999;display:none;">
        <div class="modal-content emergency-modal-content">
          <div class="emergency-modal-header">
            <div class="emergency-badge-pulse">🚨 ฉุกเฉิน</div>
            <h2 class="emergency-modal-title">ตรวจพบเหตุการณ์การล้ม!</h2>
            <button type="button" class="emergency-close-btn" onclick="dismissAlert()" title="ปิดหน้าต่าง">✕</button>
          </div>
          <div class="emergency-modal-body">
            <div class="emergency-radar-box">
              <div class="emergency-fall-icon" id="emerModalIcon">🏃‍♂️</div>
              <div class="emergency-fall-dir" id="emerModalDir">ล้มไปข้างหน้า (Forward)</div>
              <div class="emergency-fall-g" id="emerModalG">แรงกระแทก: <strong>0.00 G</strong> (0.0 m/s²)</div>
            </div>

            <div class="emergency-details-grid">
              <div class="emergency-detail-item">
                <span class="detail-label">👤 ผู้ใช้งาน</span>
                <span class="detail-val" id="emerModalUser">คุณ</span>
              </div>
              <div class="emergency-detail-item">
                <span class="detail-label">🎯 ความมั่นใจ AI</span>
                <span class="detail-val" id="emerModalConf">--%</span>
              </div>
              <div class="emergency-detail-item">
                <span class="detail-label">💥 ระดับความรุนแรง</span>
                <span class="detail-val" id="emerModalSev"><span class="pill pill-red">HIGH</span></span>
              </div>
              <div class="emergency-detail-item">
                <span class="detail-label">⏰ เวลาที่เกิดเหตุ</span>
                <span class="detail-val" id="emerModalTime">--:--:--</span>
              </div>
            </div>

            <div class="emergency-loc-card" id="emerModalLocCard">
              <div style="font-weight:600;display:flex;align-items:center;justify-content:space-between;gap:6px;flex-wrap:wrap;">
                <span>📍 พิกัด: <strong id="emerModalCoords" style="color:var(--accent);font-family:var(--font-mono);">--</strong></span>
                <a id="emerModalGmapsBtn" href="https://maps.google.com" target="_blank" class="btn btn-outline btn-sm" style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;text-decoration:none;font-size:12px;">
                  <span>🗺️ Google Maps</span>
                </a>
              </div>
            </div>

            <div class="emergency-contact-box" id="emerModalContactBox" style="display:none;">
              <div style="font-size:12.5px;color:var(--text-secondary);margin-bottom:6px;">👨‍👩‍👧 ติดต่อญาติ / ผู้ดูแลด่วน:</div>
              <a id="emerModalCallBtn" href="tel:" class="btn btn-danger btn-sm" style="display:inline-flex;align-items:center;gap:8px;font-weight:700;padding:8px 18px;text-decoration:none;background:#ff5252;color:#ffffff;">
                <span>📞 โทรติดต่อทันที: <span id="emerModalPhone">--</span></span>
              </a>
            </div>
          </div>
          <div class="emergency-modal-footer">
            <button type="button" class="btn btn-outline" onclick="dismissAlert()" style="flex:1;">✕ ปิดการเตือน</button>
            <button type="button" class="btn btn-success" id="emerModalAssistBtn" onclick="handleModalAssist()" style="flex:1.6;font-weight:700;background:#00e676;color:#0f172a;border:none;">✅ ยืนยันการช่วยเหลือแล้ว</button>
          </div>
        </div>
      </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalsHtml);
  }
}

// ==================== Initialize On Page Load ====================
document.addEventListener('DOMContentLoaded', () => {
  initThemeSettings();
  injectSharedComponents();
  loadUserProfileNav();
  initFloatingChat();
  checkDeviceStatusGlobal();
  if (!window.location.pathname.endsWith('index.html') && window.location.pathname !== (BASE + '/') && window.location.pathname !== (BASE)) {
    setInterval(checkDeviceStatusGlobal, 4000);
  }
});


