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
    <link rel="stylesheet" href="admin.css?v=<?= (int) @filemtime(__DIR__ . '/admin.css') ?>">
</head>
<body class="admin-app">
    <div class="admin-shell">
        <header class="admin-topbar">
            <div class="admin-brand">
                <h1>Jason &amp; Rhona Mae</h1>
                <p>RSVP administration</p>
        </div>
            <div class="admin-topbar-actions">
                <a class="admin-btn admin-btn-secondary" href="../index.html">View site</a>
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
                <button type="button" class="admin-nav-btn" data-tab="photos">Photos</button>
                <button type="button" class="admin-nav-btn" data-tab="reception">Reception</button>
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
                <div class="admin-pagination">
                    <button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" id="unused-prev" onclick="unusedPrevPage()" disabled aria-label="Previous page">← Prev</button>
                    <span id="unused-page-info" class="admin-pagination-info">Page 1 of 1</span>
                    <button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" id="unused-next" onclick="unusedNextPage()" aria-label="Next page">Next →</button>
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
                <div class="admin-pagination">
                    <button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" id="qrlist-prev" onclick="qrGuestPrevPage()" disabled aria-label="Previous page">← Prev</button>
                    <span id="qrlist-page-info" class="admin-pagination-info">Page 1 of 1</span>
                    <button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" id="qrlist-next" onclick="qrGuestNextPage()" aria-label="Next page">Next →</button>
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
                                <label for="invite-email">Email (optional)</label>
                            <input type="email" id="invite-email" placeholder="guest@example.com">
                        </div>
                        <div class="admin-field">
                                <label for="invite-password">Invitation password</label>
                                <input type="text" id="invite-password" placeholder="Leave blank to auto-generate">
                                <p style="font-size:0.78rem;color:var(--admin-muted);margin:0.25rem 0 0">Guests can still RSVP by scanning their QR code — the password is only needed if they use the legacy ID+password login.</p>
                        </div>
                    </div>
                        <div class="admin-field">
                            <label for="invited-guest-names">Invited guest names (one per line)</label>
                            <textarea id="invited-guest-names" rows="4" placeholder="Guest name"></textarea>
                    </div>
                        <label class="admin-checkbox">
                            <input type="checkbox" id="auto-send-invite">
                            <span>Send invitation email right away (requires an email address)</span>
                        </label>
                        <button type="submit" class="admin-btn admin-btn-primary">Create invitation</button>
                </form>
            </div>

                <div class="admin-card">
                    <h2>All invitations</h2>
                    <div class="admin-field">
                        <input type="search" id="invitations-search" placeholder="Search by guest name or invitation ID..." autocomplete="off" oninput="filterInvitations(event)">
                    </div>
                    <div class="admin-table-wrap">
                        <table class="admin-table" id="invitations-table">
                        <thead>
                            <tr>
                                    <th>Guest</th>
                                    <th>ID</th>
                                    <th>Max</th>
                            <th>Status</th>
                                    <th>QR</th>
                                    <th>Download</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="invitations-tbody">
                                <tr><td colspan="7" class="admin-empty">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="admin-pagination">
                    <button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" id="invitations-prev" onclick="invitationsPrevPage()" disabled aria-label="Previous page">← Prev</button>
                    <span id="invitations-page-info" class="admin-pagination-info">Page 1 of 1</span>
                    <button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" id="invitations-next" onclick="invitationsNextPage()" aria-label="Next page">Next →</button>
                </div>
                    <button type="button" class="admin-btn admin-btn-secondary" onclick="loadInvitations()">Refresh</button>
            </div>
            </section>

            <!-- Responses -->
            <section id="responses" class="admin-panel" aria-label="Responses">
                <div class="admin-card">
                    <h2>RSVP responses</h2>
                    <div class="admin-field">
                        <input type="search" id="responses-search" placeholder="Search by guest, invitation ID, status, or attendee name..." autocomplete="off" oninput="filterResponses(event)">
                    </div>
                    <div class="admin-table-wrap">
                        <table class="admin-table" id="responses-table">
                        <thead>
                            <tr>
                                    <th>Guest</th>
                                <th>Attending</th>
                                    <th>Count</th>
                                    <th>Latest</th>
                                    <th>Names</th>
                                    <th></th>
                            </tr>
                        </thead>
                        <tbody id="responses-tbody">
                                <tr><td colspan="6" class="admin-empty">Loading…</td></tr>
                        </tbody>
                    </table>
                    </div>
                    <div class="admin-pagination">
                        <button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" id="responses-prev" onclick="responsesPrevPage()" disabled aria-label="Previous page">← Prev</button>
                        <span id="responses-page-info" class="admin-pagination-info">Page 1 of 1</span>
                        <button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" id="responses-next" onclick="responsesNextPage()" aria-label="Next page">Next →</button>
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
                    <h2>Reception floor plan</h2>
                    <p class="admin-card-lead">The floor below is the reception room. Green circles are tables. Beige boxes are Stage, Entrance, and Buffet. Drag any of them to place them, then save.</p>
                    <div id="floor-plan-message" class="admin-flash" role="status"></div>
                    <div class="admin-floor-toolbar">
                        <button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" id="floor-add-table-btn">Add table</button>
                        <button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" id="floor-remove-table-btn" disabled>Remove selected table</button>
                        <label class="admin-floor-label-field">
                            <span>Selected label</span>
                            <input type="text" id="floor-marker-label" maxlength="32" placeholder="Stage, Entrance…" disabled>
                        </label>
                        <button type="button" class="admin-btn admin-btn-primary admin-btn-sm" id="floor-save-btn">Save floor plan</button>
                    </div>
                    <div class="admin-floor-key" aria-hidden="true">
                        <span><i class="admin-floor-key__table"></i> Tables</span>
                        <span><i class="admin-floor-key__marker"></i> Stage / Entrance / Buffet</span>
                        <span>Drag anything on the floor to edit</span>
                    </div>
                    <div class="admin-floor-editor" id="floor-plan-editor" aria-label="Editable reception floor plan">
                        <div class="admin-floor-room" id="admin-floor-room"></div>
                    </div>
                    <p class="admin-muted" id="floor-plan-hint">Green circles are tables. Beige boxes are Stage, Entrance, and Buffet. Drag them on the floor, then save.</p>
                </div>

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

            <!-- POV Photos (local failover — not shown on the guest Photos tab) -->
            <section id="photos" class="admin-panel" aria-label="POV Photos">
                <div id="photos-message" class="admin-flash" role="status"></div>

                <div class="admin-card admin-failover-card">
                    <p class="admin-failover-kicker">Failover only</p>
                    <h2>Local photo backup</h2>
                    <p class="admin-card-lead">Guests share in Google Photos from the reception Photos tab. This gallery is hidden from guests. Use it only if the shared album is unavailable, then export the ZIP into Google Photos later.</p>
                    <div class="admin-actions admin-actions--wrap">
                        <a class="admin-btn admin-btn-primary" href="https://photos.app.goo.gl/LyebvyWMcerYSJmR6" target="_blank" rel="noopener noreferrer">Open Google Photos album</a>
                    </div>

                    <div class="admin-failover-upload">
                        <label class="admin-failover-drop" for="admin-failover-photo-input">
                            <strong>Upload failover photos</strong>
                            <span>JPEG, PNG, WebP, HEIC · max 10MB. Stored locally, not shown on reception.</span>
                        </label>
                        <input id="admin-failover-photo-input" type="file" accept="image/*" multiple hidden>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="admin-photos-head">
                        <div>
                            <h2>Failover gallery</h2>
                            <p class="admin-card-lead">Local copies collected here if Google Photos is down. Export everything or clear the gallery for testing.</p>
                        </div>
                        <div class="admin-kpi admin-kpi--compact">
                            <div class="admin-kpi-label">Total photos</div>
                            <div class="admin-kpi-value" id="admin-photos-count">0</div>
                        </div>
                    </div>

                    <div class="admin-actions admin-actions--wrap">
                        <button type="button" class="admin-btn admin-btn-primary" onclick="downloadAllPhotosZip()">Export all (.zip)</button>
                        <button type="button" class="admin-btn admin-btn-secondary" onclick="loadAdminPhotos()">Refresh</button>
                        <button type="button" class="admin-btn admin-btn-danger" onclick="clearAllAdminPhotos()">Clear all photos</button>
                    </div>

                    <div id="admin-photos-grid" class="admin-photos-grid">
                        <p class="admin-empty">Loading photos…</p>
                    </div>
                </div>
            </section>

            <!-- Reception access -->
            <section id="reception" class="admin-panel" aria-label="Reception">
                <div id="reception-message" class="admin-flash" role="status"></div>

                <div class="admin-card">
                    <h2>Team Bride vs Team Groom</h2>
                    <p class="admin-card-lead">Live guest vote totals. Reset votes after rehearsal or testing so guests can vote again on the same phones.</p>
                    <div class="admin-kpi-grid">
                        <div class="admin-kpi">
                            <div class="admin-kpi-label">Team Bride</div>
                            <div class="admin-kpi-value" id="reception-votes-bride">—</div>
                        </div>
                        <div class="admin-kpi">
                            <div class="admin-kpi-label">Team Groom</div>
                            <div class="admin-kpi-value" id="reception-votes-groom">—</div>
                        </div>
                        <div class="admin-kpi">
                            <div class="admin-kpi-label">Total votes</div>
                            <div class="admin-kpi-value" id="reception-votes-total">—</div>
                        </div>
                    </div>
                    <div class="admin-actions">
                        <button type="button" class="admin-btn admin-btn-secondary" onclick="loadReceptionVotes()">Refresh votes</button>
                        <button type="button" class="admin-btn admin-btn-secondary admin-btn-danger-text" onclick="resetReceptionVotes()">Reset votes for testing</button>
                    </div>
                    <div class="admin-table-wrap" style="margin-top:1rem">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Vote</th>
                                    <th>Anonymous device</th>
                                    <th>Team</th>
                                    <th>Voted</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="reception-votes-tbody">
                                <tr><td colspan="5" class="admin-empty">Open this tab to load votes.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="admin-card">
                    <h2>Messages for the couple</h2>
                    <p class="admin-card-lead">Private notes guests leave in the reception app. Only admins can read these — guests cannot browse other messages.</p>
                    <div class="admin-kpi-grid">
                        <div class="admin-kpi">
                            <div class="admin-kpi-label">Total messages</div>
                            <div class="admin-kpi-value" id="reception-messages-total">—</div>
                        </div>
                    </div>
                    <div class="admin-actions admin-actions--wrap">
                        <button type="button" class="admin-btn admin-btn-primary" onclick="exportReceptionMessagesCsv()">Export CSV</button>
                        <button type="button" class="admin-btn admin-btn-secondary" onclick="loadReceptionMessages()">Refresh</button>
                        <button type="button" class="admin-btn admin-btn-secondary admin-btn-danger-text" onclick="clearReceptionMessages()">Clear all messages</button>
                    </div>
                    <div class="admin-table-wrap" style="margin-top:1rem">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Message</th>
                                    <th>Sent</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="reception-messages-tbody">
                                <tr><td colspan="5" class="admin-empty">Open this tab to load messages.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="admin-card">
                    <h2>Reception access QR</h2>
                    <p class="admin-card-lead">Guests scan this QR code to unlock the reception app (seating, menu, gallery, gifts). The code embeds your <code>RECEPTION_API_KEY</code> from <code>.env</code>, so the main content stays hidden until the correct key is scanned.</p>

                    <div class="admin-actions">
                        <button type="button" class="admin-btn admin-btn-primary" id="generate-reception-qr-btn" onclick="generateReceptionQR(this)">Generate reception QR</button>
                        <button type="button" class="admin-btn admin-btn-secondary" id="download-reception-qr-btn" onclick="downloadReceptionQR()" disabled>Download QR</button>
                    </div>

                    <div class="admin-qr-preview" id="reception-qr-preview" hidden>
                        <img id="reception-qr-image" src="" alt="Reception access QR code">
                        <p style="text-align:center;font-size:0.82rem;color:var(--admin-muted)">Reception access link is embedded in this QR. Anyone who scans it can unlock the reception app.</p>
                    </div>
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
            <p style="text-align:center;font-size:0.82rem;color:var(--admin-muted)">Use the Download button in the list to save a copy.</p>
            <div class="admin-actions" style="justify-content:center;margin-top:0.75rem">
                <button type="button" class="admin-btn admin-btn-primary admin-btn-sm" id="qr-modal-download">Download QR</button>
            </div>
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
                        <label for="edit-password">Invitation password</label>
                        <input type="text" id="edit-password" placeholder="Leave blank to keep current">
                </div>
