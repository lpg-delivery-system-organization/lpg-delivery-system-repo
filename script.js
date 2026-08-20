// =========================================================
//  DATABASE  (loaded from PHP/MySQL, with local fallback)
// =========================================================
const DB_KEY = 'lpg_db';
const API_URL = 'api/index.php';

async function loadDB(forceServer = false) {
  // The MySQL database is the source of truth.
  // Try the server first; fall back to localStorage only if the server is unreachable.
  try {
    const res = await fetch(`${API_URL}?action=load`, { cache: 'no-store' });
    if (res.ok) {
      const data = await res.json();
      if (data && data.users) {
        saveDB(data);
        return data;
      }
    }
  } catch (e) {
    // Server unreachable — fall through to localStorage fallback
  }

  if (!forceServer) {
    const saved = localStorage.getItem(DB_KEY);
    if (saved) {
      try {
        return JSON.parse(saved);
      } catch (e) {
        localStorage.removeItem(DB_KEY);
      }
    }
  }

  // Last-resort fallback data (used only when neither server nor localStorage works)
  {
const fallbackData = {
      users: [
        { id: 1, name: 'Janister Singson', email: 'customer@lpg.com', password: 'Customer@2026', role: 'customer', address: '123 Rizal St, Caloocan City', phone: '09171234567' },
        { id: 2, name: 'Maria Santos', email: 'admin@lpg.com', password: 'Admin@2026!', role: 'admin', address: 'Admin HQ, Quezon City', phone: '09289876543' },
        { id: 3, name: 'Pedro Reyes', email: 'rider@lpg.com', password: 'Rider@2026!', role: 'rider', address: '456 Mabini Ave, Caloocan City', phone: '09351112222' }
      ],
      products: [
        { id: 1, name: 'Solane 11kg', brand: 'Solane', weight: '11kg', price: 850, stock: 45, image: '🔵' },
        { id: 2, name: 'Gasul 11kg', brand: 'Gasul', weight: '11kg', price: 820, stock: 30, image: '🔴' },
        { id: 3, name: 'Total 11kg', brand: 'Total', weight: '11kg', price: 800, stock: 20, image: '🟡' },
        { id: 4, name: 'Solane 22kg', brand: 'Solane', weight: '22kg', price: 1650, stock: 15, image: '🔵' },
        { id: 5, name: 'Gasul 50kg', brand: 'Gasul', weight: '50kg', price: 3800, stock: 8, image: '🔴' }
      ],
      orders: [
        { id: 1001, customerId: 1, customerName: 'Janister Singson', customerAddress: '123 Rizal St, Caloocan City', customerPhone: '09171234567', productId: 1, productName: 'Solane 11kg', quantity: 2, total: 1700, payment: 'cod', status: 'delivered', riderId: 3, riderName: 'Pedro Reyes', createdAt: '2025-04-18T09:00:00', deliveredAt: '2025-04-18T11:30:00' },
        { id: 1002, customerId: 1, customerName: 'Janister Singson', customerAddress: '123 Rizal St, Caloocan City', customerPhone: '09171234567', productId: 2, productName: 'Gasul 11kg', quantity: 1, total: 820, payment: 'cod', status: 'in-transit', riderId: 3, riderName: 'Pedro Reyes', createdAt: '2025-04-22T08:00:00', deliveredAt: null },
        { id: 1003, customerId: 1, customerName: 'Janister Singson', customerAddress: '123 Rizal St, Caloocan City', customerPhone: '09171234567', productId: 3, productName: 'Total 11kg', quantity: 1, total: 800, payment: 'cod', status: 'pending', riderId: null, riderName: null, createdAt: '2025-04-22T10:00:00', deliveredAt: null }
      ]
    };
    saveDB(fallbackData);
    return fallbackData;
  }
}

async function saveDB(data) {
  localStorage.setItem(DB_KEY, JSON.stringify(data));
  try {
    await fetch(API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'save', data })
    });
  } catch (e) {
    // Ignore API save failure; local persistence still works.
  }
}

// =========================================================
//  SECURITY CONSTANTS & STATE
// =========================================================
const MAX_LOGIN_ATTEMPTS = 5;          // allowed failed attempts
const LOCKOUT_MINUTES = 15;            // account lockout duration
const SESSION_TIMEOUT_MINUTES = 15;    // inactivity auto-logout
const ATTEMPT_KEY = 'lpg_login_attempts';

let sessionTimer = null;
let lastActivity = Date.now();

// =========================================================
//  APP STATE
// =========================================================
let db = null;
let selectedRole = null;
let authMode = 'login'; // login | register | forgot
let forgotStep = 'email'; // email | code | newpassword
let forgotEmail = null;
let forgotAuthCode = null;
let currentUser = null;
let selectedProduct = null;
let selectedPayment = 'cod';
let qty = 1;

// =========================================================
//  INIT
// =========================================================
(async () => { 
  db = await loadDB(); 
  authMode = 'login';
  setupAuthScreen();
})();

// =========================================================
//  SCREENS
// =========================================================
function showScreen(id) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  document.getElementById('screen-' + id).classList.add('active');
}

// =========================================================
//  ROLE SELECT
// =========================================================
function selectRole(role) {
  selectedRole = role;
  authMode = 'login';
  forgotStep = 'email';
  forgotEmail = null;
  forgotAuthCode = null;
  setupAuthScreen();
  showScreen('auth');
}

