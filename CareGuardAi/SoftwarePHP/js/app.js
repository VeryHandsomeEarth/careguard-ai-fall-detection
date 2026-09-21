/*
 * app.js — SoftwarePHP Dashboard v2.0
 * แทน Socket.IO ด้วย polling ทุก 3 วินาที
 */

// ==================== การตั้งค่า ====================
const API_BASE = window.location.pathname.replace(/\/(index\.html)?$/, '');
const ROOM_WIDTH  = 3;
const ROOM_HEIGHT = 3;
const POLL_INTERVAL = 3000; // ms

// ==================== สถานะ ====================
let state = {
    steps: 0,
    distance: 0,
    fallCount: 0,
    rssi: 0,
    distanceFromBase: 0,
    weeklySteps: 0,
    weeklyDistance: 0,
    recentFalls: [],
    devices: {},
    targetPosition: { x: 1.5, y: 1.5 },
    interNodeDistances: [],
    lastFallId: 0  // tracking ใหม่ เพื่อตรวจ fall ใหม่
};

let stepsChart  = null;
let hourlyChart = null;

// ==================== การตั้งค่าธีม ====================
let settings = { theme: 'dark', font: 'Inter', fontSize: 14 };

function loadSettings() {
    try {
        const saved = localStorage.getItem('dashboardSettings');
        if (saved) settings = { ...settings, ...JSON.parse(saved) };
    } catch (e) {}
    applySettings();
}
function saveSettings() { localStorage.setItem('dashboardSettings', JSON.stringify(settings)); }
function applySettings() {
    document.documentElement.setAttribute('data-theme', settings.theme);
    document.body.style.setProperty('--font-family', `'${settings.font}'`);
    document.body.style.setProperty('--font-size', `${settings.fontSize}px`);
    document.querySelectorAll('.theme-btn').forEach(btn =>
        btn.classList.toggle('active', btn.dataset.theme === settings.theme));
    const fontSelect = document.getElementById('fontSelect');
    if (fontSelect) fontSelect.value = settings.font;
    const sizeDisplay = document.getElementById('fontSizeDisplay');
    if (sizeDisplay) sizeDisplay.textContent = settings.fontSize + 'px';
}
function setTheme(theme) { settings.theme = theme; saveSettings(); applySettings(); drawMiniMap(); }
function setFont(font)   { settings.font  = font;  saveSettings(); applySettings(); }
function changeFontSize(delta) {
    settings.fontSize = Math.max(10, Math.min(22, settings.fontSize + delta));
    saveSettings(); applySettings();
}
function toggleSettings() { document.getElementById('settingsModal').classList.toggle('hidden'); }

// ==================== เริ่มต้นระบบ ====================
document.addEventListener('DOMContentLoaded', () => {
    loadSettings();
    initializeCharts();
    loadInitialData();
    updateClock();
    setInterval(updateClock, 1000);
    drawMiniMap();

    // แทน Socket.IO: polling หลายช่องทาง
    startPolling();

    // Tab switching
    document.querySelectorAll('.chart-tabs .tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.chart-tabs .tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            updateStepsChart(tab.dataset.chart);
        });
    });
});

// ==================== Polling แทน Socket.IO ====================
function startPolling() {
    updateConnectionStatus('connected');

    // ดึงข้อมูล falls ใหม่ทุก 3 วินาที
    setInterval(pollFalls,    POLL_INTERVAL);
    // ดึงตำแหน่งล่าสุดทุก 3 วินาที
    setInterval(pollPosition, POLL_INTERVAL);
    // ดึง stats รายวันทุก 10 วินาที
    setInterval(pollDailyStats, 10000);
    // ดึง inter-node distances ทุก 5 วินาที
    setInterval(pollDistances,  5000);
}

