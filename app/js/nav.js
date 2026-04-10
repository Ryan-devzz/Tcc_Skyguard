/* ============================================
   SKYGUARD — nav.js
   Componente de navegação e utilitários globais
   ============================================ */

// ---- AUTENTICAÇÃO ----
function getUser() {
  const raw = sessionStorage.getItem('sg_user');
  if (!raw) { window.location.href = '../login.html'; return null; }
  return JSON.parse(raw);
}

function logout() {
  sessionStorage.removeItem('sg_user');
  // Invalida a sessão PHP no servidor também
  fetch('../api/auth.php?action=logout', { method: 'POST', credentials: 'same-origin' })
    .catch(() => {})
    .finally(() => { window.location.href = '../login.html'; });
}

// ---- RENDERIZAR SIDEBAR ----
function renderNav(activePage) {
  const user = getUser();
  if (!user) return;

  const isAdmin = user.role === 'admin';
  const initials = user.name.split(' ').map(n => n[0]).join('').slice(0,2).toUpperCase();

  const adminLinks = isAdmin ? `
    <div class="nav-section-label">Administração</div>
    <a class="nav-item ${activePage === 'users' ? 'active' : ''}" href="users.html">
      <span class="nav-icon">👥</span> Gerenciar Usuários
    </a>
    <a class="nav-item ${activePage === 'devices-admin' ? 'active' : ''}" href="devices-admin.html">
      <span class="nav-icon">⚙️</span> Gerenciar Dispositivos
    </a>
  ` : '';

  const html = `
    <div class="sidebar">
      <div class="sidebar-logo">
        <a class="logo-mark" href="dashboard.html" style="display:flex;align-items:center;gap:10px;text-decoration:none;">
          <img src="../logo.jpg" alt="SkyGuard Logo"
               style="height:44px;width:auto;border-radius:8px;object-fit:contain;
                      background:#fff;padding:3px;flex-shrink:0;
                      filter:drop-shadow(0 0 6px rgba(56,189,248,0.25));">
          <div>
            <div class="logo-text">SkyGuard</div>
            <div class="logo-sub">Air Quality</div>
          </div>
        </a>
      </div>

      <nav class="sidebar-nav">
        <div class="nav-section-label">Principal</div>
        <a class="nav-item ${activePage === 'dashboard' ? 'active' : ''}" href="dashboard.html">
          <span class="nav-icon">📊</span> Dashboard
        </a>
        <a class="nav-item ${activePage === 'devices' ? 'active' : ''}" href="devices.html">
          <span class="nav-icon">📡</span> Dispositivos
        </a>

        <div class="nav-section-label">Plataforma</div>
        <a class="nav-item ${activePage === 'home' ? 'active' : ''}" href="home.html">
          <span class="nav-icon">🏠</span> Página Inicial
        </a>
        <a class="nav-item ${activePage === 'contact' ? 'active' : ''}" href="contact.html">
          <span class="nav-icon">✉️</span> Contato
        </a>
        <a class="nav-item ${activePage === 'profile' ? 'active' : ''}" href="profile.html">
          <span class="nav-icon">👤</span> Meu Perfil
        </a>

        ${adminLinks}
      </nav>

      <div class="sidebar-bottom">
        <div class="mqtt-status mb-4" id="mqtt-status-indicator">
          <div class="live-dot" style="width:7px;height:7px;border-radius:50%;background:var(--text-muted);"></div>
          Verificando...
        </div>
        <div class="user-chip" onclick="window.location.href='profile.html'">
          <div class="user-avatar">${initials}</div>
          <div>
            <div class="user-name">${user.name}</div>
            <div class="user-role">${isAdmin ? '⭐ Administrador' : '👤 Usuário'}</div>
          </div>
        </div>
        <button class="btn btn-secondary" style="width:100%;margin-top:10px;font-size:13px;" onclick="logout()">
          ↩ Sair
        </button>
      </div>
    </div>
  `;

  const target = document.getElementById('sidebar-root');
  if (target) {
    target.innerHTML = html;
    // Atualiza o indicador MQTT com base nos dispositivos realmente online
    _updateMQTTStatus();
  }
}

