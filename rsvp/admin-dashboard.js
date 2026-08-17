/**
 * Wedding RSVP admin dashboard
 */
(function () {
  "use strict";

  const TOTAL_CAPACITY = 200;
  const INVITATIONS_PER_PAGE = 5;
  let globalInvitations = [];
  let globalResponses = [];
  let globalAssignments = [];
  let tableSelectBound = false;
  let allInvitations = [];
  let currentInvitationsPage = 1;
  const DASHBOARD_PER_PAGE = 5;
  let unusedSlotsRows = [];
  let qrGuestListRows = [];
  let currentUnusedPage = 1;
  let currentQrGuestPage = 1;
  let invitationsSearchTerm = "";
  let filteredInvitations = [];
  let allResponses = [];
  let filteredResponses = [];
  let responsesSearchTerm = "";
  let editRsvpCurrentResponse = null;

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

  function paginateRows(rows, page, perPage) {
    const totalPages = Math.max(1, Math.ceil(rows.length / perPage));
    const safePage = Math.min(Math.max(1, page), totalPages);
    const start = (safePage - 1) * perPage;
    return {
      rows: rows.slice(start, start + perPage),
      totalPages,
      currentPage: safePage,
    };
  }

  function updatePaginationControls(containerId, pageInfoId, prevId, nextId, currentPage, totalPages) {
    const info = $(pageInfoId);
    if (info) info.textContent = `Page ${currentPage} of ${totalPages}`;
    const prevBtn = $(prevId);
    const nextBtn = $(nextId);
    if (prevBtn) prevBtn.disabled = currentPage <= 1;
    if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
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
    else if (tabName === "photos") loadAdminPhotos();
    else if (tabName === "reception") loadReceptionVotes();
  };

  function renderReceptionVotes(data) {
    if ($("reception-votes-bride")) $("reception-votes-bride").textContent = String(data?.bride ?? 0);
    if ($("reception-votes-groom")) $("reception-votes-groom").textContent = String(data?.groom ?? 0);
    if ($("reception-votes-total")) $("reception-votes-total").textContent = String(data?.total ?? 0);
  }

  window.loadReceptionVotes = function loadReceptionVotes() {
    AdminAuth.apiCall("api.php?action=admin-get-reception-votes")
      .then((res) => res.json())
      .then((json) => {
        if (!json.success) throw new Error(json.error || "Could not load votes.");
        renderReceptionVotes(json.data);
      })
      .catch((err) => {
        renderReceptionVotes({ bride: "—", groom: "—", total: "—" });
        showFlash("reception-message", err.message || "Could not load votes.", "error");
      });
  };

  window.resetReceptionVotes = function resetReceptionVotes() {
    if (!confirm("Reset ALL Team Bride / Team Groom votes?\n\nUse this after testing. Every phone will be allowed to vote again.")) return;
    const typed = prompt('Type RESET to confirm clearing all votes:');
    if (typed !== "RESET") {
      showFlash("reception-message", "Vote reset cancelled.", "info");
      return;
    }

    AdminAuth.apiCall("api.php?action=admin-clear-reception-votes", {
      method: "POST",
      body: JSON.stringify({ confirm: "RESET" }),
    })
      .then((res) => res.json())
      .then((json) => {
        if (!json.success) throw new Error(json.error || "Could not reset votes.");
        renderReceptionVotes({ bride: 0, groom: 0, total: 0 });
        const deleted = json.data?.deleted ?? 0;
        showFlash("reception-message", `Reset complete. Cleared ${deleted} vote(s).`, "success");
      })
      .catch((err) => showFlash("reception-message", err.message || "Could not reset votes.", "error"));
  };

  function renderAdminPhotoCard(p) {
    const tag = p.uploaderName
      ? (p.tableNumber ? `${escapeHtml(p.uploaderName)} (T${p.tableNumber})` : escapeHtml(p.uploaderName))
      : (p.tableNumber ? `Table ${p.tableNumber}` : "Anonymous");
    const likes = p.likesCount || 0;
    const hiddenBadge = p.isApproved === false
      ? '<span class="admin-photo-badge admin-photo-badge--hidden">Hidden</span>'
      : "";
    return `
      <div class="admin-photo-card${p.isApproved === false ? " is-hidden-photo" : ""}">
        <img src="${escapeHtml(p.url)}" alt="" loading="lazy" />
        <div class="admin-photo-card__body">
          <div>
            <div class="admin-photo-card__title">${tag}</div>
            <div class="admin-photo-card__meta">❤️ ${likes} likes · ${escapeHtml(p.uploadedAt || "")} ${hiddenBadge}</div>
          </div>
          <div class="admin-photo-card__actions">
            ${p.isApproved !== false ? `<button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" onclick="hideAdminPhoto(${p.id})">Hide</button>` : ""}
            <button type="button" class="admin-btn admin-btn-secondary admin-btn-sm admin-btn-danger-text" onclick="deleteAdminPhoto(${p.id})">Delete</button>
          </div>
        </div>
      </div>
    `;
  }

  function updateAdminPhotosCount(count) {
    const counter = $("admin-photos-count");
    if (counter) counter.textContent = String(count);
  }

  window.loadAdminPhotos = function loadAdminPhotos() {
    const grid = document.getElementById("admin-photos-grid");
    if (!grid) return;
    grid.innerHTML = '<p class="admin-empty">Loading guest photos…</p>';

    AdminAuth.apiCall("api.php?action=admin-get-reception-photos")
      .then((res) => res.json())
      .then((json) => {
        if (json.success && Array.isArray(json.data)) {
          updateAdminPhotosCount(json.data.length);
          if (json.data.length === 0) {
            grid.innerHTML = '<p class="admin-empty">No guest POV photos uploaded yet.</p>';
            return;
          }
          grid.innerHTML = json.data.map(renderAdminPhotoCard).join("");
        } else {
          updateAdminPhotosCount(0);
          grid.innerHTML = '<p class="admin-empty">Could not load photos.</p>';
        }
      })
      .catch((err) => {
        updateAdminPhotosCount(0);
        grid.innerHTML = `<p class="admin-empty">Error loading photos: ${escapeHtml(err.message)}</p>`;
      });
  };

  window.hideAdminPhoto = function hideAdminPhoto(photoId) {
    if (!confirm("Hide this photo from the live guest gallery?")) return;

    AdminAuth.apiCall("api.php?action=admin-hide-reception-photo", {
      method: "POST",
      body: JSON.stringify({ photo_id: photoId }),
    })
      .then((res) => res.json())
      .then((json) => {
        if (json.success) {
          loadAdminPhotos();
          showFlash("photos-message", "Photo hidden from live gallery.", "success");
        } else {
          showFlash("photos-message", json.error || "Failed to hide photo.", "error");
        }
      })
      .catch((err) => showFlash("photos-message", err.message || "Failed to hide photo.", "error"));
  };

  window.deleteAdminPhoto = function deleteAdminPhoto(photoId) {
    if (!confirm("Are you sure you want to delete this guest photo?")) return;

    AdminAuth.apiCall("api.php?action=admin-delete-reception-photo", {
      method: "POST",
      body: JSON.stringify({ photo_id: photoId }),
    })
      .then((res) => res.json())
      .then((json) => {
        if (json.success) {
          loadAdminPhotos();
          showFlash("photos-message", "Photo deleted.", "success");
        } else {
          showFlash("photos-message", json.error || "Failed to delete photo.", "error");
        }
      })
      .catch((err) => showFlash("photos-message", err.message || "Failed to delete photo.", "error"));
  };

  window.downloadAllPhotosZip = function downloadAllPhotosZip() {
    hideFlash("photos-message");
    AdminAuth.apiCall("api.php?action=admin-download-photos-zip")
      .then((res) => {
        if (!res.ok) return res.json().then((j) => { throw new Error(j.error || "Download failed"); });
        return res.blob();
      })
      .then((blob) => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = `wedding-pov-photos-${Date.now()}.zip`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
        showFlash("photos-message", "ZIP export downloaded.", "success");
      })
      .catch((err) => showFlash("photos-message", err.message || "Failed to download ZIP.", "error"));
  };

  window.clearAllAdminPhotos = function clearAllAdminPhotos() {
    const confirmed = confirm(
      "Clear ALL guest POV photos?\n\nThis permanently deletes every uploaded photo and file. Use this for testing only."
    );
    if (!confirmed) return;

    const typed = prompt('Type DELETE to confirm clearing all photos:');
    if (typed !== "DELETE") {
      showFlash("photos-message", "Clear all cancelled.", "info");
      return;
    }

    AdminAuth.apiCall("api.php?action=admin-clear-all-reception-photos", {
      method: "POST",
      body: JSON.stringify({ confirm: "DELETE" }),
    })
      .then((res) => res.json())
      .then((json) => {
        if (json.success) {
          loadAdminPhotos();
          const count = json.data?.deletedRows ?? 0;
          showFlash("photos-message", `Cleared ${count} photo(s) from the gallery.`, "success");
        } else {
          showFlash("photos-message", json.error || "Failed to clear photos.", "error");
        }
      })
      .catch((err) => showFlash("photos-message", err.message || "Failed to clear photos.", "error"));
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

  function getInvitedNamesFromEditForm() {
    const guestName = ($("edit-guest-name")?.value || "").trim();
    const fromTextarea = String($("edit-invited-names")?.value || "")
      .split(/\r?\n/)
      .map((n) => n.trim())
      .filter(Boolean);
    return fromTextarea.length ? fromTextarea : guestName ? [guestName] : [];
  }

  function getGoingNamesFromResponse(response) {
    return new Set(extractGuestNamesFromResponse(response));
  }

  function syncEditRsvpAttendeesVisibility() {
    const status = $("edit-rsvp-status")?.value || "pending";
    const wrap = $("edit-rsvp-attendees-wrap");
    const pendingNote = $("edit-rsvp-pending-note");
    if (wrap) wrap.hidden = status !== "yes" && status !== "maybe";
    if (pendingNote) pendingNote.hidden = status !== "pending";
  }

  function renderEditRsvpAttendees(response) {
    const container = $("edit-rsvp-attendees");
    if (!container) return;

    container.innerHTML = "";
    const names = getInvitedNamesFromEditForm();
    if (!names.length) {
      container.innerHTML =
        '<p style="font-size:0.82rem;color:var(--admin-muted);margin:0">Add invited guest names above.</p>';
      return;
    }

    const goingNames = getGoingNamesFromResponse(response);
    const status = $("edit-rsvp-status")?.value || "pending";
    const defaultAllChecked = (status === "yes" || status === "maybe") && goingNames.size === 0 && !response;

    names.forEach((name) => {
      const label = document.createElement("label");
      label.className = "admin-checkbox";
      const input = document.createElement("input");
      input.type = "checkbox";
      input.value = name;
      input.checked = goingNames.has(name) || defaultAllChecked;
      const span = document.createElement("span");
      span.textContent = name;
      label.appendChild(input);
      label.appendChild(span);
      container.appendChild(label);
    });
  }

  function populateEditRsvpFields(invitation, response) {
    const statusEl = $("edit-rsvp-status");
    if (!statusEl) return;

    const attending = response?.attending || invitation?.rsvp_status || "pending";
    statusEl.value = attending === "pending" || !response ? "pending" : attending;
    renderEditRsvpAttendees(response);
    syncEditRsvpAttendeesVisibility();
  }

  function collectEditRsvpAttendees() {
    const container = $("edit-rsvp-attendees");
    if (!container) return [];
    return Array.from(container.querySelectorAll('input[type="checkbox"]')).map((cb) => ({
      name: cb.value,
      attending: cb.checked,
    }));
  }

  async function saveEditRsvpStatus(invitationId) {
    const status = $("edit-rsvp-status")?.value || "pending";

    if (status === "pending") {
      const response = await AdminAuth.apiCall("api.php?action=admin-clear-rsvp", {
        method: "POST",
        body: JSON.stringify({ invitation_id: invitationId }),
      });
      const data = await response.json();
      const errorText = String(data.error || "").toLowerCase();
      if (!data.success && !errorText.includes("no rsvp")) {
        throw new Error(data.error || "Failed to reset RSVP status.");
      }
      return data;
    }

    const payload = {
      invitation_id: invitationId,
      attending: status,
      attendees: status === "yes" || status === "maybe" ? collectEditRsvpAttendees() : [],
    };
    const response = await AdminAuth.apiCall("api.php?action=admin-update-rsvp", {
      method: "POST",
      body: JSON.stringify(payload),
    });
    const data = await response.json();
    if (!data.success) {
      throw new Error(data.error || "Failed to update RSVP.");
    }
    return data;
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

    unusedSlotsRows = unusedInvitations.map((inv) => {
      const confirmed = confirmationMap[inv.invitation_id] || 0;
      const maxGuests = parseInt(inv.max_guests, 10);
      const unusedSlots = maxGuests - confirmed;
      return { inv, confirmed, maxGuests, unusedSlots };
    });

    currentUnusedPage = 1;
    renderUnusedSlotsPage();
  }

  function renderUnusedSlotsPage() {
    const tbody = $("unused-slots-tbody");
    tbody.innerHTML = "";

    if (!unusedSlotsRows.length) {
      tbody.innerHTML =
        '<tr><td colspan="4" class="admin-empty">All invitations have confirmed their guest count.</td></tr>';
      updatePaginationControls("unused-slots-tbody", "unused-page-info", "unused-prev", "unused-next", 1, 1);
      return;
    }

    const { rows, totalPages, currentPage } = paginateRows(unusedSlotsRows, currentUnusedPage, DASHBOARD_PER_PAGE);
    currentUnusedPage = currentPage;

    rows.forEach((row) => {
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${escapeHtml(row.inv.guest_name)}</td>
        <td style="text-align:center">${row.maxGuests}</td>
        <td style="text-align:center">${row.confirmed}</td>
        <td style="text-align:center"><span class="admin-badge admin-badge-pending">${row.unusedSlots} open</span></td>
      `;
      tbody.appendChild(tr);
    });

    updatePaginationControls("unused-slots-tbody", "unused-page-info", "unused-prev", "unused-next", currentPage, totalPages);
  }

  window.unusedPrevPage = function unusedPrevPage() {
    if (currentUnusedPage > 1) {
      currentUnusedPage -= 1;
      renderUnusedSlotsPage();
    }
  };

  window.unusedNextPage = function unusedNextPage() {
    const { totalPages } = paginateRows(unusedSlotsRows, currentUnusedPage, DASHBOARD_PER_PAGE);
    if (currentUnusedPage < totalPages) {
      currentUnusedPage += 1;
      renderUnusedSlotsPage();
    }
  };

  function populateQrGuestListTable(invitations, responses) {
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

    qrGuestListRows = invitations.map((inv) => ({
      inv,
      response: responseMap[inv.invitation_id],
    }));

    currentQrGuestPage = 1;
    renderQrGuestListPage();
  }

  function renderQrGuestListPage() {
    const tbody = $("qr-guest-list-tbody");
    tbody.innerHTML = "";

    if (!qrGuestListRows.length) {
      tbody.innerHTML = '<tr><td colspan="3" class="admin-empty">No invitations yet.</td></tr>';
      updatePaginationControls("qr-guest-list-tbody", "qrlist-page-info", "qrlist-prev", "qrlist-next", 1, 1);
      return;
    }

    const { rows, totalPages, currentPage } = paginateRows(qrGuestListRows, currentQrGuestPage, DASHBOARD_PER_PAGE);
    currentQrGuestPage = currentPage;

    rows.forEach((row) => {
      const { inv, response } = row;
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

    updatePaginationControls("qr-guest-list-tbody", "qrlist-page-info", "qrlist-prev", "qrlist-next", currentPage, totalPages);
  }

  window.qrGuestPrevPage = function qrGuestPrevPage() {
    if (currentQrGuestPage > 1) {
      currentQrGuestPage -= 1;
      renderQrGuestListPage();
    }
  };

  window.qrGuestNextPage = function qrGuestNextPage() {
    const { totalPages } = paginateRows(qrGuestListRows, currentQrGuestPage, DASHBOARD_PER_PAGE);
    if (currentQrGuestPage < totalPages) {
      currentQrGuestPage += 1;
      renderQrGuestListPage();
    }
  };

  window.createInvitation = function createInvitation(event) {
    event.preventDefault();
    hideFlash("invitations-message");

    const guestName = $("guest-name").value.trim();
    const maxGuests = parseInt($("max-guests").value, 10);
    const passwordEl = $("invite-password");
    const password = passwordEl ? passwordEl.value.trim() : "";
    const email = $("invite-email").value.trim();
    const autoSend = !!$("auto-send-invite") && $("auto-send-invite").checked;
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
        if (!data.success) {
          throw new Error(data.error || "Failed to create invitation.");
        }
        const newInvitationId = data.data && data.data.invitation_id;
        if (autoSend && newInvitationId) {
          // Attempt to send the email, but don't fail the whole flow if it errors.
          return sendInvitation(newInvitationId, true)
            .then(() => ({ data, emailSent: true }))
            .catch((error) => ({ data, emailSent: false, emailError: error.message }));
        }
        return { data, emailSent: true };
      })
      .then(({ data, emailSent, emailError }) => {
        if (emailSent) {
          showFlash("invitations-message", "Invitation created and email sent.", "success");
        } else {
          showFlash(
            "invitations-message",
            "Invitation created, but the email could not be sent: " + (emailError || "unknown error"),
            "error"
          );
        }
        $("create-invitation-form").reset();
        $("max-guests").value = "1";
        if ($("auto-send-invite")) $("auto-send-invite").checked = false;
        loadInvitations();
        loadStats();
      })
      .catch((error) => {
        showFlash("invitations-message", error.message || "Failed to create invitation.", "error");
      });
  };

  window.sendInvitation = function sendInvitation(invitationId, silent) {
    return AdminAuth.apiCall("api.php?action=send-invitation", {
      method: "POST",
      body: JSON.stringify({ invitation_id: invitationId }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          if (!silent) {
            showFlash("invitations-message", data.message || "Invitation email sent.", "success");
          }
          return data;
        }
        throw new Error(data.error || "Failed to send invitation.");
      })
      .catch((error) => {
        if (!silent) {
          showFlash("invitations-message", error.message || "Failed to send invitation.", "error");
        }
        throw error;
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
        if (!data.success) {
          $("invitations-tbody").innerHTML =
            '<tr><td colspan="7" class="admin-empty">Failed to load invitations.</td></tr>';
          return;
        }

        allInvitations = data.data || [];
        currentInvitationsPage = 1;
        applyInvitationsSearchFromInput();
        renderInvitationsPage();
      })
      .catch(() => {
        $("invitations-tbody").innerHTML =
          '<tr><td colspan="7" class="admin-empty">Failed to load invitations.</td></tr>';
      });
  };

  function invitationMatchesSearch(inv, query) {
    const guestName = (inv.guest_name || "").toLowerCase();
    const invitationId = (inv.invitation_id || "").toLowerCase();
    if (guestName.includes(query) || invitationId.includes(query)) return true;

    const invitedNames = Array.isArray(inv.invited_guest_names) ? inv.invited_guest_names : [];
    return invitedNames.some((name) => String(name || "").toLowerCase().includes(query));
  }

  function getInvitationsToShow() {
    return invitationsSearchTerm ? filteredInvitations : allInvitations;
  }

  function applyInvitationsSearchFromInput() {
    const searchInput = $("invitations-search");
    invitationsSearchTerm = searchInput ? String(searchInput.value || "").trim().toLowerCase() : "";

    if (!invitationsSearchTerm) {
      filteredInvitations = allInvitations;
      return;
    }

    filteredInvitations = allInvitations.filter((inv) =>
      invitationMatchesSearch(inv, invitationsSearchTerm)
    );
  }

  window.filterInvitations = function filterInvitations(evt) {
    applyInvitationsSearchFromInput();
    currentInvitationsPage = 1;
    renderInvitationsPage();
  };

  function renderInvitationsPage() {
    const tbody = $("invitations-tbody");
    tbody.innerHTML = "";

    const invitationsToShow = getInvitationsToShow();
    const totalPages = Math.max(1, Math.ceil(invitationsToShow.length / INVITATIONS_PER_PAGE));
    if (currentInvitationsPage > totalPages) currentInvitationsPage = totalPages;

    const startIndex = (currentInvitationsPage - 1) * INVITATIONS_PER_PAGE;
    const pageInvitations = invitationsToShow.slice(startIndex, startIndex + INVITATIONS_PER_PAGE);

    if (!pageInvitations.length) {
      const emptyMsg = invitationsSearchTerm 
        ? `No invitations found matching "${escapeHtml(invitationsSearchTerm)}".`
        : "No invitations yet.";
      tbody.innerHTML = `<tr><td colspan="7" class="admin-empty">${emptyMsg}</td></tr>`;
    } else {
      pageInvitations.forEach((inv) => {
        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td>${escapeHtml(inv.guest_name)}</td>
          <td><code>${escapeHtml(inv.invitation_id)}</code></td>
          <td>${escapeHtml(String(inv.max_guests))}</td>
          <td>${statusBadge(inv.rsvp_status)}</td>
          <td></td>
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

        const downloadBtn = document.createElement("button");
        downloadBtn.type = "button";
        downloadBtn.className = "admin-btn admin-btn-secondary admin-btn-sm";
        downloadBtn.textContent = "Download";
        downloadBtn.title = "Download this invitation's QR code";
        downloadBtn.dataset.action = "download-qr";
        downloadBtn.dataset.id = inv.invitation_id;
        tr.children[5].appendChild(downloadBtn);

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

        tr.children[6].appendChild(editBtn);
        tr.children[6].appendChild(deleteBtn);
        tbody.appendChild(tr);
      });
    }

    const pageInfo = $("invitations-page-info");
    if (pageInfo) pageInfo.textContent = `Page ${currentInvitationsPage} of ${totalPages}`;

    const prevBtn = $("invitations-prev");
    const nextBtn = $("invitations-next");
    if (prevBtn) prevBtn.disabled = currentInvitationsPage <= 1;
    if (nextBtn) nextBtn.disabled = currentInvitationsPage >= totalPages;
  }

  window.invitationsPrevPage = function invitationsPrevPage() {
    if (currentInvitationsPage > 1) {
      currentInvitationsPage -= 1;
      renderInvitationsPage();
    }
  };

  window.invitationsNextPage = function invitationsNextPage() {
    const invitationsToShow = getInvitationsToShow();
    const totalPages = Math.max(1, Math.ceil(invitationsToShow.length / INVITATIONS_PER_PAGE));
    if (currentInvitationsPage < totalPages) {
      currentInvitationsPage += 1;
      renderInvitationsPage();
    }
  };

  window.downloadQRCode = function downloadQRCode(invitationId) {
    AdminAuth.apiCall(`api.php?action=generate-qr&invitation_id=${encodeURIComponent(invitationId)}`)
      .then((response) => response.json())
      .then((data) => {
        if (data.success && data.data.qr_image_path) {
          const imageUrl = data.data.qr_image_path;
          const a = document.createElement("a");
          a.href = imageUrl;
          a.download = `QR-${invitationId}.png`;
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
          showFlash("invitations-message", `QR code for ${invitationId} downloaded.`, "success");
        } else {
          showFlash("invitations-message", data.error || "QR code not available.", "error");
        }
      })
      .catch((error) => {
        showFlash("invitations-message", error.message || "Failed to download QR code.", "error");
      });
  };

  /* ───────────────────────────────────────────
     RECEPTION ACCESS QR (admin)
     ─────────────────────────────────────────── */
  window.generateReceptionQR = function generateReceptionQR(btn) {
    const originalText = btn ? btn.textContent : "";
    if (btn) { btn.textContent = "Generating…"; btn.disabled = true; }
    hideFlash("reception-message");

    AdminAuth.apiCall("api.php?action=generate-reception-qr")
      .then((response) => response.json())
      .then((data) => {
        if (btn) { btn.textContent = originalText; btn.disabled = false; }
        if (!data.success) {
          showFlash("reception-message", data.error || "Failed to generate reception QR.", "error");
          return;
        }

        const img = $("reception-qr-image");
        if (img) img.src = data.data.qr_image_path;
        const preview = $("reception-qr-preview");
        if (preview) preview.hidden = false;

        const downloadBtn = $("download-reception-qr-btn");
        if (downloadBtn) downloadBtn.disabled = false;

        showFlash("reception-message", "Reception QR code generated. Guests scan it to unlock the reception app.", "success");
      })
      .catch((error) => {
        if (btn) { btn.textContent = originalText; btn.disabled = false; }
        showFlash("reception-message", error.message || "Failed to generate reception QR.", "error");
      });
  };

  window.downloadReceptionQR = function downloadReceptionQR() {
    const img = $("reception-qr-image");
    if (!img || !img.src) {
      showFlash("reception-message", "Generate the reception QR first.", "info");
      return;
    }

    const a = document.createElement("a");
    a.href = img.src;
    a.download = "reception-access.png";
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    showFlash("reception-message", "Reception QR downloaded. Print or share it with guests.", "success");
  };

  window.loadResponses = function loadResponses() {
    AdminAuth.apiCall("api.php?action=get-rsvp-summary")
      .then((response) => response.json())
      .then((data) => {
        if (!data.success) {
          $("responses-tbody").innerHTML =
            '<tr><td colspan="6" class="admin-empty">Failed to load responses.</td></tr>';
          return;
        }

        allResponses = (data.data || []).filter((item) => item.attending !== null);
        applyResponsesSearchFromInput();
        renderResponsesTable();
      })
      .catch(() => {
        $("responses-tbody").innerHTML =
          '<tr><td colspan="6" class="admin-empty">Failed to load responses.</td></tr>';
      });
  };

  function responseAttendeeNames(item) {
    if (Array.isArray(item.attendees) && item.attendees.length > 0) {
      return item.attendees
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
        .map((a) => String(a.attendee_name || a.name || "").trim())
        .filter(Boolean);
    }

    if (item.special_notes) {
      return String(item.special_notes)
        .split(/\r\n|\r|\n|,/)
        .map((name) => name.trim())
        .filter(Boolean);
    }

    return [];
  }

  function responseMatchesSearch(item, query) {
    const guestName = (item.guest_name || "").toLowerCase();
    const invitationId = (item.invitation_id || "").toLowerCase();
    const attending = (item.attending || "").toLowerCase();
    const attendeeCount = String(item.attendee_count || "");

    if (guestName.includes(query) || invitationId.includes(query)) return true;
    if (attending.includes(query) || attendeeCount.includes(query)) return true;

    return responseAttendeeNames(item).some((name) => name.toLowerCase().includes(query));
  }

  function applyResponsesSearchFromInput() {
    const searchInput = $("responses-search");
    responsesSearchTerm = searchInput ? String(searchInput.value || "").trim().toLowerCase() : "";

    if (!responsesSearchTerm) {
      filteredResponses = allResponses;
      return;
    }

    filteredResponses = allResponses.filter((item) => responseMatchesSearch(item, responsesSearchTerm));
  }

  window.filterResponses = function filterResponses() {
    applyResponsesSearchFromInput();
    renderResponsesTable();
  };

  function renderGuestNamesCell(item) {
    const names = responseAttendeeNames(item);
    if (names.length) {
      return names.map((name) => `<div>${escapeHtml(name)}</div>`).join("");
    }
    return "<div>—</div>";
  }

  function renderResponsesTable() {
    const tbody = $("responses-tbody");
    tbody.innerHTML = "";

    const responsesToShow = responsesSearchTerm ? filteredResponses : allResponses;

    if (!responsesToShow.length) {
      const emptyMsg = responsesSearchTerm
        ? `No responses found matching "${escapeHtml(responsesSearchTerm)}".`
        : "No responses yet.";
      tbody.innerHTML = `<tr><td colspan="6" class="admin-empty">${emptyMsg}</td></tr>`;
      return;
    }

    responsesToShow.forEach((item) => {
      const submittedAt = item.submitted_at
        ? new Date(item.submitted_at).toLocaleString()
        : "—";
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${escapeHtml(item.guest_name)}</td>
        <td>${attendanceBadge(item.attending)}</td>
        <td>${escapeHtml(String(item.attendee_count || 0))}</td>
        <td>${escapeHtml(submittedAt)}</td>
        <td>${renderGuestNamesCell(item)}</td>
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
      const [invRes, respRes] = await Promise.all([
        AdminAuth.apiCall("api.php?action=get-invitations").then((r) => r.json()),
        AdminAuth.apiCall("api.php?action=get-rsvp-summary").then((r) => r.json()),
      ]);

      if (!invRes.success) {
        showFlash("invitations-message", invRes.error || "Unable to load invitations.", "error");
        return;
      }

      const invitation = (invRes.data || []).find((inv) => inv.invitation_id === invitationId);
      if (!invitation) {
        showFlash("invitations-message", "Invitation not found.", "error");
        return;
      }

      const response = (respRes.data || []).find((r) => r.invitation_id === invitationId) || null;
      editRsvpCurrentResponse = response;

      $("edit-invitation-id").value = invitation.invitation_id;
      $("edit-guest-name").value = invitation.guest_name || "";
      $("edit-max-guests").value = invitation.max_guests || 1;
      $("edit-email").value = invitation.email || "";
      const passwordEl = $("edit-password");
      if (passwordEl) passwordEl.value = "";
      $("edit-invited-names").value = Array.isArray(invitation.invited_guest_names)
        ? invitation.invited_guest_names.join("\n")
        : "";
      populateEditRsvpFields(invitation, response);
      openModal("edit-modal");
    } catch (error) {
      showFlash("invitations-message", error.message || "Failed to open editor.", "error");
    }
  };

  window.resetEditInvitationRsvp = async function resetEditInvitationRsvp() {
    const invitationId = $("edit-invitation-id")?.value;
    if (!invitationId) return;
    if (!confirm("Clear this RSVP so the guest can submit again?")) return;

    hideFlash("invitations-message");
    try {
      const response = await AdminAuth.apiCall("api.php?action=admin-clear-rsvp", {
        method: "POST",
        body: JSON.stringify({ invitation_id: invitationId }),
      });
      const data = await response.json();
      if (!data.success) {
        showFlash("invitations-message", data.error || "Reset failed.", "error");
        return;
      }

      editRsvpCurrentResponse = null;
      $("edit-rsvp-status").value = "pending";
      renderEditRsvpAttendees(null);
      syncEditRsvpAttendeesVisibility();
      showFlash("invitations-message", "RSVP cleared. Guest can respond again.", "success");
      loadInvitations();
      loadStats();
      loadResponses();
    } catch (error) {
      showFlash("invitations-message", error.message || "Reset failed.", "error");
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

    const passwordEl = $("edit-password");
    const password = passwordEl ? passwordEl.value.trim() : "";
    if (password) payload.password = password;

    AdminAuth.apiCall("api.php?action=update-invitation", {
      method: "POST",
      body: JSON.stringify(payload),
    })
      .then((r) => r.json())
      .then(async (data) => {
        if (!data.success) {
          showFlash("invitations-message", data.error || "Update failed.", "error");
          return;
        }

        try {
          await saveEditRsvpStatus(invitationId);
        } catch (rsvpError) {
          showFlash(
            "invitations-message",
            rsvpError.message || "Invitation saved, but RSVP update failed.",
            "error"
          );
          return;
        }

        closeModal("edit-modal");
        showFlash("invitations-message", "Invitation and RSVP updated.", "success");
        loadInvitations();
        loadStats();
        loadResponses();
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
        updateTablePlanningSummary(globalInvitations, globalResponses, globalAssignments);

        const tableSearchValue = ($("table-search")?.value || "").trim();
        if (tableSearchValue) {
          filterTableOverview();
        } else {
          populateTableOverview(globalAssignments, globalResponses);
        }

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

  function assignmentMatchesTableSearch(assignment, responses, query) {
    const guestName = (assignment.guest_name || "").toLowerCase();
    if (guestName.includes(query)) return true;

    const attendeeLists = [];
    if (Array.isArray(assignment.attendees)) attendeeLists.push(assignment.attendees);

    const response = responses.find((r) => r.invitation_id === assignment.invitation_id);
    if (response && Array.isArray(response.attendees)) attendeeLists.push(response.attendees);

    return attendeeLists.some((attendees) =>
      attendees.some((guest) =>
        String(guest?.attendee_name || guest?.name || "")
          .toLowerCase()
          .includes(query)
      )
    );
  }

  window.filterTableOverview = function filterTableOverview() {
    const searchInput = $("table-search");
    const query = (searchInput ? searchInput.value : "").trim().toLowerCase();
    const tableData = window.currentTableData || {
      responses: globalResponses,
      assignments: globalAssignments,
    };
    const { responses, assignments } = tableData;

    if (!query) {
      populateTableOverview(assignments, responses);
      $("table-overview-search-result").textContent = "";
      return;
    }

    const matchedAssignments = assignments.filter((assignment) =>
      assignmentMatchesTableSearch(assignment, responses, query)
    );

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
      else if (action === "download-qr") downloadQRCode(id);
      else if (action === "edit") openEditInvitation(id);
      else if (action === "delete") deleteInvitation(id);
    });

    const qrModalDownload = document.getElementById("qr-modal-download");
    if (qrModalDownload) {
      qrModalDownload.addEventListener("click", () => {
        const invitationId = $("qr-modal-id")?.textContent || "";
        if (invitationId) downloadQRCode(invitationId);
      });
    }

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
    const rsvpStatusEl = $("edit-rsvp-status");
    if (rsvpStatusEl) {
      rsvpStatusEl.addEventListener("change", () => {
        renderEditRsvpAttendees(editRsvpCurrentResponse);
        syncEditRsvpAttendeesVisibility();
      });
    }
    const invitedNamesEl = $("edit-invited-names");
    if (invitedNamesEl) {
      invitedNamesEl.addEventListener("input", () => renderEditRsvpAttendees(editRsvpCurrentResponse));
    }
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