async function pollFalls() {
    try {
        const res    = await fetch(`${API_BASE}/api/falls?limit=10`);
        const result = await res.json();
        if (!result.success) return;

        const falls = result.data;
        if (falls.length > 0) {
            const latestId = falls[0].id;
            if (latestId > state.lastFallId && state.lastFallId > 0) {
                // มี fall ใหม่
                handleFallAlert(falls[0]);
            }
            if (state.lastFallId === 0) state.lastFallId = latestId;
            state.lastFallId  = latestId;
            state.recentFalls = falls;
            updateRecentFallsList();
        }
        updateConnectionStatus('connected');
    } catch (e) {
        updateConnectionStatus('disconnected');
    }
}

async function pollPosition() {
    try {
        const res    = await fetch(`${API_BASE}/api/position/latest`);
        const result = await res.json();
        if (!result.success || !result.data || !result.data.length) return;

        result.data.forEach(d => handlePositionUpdate(d));
        updateConnectionStatus('connected');
    } catch (e) {}
}

async function pollDailyStats() {
    try {
        const res    = await fetch(`${API_BASE}/api/stats/daily`);
        const result = await res.json();
        if (!result.success) return;

        const d = result.data;
        state.steps    = parseInt(d.steps?.total_steps  || 0);
        state.distance = parseFloat(d.steps?.total_distance || 0);
        state.fallCount = parseInt(d.falls?.fall_count || 0);
        updateStatsDisplay();
    } catch (e) {}
}

async function pollDistances() {
    try {
        const res    = await fetch(`${API_BASE}/api/position/distance`);
        const result = await res.json();
        if (!result.success) return;

        state.interNodeDistances = result.distances || [];

        // อัปเดต device positions จาก nodes
        (result.nodes || []).forEach(n => {
            if (state.devices[n.device_id]) {
                state.devices[n.device_id].x = n.position_x || 0;
                state.devices[n.device_id].y = n.position_y || 0;
            }
        });

        drawMiniMap();
    } catch (e) {}
}

function updateConnectionStatus(status) {
    const badge = document.getElementById('connectionStatus');
    if (!badge) return;
    badge.className = 'status-badge ' + status;
    const span = badge.querySelector('span:last-child');
    if (span) span.textContent = status === 'connected' ? 'Connected' : 'Connecting...';
}

// ==================== ตัวจัดการเหตุการณ์ ====================
function handleFallAlert(data) {
    console.log('[Fall Alert]', data);
    state.fallCount++;

    const alertBanner = document.getElementById('fallAlert');
    if (alertBanner) alertBanner.classList.remove('hidden');
    playAlertSound();

    const fallCountEl = document.getElementById('fallCount');
    if (fallCountEl) fallCountEl.textContent = state.fallCount;

    const fallCard = document.getElementById('fallCard');
    if (fallCard) fallCard.classList.add('alert');

    const fallStatus = document.getElementById('fallStatus');
    if (fallStatus) fallStatus.innerHTML = `<span class="status-danger">⚠️ ตรวจพบการล้ม!</span>`;

    addFallToList(data);
    updateLastUpdateTime();
}

function handlePositionUpdate(data) {
    const deviceId = data.device_id || 'NODE_1';
    state.devices[deviceId] = {
        rssi:     data.rssi     || 0,
        distance: data.distance || 0,
        x:        data.position_x || 0,
        y:        data.position_y || 0,
        lastSeen: Date.now()
    };

    state.steps            = data.step_count    || state.steps;
    state.distance         = data.walk_distance || state.distance;
    state.rssi             = data.rssi          || state.rssi;
    state.distanceFromBase = data.distance      || state.distanceFromBase;

    calculateTargetPosition();
    updateStatsDisplay();
    updateLastUpdateTime();
    drawMiniMap();
    updateOnlineDeviceCount();
}

