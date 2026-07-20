/**
 * Wedding RSVP admin dashboard
 */
(function () {
  "use strict";

  const TOTAL_CAPACITY = 200;
  let globalInvitations = [];
  let globalResponses = [];
  let globalAssignments = [];
  let tableSelectBound = false;

  function $(id) {
    return document.getElementById(id);
  }

  function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = text == null ? "" : String(text);
    return div.innerHTML;
  }

  function showFlash(elementId, message, type) {
    const el = $(elementId);
    if (!el) return;
    el.textContent = message;
    el.className = `admin-flash admin-flash-${type} is-visible`;
  }

  function hideFlash(elementId) {
    const el = $(elementId);
    if (!el) return;
    el.className = "admin-flash";
    el.textContent = "";
  }

  function openModal(id) {
    const modal = $(id);
    if (modal) modal.classList.add("is-open");
  }

  function closeModal(id) {
    if (id) {
      const modal = $(id);
      if (modal) modal.classList.remove("is-open");
      return;
    }
    document.querySelectorAll(".admin-modal.is-open").forEach((m) => m.classList.remove("is-open"));
  }

  window.switchTab = function switchTab(tabName, evt) {
    document.querySelectorAll(".admin-panel").forEach((el) => el.classList.remove("is-active"));
    document.querySelectorAll(".admin-nav-btn").forEach((el) => el.classList.remove("is-active"));

    const panel = $(tabName);
    if (panel) panel.classList.add("is-active");

    let button = evt && evt.target ? evt.target.closest(".admin-nav-btn") : null;
    if (!button) {
      button = document.querySelector(`.admin-nav-btn[data-tab="${tabName}"]`);
    }
    if (button) button.classList.add("is-active");

    if (tabName === "dashboard") loadStats();
    else if (tabName === "invitations") loadInvitations();
    else if (tabName === "responses") loadResponses();
    else if (tabName === "tables") loadTableAssignments();
  };

  function extractGuestNamesFromResponse(response) {
    if (!response) return [];
    let names = [];
    if (Array.isArray(response.attendees) && response.attendees.length > 0) {
      names = response.attendees
        .filter((a) => {
          if (!a || typeof a !== "object") return false;
          if (Object.prototype.hasOwnProperty.call(a, "attending")) {
            return !!a.attending && a.attending !== "false" && a.attending !== "0";
          }
          if (Object.prototype.hasOwnProperty.call(a, "going")) {
            return !!a.going;
          }
          return true;
        })
        .map((a) => a.attendee_name || a.name || "")
        .map((n) => String(n).trim())
        .filter(Boolean);
    }
    if (names.length === 0 && response.special_notes) {
      names = String(response.special_notes)
        .split(/\r\n|\r|\n|,/)
        .map((n) => n.trim())
        .filter(Boolean);
    }
    return names;
  }

  function computeDashboardStats(invitations, responses) {
    const totalInvitations = invitations.length;
    const respondedInvitationIds = new Set(responses.map((r) => r.invitation_id));
    const responded = respondedInvitationIds.size;
    const confirmedGuests = responses
      .filter((r) => r.attending === "yes")
      .reduce((sum, r) => sum + (parseInt(r.attendee_count, 10) || 0), 0);
    const declined = responses.filter((r) => r.attending === "no").length;
    const totalSlots = invitations.reduce((sum, inv) => sum + (parseInt(inv.max_guests, 10) || 0), 0);
    const pending = totalInvitations - responded;
    const capacityRemaining = Math.max(0, TOTAL_CAPACITY - confirmedGuests);

    return {
      totalInvitations,
      responded,
      confirmedGuests,
      declined,
      totalSlots,
      pending,
      capacityTotal: TOTAL_CAPACITY,
      capacityRemaining,
    };
  }

  window.loadStats = function loadStats() {
    Promise.all([
      AdminAuth.apiCall("api.php?action=get-invitations").then((r) => r.json()),
      AdminAuth.apiCall("api.php?action=get-rsvp-summary").then((r) => r.json()),
    ])
      .then(([invitationsRes, responsesRes]) => {
        if (!invitationsRes.success || !responsesRes.success) {
          showFlash("dashboard-message", "Could not load dashboard data.", "error");
          return;
        }

        const allInvitations = invitationsRes.data || [];
        const allResponses = responsesRes.data || [];
        const stats = computeDashboardStats(allInvitations, allResponses);

        $("stat-total").textContent = stats.totalInvitations;
        $("stat-responded").textContent = stats.responded;
        $("stat-confirmed").textContent = stats.confirmedGuests;
        $("stat-declined").textContent = stats.declined;
        $("total-slots").textContent = stats.totalSlots;
        $("confirmed-guests").textContent = stats.confirmedGuests;
        $("pending-responses").textContent = stats.pending;
        $("capacity-total").textContent = stats.capacityTotal;
        $("capacity-used").textContent = stats.confirmedGuests;
        $("capacity-remaining").textContent = stats.capacityRemaining;

        const usagePercent = stats.capacityTotal
          ? (stats.confirmedGuests / stats.capacityTotal) * 100
          : 0;
        $("capacity-bar").style.width = `${Math.min(100, usagePercent)}%`;

        populateUnusedSlotsTable(allInvitations, allResponses);
        populateQrGuestListTable(allInvitations, allResponses);
      })
      .catch((error) => {
        showFlash("dashboard-message", error.message || "Failed to load stats.", "error");
      });
  };

  function populateUnusedSlotsTable(invitations, responses) {
    const tbody = $("unused-slots-tbody");
    tbody.innerHTML = "";

    const confirmationMap = {};
    responses.forEach((r) => {
      if (!confirmationMap[r.invitation_id]) confirmationMap[r.invitation_id] = 0;
      if (r.attending === "yes") {
        confirmationMap[r.invitation_id] += parseInt(r.attendee_count, 10) || 0;
      }
    });

    const unusedInvitations = invitations.filter((inv) => {
      const confirmed = confirmationMap[inv.invitation_id] || 0;
      return confirmed < parseInt(inv.max_guests, 10);
    });

    if (unusedInvitations.length === 0) {
      tbody.innerHTML =
        '<tr><td colspan="4" class="admin-empty">All invitations have confirmed their guest count.</td></tr>';
      return;
    }

    unusedInvitations.forEach((inv) => {
      const confirmed = confirmationMap[inv.invitation_id] || 0;
      const maxGuests = parseInt(inv.max_guests, 10);
      const unusedSlots = maxGuests - confirmed;
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${escapeHtml(inv.guest_name)}</td>
        <td style="text-align:center">${maxGuests}</td>
        <td style="text-align:center">${confirmed}</td>
        <td style="text-align:center"><span class="admin-badge admin-badge-pending">${unusedSlots} open</span></td>
      `;
      tbody.appendChild(tr);
    });
  }

  function populateQrGuestListTable(invitations, responses) {
    const tbody = $("qr-guest-list-tbody");
    tbody.innerHTML = "";

    const responseMap = {};
    responses.forEach((response) => {
      if (!response || !response.invitation_id) return;
      const current = responseMap[response.invitation_id];
      if (!current) {
        responseMap[response.invitation_id] = response;
        return;
      }
      const currentTime = current.submitted_at ? new Date(current.submitted_at).getTime() : 0;
      const candidateTime = response.submitted_at ? new Date(response.submitted_at).getTime() : 0;
      if (candidateTime >= currentTime) responseMap[response.invitation_id] = response;
    });

    if (!invitations.length) {
      tbody.innerHTML = '<tr><td colspan="3" class="admin-empty">No invitations yet.</td></tr>';
      return;
    }

    invitations.forEach((inv) => {
      const response = responseMap[inv.invitation_id];
      const guestNames = extractGuestNamesFromResponse(response);
      const listedGuestsHtml = guestNames.length
        ? guestNames.map((name) => `<div>${escapeHtml(name)}</div>`).join("")
        : '<span style="color:var(--admin-muted)">No submitted names yet</span>';

      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td><code>${escapeHtml(inv.invitation_id)}</code></td>
        <td>${escapeHtml(inv.guest_name || "")}</td>
        <td>${listedGuestsHtml}</td>
      `;
      tbody.appendChild(tr);
    });
  }

  window.createInvitation = function createInvitation(event) {
    event.preventDefault();
    hideFlash("invitations-message");

    const guestName = $("guest-name").value.trim();
    const maxGuests = parseInt($("max-guests").value, 10);
    const password = $("invite-password").value;
    const email = $("invite-email").value.trim();
    const invitedGuestNames = String($("invited-guest-names").value || "")
      .split(/\r?\n/)
      .map((name) => name.trim())
      .filter(Boolean);

    AdminAuth.apiCall("api.php?action=create-invitation", {
      method: "POST",
      body: JSON.stringify({
        guest_name: guestName,
        max_guests: maxGuests,
        password,
        email,
        invited_guest_names: invitedGuestNames,
      }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          showFlash("invitations-message", "Invitation created.", "success");
          $("create-invitation-form").reset();
          $("max-guests").value = "1";
          loadInvitations();
          loadStats();
        } else {
          showFlash("invitations-message", data.error || "Failed to create invitation.", "error");
        }
      })
      .catch((error) => {
        showFlash("invitations-message", error.message || "Failed to create invitation.", "error");
      });
  };

  function statusBadge(status) {
    const safe = escapeHtml(status || "pending");
    const cls = ["responded", "yes"].includes(status)
      ? "admin-badge-responded"
      : status === "declined" || status === "no"
        ? "admin-badge-declined"
        : "admin-badge-pending";
    return `<span class="admin-badge ${cls}">${safe}</span>`;
  }

  function attendanceBadge(attending) {
    const safe = escapeHtml(attending || "pending");
    const cls =
      attending === "yes"
        ? "admin-badge-yes"
        : attending === "no"
          ? "admin-badge-no"
          : attending === "maybe"
            ? "admin-badge-maybe"
            : "admin-badge-pending";
    return `<span class="admin-badge ${cls}">${safe}</span>`;
  }

  window.loadInvitations = function loadInvitations() {
    AdminAuth.apiCall("api.php?action=get-invitations")
      .then((response) => response.json())
      .then((data) => {
        const tbody = $("invitations-tbody");
        tbody.innerHTML = "";

        if (!data.success) {
          tbody.innerHTML = '<tr><td colspan="6" class="admin-empty">Failed to load invitations.</td></tr>';
          return;
        }

        if (!data.data.length) {
          tbody.innerHTML = '<tr><td colspan="6" class="admin-empty">No invitations yet.</td></tr>';
          return;
        }

        data.data.forEach((inv) => {
          const tr = document.createElement("tr");
          tr.innerHTML = `
            <td>${escapeHtml(inv.guest_name)}</td>
            <td><code>${escapeHtml(inv.invitation_id)}</code></td>
            <td>${escapeHtml(String(inv.max_guests))}</td>
            <td>${statusBadge(inv.rsvp_status)}</td>
            <td></td>
            <td class="admin-actions"></td>
          `;

          const qrBtn = document.createElement("button");
          qrBtn.type = "button";
          qrBtn.className = "admin-btn admin-btn-secondary admin-btn-sm";
          qrBtn.textContent = "View QR";
          qrBtn.dataset.action = "qr";
          qrBtn.dataset.id = inv.invitation_id;
          tr.children[4].appendChild(qrBtn);

          const editBtn = document.createElement("button");
          editBtn.type = "button";
          editBtn.className = "admin-btn admin-btn-secondary admin-btn-sm";
          editBtn.textContent = "Edit";
          editBtn.dataset.action = "edit";
          editBtn.dataset.id = inv.invitation_id;

          const deleteBtn = document.createElement("button");
          deleteBtn.type = "button";
          deleteBtn.className = "admin-btn admin-btn-danger admin-btn-sm";
          deleteBtn.textContent = "Delete";
          deleteBtn.dataset.action = "delete";
          deleteBtn.dataset.id = inv.invitation_id;

          tr.children[5].appendChild(editBtn);
          tr.children[5].appendChild(deleteBtn);
          tbody.appendChild(tr);
        });
      })
      .catch(() => {
        $("invitations-tbody").innerHTML =
          '<tr><td colspan="6" class="admin-empty">Failed to load invitations.</td></tr>';
      });
  };

  window.loadResponses = function loadResponses() {
    AdminAuth.apiCall("api.php?action=get-rsvp-summary")
      .then((response) => response.json())
      .then((data) => {
        const tbody = $("responses-tbody");
        tbody.innerHTML = "";

        if (!data.success) {
          tbody.innerHTML = '<tr><td colspan="6" class="admin-empty">Failed to load responses.</td></tr>';
          return;
        }

        const responses = (data.data || []).filter((item) => item.attending !== null);
        if (!responses.length) {
          tbody.innerHTML = '<tr><td colspan="6" class="admin-empty">No responses yet.</td></tr>';
          return;
        }

        responses.forEach((item) => {
          let guestNamesHtml;
          if (Array.isArray(item.attendees) && item.attendees.length > 0) {
            guestNamesHtml = item.attendees
              .filter((a) => {
                if (!a || typeof a !== "object") return false;
                if (Object.prototype.hasOwnProperty.call(a, "attending")) {
                  return !!a.attending && a.attending !== "false" && a.attending !== "0";
                }
                if (Object.prototype.hasOwnProperty.call(a, "going")) {
                  return !!a.going;
                }
                return true;
              })
              .map((a) => escapeHtml(a.attendee_name || a.name || ""))
              .filter(Boolean)
              .map((name) => `<div>${name}</div>`)
              .join("");
          } else if (item.special_notes) {
            guestNamesHtml = escapeHtml(item.special_notes)
              .split(/\r\n|\r|\n/)
              .map((n) => n.trim())
              .filter(Boolean)
              .map((name) => `<div>${name}</div>`)
              .join("");
          } else {
            guestNamesHtml = '<div>—</div>';
          }

          const submittedAt = item.submitted_at
            ? new Date(item.submitted_at).toLocaleString()
            : "—";
          const tr = document.createElement("tr");
          tr.innerHTML = `
            <td>${escapeHtml(item.guest_name)}</td>
            <td>${attendanceBadge(item.attending)}</td>
            <td>${escapeHtml(String(item.attendee_count || 0))}</td>
            <td>${escapeHtml(submittedAt)}</td>
            <td>${guestNamesHtml}</td>
            <td></td>
          `;
          const detailsBtn = document.createElement("button");
          detailsBtn.type = "button";
          detailsBtn.className = "admin-btn admin-btn-secondary admin-btn-sm";
          detailsBtn.textContent = "View";
          detailsBtn.dataset.action = "details";
          detailsBtn.dataset.id = item.invitation_id;
          tr.lastElementChild.appendChild(detailsBtn);
          tbody.appendChild(tr);
        });
      })
      .catch(() => {
        $("responses-tbody").innerHTML =
          '<tr><td colspan="6" class="admin-empty">Failed to load responses.</td></tr>';
      });
  };

  window.showQRCode = function showQRCode(invitationId) {
    AdminAuth.apiCall(`api.php?action=generate-qr&invitation_id=${encodeURIComponent(invitationId)}`)
      .then((response) => response.json())
      .then((data) => {
        if (data.success && data.data.qr_image_path) {
          $("qr-modal-id").textContent = invitationId;
          $("qr-modal-image").src = data.data.qr_image_path;
          openModal("qr-modal");
        } else {
          showFlash("invitations-message", data.error || "QR code not available.", "error");
        }
      })
      .catch((error) => {
        showFlash("invitations-message", error.message || "Failed to load QR code.", "error");
      });
  };

  window.showDetails = async function showDetails(invitationId) {
    try {
      const [invRes, respRes] = await Promise.all([
        AdminAuth.apiCall("api.php?action=get-invitations").then((r) => r.json()),
        AdminAuth.apiCall("api.php?action=get-rsvp-summary").then((r) => r.json()),
      ]);

      const invitation = (invRes.data || []).find((inv) => inv.invitation_id === invitationId);
      const response = (respRes.data || []).find((r) => r.invitation_id === invitationId);

      if (!invitation) {
        showFlash("dashboard-message", "Invitation not found.", "error");
        return;
      }

      const guestNames = extractGuestNamesFromResponse(response);
      const invitedNames = Array.isArray(invitation.invited_guest_names)
        ? invitation.invited_guest_names
        : [];

      $("details-modal-content").innerHTML = `
        <dl class="admin-detail-list">
          <div><dt>Invitation ID</dt><dd><code>${escapeHtml(invitation.invitation_id)}</code></dd></div>
          <div><dt>Primary guest</dt><dd>${escapeHtml(invitation.guest_name || "—")}</dd></div>
          <div><dt>Max guests</dt><dd>${escapeHtml(String(invitation.max_guests || "—"))}</dd></div>
          <div><dt>Email</dt><dd>${escapeHtml(invitation.email || "—")}</dd></div>
          <div><dt>RSVP status</dt><dd>${escapeHtml(invitation.rsvp_status || "pending")}</dd></div>
          <div><dt>Invited names</dt><dd>${invitedNames.length ? invitedNames.map(escapeHtml).join(", ") : "—"}</dd></div>
          <div><dt>Attending</dt><dd>${escapeHtml(response?.attending || "No response yet")}</dd></div>
          <div><dt>Guest count</dt><dd>${escapeHtml(String(response?.attendee_count ?? "—"))}</dd></div>
          <div><dt>Submitted</dt><dd>${response?.submitted_at ? escapeHtml(new Date(response.submitted_at).toLocaleString()) : "—"}</dd></div>
          <div><dt>Submitted names</dt><dd>${guestNames.length ? guestNames.map(escapeHtml).join(", ") : "—"}</dd></div>
          <div><dt>Notes</dt><dd>${escapeHtml(response?.special_notes || "—")}</dd></div>
        </dl>
      `;
      openModal("details-modal");
    } catch (error) {
      showFlash("dashboard-message", error.message || "Could not load details.", "error");
    }
  };

  window.openEditInvitation = async function openEditInvitation(invitationId) {
    try {
      const response = await AdminAuth.apiCall("api.php?action=get-invitations");
      const data = await response.json();
      if (!data.success) {
        showFlash("invitations-message", data.error || "Unable to load invitations.", "error");
        return;
      }

      const invitation = (data.data || []).find((inv) => inv.invitation_id === invitationId);
      if (!invitation) {
        showFlash("invitations-message", "Invitation not found.", "error");
        return;
      }

      $("edit-invitation-id").value = invitation.invitation_id;
      $("edit-guest-name").value = invitation.guest_name || "";
      $("edit-max-guests").value = invitation.max_guests || 1;
      $("edit-email").value = invitation.email || "";
      $("edit-password").value = "";
      $("edit-invited-names").value = Array.isArray(invitation.invited_guest_names)
        ? invitation.invited_guest_names.join("\n")
        : "";
      openModal("edit-modal");
    } catch (error) {
      showFlash("invitations-message", error.message || "Failed to open editor.", "error");
    }
  };

  window.saveEditInvitation = function saveEditInvitation(event) {
    event.preventDefault();
    hideFlash("invitations-message");

    const invitationId = $("edit-invitation-id").value;
    const payload = {
      invitation_id: invitationId,
      guest_name: $("edit-guest-name").value.trim(),
      max_guests: parseInt($("edit-max-guests").value, 10),
      email: $("edit-email").value.trim(),
      invited_guest_names: String($("edit-invited-names").value || "")
        .split(/\r?\n/)
        .map((n) => n.trim())
        .filter(Boolean),
    };

    const password = $("edit-password").value.trim();
    if (password) payload.password = password;

    AdminAuth.apiCall("api.php?action=update-invitation", {
      method: "POST",
      body: JSON.stringify(payload),
    })
      .then((r) => r.json())
      .then((data) => {
        if (!data.success) {
          showFlash("invitations-message", data.error || "Update failed.", "error");
          return;
        }
        closeModal("edit-modal");
        showFlash("invitations-message", "Invitation updated.", "success");
        loadInvitations();
        loadStats();
      })
      .catch((error) => {
        showFlash("invitations-message", error.message || "Update failed.", "error");
      });
  };

  window.deleteInvitation = async function deleteInvitation(invitationId) {
    if (!confirm(`Delete invitation ${invitationId}? This removes RSVP and QR records.`)) return;

    try {
      const response = await AdminAuth.apiCall("api.php?action=delete-invitation", {
        method: "POST",
        body: JSON.stringify({ invitation_id: invitationId }),
      });
      const data = await response.json();
      if (!data.success) {
        showFlash("invitations-message", data.error || "Delete failed.", "error");
        return;
      }
      showFlash("invitations-message", "Invitation deleted.", "success");
      loadInvitations();
      loadStats();
    } catch (error) {
      showFlash("invitations-message", error.message || "Delete failed.", "error");
    }
  };

  window.exportToGoogleSheets = function exportToGoogleSheets(type, btn) {
    const button = btn || document.activeElement;
    const originalText = button.textContent;
    button.textContent = "Exporting…";
    button.disabled = true;
    hideFlash("export-message");

    const action =
      type === "invitations"
        ? "export-to-google-sheets-invitations"
        : "export-to-google-sheets-responses";

    adminApiJson(`api.php?action=${action}`)
      .then((data) => {
        showFlash(
          "export-message",
          data.message || "Exported to Google Sheets.",
          "success"
        );
        if (data.sheetUrl) window.open(data.sheetUrl, "_blank", "noopener");
      })
      .catch((error) => {
        showFlash("export-message", error.message || "Export failed.", "error");
      })
      .finally(() => {
        button.textContent = originalText;
        button.disabled = false;
      });
  };

  function flattenCsvValue(value) {
    if (value == null) return "";
    if (Array.isArray(value)) {
      return value
        .map((item) => {
          if (item && typeof item === "object") {
            return item.attendee_name || item.name || "";
          }
          return String(item);
        })
        .filter(Boolean)
        .join("; ");
    }
    if (typeof value === "object") return JSON.stringify(value);
    return String(value);
  }

  function flattenCsvRow(row) {
    const flat = {};
    Object.keys(row).forEach((key) => {
      flat[key] = flattenCsvValue(row[key]);
    });
    return flat;
  }

  function downloadCSV(data, filename) {
    if (!Array.isArray(data) || data.length === 0) {
      showFlash("export-message", "No data to export.", "info");
      return;
    }

    const flatData = data.map(flattenCsvRow);
    const headers = [];
    flatData.forEach((row) => {
      Object.keys(row).forEach((key) => {
        if (!headers.includes(key)) headers.push(key);
      });
    });

    let csv = `${headers.join(",")}\n`;
    flatData.forEach((row) => {
      csv += `${headers
        .map((header) => {
          const str = row[header] == null ? "" : String(row[header]);
          return str.includes(",") || str.includes('"') || str.includes("\n")
            ? `"${str.replace(/"/g, '""')}"`
            : str;
        })
        .join(",")}\n`;
    });

    const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `${filename}.csv`;
    document.body.appendChild(a);
    a.click();
    window.URL.revokeObjectURL(url);
    a.remove();
    showFlash("export-message", `Downloaded ${filename}.csv`, "success");
  }

  async function adminApiJson(url, options) {
    const response = await AdminAuth.apiCall(url, options);
    let data = null;
    try {
      data = await response.json();
    } catch (error) {
      throw new Error(`Server returned ${response.status} (invalid JSON).`);
    }
    if (!response.ok || data.success === false) {
      throw new Error(data.error || data.message || `Request failed (${response.status}).`);
    }
    return data;
  }

  window.exportCSV = function exportCSV() {
    hideFlash("export-message");
    adminApiJson("api.php?action=export-rsvp")
      .then((data) => downloadCSV(data.data, "wedding-rsvp-full"))
      .catch((error) => showFlash("export-message", error.message, "error"));
  };

  window.exportSummary = function exportSummary() {
    hideFlash("export-message");
    adminApiJson("api.php?action=export-responses")
      .then((data) => downloadCSV(data.data, "wedding-rsvp-responses"))
      .catch((error) => showFlash("export-message", error.message, "error"));
  };

  window.exportInvitationsCSV = function exportInvitationsCSV() {
    hideFlash("export-message");
    adminApiJson("api.php?action=export-invitations")
      .then((data) => downloadCSV(data.data, "wedding-invitations"))
      .catch((error) => showFlash("export-message", error.message, "error"));
  };

  window.loadTableAssignments = function loadTableAssignments() {
    Promise.all([
      AdminAuth.apiCall("api.php?action=get-invitations").then((r) => r.json()),
      AdminAuth.apiCall("api.php?action=get-rsvp-summary").then((r) => r.json()),
      AdminAuth.apiCall("api.php?action=get-table-assignments").then((r) => r.json()),
    ])
      .then(([invitationsRes, responsesRes, assignmentsRes]) => {
        if (!invitationsRes.success || !responsesRes.success) return;

        globalInvitations = invitationsRes.data || [];
        globalResponses = responsesRes.data || [];
        globalAssignments = assignmentsRes.success ? assignmentsRes.data || [] : [];

        populateTableAssignmentsTable(globalInvitations, globalResponses, globalAssignments);
        populateTableOverview(globalAssignments, globalResponses);
        updateTablePlanningSummary(globalInvitations, globalResponses, globalAssignments);

        const totalCapacity = parseInt($("total-capacity").value, 10) || 200;
        const seatsPerTable = parseInt($("seats-per-table").value, 10) || 10;
        const tablesNeeded = Math.max(1, Math.ceil(totalCapacity / seatsPerTable));
        populateTableNumberSelect(tablesNeeded);
        attachTableSelectionListener();
      })
      .catch(() => {
        showFlash("dashboard-message", "Failed to load table assignments.", "error");
      });
  };

  function companionNamesFromResponse(response) {
    let names = [];
    if (response && Array.isArray(response.attendees) && response.attendees.length > 0) {
      names = response.attendees
        .filter((a) => {
          if (!a || typeof a !== "object") return false;
          if (Object.prototype.hasOwnProperty.call(a, "attending")) {
            return !!a.attending && a.attending !== "false" && a.attending !== "0";
          }
          if (Object.prototype.hasOwnProperty.call(a, "going")) {
            return !!a.going;
          }
          return true;
        })
        .map((a) => a.attendee_name || a.name || "")
        .map((n) => n.trim())
        .filter(Boolean);
    } else if (response && response.special_notes) {
      names = response.special_notes
        .split(/\r\n|\r|\n|,/)
        .map((n) => n.trim())
        .filter(Boolean);
    }
    return names;
  }

  function buildAssignmentRow(invitation, response, assignment) {
    const tr = document.createElement("tr");
    const companions = companionNamesFromResponse(response);
    tr.innerHTML = `
      <td>${escapeHtml(invitation.guest_name)}</td>
      <td>${companions.length ? companions.map((n) => `<div>${escapeHtml(n)}</div>`).join("") : "None"}</td>
      <td>${assignment ? `Table ${escapeHtml(String(assignment.table_number))}` : "Not assigned"}</td>
      <td></td>
    `;
    const assignBtn = tr.lastElementChild.appendChild(document.createElement("button"));
    assignBtn.type = "button";
    assignBtn.className = "admin-btn admin-btn-secondary admin-btn-sm";
    assignBtn.textContent = assignment ? "Change" : "Assign";
    assignBtn.dataset.action = "assign-table";
    assignBtn.dataset.id = invitation.invitation_id;
    assignBtn.addEventListener("click", () => {
      openAssignTableModal(invitation, response, assignment);
    });
    return tr;
  }

  function populateTableAssignmentsTable(invitations, responses, assignments) {
    const tbody = document.querySelector("#table-assignments-table tbody");
    tbody.innerHTML = "";

    const assignmentMap = {};
    assignments.forEach((a) => {
      assignmentMap[a.invitation_id] = a;
    });

    const respondedInvitations = invitations.filter((inv) =>
      responses.some((resp) => resp.invitation_id === inv.invitation_id)
    );

    if (!respondedInvitations.length) {
      tbody.innerHTML = '<tr><td colspan="4" class="admin-empty">No RSVP responses to assign yet.</td></tr>';
      return;
    }

    respondedInvitations.forEach((invitation) => {
      const response = responses.find((r) => r.invitation_id === invitation.invitation_id);
      tbody.appendChild(
        buildAssignmentRow(invitation, response, assignmentMap[invitation.invitation_id])
      );
    });
  }

  function populateTableNumberSelect(tablesNeeded) {
    const select = $("table-number-select");
    const current = select.value;
    select.innerHTML = '<option value="">All tables</option>';
    for (let i = 1; i <= tablesNeeded; i += 1) {
      const option = document.createElement("option");
      option.value = String(i);
      option.textContent = `Table ${i}`;
      select.appendChild(option);
    }
    select.value = current;
  }

  function attachTableSelectionListener() {
    const select = $("table-number-select");
    if (!select || tableSelectBound) return;
    tableSelectBound = true;
    select.addEventListener("change", () => {
      const selectedTable = select.value;
      if (!selectedTable) {
        populateTableAssignmentsTable(globalInvitations, globalResponses, globalAssignments);
        return;
      }
      filterAndDisplayTable(parseInt(selectedTable, 10));
    });
  }

  function filterAndDisplayTable(tableNumber) {
    const filteredAssignments = globalAssignments.filter(
      (a) => parseInt(a.table_number, 10) === tableNumber
    );
    const filteredInvitationIds = new Set(filteredAssignments.map((a) => a.invitation_id));
    const filteredInvitations = globalInvitations.filter((inv) =>
      filteredInvitationIds.has(inv.invitation_id)
    );
    const filteredResponses = globalResponses.filter((resp) =>
      filteredInvitationIds.has(resp.invitation_id)
    );

    const tbody = document.querySelector("#table-assignments-table tbody");
    tbody.innerHTML = "";

    if (!filteredInvitations.length) {
      tbody.innerHTML = `<tr><td colspan="4" class="admin-empty">No guests assigned to Table ${tableNumber}.</td></tr>`;
      return;
    }

    const assignmentMap = {};
    filteredAssignments.forEach((a) => {
      assignmentMap[a.invitation_id] = a;
    });

    filteredInvitations.forEach((invitation) => {
      const response = filteredResponses.find((r) => r.invitation_id === invitation.invitation_id);
      tbody.appendChild(
        buildAssignmentRow(invitation, response, assignmentMap[invitation.invitation_id])
      );
    });
  }

  function populateTableOverview(assignments, responses) {
    const overviewDiv = $("table-overview");
    overviewDiv.innerHTML = "";

    const responseMap = {};
    responses.forEach((r) => {
      responseMap[r.invitation_id] = r;
    });

    const tableGroups = {};
    assignments.forEach((assignment) => {
      if (!tableGroups[assignment.table_number]) tableGroups[assignment.table_number] = [];
      tableGroups[assignment.table_number].push(assignment);
    });

    if (!Object.keys(tableGroups).length) {
      overviewDiv.innerHTML = '<p class="admin-empty">No table assignments yet.</p>';
      return;
    }

    const grid = document.createElement("div");
    grid.className = "admin-table-overview-grid";

    Object.keys(tableGroups)
      .sort((a, b) => parseInt(a, 10) - parseInt(b, 10))
      .forEach((tableNum) => {
        const tableAssignments = tableGroups[tableNum];
        const totalGuests = tableAssignments.reduce((sum, a) => {
          const response = responseMap[a.invitation_id];
          const count = response && response.attendee_count ? parseInt(response.attendee_count, 10) : 1;
          return sum + Math.max(1, count);
        }, 0);

        const listItems = tableAssignments
          .map((a) => {
            const rows = [escapeHtml(a.guest_name)];
            const response = responseMap[a.invitation_id];
            if (response && Array.isArray(response.attendees)) {
              response.attendees.forEach((att) => {
                if (!att || typeof att !== "object") return;
                if (Object.prototype.hasOwnProperty.call(att, "attending") && !att.attending) return;
                if (Object.prototype.hasOwnProperty.call(att, "going") && !att.going) return;
                const name = att.attendee_name || att.name || "";
                if (name) rows.push(escapeHtml(name));
              });
            }
            return `<li>${rows.map((n) => `<div>${n}</div>`).join("")}</li>`;
          })
          .join("");

        const card = document.createElement("div");
        card.className = "admin-card admin-table-overview-card";
        card.innerHTML = `
          <h3>Table ${escapeHtml(tableNum)}</h3>
          <p><strong>${totalGuests}</strong> guest(s)</p>
          <ul>${listItems}</ul>
        `;
        grid.appendChild(card);
      });

    overviewDiv.appendChild(grid);
  }

  window.updateTableCalculations = function updateTableCalculations() {
    const totalCapacity = parseInt($("total-capacity").value, 10) || 200;
    const seatsPerTable = parseInt($("seats-per-table").value, 10) || 10;
    $("tables-needed").textContent = Math.ceil(totalCapacity / seatsPerTable);

    if (window.currentTableData) {
      updateTablePlanningSummary(
        window.currentTableData.invitations,
        window.currentTableData.responses,
        window.currentTableData.assignments
      );
    }

    const select = $("table-number-select");
    if (select) {
      populateTableNumberSelect(Math.max(1, Math.ceil(totalCapacity / seatsPerTable)));
    }
  };

  function updateTablePlanningSummary(invitations, responses, assignments) {
    const totalCapacity = parseInt($("total-capacity").value, 10) || 200;
    const seatsPerTable = parseInt($("seats-per-table").value, 10) || 10;
    const tablesNeeded = Math.ceil(totalCapacity / seatsPerTable);
    const assignedTableNumbers = new Set(assignments.map((a) => a.table_number));
    const tablesAssigned = assignedTableNumbers.size;

    $("tables-needed").textContent = tablesNeeded;
    $("tables-assigned").textContent = tablesAssigned;

    const confirmedGuests = responses
      .filter((r) => r.attending === "yes")
      .reduce((total, r) => total + (parseInt(r.attendee_count, 10) || 0), 0);

    const assignedInvitationIds = new Set(assignments.map((a) => a.invitation_id));
    const unassignedGuests = responses
      .filter((r) => r.attending === "yes" && !assignedInvitationIds.has(r.invitation_id))
      .reduce((total, r) => total + (parseInt(r.attendee_count, 10) || 0), 0);

    const yesCount = responses.filter((r) => r.attending === "yes").length;
    const coverage =
      yesCount > 0 ? Math.round((assignedInvitationIds.size / yesCount) * 100) : 0;

    $("table-planning-summary").innerHTML = `
      <div class="admin-planning-grid">
        <div>
          <strong>Total capacity</strong><br>${totalCapacity} guests<br>
          <strong>Seats per table</strong><br>${seatsPerTable}<br>
          <strong>Tables required</strong><br>${tablesNeeded}
        </div>
        <div>
          <strong>Confirmed guests</strong><br>${confirmedGuests}<br>
          <strong>Tables in use</strong><br>${tablesAssigned}<br>
          <strong>Unassigned guests</strong><br>${unassignedGuests}
        </div>
        <div>
          <strong>Status</strong><br>${tablesAssigned >= tablesNeeded ? "Complete" : `${tablesNeeded - tablesAssigned} table(s) still open`}<br>
          <strong>Coverage</strong><br>${coverage}% of confirmed parties assigned
        </div>
      </div>
    `;

    window.currentTableData = { invitations, responses, assignments };
  }

  window.filterTableOverview = function filterTableOverview() {
    const query = ($("table-search").value || "").trim().toLowerCase();
    if (!window.currentTableData) return;

    const { responses, assignments } = window.currentTableData;
    if (!query) {
      populateTableOverview(assignments, responses);
      $("table-overview-search-result").textContent = "";
      return;
    }

    const matchedAssignments = assignments.filter((assignment) => {
      const guestName = (assignment.guest_name || "").toLowerCase();
      if (guestName.includes(query)) return true;
      const response = responses.find((r) => r.invitation_id === assignment.invitation_id);
      if (response && Array.isArray(response.attendees)) {
        return response.attendees.some((guest) =>
          String(guest.attendee_name || guest.name || "")
            .toLowerCase()
            .includes(query)
        );
      }
      return false;
    });

    populateTableOverview(matchedAssignments, responses);
    $("table-overview-search-result").textContent = matchedAssignments.length
      ? `${matchedAssignments.length} matching assignment(s).`
      : "No matching guests found.";
  };

  window.openAssignTableModal = function openAssignTableModal(invitation, response, currentAssignment) {
    $("assign-invitation-id").value = invitation.invitation_id;
    $("assign-guest-label").textContent = invitation.guest_name;
    const companions = companionNamesFromResponse(response);
    $("assign-companions-label").textContent = companions.length ? companions.join(", ") : "None";
    $("assign-table-number").value = currentAssignment
      ? currentAssignment.table_number
      : $("table-number-select").value || "";
    openModal("assign-table-modal");
  };

  window.saveTableAssignment = function saveTableAssignment(event) {
    event.preventDefault();
    const invitationId = $("assign-invitation-id").value;
    const tableNumber = parseInt($("assign-table-number").value, 10);
    const submitBtn = $("assign-table-submit");

    if (!Number.isInteger(tableNumber) || tableNumber < 1) {
      showFlash("dashboard-message", "Enter a valid table number.", "error");
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = "Saving…";

    AdminAuth.apiCall("api.php?action=assign-table", {
      method: "POST",
      body: JSON.stringify({ invitation_id: invitationId, table_number: tableNumber }),
    })
      .then((response) => response.json())
      .then((data) => {
        submitBtn.disabled = false;
        submitBtn.textContent = "Save assignment";
        if (data.success) {
          closeModal("assign-table-modal");
          loadTableAssignments();
          showFlash("dashboard-message", "Table assignment saved.", "success");
        } else {
          showFlash("dashboard-message", data.message || data.error || "Save failed.", "error");
        }
      })
      .catch((error) => {
        submitBtn.disabled = false;
        submitBtn.textContent = "Save assignment";
        showFlash("dashboard-message", error.message || "Save failed.", "error");
      });
  };

  window.closeModal = closeModal;

  function bindDelegatedActions() {
    $("invitations-tbody").addEventListener("click", (event) => {
      const btn = event.target.closest("[data-action]");
      if (!btn) return;
      const id = btn.dataset.id;
      const action = btn.dataset.action;
      if (action === "qr") showQRCode(id);
      else if (action === "edit") openEditInvitation(id);
      else if (action === "delete") deleteInvitation(id);
    });

    $("responses-tbody").addEventListener("click", (event) => {
      const btn = event.target.closest('[data-action="details"]');
      if (btn) showDetails(btn.dataset.id);
    });

    document.querySelectorAll("[data-close-modal]").forEach((btn) => {
      btn.addEventListener("click", () => closeModal(btn.dataset.closeModal || ""));
    });

    document.querySelectorAll(".admin-modal").forEach((modal) => {
      modal.addEventListener("click", (event) => {
        if (event.target === modal) closeModal(modal.id);
      });
    });

    document.querySelectorAll(".admin-nav-btn").forEach((btn) => {
      btn.addEventListener("click", (event) => {
        switchTab(btn.dataset.tab, event);
      });
    });
  }

  window.initAdminDashboard = function initAdminDashboard() {
    bindDelegatedActions();
    const sheetBtn = document.getElementById("open-google-sheet-btn");
    if (sheetBtn) {
      sheetBtn.addEventListener("click", () => {
        window.open(
          "https://docs.google.com/spreadsheets/d/1Y0447zO9KI2G7FKLTbH-qwTaghTVXTlB4ztapHr3mtY/edit",
          "_blank",
          "noopener"
        );
      });
    }
    switchTab("dashboard");
  };
})();