// =========================================================
//  AUTH
// =========================================================
const roleConfig = {
  customer: { badge: '👤', color: '#3b7cf4', bg: '#eff4ff', label: 'Customer' },
  admin: { badge: '🛡️', color: '#22c55e', bg: '#dcfce7', label: 'Admin' },
  rider: { badge: '🚴', color: '#8b5cf6', bg: '#ede9fe', label: 'Rider' }
};

function setupAuthScreen() {
  const cfg = selectedRole ? roleConfig[selectedRole] : { badge: '🔥', color: '#3b7cf4', bg: '#eff4ff', label: '' };
  const badge = document.getElementById('auth-role-badge');
  const isLogin = authMode === 'login';
  const isForgot = authMode === 'forgot';

  badge.textContent = isLogin
    ? '🔥'
    : (isForgot
      ? (forgotStep === 'email' ? '🔑' : (forgotStep === 'code' ? '🔢' : '🔒'))
      : '👋');
  badge.style.background = cfg.bg;

  if (isForgot) {
    const stepCopy = {
      email: ['Reset Password', 'Enter your email to receive a verification code'],
      code: ['Enter Code', 'We emailed a 6-digit code to ' + (forgotEmail || 'your account')],
      newpassword: ['Set New Password', 'Enter and confirm your new password']
    };
    document.getElementById('auth-title').textContent = stepCopy[forgotStep][0];
    document.getElementById('auth-sub').textContent = stepCopy[forgotStep][1];
    document.getElementById('auth-btn').textContent =
      forgotStep === 'email' ? 'Send Code'
        : (forgotStep === 'code' ? 'Verify Code' : 'Reset Password');
    document.getElementById('auth-toggle-link').innerHTML =
      `<span style="color:var(--blue); font-weight:600; cursor:pointer;" onclick="toggleForgotMode()">Back to Login</span>`;
  } else {
    document.getElementById('auth-title').textContent = isLogin ? 'Welcome Back' : 'Create Account';
    document.getElementById('auth-sub').textContent = isLogin ? 'Sign in to your account' : 'Register your account';
    document.getElementById('auth-btn').textContent = isLogin ? 'Login' : 'Sign Up';
    document.getElementById('auth-toggle-link').innerHTML = isLogin
      ? `<span style="color:var(--blue); font-weight:600; cursor:pointer;" onclick="toggleForgotMode()">Forgot Password?</span><span onclick="toggleAuthMode()">Sign Up</span>`
      : `<span style="color:var(--blue); font-weight:600; cursor:pointer;" onclick="toggleForgotMode()">Forgot Password?</span><span onclick="toggleAuthMode()">Login</span>`;
  }

  document.querySelectorAll('.reg-extra').forEach(el => el.classList.toggle('show', !isLogin && !isForgot));
  // Forgot step-1 email field
  document.querySelectorAll('.forgot-extra').forEach(el => el.classList.toggle('show', isForgot && forgotStep === 'email'));
  // Forgot step-2 code field
  document.querySelectorAll('#forgot-code-field').forEach(el => el.classList.toggle('show', isForgot && forgotStep === 'code'));
  // Forgot step-3 new password + confirm
  document.querySelectorAll('#forgot-password-field, #forgot-confirm-field').forEach(el => el.classList.toggle('show', isForgot && forgotStep === 'newpassword'));
  // Show/hide password field
  const pwField = document.getElementById('password-field');
  if (pwField) pwField.style.display = (!isForgot) ? 'block' : 'none';
  // Show/hide main email field
  const emailField = document.getElementById('auth-email');
  if (emailField) emailField.closest('.field').style.display = (!isForgot) ? 'block' : 'none';
// Hide back button when there is no selected role (straight login)
  const backBtn = document.querySelector('.auth-back');
  if (backBtn) backBtn.style.display = selectedRole ? 'flex' : 'none';
  hideError();
  clearAuthFields();
}

function toggleAuthMode() {
  authMode = authMode === 'login' ? 'register' : 'login';
  forgotStep = 'email';
  forgotEmail = null;
  forgotAuthCode = null;
  setupAuthScreen();
}

function toggleForgotMode() {
  if (authMode === 'forgot') {
    authMode = 'login';
    forgotStep = 'email';
    forgotEmail = null;
    forgotAuthCode = null;
  } else {
    authMode = 'forgot';
    forgotStep = 'email';
    forgotEmail = null;
    forgotAuthCode = null;
  }
  setupAuthScreen();
}

function togglePasswordVisibility() {
  const pwInput = document.getElementById('auth-password');
  const eyeOpen = document.getElementById('eye-icon-open');
  const eyeClosed = document.getElementById('eye-icon-closed');
  if (pwInput.type === 'password') {
    pwInput.type = 'text';
    eyeOpen.style.display = 'block';
    eyeClosed.style.display = 'none';
  } else {
    pwInput.type = 'password';
    eyeOpen.style.display = 'none';
    eyeClosed.style.display = 'block';
  }
}

