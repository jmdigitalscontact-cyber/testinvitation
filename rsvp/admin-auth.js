/**
 * Admin authentication & CSRF for RSVP dashboard
 */
const AdminAuth = {
  token: null,
  csrfToken: null,
  isInitialized: false,
  onAuthenticated: null,

  init(options = {}) {
    if (this.isInitialized) return;
    this.onAuthenticated = typeof options.onAuthenticated === "function" ? options.onAuthenticated : null;

    this.hideDashboard();
    this.token = null;
    this.csrfToken = null;
    localStorage.removeItem("admin_token");
    localStorage.removeItem("admin_csrf_token");

    this.showLoginModal();
    this.isInitialized = true;
  },

  apiCall(url, options = {}) {
    if (!this.token || !this.csrfToken) {
      this.showLoginModal();
      return Promise.reject(new Error("Not authenticated"));
    }

    const headers = { ...(options.headers || {}) };
    headers.Authorization = `Bearer ${this.token}`;
    headers["X-CSRF-Token"] = this.csrfToken;
    if (!headers["Content-Type"] && options.body) {
      headers["Content-Type"] = "application/json";
    }

    return fetch(url, { ...options, headers });
  },

  showLoginModal() {
    this.hideLoginModal();
    document.body.style.overflow = "hidden";

    const modal = document.createElement("div");
    modal.id = "admin-login-modal";
    modal.className = "admin-login-overlay";

    modal.innerHTML = `
      <div class="admin-login-card" role="dialog" aria-modal="true" aria-labelledby="admin-login-title">
        <div class="admin-login-brand">
          <p class="admin-login-eyebrow">Jason &amp; Rhona Mae</p>
          <h2 id="admin-login-title">Admin sign in</h2>
          <p class="admin-login-lead">Enter your credentials to manage invitations and RSVPs.</p>
        </div>
        <p id="login-message" class="admin-login-error" hidden></p>
        <form id="admin-login-form">
          <div class="admin-login-field">
            <label for="admin-username">Username</label>
            <input type="text" id="admin-username" autocomplete="username" required>
          </div>
          <div class="admin-login-field">
            <label for="admin-password">Password</label>
            <input type="password" id="admin-password" autocomplete="current-password" required>
          </div>
          <button type="submit" class="admin-login-submit">Sign in</button>
        </form>
      </div>
    `;

    document.body.appendChild(modal);
    document.getElementById("admin-login-form").addEventListener("submit", (e) => this.handleLogin(e));
    document.getElementById("admin-username").focus();
  },

  hideLoginModal() {
    document.getElementById("admin-login-modal")?.remove();
    document.body.style.overflow = "";
  },

  hideDashboard() {
    const shell = document.querySelector(".admin-shell");
    if (shell) shell.style.visibility = "hidden";
  },

  showDashboard() {
    const shell = document.querySelector(".admin-shell");
    if (shell) shell.style.visibility = "visible";
  },

  handleLogin(event) {
    event.preventDefault();

    const username = document.getElementById("admin-username").value.trim();
    const password = document.getElementById("admin-password").value;
    const messageEl = document.getElementById("login-message");
    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;

    messageEl.hidden = true;
    submitBtn.textContent = "Signing in…";
    submitBtn.disabled = true;

    fetch("api.php?action=admin-login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ username, password }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success && data.token) {
          this.token = data.token;
          this.csrfToken = data.csrf_token;
          localStorage.setItem("admin_token", this.token);
          localStorage.setItem("admin_csrf_token", this.csrfToken);
          this.hideLoginModal();
          this.showDashboard();
          if (this.onAuthenticated) this.onAuthenticated();
        } else {
          messageEl.textContent = data.error || "Sign in failed. Check your credentials.";
          messageEl.hidden = false;
          submitBtn.textContent = originalText;
          submitBtn.disabled = false;
        }
      })
      .catch((error) => {
        messageEl.textContent = `Error: ${error.message}`;
        messageEl.hidden = false;
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
      });
  },

  logout() {
    this.token = null;
    this.csrfToken = null;
    localStorage.removeItem("admin_token");
    localStorage.removeItem("admin_csrf_token");
    location.reload();
  },

  isAuthenticated() {
    return !!(this.token && this.csrfToken);
  },
};