// ==================== Triangulation ====================
function calculateTargetPosition() {
    const devices = Object.entries(state.devices)
        .filter(([, d]) => Date.now() - d.lastSeen < 30000 && d.distance > 0);

    if (devices.length === 0) {
        state.targetPosition = { x: ROOM_WIDTH / 2, y: ROOM_HEIGHT / 2 };
        return;
    }
    if (devices.length === 1) {
        const [, d] = devices[0];
        const angle = Math.PI / 4;
        state.targetPosition = {
            x: Math.max(0, Math.min(ROOM_WIDTH,  d.x + d.distance * Math.cos(angle))),
            y: Math.max(0, Math.min(ROOM_HEIGHT, d.y + d.distance * Math.sin(angle)))
        };
        return;
    }
    let totalWeight = 0, wx = 0, wy = 0;
    devices.forEach(([, d]) => {
        const weight = 1 / Math.max(d.distance, 0.1);
        totalWeight += weight;
        devices.forEach(([, other]) => {
            if (d !== other) {
                const dx   = other.x - d.x;
                const dy   = other.y - d.y;
                const dist = Math.sqrt(dx * dx + dy * dy);
                if (dist > 0) {
                    const ratio = d.distance / dist;
                    wx += (d.x + dx * ratio) * weight;
                    wy += (d.y + dy * ratio) * weight;
                }
            }
        });
    });
    if (totalWeight > 0) {
        const pairCount = devices.length * (devices.length - 1);
        state.targetPosition = {
            x: Math.max(0, Math.min(ROOM_WIDTH,  wx / (totalWeight * (pairCount || 1)) * devices.length)),
            y: Math.max(0, Math.min(ROOM_HEIGHT, wy / (totalWeight * (pairCount || 1)) * devices.length))
        };
    }
}