function clearAuthFields() {
  ['auth-email', 'auth-password', 'reg-name', 'reg-phone', 'reg-address', 'forgot-email', 'forgot-code', 'forgot-newpass', 'forgot-confirm'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  // Reset the password strength checklist
  updatePwChecklist('');
}

function goBack() { 
  if (selectedRole) { showScreen('role'); }
  else { hideError(); clearAuthFields(); }
}

function showError(msg) {
  const el = document.getElementById('auth-error');
  el.textContent = msg; el.classList.add('show');
}
function hideError() {
  document.getElementById('auth-error').classList.remove('show');
}

// =========================================================
//  PASSWORD VALIDATION
// =========================================================
function validatePassword(pw) {
  return {
    ok: pw.length >= 8 && /[A-Z]/.test(pw) && /[a-z]/.test(pw) && /\d/.test(pw) && /[!@#$%^&*(),.?":{}|<>]/.test(pw),
    len: pw.length >= 8,
    upper: /[A-Z]/.test(pw),
    lower: /[a-z]/.test(pw),
    num: /\d/.test(pw),
    special: /[!@#$%^&*(),.?":{}|<>]/.test(pw)
  };
}

function updatePwChecklist(pw) {
  const r = validatePassword(pw);
  const map = { 'pw-req-len': r.len, 'pw-req-upper': r.upper, 'pw-req-lower': r.lower, 'pw-req-num': r.num, 'pw-req-special': r.special };
  Object.keys(map).forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.classList.toggle('met', map[id]);
      el.querySelector('.pw-check').textContent = map[id] ? '✓' : '○';
    }
  });
}

// =========================================================
//  LOGIN ATTEMPT LOCKOUT
// =========================================================
function getAttempts(email) {
  try {
    const map = JSON.parse(localStorage.getItem(ATTEMPT_KEY) || '{}');
    return map[email.toLowerCase()] || { count: 0, lockedUntil: 0 };
  } catch (e) {
    return { count: 0, lockedUntil: 0 };
  }
}

function saveAttempts(email, data) {
  try {
    const map = JSON.parse(localStorage.getItem(ATTEMPT_KEY) || '{}');
    map[email.toLowerCase()] = data;
    localStorage.setItem(ATTEMPT_KEY, JSON.stringify(map));
  } catch (e) { /* ignore */ }
}

function isLocked(email) {
  const a = getAttempts(email);
  if (a.lockedUntil && Date.now() < a.lockedUntil) {
    return Math.ceil((a.lockedUntil - Date.now()) / 60000);
  }
  return 0;
}

function recordFailedAttempt(email) {
  let a = getAttempts(email);
  a.count = (a.count || 0) + 1;
  if (a.count >= MAX_LOGIN_ATTEMPTS) {
    a.lockedUntil = Date.now() + LOCKOUT_MINUTES * 60 * 1000;
  }
  saveAttempts(email, a);
  return a;
}

function resetAttempts(email) {
  saveAttempts(email, { count: 0, lockedUntil: 0 });
}

// =========================================================
//  SESSION MANAGEMENT (auto-logout after inactivity)
// =========================================================
function startSessionTimer() {
  stopSessionTimer();
  lastActivity = Date.now();
  sessionTimer = setInterval(() => {
    if (Date.now() - lastActivity >= SESSION_TIMEOUT_MINUTES * 60 * 1000) {
      stopSessionTimer();
      logout(true);
    }
  }, 1000);
}

function stopSessionTimer() {
  if (sessionTimer) {
    clearInterval(sessionTimer);
    sessionTimer = null;
  }
}

function trackActivity() {
  lastActivity = Date.now();
}

// Listen for user activity to refresh the inactivity timer
['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(evt => {
  document.addEventListener(evt, trackActivity, { passive: true });
});

