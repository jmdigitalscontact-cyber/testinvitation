<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>RSVP Admin | Jason &amp; Rhona Mae</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin.css">
</head>
<body class="admin-app">
    <div class="admin-shell">
        <header class="admin-topbar">
            <div class="admin-brand">
                <h1>Jason &amp; Rhona Mae</h1>
                <p>RSVP administration</p>
        </div>
            <div class="admin-topbar-actions">
                <a class="admin-btn admin-btn-secondary" href="../home.html">View site</a>
                <button type="button" class="admin-btn admin-btn-secondary" onclick="AdminAuth.logout()">Sign out</button>
        </div>
        </header>

        <main class="admin-layout">
            <nav class="admin-nav" aria-label="Admin sections">
                <button type="button" class="admin-nav-btn is-active" data-tab="dashboard">Dashboard</button>
                <button type="button" class="admin-nav-btn" data-tab="invitations">Invitations</button>
                <button type="button" class="admin-nav-btn" data-tab="responses">Responses</button>
                <button type="button" class="admin-nav-btn" data-tab="export">Export</button>
                <button type="button" class="admin-nav-btn" data-tab="tables">Seating</button>
            </nav>

            <!-- Dashboard -->
            <section id="dashboard" class="admin-panel is-active" aria-label="Dashboard">
                <div id="dashboard-message" class="admin-flash" role="status"></div>

                <div class="admin-capacity">
                    <div class="admin-capacity-head">
                        <h2>Guest capacity</h2>
                        <span style="font-size:0.82rem;color:var(--admin-muted)">200 guest limit</span>
                    </div>
                    <div class="admin-capacity-stats">
                        <div class="admin-capacity-stat"><strong>Total</strong><span id="capacity-total">200</span></div>
                        <div class="admin-capacity-stat"><strong>Confirmed</strong><span id="capacity-used">0</span></div>
                        <div class="admin-capacity-stat"><strong>Remaining</strong><span id="capacity-remaining">200</span></div>
                    </div>
                    <div class="admin-progress" aria-hidden="true"><div id="capacity-bar" class="admin-progress-fill"></div></div>
            </div>

                <div class="admin-kpi-grid">
                    <div class="admin-kpi"><div class="admin-kpi-label">Invitations</div><div class="admin-kpi-value" id="stat-total">0</div></div>
                    <div class="admin-kpi"><div class="admin-kpi-label">Responded</div><div class="admin-kpi-value" id="stat-responded">0</div></div>
                    <div class="admin-kpi"><div class="admin-kpi-label">Confirmed guests</div><div class="admin-kpi-value" id="stat-confirmed">0</div></div>
                    <div class="admin-kpi"><div class="admin-kpi-label">Declined</div><div class="admin-kpi-value" id="stat-declined">0</div></div>
            </div>

                <div class="admin-card">
                    <h2>Response summary</h2>
                    <div class="admin-kpi-grid">
                        <div class="admin-kpi"><div class="admin-kpi-label">Total slots</div><div class="admin-kpi-value" id="total-slots">0</div></div>
                        <div class="admin-kpi"><div class="admin-kpi-label">Confirmed</div><div class="admin-kpi-value" id="confirmed-guests">0</div></div>
                        <div class="admin-kpi"><div class="admin-kpi-label">Pending</div><div class="admin-kpi-value" id="pending-responses">0</div></div>
                    </div>
                    <button type="button" class="admin-btn admin-btn-secondary" onclick="loadStats()">Refresh</button>
            </div>

                <div class="admin-card">
                    <h2>Unused guest slots</h2>
                    <p class="admin-card-lead">Invitations where confirmed guests are below the allowed maximum.</p>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                        <thead>
                                <tr>
                                    <th>Family / guest</th>
                                    <th>Max</th>
                                    <th>Confirmed</th>
                                    <th>Open</th>
                            </tr>
                        </thead>
                        <tbody id="unused-slots-tbody">
                                <tr><td colspan="4" class="admin-empty">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

                <div class="admin-card">
                    <h2>Guest list by invitation</h2>
                    <p class="admin-card-lead">Each QR code maps to one invitation and its submitted names.</p>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                        <thead>
                                <tr>
                                    <th>Invitation ID</th>
                                    <th>Primary guest</th>
                                    <th>Submitted names</th>
                            </tr>
                        </thead>
                        <tbody id="qr-guest-list-tbody">
                                <tr><td colspan="3" class="admin-empty">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            </section>

            <!-- Invitations -->
            <section id="invitations" class="admin-panel" aria-label="Invitations">
                <div id="invitations-message" class="admin-flash" role="status"></div>

                <div class="admin-card">
                    <h2>Create invitation</h2>
                    <form id="create-invitation-form" onsubmit="createInvitation(event)">
                        <div class="admin-form-grid">
                            <div class="admin-field">
                                <label for="guest-name">Guest / family name</label>
                                <input type="text" id="guest-name" placeholder="Smith Family" required>
                        </div>
                            <div class="admin-field">
                                <label for="max-guests">Maximum guests</label>
                            <input type="number" id="max-guests" min="1" max="10" value="1" required>
                        </div>
                            <div class="admin-field">
                            <label for="invite-password">Password</label>
                                <input type="password" id="invite-password" required>
                        </div>
                            <div class="admin-field">
                                <label for="invite-email">Email (optional)</label>
                            <input type="email" id="invite-email" placeholder="guest@example.com">
                        </div>
                    </div>
                        <div class="admin-field">
                            <label for="invited-guest-names">Invited guest names (one per line)</label>
                            <textarea id="invited-guest-names" rows="4" placeholder="Guest name"></textarea>
                    </div>
                        <button type="submit" class="admin-btn admin-btn-primary">Create invitation</button>
                </form>
            </div>

                <div class="admin-card">
                    <h2>All invitations</h2>
                    <div class="admin-table-wrap">
                        <table class="admin-table" id="invitations-table">
                        <thead>
                            <tr>
                                    <th>Guest</th>
                                    <th>ID</th>
                                    <th>Max</th>
                                <th>Status</th>
                                    <th>QR</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="invitations-tbody">
                                <tr><td colspan="6" class="admin-empty">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
                    <button type="button" class="admin-btn admin-btn-secondary" onclick="loadInvitations()">Refresh</button>
            </div>
            </section>

            <!-- Responses -->
            <section id="responses" class="admin-panel" aria-label="Responses">
                <div class="admin-card">
                    <h2>RSVP responses</h2>
                    <div class="admin-table-wrap">
                        <table class="admin-table" id="responses-table">
                        <thead>
                            <tr>
                                    <th>Guest</th>
                                <th>Attending</th>
                                    <th>Count</th>
                                    <th>Submitted</th>
                                    <th>Names</th>
                                    <th></th>
                            </tr>
                        </thead>
                        <tbody id="responses-tbody">
                                <tr><td colspan="6" class="admin-empty">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
                    <button type="button" class="admin-btn admin-btn-secondary" onclick="loadResponses()">Refresh</button>
            </div>
            </section>

            <!-- Export -->
            <section id="export" class="admin-panel" aria-label="Export">
                <div id="export-message" class="admin-flash" role="status"></div>

                <div class="admin-card">
                    <h2>Google Sheets</h2>
                    <p class="admin-card-lead">Push the latest data to your shared sheet. Requires <code>GOOGLE_SHEETS_CREDENTIALS_PATH</code> in <code>.env</code>.</p>
                    <div class="admin-actions">
                        <button type="button" class="admin-btn admin-btn-primary" onclick="exportToGoogleSheets('invitations', this)">Export invitations</button>
                        <button type="button" class="admin-btn admin-btn-primary" onclick="exportToGoogleSheets('responses', this)">Export responses</button>
                        <button type="button" class="admin-btn admin-btn-secondary" id="open-google-sheet-btn">Open sheet</button>
                    </div>
            </div>

                <div class="admin-card">
                    <h2>CSV download</h2>
                    <p class="admin-card-lead">Download flat CSV files for backup or mail merge.</p>
                    <div class="admin-actions">
                        <button type="button" class="admin-btn admin-btn-secondary" onclick="exportInvitationsCSV()">Invitations CSV</button>
                        <button type="button" class="admin-btn admin-btn-secondary" onclick="exportSummary()">Responses CSV</button>
                        <button type="button" class="admin-btn admin-btn-secondary" onclick="exportCSV()">Full RSVP CSV</button>
            </div>
        </div>
            </section>

            <!-- Seating -->
            <section id="tables" class="admin-panel" aria-label="Seating">
                <div class="admin-card">
                    <h2>Table planning</h2>
                    <div class="admin-form-grid">
                        <div class="admin-field">
                            <label for="total-capacity">Total guest capacity</label>
                            <input type="number" id="total-capacity" value="200" min="1" onchange="updateTableCalculations()">
                    </div>
                        <div class="admin-field">
                            <label for="seats-per-table">Seats per table</label>
                            <input type="number" id="seats-per-table" value="10" min="1" onchange="updateTableCalculations()">
                    </div>
                        <div class="admin-field">
                            <label>Tables needed</label>
                            <div class="admin-kpi-value" id="tables-needed" style="font-size:1.35rem">20</div>
                    </div>
                        <div class="admin-field">
                            <label>Tables in use</label>
                            <div class="admin-kpi-value" id="tables-assigned" style="font-size:1.35rem">0</div>
                    </div>
                </div>
                    <div class="admin-planning-note" id="table-planning-summary">Loading…</div>
            </div>

                <div class="admin-card">
                    <h2>Assign tables</h2>
                    <div class="admin-field">
                        <label for="table-number-select">Filter by table</label>
                        <select id="table-number-select">
                            <option value="">All tables</option>
                    </select>
                </div>
                    <div class="admin-table-wrap">
                        <table class="admin-table" id="table-assignments-table">
                        <thead>
                            <tr>
                                    <th>Guest</th>
                                <th>Companions</th>
                                    <th>Table</th>
                                    <th></th>
                            </tr>
                        </thead>
                            <tbody></tbody>
                    </table>
                </div>
                    <button type="button" class="admin-btn admin-btn-secondary" onclick="loadTableAssignments()">Refresh</button>
            </div>

                <div class="admin-card">
                    <h2>Table overview</h2>
                    <div class="admin-field">
                        <label for="table-search">Search guest or companion</label>
                        <input type="search" id="table-search" placeholder="Start typing a name" oninput="filterTableOverview()">
                </div>
                    <div id="table-overview"></div>
                    <p id="table-overview-search-result" style="margin-top:0.5rem;color:var(--admin-muted);font-size:0.84rem"></p>
                </div>
            </section>
        </main>
    </div>

    <!-- QR modal -->
    <div id="qr-modal" class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="qr-modal-title">
        <div class="admin-modal-dialog">
            <div class="admin-modal-head">
                <h3 id="qr-modal-title">QR code</h3>
                <button type="button" class="admin-modal-close" data-close-modal="qr-modal" aria-label="Close">&times;</button>
            </div>
            <p style="text-align:center;color:var(--admin-muted);margin-bottom:0.5rem"><strong id="qr-modal-id"></strong></p>
            <div class="admin-qr-preview"><img id="qr-modal-image" src="" alt="Invitation QR code"></div>
            <p style="text-align:center;font-size:0.82rem;color:var(--admin-muted)">Right-click to save or print.</p>
        </div>
    </div>

    <!-- Details modal -->
    <div id="details-modal" class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="details-modal-title">
        <div class="admin-modal-dialog admin-modal-dialog--wide">
            <div class="admin-modal-head">
                <h3 id="details-modal-title">Response details</h3>
                <button type="button" class="admin-modal-close" data-close-modal="details-modal" aria-label="Close">&times;</button>
            </div>
            <div id="details-modal-content"></div>
        </div>
    </div>

    <!-- Edit invitation modal -->
    <div id="edit-modal" class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title">
        <div class="admin-modal-dialog admin-modal-dialog--wide">
            <div class="admin-modal-head">
                <h3 id="edit-modal-title">Edit invitation</h3>
                <button type="button" class="admin-modal-close" data-close-modal="edit-modal" aria-label="Close">&times;</button>
                    </div>
            <form onsubmit="saveEditInvitation(event)">
                <input type="hidden" id="edit-invitation-id">
                <div class="admin-form-grid">
                    <div class="admin-field">
                        <label for="edit-guest-name">Guest / family name</label>
                        <input type="text" id="edit-guest-name" required>
                    </div>
                    <div class="admin-field">
                        <label for="edit-max-guests">Maximum guests</label>
                        <input type="number" id="edit-max-guests" min="1" max="10" required>
                    </div>
                    <div class="admin-field">
                        <label for="edit-email">Email</label>
                        <input type="email" id="edit-email">
                </div>
                    <div class="admin-field">
                        <label for="edit-password">New password (optional)</label>
                        <input type="password" id="edit-password" placeholder="Leave blank to keep current">
                    </div>
                </div>
                <div class="admin-field">
                    <label for="edit-invited-names">Invited guest names (one per line)</label>
                    <textarea id="edit-invited-names" rows="4"></textarea>
                </div>
                <div class="admin-actions">
                    <button type="button" class="admin-btn admin-btn-secondary" data-close-modal="edit-modal">Cancel</button>
                    <button type="submit" class="admin-btn admin-btn-primary">Save changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Assign table modal -->
    <div id="assign-table-modal" class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="assign-table-title">
        <div class="admin-modal-dialog">
            <div class="admin-modal-head">
                <h3 id="assign-table-title">Assign table</h3>
                <button type="button" class="admin-modal-close" data-close-modal="assign-table-modal" aria-label="Close">&times;</button>
                    </div>
            <form onsubmit="saveTableAssignment(event)">
                <input type="hidden" id="assign-invitation-id">
                <p><strong>Guest:</strong> <span id="assign-guest-label"></span></p>
                <p style="margin-bottom:0.85rem"><strong>Companions:</strong> <span id="assign-companions-label"></span></p>
                <div class="admin-field">
                    <label for="assign-table-number">Table number</label>
                    <input type="number" id="assign-table-number" min="1" max="50" required>
                    </div>
                <div class="admin-actions">
                    <button type="button" class="admin-btn admin-btn-secondary" data-close-modal="assign-table-modal">Cancel</button>
                    <button type="submit" class="admin-btn admin-btn-primary" id="assign-table-submit">Save assignment</button>
                </div>
            </form>
        </div>
    </div>

    <script src="admin-auth.js"></script>
    <script src="admin-dashboard.js"></script>
    <script>
        window.addEventListener('load', function () {
            AdminAuth.init({
                onAuthenticated: function () {
                    if (typeof initAdminDashboard === 'function') initAdminDashboard();
                }
            });
        });
    </script>
</body>
</html>