// ==================== แผนที่จำลอง ====================
function drawMiniMap() {
    const canvas = document.getElementById('miniMap');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const w = canvas.width, h = canvas.height, pad = 30;
    const mapW = w - pad * 2, mapH = h - pad * 2;

    const isDark    = settings.theme !== 'light';
    const bgColor   = isDark ? '#1a1f26' : '#f7f8fa';
    const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
    const textColor = isDark ? '#8b949e' : '#656d76';
    const nodeColor   = settings.theme === 'blue' ? '#57cbff' : '#58a6ff';
    const targetColor = '#f85149';
    const rangeColor  = isDark ? 'rgba(88,166,255,0.08)' : 'rgba(9,105,218,0.06)';

    ctx.clearRect(0, 0, w, h);
    ctx.fillStyle = bgColor;
    ctx.fillRect(0, 0, w, h);

    ctx.strokeStyle = gridColor;
    ctx.lineWidth = 1;
    for (let i = 0; i <= ROOM_WIDTH * 2; i++) {
        const x = pad + (i / (ROOM_WIDTH * 2)) * mapW;
        ctx.beginPath(); ctx.moveTo(x, pad); ctx.lineTo(x, pad + mapH); ctx.stroke();
    }
    for (let i = 0; i <= ROOM_HEIGHT * 2; i++) {
        const y = pad + (i / (ROOM_HEIGHT * 2)) * mapH;
        ctx.beginPath(); ctx.moveTo(pad, y); ctx.lineTo(pad + mapW, y); ctx.stroke();
    }

    ctx.strokeStyle = isDark ? 'rgba(255,255,255,0.15)' : 'rgba(0,0,0,0.15)';
    ctx.lineWidth = 2;
    ctx.strokeRect(pad, pad, mapW, mapH);

    ctx.fillStyle = textColor; ctx.font = '10px Inter';
    ctx.textAlign = 'center';
    for (let i = 0; i <= ROOM_WIDTH; i++)
        ctx.fillText(i + 'm', pad + (i / ROOM_WIDTH) * mapW, h - 6);
    ctx.textAlign = 'right';
    for (let i = 0; i <= ROOM_HEIGHT; i++)
        ctx.fillText(i + 'm', pad - 6, pad + (i / ROOM_HEIGHT) * mapH + 4);

    const toX = mx => pad + (mx / ROOM_WIDTH)  * mapW;
    const toY = my => pad + (my / ROOM_HEIGHT) * mapH;

    const activeDevices = Object.entries(state.devices)
        .filter(([, d]) => Date.now() - d.lastSeen < 30000);

    activeDevices.forEach(([id, d]) => {
        const cx = toX(d.x), cy = toY(d.y);
        if (d.distance > 0) {
            const rangeR = (d.distance / ROOM_WIDTH) * mapW;
            ctx.beginPath(); ctx.arc(cx, cy, rangeR, 0, Math.PI * 2);
            ctx.fillStyle = rangeColor; ctx.fill();
            ctx.strokeStyle = isDark ? 'rgba(88,166,255,0.2)' : 'rgba(9,105,218,0.15)';
            ctx.lineWidth = 1; ctx.stroke();
        }
        ctx.beginPath(); ctx.arc(cx, cy, 8, 0, Math.PI * 2);
        ctx.fillStyle = nodeColor; ctx.fill();
        ctx.beginPath(); ctx.arc(cx, cy, 12, 0, Math.PI * 2);
        ctx.strokeStyle = nodeColor; ctx.lineWidth = 2; ctx.globalAlpha = 0.3; ctx.stroke(); ctx.globalAlpha = 1;
        ctx.fillStyle = textColor; ctx.font = 'bold 9px Inter'; ctx.textAlign = 'center';
        ctx.fillText(id, cx, cy - 14);
    });

    if (state.interNodeDistances.length > 0 && activeDevices.length >= 2) {
        state.interNodeDistances.forEach(d => {
            const from = state.devices[d.from], to = state.devices[d.to];
            if (!from || !to) return;
            const x1 = toX(from.x), y1 = toY(from.y), x2 = toX(to.x), y2 = toY(to.y);
            ctx.beginPath(); ctx.setLineDash([4, 4]);
            ctx.strokeStyle = isDark ? 'rgba(163,113,247,0.5)' : 'rgba(130,80,220,0.5)';
            ctx.lineWidth = 1.5; ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke(); ctx.setLineDash([]);
            const mx = (x1 + x2) / 2, my = (y1 + y2) / 2;
            const label = (parseFloat(d.distance) || 0).toFixed(2) + 'm';
            ctx.font = 'bold 9px Inter';
            const tw = ctx.measureText(label).width;
            ctx.fillStyle = isDark ? 'rgba(30,35,44,0.85)' : 'rgba(255,255,255,0.85)';
            ctx.beginPath(); ctx.roundRect(mx - tw / 2 - 4, my - 7, tw + 8, 14, 4); ctx.fill();
            ctx.fillStyle = isDark ? '#a371f7' : '#8250df'; ctx.textAlign = 'center';
            ctx.fillText(label, mx, my + 3);
        });
    }

    const tx = toX(state.targetPosition.x), ty = toY(state.targetPosition.y);
    ctx.beginPath(); ctx.arc(tx, ty, 14, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(248,81,73,0.15)'; ctx.fill();
    ctx.beginPath(); ctx.arc(tx, ty, 7, 0, Math.PI * 2);
    ctx.fillStyle = targetColor; ctx.fill();
    ctx.strokeStyle = targetColor; ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.moveTo(tx-12,ty); ctx.lineTo(tx-5,ty);
    ctx.moveTo(tx+5,ty);  ctx.lineTo(tx+12,ty);
    ctx.moveTo(tx,ty-12); ctx.lineTo(tx,ty-5);
    ctx.moveTo(tx,ty+5);  ctx.lineTo(tx,ty+12);
    ctx.stroke();
    ctx.fillStyle = targetColor; ctx.font = 'bold 9px Inter'; ctx.textAlign = 'center';
    ctx.fillText('HW1', tx, ty - 18);

    if (activeDevices.length === 0) {
        ctx.fillStyle = textColor; ctx.font = '12px Inter'; ctx.textAlign = 'center';
        ctx.fillText('รอการเชื่อมต่ออุปกรณ์...', w / 2, h / 2);
    }
}

function updateOnlineDeviceCount() {
    const count = Object.values(state.devices).filter(d => Date.now() - d.lastSeen < 30000).length;
    const el = document.getElementById('onlineDevices');
    if (el) el.textContent = count;
}

// ==================== อัปเดต UI ====================
function updateStatsDisplay() {
    document.getElementById('stepCount').textContent = formatNumber(state.steps);
    const progress = Math.min((state.steps / 10000) * 100, 100);
    document.getElementById('stepProgress').style.width = progress + '%';
    document.getElementById('distance').textContent        = (state.distance || 0).toFixed(1);
    document.getElementById('rssiValue').textContent       = state.rssi || '--';
    document.getElementById('distanceFromBase').textContent =
        state.distanceFromBase ? state.distanceFromBase.toFixed(2) : '--';
    document.getElementById('fallCount').textContent = state.fallCount;
    document.getElementById('weeklySteps').textContent   = formatNumber(state.weeklySteps);
    document.getElementById('weeklyDistance').textContent = (state.weeklyDistance || 0).toFixed(0);
}

function updateRecentFallsList() {
    const container = document.getElementById('recentFalls');
    if (!container) return;
    if (state.recentFalls.length === 0) {
        container.innerHTML = '<div class="activity-empty">ไม่มีเหตุการณ์</div>'; return;
    }
    container.innerHTML = state.recentFalls.map(fall => `
        <div class="activity-item fall">
            <span class="activity-icon">⚠️</span>
            <div class="activity-info">
                <span class="activity-title">ตรวจพบการล้ม</span>
                <span class="activity-time">${formatTime(fall.timestamp)}</span>
            </div>
            <span class="activity-severity ${fall.severity || 'medium'}">${fall.severity || 'medium'}</span>
        </div>
    `).join('');
}

function addFallToList(fall) {
    const container = document.getElementById('recentFalls');
    if (!container) return;
    const emptyMsg = container.querySelector('.activity-empty');
    if (emptyMsg) emptyMsg.remove();
    const item = document.createElement('div');
    item.className = 'activity-item fall';
    item.innerHTML = `
        <span class="activity-icon">⚠️</span>
        <div class="activity-info">
            <span class="activity-title">ตรวจพบการล้ม</span>
            <span class="activity-time">${formatTime(fall.timestamp)}</span>
        </div>
        <span class="activity-severity ${fall.severity || 'medium'}">${fall.severity || 'medium'}</span>
    `;
    container.insertBefore(item, container.firstChild);
    while (container.children.length > 5) container.removeChild(container.lastChild);
}

function dismissAlert() {
    document.getElementById('fallAlert').classList.add('hidden');
    setTimeout(() => {
        const fallCard   = document.getElementById('fallCard');
        const fallStatus = document.getElementById('fallStatus');
        if (fallCard)   fallCard.classList.remove('alert');
        if (fallStatus) fallStatus.innerHTML = '<span class="status-safe">✓ ปลอดภัย</span>';
    }, 5000);
}

function playAlertSound() {
    try {
        const ctx  = new (window.AudioContext || window.webkitAudioContext)();
        const osc  = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain); gain.connect(ctx.destination);
        osc.frequency.value = 880; osc.type = 'sine';
        gain.gain.setValueAtTime(0.3, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.5);
        osc.start(ctx.currentTime); osc.stop(ctx.currentTime + 0.5);
    } catch (e) {}
}