async function postApi(payload) {
  try {
    const res = await fetch(API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
  } catch (e) {
    throw e;
  }
}

async function handleForgotAuth() {
  hideError();

if (forgotStep === 'email') {
    const email = document.getElementById('forgot-email').value.trim();
    if (!email) { showError('Please enter your email.'); return; }

    // Check against the local database FIRST (source of truth for registered accounts)
    const localUser = db.users.find(u => u.email.toLowerCase() === email.toLowerCase());
    if (!localUser) {
      showError('No account found with that email.');
      return;
    }

    forgotEmail = email;
    const btn = document.getElementById('auth-btn');
    btn.disabled = true; btn.textContent = 'Sending...';
    try {
      // Ensure the registered account is synced to the backend/MySQL database
      // so the reset-code email can be sent and validated server-side.
      try { await saveDB(db); } catch (e) { /* local-only mode */ }

      const data = await postApi({ action: 'send_reset_code', email });
      forgotAuthCode = data.debugCode || null;
      forgotStep = 'code';
      setupAuthScreen();
      if (data.debugCode) {
        toast('⚠️ SMTP not configured — your demo code is: ' + data.debugCode, 'info');
      } else {
        toast('Code sent to ' + email + '! Check your inbox.', 'success');
      }
    } catch (e) {
      showError(e.message);
    } finally {
      btn.disabled = false; btn.textContent = 'Send Code';
    }
    return;
  }

  if (forgotStep === 'code') {
    const code = document.getElementById('forgot-code').value.trim();
    if (!/^\d{6}$/.test(code)) { showError('Please enter the 6-digit code.'); return; }
    const btn = document.getElementById('auth-btn');
    btn.disabled = true; btn.textContent = 'Verifying...';
    try {
      await postApi({ action: 'verify_reset_code', email: forgotEmail, code });
      forgotAuthCode = code;
      forgotStep = 'newpassword';
      setupAuthScreen();
      toast('Code verified! Set your new password.', 'success');
    } catch (e) {
      showError(e.message);
    } finally {
      btn.disabled = false; btn.textContent = 'Verify Code';
    }
    return;
  }

// newpassword step
  const pw = document.getElementById('forgot-newpass').value;
  const pw2 = document.getElementById('forgot-confirm').value;
  if (pw !== pw2) { showError('Passwords do not match.'); return; }
  // Enforce strong password rules on password reset
  const pwCheck = validatePassword(pw);
  if (!pwCheck.ok) {
    showError('Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.');
    return;
  }
  const btn = document.getElementById('auth-btn');
  btn.disabled = true; btn.textContent = 'Resetting...';
  try {
    await postApi({ action: 'reset_password', email: forgotEmail, code: forgotAuthCode, password: pw });
    // Reload the DB from MySQL so the updated password is reflected locally
    try { db = await loadDB(true); } catch (e) {}
    toast('Password reset successfully! Please login.', 'success');
    toggleForgotMode();
    document.getElementById('auth-password').value = pw;
  } catch (e) {
    showError(e.message);
  } finally {
    btn.disabled = false;
  }
}

async function handleAuth() {
  hideError();

  if (authMode === 'forgot') {
    handleForgotAuth();
    return;
  }

  const email = document.getElementById('auth-email').value.trim();
  const password = document.getElementById('auth-password').value;

  if (authMode === 'login') {
    // Generic "both required" message per guidelines
    if (!email || !password) { showError('Please enter both username and password.'); return; }

    // Check account lockout
    const minsLeft = isLocked(email);
    if (minsLeft > 0) {
      showError('Your account has been locked due to multiple failed login attempts. Try again in ' + minsLeft + ' minute(s), or contact the system administrator.');
      return;
    }

    const user = db.users.find(u => u.email === email && u.password === password);
    if (!user) {
      // Do NOT reveal whether username or password was wrong
      recordFailedAttempt(email);
      const attempts = getAttempts(email);
      if (attempts.count >= MAX_LOGIN_ATTEMPTS) {
        showError('Your account has been locked due to multiple failed login attempts. Try again in ' + LOCKOUT_MINUTES + ' minute(s), or contact the system administrator.');
      } else {
        showError('Invalid username or password.');
      }
      return;
    }
    // Successful login — reset attempts & start session timer
    resetAttempts(email);
    currentUser = user;
    startSessionTimer();
    launchApp();
  } else {
    const name = document.getElementById('reg-name').value.trim();
    const phone = document.getElementById('reg-phone').value.trim();
    const address = document.getElementById('reg-address').value.trim();
    if (!name || !phone || !address) { showError('Please fill in all fields.'); return; }
    if (!email || !password) { showError('Please fill in all fields.'); return; }

    // Enforce strong password rules on registration
    const pwCheck = validatePassword(password);
    if (!pwCheck.ok) {
      showError('Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.');
      return;
    }

    const btn = document.getElementById('auth-btn');
    btn.disabled = true; btn.textContent = 'Signing up...';
    try {
      // Register directly into the MySQL database via the API
      const data = await postApi({
        action: 'register',
        name, email, phone, address, password
      });
      // Reload the DB straight from MySQL so localStorage matches the database
      db = await loadDB(true);
      const newUser = db.users.find(u => u.email.toLowerCase() === email.toLowerCase()) || data.user;
      currentUser = newUser;
      startSessionTimer();
      launchApp();
    } catch (e) {
      showError(e.message);
    } finally {
      btn.disabled = false; btn.textContent = 'Sign Up';
    }
  }
}

// Enter key on password
document.addEventListener('keydown', e => {
  if (e.key === 'Enter' && document.getElementById('screen-auth').classList.contains('active')) handleAuth();
});

// Live password strength checklist (register)
document.getElementById('auth-password').addEventListener('input', e => {
  updatePwChecklist(e.target.value);
});

// =========================================================
//  APP LAUNCH
// =========================================================
function launchApp() {
  const cfg = roleConfig[currentUser.role];

  // Nav
  const av = document.getElementById('nav-avatar');
  av.textContent = currentUser.name[0].toUpperCase();
  av.style.background = cfg.color;
  document.getElementById('nav-username').textContent = currentUser.name;

  buildTabs();
  showScreen('app');
  toast(`Welcome, ${currentUser.name}! 👋`, 'success');
}

// =========================================================
//  TABS
// =========================================================
const roleTabs = {
  customer: [
    { id: 'shop', label: '🛒 Shop' },
    { id: 'my-orders', label: '📦 My Orders' },
    { id: 'c-profile', label: '👤 Profile' }
  ],
  admin: [
    { id: 'dashboard', label: '📊 Dashboard' },
    { id: 'orders', label: '📋 Orders' },
    { id: 'inventory', label: '📦 Inventory' },
    { id: 'users', label: '👥 Users' }
  ],
  rider: [
    { id: 'assignments', label: '🚴 My Deliveries' },
    { id: 'available', label: '📬 Available' },
    { id: 'r-profile', label: '👤 Profile' }
  ]
};

let activeTab = null;

function buildTabs() {
  const tabs = roleTabs[currentUser.role];
  const container = document.getElementById('app-tabs');
  container.innerHTML = tabs.map(t =>
    `<div class="tab" id="tab-${t.id}" onclick="switchTab('${t.id}')">${t.label}</div>`
  ).join('');
  switchTab(tabs[0].id);
}

function switchTab(id) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
  const tabEl = document.getElementById('tab-' + id);
  const panelEl = document.getElementById('panel-' + id);
  if (tabEl) tabEl.classList.add('active');
  if (panelEl) panelEl.classList.add('active');
  activeTab = id;
  renderPanel(id);
}