</div>
                <div class="admin-field">
                    <label for="edit-invited-names">Invited guest names (one per line)</label>
                    <textarea id="edit-invited-names" rows="4"></textarea>
                </div>
                <div class="admin-card" style="margin-top:1rem;padding:1rem;background:var(--admin-surface-alt, #f7f9f7)">
                    <h4 style="margin:0 0 0.75rem;font-size:0.95rem">RSVP override</h4>
                    <p style="font-size:0.82rem;color:var(--admin-muted);margin:0 0 0.85rem">Fix mistaken submissions or set attendance on behalf of a guest.</p>
                    <div class="admin-form-grid">
                        <div class="admin-field">
                            <label for="edit-rsvp-status">RSVP status</label>
                            <select id="edit-rsvp-status">
                                <option value="pending">Pending (no response)</option>
                                <option value="yes">Yes — attending</option>
                                <option value="no">No — declined</option>
                                <option value="maybe">Maybe</option>
                            </select>
                        </div>
                        <div class="admin-field" style="display:flex;align-items:flex-end">
                            <button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick="resetEditInvitationRsvp()">Reset RSVP</button>
                        </div>
                    </div>
                    <p id="edit-rsvp-pending-note" style="font-size:0.82rem;color:var(--admin-muted);margin:0.5rem 0 0" hidden>
                        Pending clears the stored response so the guest can scan their QR and submit again.
                    </p>
                    <div id="edit-rsvp-attendees-wrap" class="admin-field" style="margin-top:0.75rem;margin-bottom:0" hidden>
                        <label>Who is attending</label>
                        <div id="edit-rsvp-attendees" class="admin-rsvp-attendees"></div>
                    </div>
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

    <script src="admin-auth.js?v=<?= (int) @filemtime(__DIR__ . '/admin-auth.js') ?>"></script>
    <script src="admin-dashboard.js?v=<?= (int) @filemtime(__DIR__ . '/admin-dashboard.js') ?>"></script>
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