// ==================== กราฟ ====================
function initializeCharts() {
    const stepsCtx = document.getElementById('stepsChart')?.getContext('2d');
    if (stepsCtx) {
        stepsChart = new Chart(stepsCtx, {
            type: 'bar',
            data: { labels: [], datasets: [{ label: 'จำนวนก้าว', data: [],
                backgroundColor: 'rgba(63,185,80,0.6)', borderColor: '#3fb950',
                borderWidth: 1, borderRadius: 5 }] },
            options: { responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' },
                    ticks: { color: '#8b949e' } },
                    x: { grid: { display: false }, ticks: { color: '#8b949e' } } } }
        });
    }

    const hourlyCtx = document.getElementById('hourlyChart')?.getContext('2d');
    if (hourlyCtx) {
        hourlyChart = new Chart(hourlyCtx, {
            type: 'line',
            data: { labels: Array.from({length:24},(_,i)=>`${i}:00`),
                datasets: [{ label: 'กิจกรรม', data: Array(24).fill(0),
                    borderColor: '#58a6ff', backgroundColor: 'rgba(88,166,255,0.1)',
                    fill: true, tension: 0.4, pointRadius: 2, pointHoverRadius: 5 }] },
            options: { responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' },
                    ticks: { color: '#8b949e' } },
                    x: { grid: { display: false }, ticks: { color: '#8b949e', maxTicksLimit: 12 } } } }
        });
    }
}