function renderPanel(id) {
  switch (id) {
    case 'shop': renderShop(); break;
    case 'my-orders': renderMyOrders(); break;
    case 'c-profile': renderCProfile(); break;
    case 'dashboard': renderDashboard(); break;
    case 'orders': renderAdminOrders(); break;
    case 'inventory': renderInventory(); break;
    case 'users': renderUsers(); break;
    case 'assignments': renderAssignments(); break;
    case 'available': renderAvailable(); break;
    case 'r-profile': renderRProfile(); break;
  }
}

// =========================================================
//  CUSTOMER: SHOP
// =========================================================
function renderShop() {
  const grid = document.getElementById('products-grid');
  grid.innerHTML = db.products.map(p => `
<div class="product-card ${selectedProduct && selectedProduct.id === p.id ? 'selected' : ''}"
     id="pc-${p.id}" onclick="selectProduct(${p.id})">
  <div class="product-selected-check">✓</div>
  <span class="product-emoji">${p.image}</span>
  <div class="product-name">${p.name}</div>
  <div class="product-price">₱${p.price.toLocaleString()}</div>
  <div class="product-stock">${p.stock > 0 ? `${p.stock} in stock` : '⚠️ Out of stock'}</div>
</div>
`).join('');
}

function selectProduct(id) {
  const p = db.products.find(x => x.id === id);
  if (!p || p.stock === 0) { toast('Out of stock!', 'error'); return; }
  selectedProduct = p;
  qty = 1;
  selectedPayment = 'cod';
  document.querySelectorAll('.payment-opt').forEach(el => el.classList.remove('selected'));
  const codEl = document.getElementById('pay-cod');
  if (codEl) codEl.classList.add('selected');
  document.querySelectorAll('.product-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('pc-' + id).classList.add('selected');
  const op = document.getElementById('order-panel');
  op.style.display = 'block';
  op.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  updateOrderSummary();
}

function changeQty(delta) {
  if (!selectedProduct) return;
  qty = Math.max(1, Math.min(selectedProduct.stock, qty + delta));
  updateOrderSummary();
}

function updateOrderSummary() {
  if (!selectedProduct) return;
  document.getElementById('sel-product-name').textContent = selectedProduct.name;
  document.getElementById('qty-val').textContent = qty;
  document.getElementById('sum-unit').textContent = '₱' + selectedProduct.price.toLocaleString();
  document.getElementById('sum-qty').textContent = '×' + qty;
  document.getElementById('sum-total').textContent = '₱' + (selectedProduct.price * qty).toLocaleString();
}

const paymentLabels = { cod: 'Cash on Delivery', gcash: 'GCash', paymaya: 'Maya', bank: 'Bank Transfer' };

function selectPayment(method) {
  selectedPayment = method;
  document.querySelectorAll('.payment-opt').forEach(el => el.classList.remove('selected'));
  const el = document.getElementById('pay-' + method);
  if (el) el.classList.add('selected');
}

function placeOrder() {
  if (!selectedProduct) return;
  showModal('🛒', 'Confirm Order',
    `Order ${qty}× ${selectedProduct.name} for ₱${(selectedProduct.price * qty).toLocaleString()} via ${paymentLabels[selectedPayment]}?`,
    [
      { label: 'Cancel', cls: 'btn-outline', action: closeModal },
      { label: 'Place Order', cls: 'btn btn-primary', action: confirmOrder }
    ]
  );
}

function confirmOrder() {
  closeModal();
  const order = {
    id: 1000 + Date.now() % 10000,
    customerId: currentUser.id,
    customerName: currentUser.name,
    customerAddress: currentUser.address,
    customerPhone: currentUser.phone,
    productId: selectedProduct.id,
    productName: selectedProduct.name,
    quantity: qty,
    total: selectedProduct.price * qty,
    payment: selectedPayment,
    status: 'pending',
    riderId: null, riderName: null,
    createdAt: new Date().toISOString(),
    deliveredAt: null
  };
  db.orders.push(order);
  const prod = db.products.find(p => p.id === selectedProduct.id);
  prod.stock -= qty;
  saveDB(db);
  selectedProduct = null;
  qty = 1;
  document.getElementById('order-panel').style.display = 'none';
  toast('Order placed successfully! 🎉', 'success');
  renderShop();
}

// =========================================================
//  CUSTOMER: MY ORDERS
// =========================================================
function renderMyOrders() {
  const orders = db.orders.filter(o => o.customerId === currentUser.id).reverse();
  const container = document.getElementById('my-orders-list');

  if (!orders.length) {
    container.innerHTML = `<div class="empty"><span class="empty-icon">📦</span><h3>No orders yet</h3><p>Head to the Shop tab to place your first order!</p></div>`;
    return;
  }

  container.innerHTML = `
<div class="table-wrap">
  <div style="overflow-x:auto">
  <table>
    <thead><tr>
      <th>Order ID</th><th>Product</th><th>Qty</th><th>Total</th><th>Payment</th><th>Status</th><th>Rider</th><th>Date</th>
    </tr></thead>
    <tbody>
      ${orders.map(o => `
        <tr>
          <td>#${o.id}</td>
          <td><strong>${o.productName}</strong></td>
          <td>${o.quantity}</td>
          <td>₱${o.total.toLocaleString()}</td>
          <td>${paymentBadge(o.payment)}</td>
          <td>${badge(o.status)}</td>
          <td>${o.riderName || '<span style="color:var(--text-muted)">Not assigned</span>'}</td>
          <td style="font-size:14px;color:var(--text-muted)">${fmtDate(o.createdAt)}</td>
        </tr>

        ${o.status === 'in-transit' ? `
        <tr>
          <td colspan="8">
            <div id="map-${o.id}" style="height:260px; border-radius:12px; margin-top:10px;"></div>
          </td>
        </tr>
        ` : ''}
      `).join('')}
    </tbody>
  </table>
  </div>
</div>
`;

  // Initialize maps after render
  setTimeout(() => {
    orders.forEach(o => {
      if (o.status === 'in-transit') {
        initMap(o.id);
      }
    });
  }, 100);
}

// =========================================================
//  CUSTOMER: PROFILE
// =========================================================
function renderCProfile() {
  document.getElementById('c-profile-content').innerHTML = profileCard(currentUser);
}

// =========================================================
//  ADMIN: DASHBOARD
// =========================================================
function renderDashboard() {
  const total = db.orders.length;
  const pending = db.orders.filter(o => o.status === 'pending').length;
  const transit = db.orders.filter(o => o.status === 'in-transit').length;
  const done = db.orders.filter(o => o.status === 'delivered').length;
  const revenue = db.orders.filter(o => o.status === 'delivered').reduce((s, o) => s + o.total, 0);

  document.getElementById('admin-stats').innerHTML = `
${statCard('📋', total, 'Total Orders', '#3b7cf4', '#eff4ff')}
${statCard('⏳', pending, 'Pending', '#f59e0b', '#fef3c7')}
${statCard('🚴', transit, 'In Transit', '#8b5cf6', '#ede9fe')}
${statCard('✅', done, 'Delivered', '#22c55e', '#dcfce7')}
${statCard('💰', '₱' + revenue.toLocaleString(), 'Revenue', '#3b7cf4', '#eff4ff')}
`;

  const recent = [...db.orders].reverse().slice(0, 5);
  document.getElementById('admin-recent-table').innerHTML = `
<thead><tr><th>ID</th><th>Customer</th><th>Product</th><th>Total</th><th>Status</th></tr></thead>
<tbody>${recent.map(o => `
  <tr>
    <td style="font-size:14px;color:var(--text-muted)">#${o.id}</td>
    <td>${o.customerName}</td>
    <td>${o.productName}</td>
    <td>₱${o.total.toLocaleString()}</td>
    <td>${badge(o.status)}</td>
  </tr>`).join('')}
</tbody>`;
}

// =========================================================
//  ADMIN: ORDERS
// =========================================================
function renderAdminOrders() {
  const orders = [...db.orders].reverse();
  const riders = db.users.filter(u => u.role === 'rider');
  document.getElementById('admin-orders-table').innerHTML = `
<thead><tr><th>ID</th><th>Customer</th><th>Product</th><th>Qty</th><th>Total</th><th>Payment</th><th>Status</th><th>Rider</th><th>Actions</th></tr></thead>
<tbody>${orders.map(o => `
  <tr>
    <td style="font-size:14px;color:var(--text-muted)">#${o.id}</td>
    <td>${o.customerName}<br><span style="font-size:13px;color:var(--text-muted)">${o.customerAddress}</span></td>
    <td>${o.productName}</td>
    <td>${o.quantity}</td>
    <td>₱${o.total.toLocaleString()}</td>
    <td>${paymentBadge(o.payment)}</td>
    <td>${badge(o.status)}</td>
    <td>
      ${o.status === 'pending' ?
      `<select onchange="assignRider(${o.id}, this.value)" style="font-size:14px;padding:6px 10px;border-radius:6px;border:1.5px solid var(--border);font-family:var(--font)">
          <option value="">Assign rider</option>
          ${riders.map(r => `<option value="${r.id}" ${o.riderId === r.id ? 'selected' : ''}>${r.name}</option>`).join('')}
        </select>`
      : (o.riderName || '—')}
    </td>
    <td>
      <div class="flex-gap">
        ${o.status === 'pending' ? `<button class="btn-sm btn-blue" onclick="updateOrderStatus(${o.id},'confirmed')">Confirm</button>` : ''}
        ${o.status === 'confirmed' ? `<button class="btn-sm btn-purple" onclick="updateOrderStatus(${o.id},'in-transit')">Dispatch</button>` : ''}
        ${o.status !== 'delivered' && o.status !== 'cancelled'
      ? `<button class="btn-sm btn-red" onclick="updateOrderStatus(${o.id},'cancelled')">Cancel</button>` : ''}
      </div>
    </td>
  </tr>`).join('')}
</tbody>`;
}

function assignRider(orderId, riderId) {
  if (!riderId) return;
  const o = db.orders.find(x => x.id === orderId);
  const r = db.users.find(x => x.id == riderId);
  if (o && r) { o.riderId = r.id; o.riderName = r.name; saveDB(db); toast('Rider assigned!', 'success'); renderAdminOrders(); }
}

function updateOrderStatus(orderId, status) {
  const o = db.orders.find(x => x.id === orderId);
  if (o) {
    o.status = status;
    if (status === 'delivered') o.deliveredAt = new Date().toISOString();
    saveDB(db);
    toast(`Order #${orderId} → ${status}`, 'info');
    renderAdminOrders();
  }
}

// =========================================================
//  ADMIN: INVENTORY
// =========================================================
function renderInventory() {
  document.getElementById('inventory-table').innerHTML = `
<thead><tr><th>Product</th><th>Brand</th><th>Weight</th><th>Price</th><th>Stock</th><th>Action</th></tr></thead>
<tbody>${db.products.map(p => `
  <tr>
    <td><span style="font-size:20px;margin-right:8px">${p.image}</span><strong>${p.name}</strong></td>
    <td>${p.brand}</td>
    <td>${p.weight}</td>
    <td>₱${p.price.toLocaleString()}</td>
    <td>
      <div class="inv-edit" style="display:flex;align-items:center;gap:8px">
        <input type="number" value="${p.stock}" id="inv-${p.id}" min="0" />
        <span style="font-size:14px;color:var(--text-muted)">units</span>
      </div>
    </td>
    <td>
      <button class="btn-sm btn-green" onclick="saveStock(${p.id})">Save</button>
    </td>
  </tr>`).join('')}
</tbody>`;
}

function saveStock(id) {
  const val = parseInt(document.getElementById('inv-' + id).value);
  if (isNaN(val) || val < 0) { toast('Invalid stock value!', 'error'); return; }
  db.products.find(p => p.id === id).stock = val;
  saveDB(db);
  toast('Stock updated!', 'success');
}

// =========================================================
//  ADMIN: USERS
// =========================================================
function renderUsers() {
  document.getElementById('users-table').innerHTML = `
<thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Phone</th><th>Address</th></tr></thead>
<tbody>${db.users.map(u => `
  <tr>
    <td><strong>${u.name}</strong></td>
    <td style="font-size:14px;color:var(--text-muted)">${u.email}</td>
    <td>${rolePill(u.role)}</td>
    <td style="font-size:15px">${u.phone}</td>
    <td style="font-size:14px;color:var(--text-muted)">${u.address}</td>
  </tr>`).join('')}
</tbody>`;
}

// =========================================================
//  RIDER: ASSIGNMENTS
// =========================================================
function renderAssignments() {
  const orders = db.orders.filter(o => o.riderId === currentUser.id && o.status !== 'delivered' && o.status !== 'cancelled');
  const container = document.getElementById('rider-assignments');
  if (!orders.length) {
    container.innerHTML = `<div class="empty"><span class="empty-icon">🛵</span><h3>No active deliveries</h3><p>Check the Available tab to pick up orders.</p></div>`;
    return;
  }
  container.innerHTML = orders.map(o => `
<div class="rider-order-card">
  <div class="roc-header">
    <div>
      <div class="roc-id">Order #${o.id}</div>
      <div class="roc-product">${o.productName} × ${o.quantity}</div>
    </div>
    ${badge(o.status)}
  </div>
  <div class="roc-detail">
    <span>👤 ${o.customerName}</span>
    <span>📍 ${o.customerAddress}</span>
    <span>📞 ${o.customerPhone}</span>
    <span>💰 ₱${o.total.toLocaleString()}</span>
    <span>${payIcons[o.payment] || '💵'} ${payLabels[o.payment] || 'Cash on Delivery'}</span>
  </div>
  <div class="roc-actions">
    ${o.status === 'confirmed' ? `<button class="btn-sm btn-purple" onclick="riderUpdateStatus(${o.id},'in-transit')">🚀 Start Delivery</button>` : ''}
    ${o.status === 'in-transit' ? `<button class="btn-sm btn-green" onclick="riderUpdateStatus(${o.id},'delivered')">✅ Mark Delivered</button>` : ''}
  </div>
</div>`).join('');
}

function riderUpdateStatus(orderId, status) {
  const o = db.orders.find(x => x.id === orderId);
  if (o) {
    o.status = status;
    if (status === 'delivered') o.deliveredAt = new Date().toISOString();
    saveDB(db);
    toast(status === 'delivered' ? '✅ Delivery complete!' : '🚀 Delivery started!', 'success');
    renderAssignments();
  }
}

// =========================================================
//  RIDER: AVAILABLE
// =========================================================
function renderAvailable() {
  const orders = db.orders.filter(o => o.status === 'confirmed' && !o.riderId);
  const container = document.getElementById('rider-available');
  if (!orders.length) {
    container.innerHTML = `<div class="empty"><span class="empty-icon">📬</span><h3>No available orders</h3><p>Check back later for new deliveries.</p></div>`;
    return;
  }
  container.innerHTML = orders.map(o => `
<div class="rider-order-card">
  <div class="roc-header">
    <div>
      <div class="roc-id">Order #${o.id}</div>
      <div class="roc-product">${o.productName} × ${o.quantity}</div>
    </div>
    ${badge(o.status)}
  </div>
  <div class="roc-detail">
    <span>👤 ${o.customerName}</span>
    <span>📍 ${o.customerAddress}</span>
    <span>💰 ₱${o.total.toLocaleString()}</span>
    <span>${payIcons[o.payment] || '💵'} ${payLabels[o.payment] || 'Cash on Delivery'}</span>
  </div>
  <div class="roc-actions">
    <button class="btn-sm btn-blue" onclick="acceptOrder(${o.id})">Accept Delivery</button>
  </div>
</div>`).join('');
}

function acceptOrder(orderId) {
  const o = db.orders.find(x => x.id === orderId);
  if (o) {
    o.riderId = currentUser.id;
    o.riderName = currentUser.name;
    saveDB(db);
    toast('Order accepted! 🎉', 'success');
    renderAvailable();
    switchTab('assignments');
  }
}

// =========================================================
//  RIDER: PROFILE
// =========================================================
function renderRProfile() {
  document.getElementById('r-profile-content').innerHTML = profileCard(currentUser);
}

// =========================================================
//  PROFILE CARD HELPER
// =========================================================
function profileCard(user) {
  const cfg = roleConfig[user.role];
  const deliveries = user.role === 'rider'
    ? db.orders.filter(o => o.riderId === user.id && o.status === 'delivered').length
    : db.orders.filter(o => o.customerId === user.id).length;
  const label = user.role === 'rider' ? 'Deliveries' : 'Total Orders';
  return `
<div class="profile-card">
  <div class="profile-avatar" style="background:${cfg.color}">${user.name[0]}</div>
  <div class="profile-name">${user.name}</div>
  <div class="profile-role" style="background:${cfg.bg};color:${cfg.color}">${cfg.badge} ${cfg.label}</div>
  <div class="profile-info">
    <div class="profile-row"><span class="label">Email</span><span>${user.email}</span></div>
    <div class="profile-row"><span class="label">Phone</span><span>${user.phone}</span></div>
    <div class="profile-row"><span class="label">Address</span><span>${user.address}</span></div>
    <div class="profile-row"><span class="label">${label}</span><span><strong>${deliveries}</strong></span></div>
  </div>
</div>`;
}

// =========================================================
//  LOGOUT
// =========================================================
function logout(expired = false) {
  stopSessionTimer();
  currentUser = null;
  selectedProduct = null;
  selectedRole = null;
  authMode = 'login';
  forgotStep = 'email';
  forgotEmail = null;
  forgotAuthCode = null;
  setupAuthScreen();
  showScreen('auth');
  if (expired) {
    toast('Session expired. Please log in again.', 'info');
  } else {
    toast('Logged out 👋');
  }
}

// =========================================================
//  HELPERS
// =========================================================
function badge(status) {
  const dot = { pending: '#f59e0b', confirmed: '#3b7cf4', 'in-transit': '#8b5cf6', delivered: '#22c55e', cancelled: '#ef4444' };
  return `<span class="badge ${status}"><span class="badge-dot" style="background:${dot[status] || '#ccc'}"></span>${status.replace('-', ' ')}</span>`;
}

const payLabels = { cod: 'Cash on Delivery', gcash: 'GCash'};
const payIcons = { cod: '💵', gcash: '📱', paymaya: '💳', bank: '🏦' };
function paymentBadge(method) {
  if (!method) return '<span style="color:var(--text-muted);font-size:12px">—</span>';
  return `<span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:500;color:var(--text)">${payIcons[method] || ''} ${payLabels[method] || method}</span>`;
}

function rolePill(role) {
  const cfg = roleConfig[role];
  return `<span class="badge" style="background:${cfg.bg};color:${cfg.color}">${cfg.badge} ${cfg.label}</span>`;
}

function statCard(icon, val, label, color, bg) {
  return `<div class="stat-card">
<div class="stat-icon" style="background:${bg};color:${color}">${icon}</div>
<div class="stat-val">${val}</div>
<div class="stat-label">${label}</div>
</div>`;
}

function fmtDate(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

// =========================================================
//  MODAL
// =========================================================
function showModal(icon, title, msg, buttons) {
  document.getElementById('modal-icon').textContent = icon;
  document.getElementById('modal-title').textContent = title;
  document.getElementById('modal-msg').textContent = msg;
  document.getElementById('modal-btns').innerHTML = '';
  buttons.forEach(b => {
    const el = document.createElement('button');
    el.className = b.cls; el.textContent = b.label;
    el.onclick = b.action;
    document.getElementById('modal-btns').appendChild(el);
  });
  document.getElementById('modal').classList.add('show');
}
function closeModal() { document.getElementById('modal').classList.remove('show'); }
document.getElementById('modal').addEventListener('click', e => { if (e.target === e.currentTarget) closeModal(); });

// =========================================================
//  TOAST
// =========================================================
let toastTimer;
function toast(msg, type = '') {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = `toast ${type}`;
  void el.offsetWidth;
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), 3000);
}