function _updateMQTTStatus() {
  const indicator = document.getElementById('mqtt-status-indicator');
  if (!indicator) return;
  const allIds = ['SGP-001', 'SGP-002', 'SGP-003'];
  const onlineCount = countOnlineDevices(allIds);
  if (onlineCount > 0) {
    indicator.innerHTML = `<div class="live-dot" style="width:7px;height:7px;border-radius:50%;background:var(--success);animation:live-pulse 1.5s infinite;"></div> MQTT · ${onlineCount} dispositivo${onlineCount > 1 ? 's' : ''} online`;
  } else {
    indicator.innerHTML = `<div class="live-dot" style="width:7px;height:7px;border-radius:50%;background:var(--danger);"></div> Sem dispositivos online`;
  }
}

// ---- TOAST NOTIFICATIONS ----
function showToast(msg, type = 'info') {
  const icons = { info: 'ℹ️', success: '✅', warning: '⚠️', danger: '❌' };
  const container = document.getElementById('toast-container') || (() => {
    const el = document.createElement('div');
    el.id = 'toast-container';
    el.className = 'toast-container';
    document.body.appendChild(el);
    return el;
  })();

  const toast = document.createElement('div');
  toast.className = 'toast';
  toast.innerHTML = `<span>${icons[type]}</span><span>${msg}</span>`;
  container.appendChild(toast);
  setTimeout(() => toast.remove(), 3500);
}

// ---- MODAL ----
function openModal(id) {
  document.getElementById(id)?.classList.add('active');
}
function closeModal(id) {
  document.getElementById(id)?.classList.remove('active');
}

// ---- GERENCIAMENTO DE CONEXÃO DOS DISPOSITIVOS ----
// Controla quais dispositivos estão online (simulação de conexão real)
const _deviceOnlineState = (() => {
  const KEY = 'sg_device_online';
  function load() {
    try { return JSON.parse(sessionStorage.getItem(KEY)) || {}; } catch { return {}; }
  }
  function save(state) {
    sessionStorage.setItem(KEY, JSON.stringify(state));
  }
  return {
    isOnline(id) {
      const state = load();
      // Por padrão, dispositivo começa offline até receber dados
      return state[id] === true;
    },
    setOnline(id, online) {
      const state = load();
      state[id] = online;
      save(state);
    },
    toggle(id) {
      const state = load();
      state[id] = !state[id];
      save(state);
    }
  };
})();

// ---- SIMULAÇÃO DE DADOS MQTT ----
// Em produção, substituir por conexão real com broker MQTT via WebSocket
// Apenas retorna dados se o dispositivo estiver online
function getMQTTData(deviceId) {
  if (!_deviceOnlineState.isOnline(deviceId)) {
    return null; // Dispositivo offline — sem dados
  }
  const base = {
    'SGP-001': { co2: 412, tvoc: 45, iaq: 87 },
    'SGP-002': { co2: 895, tvoc: 210, iaq: 42 },
    'SGP-003': { co2: 620, tvoc: 98, iaq: 65 },
  };
  const d = base[deviceId] || base['SGP-001'];
  return {
    co2: d.co2 + Math.floor((Math.random() - 0.5) * 30),
    tvoc: d.tvoc + Math.floor((Math.random() - 0.5) * 15),
    iaq: d.iaq,
    timestamp: new Date().toLocaleTimeString('pt-BR')
  };
}

function countOnlineDevices(deviceIds) {
  return deviceIds.filter(id => _deviceOnlineState.isOnline(id)).length;
}

function getAQILabel(iaq) {
  if (iaq >= 80) return { label: 'Boa', color: 'var(--success)', class: 'good' };
  if (iaq >= 50) return { label: 'Moderada', color: 'var(--warning)', class: 'moderate' };
  return { label: 'Ruim', color: 'var(--danger)', class: 'poor' };
}

function getCO2Status(ppm) {
  if (ppm < 600) return { label: 'Normal', color: 'var(--success)' };
  if (ppm < 1000) return { label: 'Elevado', color: 'var(--warning)' };
  return { label: 'Crítico', color: 'var(--danger)' };
}

function getTVOCStatus(ppb) {
  if (ppb < 100) return { label: 'Normal', color: 'var(--success)' };
  if (ppb < 300) return { label: 'Moderado', color: 'var(--warning)' };
  return { label: 'Crítico', color: 'var(--danger)' };
}