async function updateStepsChart(type = 'steps') {
    try {
        const res    = await fetch(`${API_BASE}/api/stats/weekly`);
        const result = await res.json();
        if (!result.success || !result.data) return;
        const weekly = result.data.weeklySteps || [];
        stepsChart.data.labels             = weekly.map(d => formatDateShort(d.date));
        stepsChart.data.datasets[0].data   = weekly.map(d => type === 'steps' ? d.total_steps : d.total_distance);
        stepsChart.data.datasets[0].label  = type === 'steps' ? 'จำนวนก้าว' : 'ระยะทาง (เมตร)';
        stepsChart.data.datasets[0].backgroundColor = type === 'steps'
            ? 'rgba(63,185,80,0.6)' : 'rgba(163,113,247,0.6)';
        stepsChart.data.datasets[0].borderColor = type === 'steps' ? '#3fb950' : '#a371f7';
        stepsChart.update();
    } catch (e) { console.error('Steps chart error:', e); }
}

async function updateHourlyChart() {
    try {
        const res    = await fetch(`${API_BASE}/api/stats/hourly`);
        const result = await res.json();
        if (!result.success || !result.data) return;
        const hourly = result.data.hourlySteps || [];
        const data   = Array(24).fill(0);
        hourly.forEach(h => { data[parseInt(h.hour)] = parseInt(h.steps) || 0; });
        hourlyChart.data.datasets[0].data = data;
        hourlyChart.update();
    } catch (e) { console.error('Hourly chart error:', e); }
}

// ==================== โหลดข้อมูลเริ่มต้น ====================
async function loadInitialData() {
    try {
        // daily stats
        const dailyRes = await fetch(`${API_BASE}/api/stats/daily`);
        const daily    = await dailyRes.json();
        if (daily.success) {
            state.steps     = parseInt(daily.data.steps?.total_steps  || 0);
            state.distance  = parseFloat(daily.data.steps?.total_distance || 0);
            state.fallCount = parseInt(daily.data.falls?.fall_count || 0);
        }

        // weekly stats for weekly card
        const weeklyRes = await fetch(`${API_BASE}/api/stats/weekly`);
        const weekly    = await weeklyRes.json();
        if (weekly.success) {
            const rows    = weekly.data.weeklySteps || [];
            state.weeklySteps    = rows.reduce((s, r) => s + parseInt(r.total_steps || 0), 0) / Math.max(rows.length, 1);
            state.weeklyDistance = rows.reduce((s, r) => s + parseFloat(r.total_distance || 0), 0) / Math.max(rows.length, 1);
        }

        // recent falls
        const fallsRes = await fetch(`${API_BASE}/api/falls?limit=5`);
        const falls    = await fallsRes.json();
        if (falls.success) {
            state.recentFalls = falls.data;
            if (falls.data.length > 0) state.lastFallId = falls.data[0].id;
            updateRecentFallsList();
        }

        // latest position
        const posRes = await fetch(`${API_BASE}/api/position/latest`);
        const pos    = await posRes.json();
        if (pos.success && pos.data && pos.data.length > 0) {
            pos.data.forEach(d => handlePositionUpdate(d));
        }

        updateStatsDisplay();
        updateStepsChart();
        updateHourlyChart();
        updateLastUpdateTime();
    } catch (e) {
        console.error('Error loading initial data:', e);
    }
}