function initMap(orderId) {
  // Simulated customer location
  const customer = [14.6091, 120.9822];

  // Rider starts farther away
  let rider = [14.5995, 120.9700];

  const map = L.map(`map-${orderId}`).setView(customer, 13);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap'
  }).addTo(map);

  // Customer marker
  L.marker(customer).addTo(map).bindPopup("📍 Customer");

  // Rider marker
  const riderMarker = L.marker(rider, {
    icon: L.icon({
      iconUrl: "https://cdn-icons-png.flaticon.com/512/854/854894.png",
      iconSize: [32, 32]
    })
  }).addTo(map).bindPopup("🚴 Rider");

  // Route line
  const routeLine = L.polyline([rider, customer], {
    color: '#3b7cf4',
    weight: 4,
    dashArray: '6,6'
  }).addTo(map);

  function moveRider() {
    const latDiff = customer[0] - rider[0];
    const lngDiff = customer[1] - rider[1];

    rider[0] += latDiff * 0.05;
    rider[1] += lngDiff * 0.05;

    riderMarker.setLatLng(rider);
    routeLine.setLatLngs([rider, customer]);

    if (Math.abs(latDiff) < 0.0005 && Math.abs(lngDiff) < 0.0005) {
      clearInterval(interval);
      riderMarker.bindPopup("✅ Arrived!").openPopup();
    }
  }

  const interval = setInterval(moveRider, 1000);
}