// ==================== แชท AI ====================
async function sendMessage() {
    const input   = document.getElementById('chatInput');
    const message = input.value.trim();
    if (!message) return;

    addChatMessage(message, 'user');
    input.value = '';

    const loadingId = 'loading-' + Date.now();
    addLoadingMessage(loadingId);

    try {
        const res    = await fetch(`${API_BASE}/api/chat`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message })
        });
        const result = await res.json();
        removeLoadingMessage(loadingId);
        addChatMessage(result.success ? result.response : 'ขอโทษครับ เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง', 'assistant');
    } catch (e) {
        removeLoadingMessage(loadingId);
        addChatMessage('ไม่สามารถเชื่อมต่อกับ AI ได้ กรุณาลองใหม่', 'assistant');
    }
}

function handleChatKeypress(event) { if (event.key === 'Enter') sendMessage(); }

function addChatMessage(content, role) {
    const container = document.getElementById('chatMessages');
    const div = document.createElement('div');
    div.className = `message ${role}`;
    let html = content
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/\n/g, '<br>')
        .replace(/- (.*?)(<br>|$)/g, '<li>$1</li>')
        .replace(/(<li>.*<\/li>)+/g, '<ul>$&</ul>');
    div.innerHTML = `<div class="message-avatar">${role === 'user' ? '👤' : '🤖'}</div><div class="message-content">${html}</div>`;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function addLoadingMessage(id) {
    const container = document.getElementById('chatMessages');
    const div = document.createElement('div');
    div.className = 'message assistant'; div.id = id;
    div.innerHTML = `<div class="message-avatar">🤖</div><div class="message-content">กำลังคิด...</div>`;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
}

function removeLoadingMessage(id) { document.getElementById(id)?.remove(); }

async function clearChat() {
    if (!confirm('ต้องการล้างประวัติการแชทหรือไม่?')) return;
    await fetch(`${API_BASE}/api/chat/history`, { method: 'DELETE' });
    document.getElementById('chatMessages').innerHTML = `
        <div class="message assistant">
            <div class="message-avatar">🤖</div>
            <div class="message-content">
                สวัสดีครับ! ผมช่วยแนะนำด้านสุขภาพได้ ลองถามเกี่ยวกับ:
                <ul><li>การป้องกันการล้ม</li><li>เป้าหมายการเดิน</li><li>การออกกำลังกาย</li></ul>
            </div>
        </div>`;
}

// ==================== ฟังก์ชันอรรถประโยชน์ ====================
function updateClock() {
    const now = new Date();
    document.getElementById('currentTime').textContent =
        now.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
    document.getElementById('currentDate').textContent =
        now.toLocaleDateString('th-TH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
}
function updateLastUpdateTime() {
    const el = document.getElementById('lastUpdate');
    if (el) el.textContent = 'อัปเดตล่าสุด: ' + new Date().toLocaleTimeString('th-TH');
}
function formatNumber(num) { return new Intl.NumberFormat('th-TH').format(num || 0); }
function formatTime(ts)    {
    if (!ts) return '--';
    return new Date(ts).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
}
function formatDateShort(dateStr) {
    if (!dateStr) return '--';
    return new Date(dateStr).toLocaleDateString('th-TH', { day: 'numeric', month: 'short' });
}

window.addEventListener('resize', drawMiniMap);
