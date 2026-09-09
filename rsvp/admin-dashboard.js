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
  let globalSeatingGuests = [];
  let tableSelectBound = false;
  let tableGuestSearchBound = false;
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
  let currentResponsesPage = 1;
  const RESPONSES_PER_PAGE = 5;
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
    else if (tabName === "tables") {
      loadTableAssignments();
      loadFloorPlanEditor();
    }
    else if (tabName === "menu") loadMenuEditor();
    else if (tabName === "photos") loadAdminPhotos();
    else if (tabName === "reception") {
      loadReceptionVotes();
      loadReceptionMessages();
    }
  };

  function renderReceptionVotes(data) {
    if ($("reception-votes-bride")) $("reception-votes-bride").textContent = String(data?.bride ?? 0);
    if ($("reception-votes-groom")) $("reception-votes-groom").textContent = String(data?.groom ?? 0);
    if ($("reception-votes-total")) $("reception-votes-total").textContent = String(data?.total ?? 0);

    const tbody = $("reception-votes-tbody");
    if (!tbody) return;
    const votes = Array.isArray(data?.votes) ? data.votes : [];
    if (!votes.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="admin-empty">No votes yet.</td></tr>';
      return;
    }

    tbody.innerHTML = votes.map((vote) => {
      const id = Number(vote.id || 0);
      const votedAt = vote.votedAt ? new Date(vote.votedAt) : null;
      const dateLabel = votedAt && !Number.isNaN(votedAt.getTime()) ? votedAt.toLocaleString() : "—";
      return `
        <tr>
          <td>#${id}</td>
          <td><code>${escapeHtml(vote.voter || "anonymous")}</code></td>
          <td>
            <select class="admin-select" aria-label="Change vote #${id}" onchange="updateReceptionVote(${id}, this.value)">
              <option value="bride"${vote.team === "bride" ? " selected" : ""}>Team Bride</option>
              <option value="groom"${vote.team === "groom" ? " selected" : ""}>Team Groom</option>
            </select>
          </td>
          <td>${escapeHtml(dateLabel)}</td>
          <td><button type="button" class="admin-btn admin-btn-secondary admin-btn-sm admin-btn-danger-text" onclick="deleteReceptionVote(${id})">Delete</button></td>
        </tr>
      `;
    }).join("");
  }

  window.loadReceptionVotes = function loadReceptionVotes() {
    const tbody = $("reception-votes-tbody");
    if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="admin-empty">Loading votes…</td></tr>';
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

  window.updateReceptionVote = function updateReceptionVote(voteId, team) {
    AdminAuth.apiCall("api.php?action=admin-update-reception-vote", {
      method: "POST",
      body: JSON.stringify({ vote_id: voteId, team }),
    })
      .then((res) => res.json())
      .then((json) => {
        if (!json.success) throw new Error(json.error || "Could not update vote.");
        showFlash("reception-message", `Vote #${voteId} changed to Team ${team === "bride" ? "Bride" : "Groom"}.`, "success");
        loadReceptionVotes();
      })
      .catch((err) => {
        showFlash("reception-message", err.message || "Could not update vote.", "error");
        loadReceptionVotes();
      });
  };

  window.deleteReceptionVote = function deleteReceptionVote(voteId) {
    if (!confirm(`Delete vote #${voteId}? This phone will be allowed to vote again.`)) return;
    AdminAuth.apiCall("api.php?action=admin-delete-reception-vote", {
      method: "POST",
      body: JSON.stringify({ vote_id: voteId }),
    })
      .then((res) => res.json())
      .then((json) => {
        if (!json.success) throw new Error(json.error || "Could not delete vote.");
        showFlash("reception-message", `Vote #${voteId} deleted.`, "success");
        loadReceptionVotes();
      })
      .catch((err) => showFlash("reception-message", err.message || "Could not delete vote.", "error"));
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
        renderReceptionVotes({ bride: 0, groom: 0, total: 0, votes: [] });
        const deleted = json.data?.deleted ?? 0;
        showFlash("reception-message", `Reset complete. Cleared ${deleted} vote(s).`, "success");
      })
      .catch((err) => showFlash("reception-message", err.message || "Could not reset votes.", "error"));
  };

  function renderReceptionMessages(data) {
    const totalEl = $("reception-messages-total");
    const messages = Array.isArray(data?.messages) ? data.messages : [];
    if (totalEl) totalEl.textContent = data?.total ?? messages.length;

    const tbody = $("reception-messages-tbody");
    if (!tbody) return;
    if (!messages.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="admin-empty">No messages yet.</td></tr>';
      return;
    }

    tbody.innerHTML = messages.map((item) => {
      const id = Number(item.id || 0);
      const createdAt = item.createdAt ? new Date(item.createdAt) : null;
      const dateLabel = createdAt && !Number.isNaN(createdAt.getTime()) ? createdAt.toLocaleString() : "—";
      const preview = String(item.message || "").replace(/\s+/g, " ").trim();
      return `
        <tr>
          <td>#${id}</td>
          <td>${escapeHtml(item.guestName || "—")}</td>
          <td style="max-width:28rem;white-space:pre-wrap;word-break:break-word">${escapeHtml(preview)}</td>
          <td>${escapeHtml(dateLabel)}</td>
          <td><button type="button" class="admin-btn admin-btn-secondary admin-btn-sm admin-btn-danger-text" onclick="deleteReceptionMessage(${id})">Delete</button></td>
        </tr>
      `;
    }).join("");
  }

  window.loadReceptionMessages = function loadReceptionMessages() {
    const tbody = $("reception-messages-tbody");
    if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="admin-empty">Loading messages…</td></tr>';
    AdminAuth.apiCall("api.php?action=admin-get-reception-messages")
      .then((res) => res.json())
      .then((json) => {
        if (!json.success) throw new Error(json.error || "Could not load messages.");
        renderReceptionMessages(json.data);
      })
      .catch((err) => {
        renderReceptionMessages({ total: "—", messages: [] });
        showFlash("reception-message", err.message || "Could not load messages.", "error");
      });
  };

  window.deleteReceptionMessage = function deleteReceptionMessage(messageId) {
    if (!confirm(`Delete message #${messageId}?`)) return;
    AdminAuth.apiCall("api.php?action=admin-delete-reception-message", {
      method: "POST",
      body: JSON.stringify({ message_id: messageId }),
    })
      .then((res) => res.json())
      .then((json) => {
        if (!json.success) throw new Error(json.error || "Could not delete message.");
        showFlash("reception-message", `Message #${messageId} deleted.`, "success");
        loadReceptionMessages();
      })
      .catch((err) => showFlash("reception-message", err.message || "Could not delete message.", "error"));
  };

  window.clearReceptionMessages = function clearReceptionMessages() {
    if (!confirm("Clear ALL guest messages for the couple?\n\nThis cannot be undone.")) return;
    const typed = prompt("Type DELETE to confirm clearing all messages:");
    if (typed !== "DELETE") {
      showFlash("reception-message", "Clear all cancelled.", "info");
      return;
    }

    AdminAuth.apiCall("api.php?action=admin-clear-reception-messages", {
      method: "POST",
      body: JSON.stringify({ confirm: "DELETE" }),
    })
      .then((res) => res.json())
      .then((json) => {
        if (!json.success) throw new Error(json.error || "Could not clear messages.");
        renderReceptionMessages({ total: 0, messages: [] });
        const deleted = json.data?.deleted ?? 0;
        showFlash("reception-message", `Cleared ${deleted} message(s).`, "success");
      })
      .catch((err) => showFlash("reception-message", err.message || "Could not clear messages.", "error"));
  };

  window.exportReceptionMessagesCsv = function exportReceptionMessagesCsv() {
    hideFlash("reception-message");
    AdminAuth.apiCall("api.php?action=admin-export-reception-messages-csv")
      .then((res) => {
        if (!res.ok) {
          return res.json().then((j) => {
            throw new Error(j.error || "CSV export failed");
          });
        }
        return res.blob();
      })
      .then((blob) => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = `couple-messages-${Date.now()}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
        showFlash("reception-message", "CSV export downloaded.", "success");
      })
      .catch((err) => showFlash("reception-message", err.message || "Failed to export CSV.", "error"));
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
    grid.innerHTML = '<p class="admin-empty">Loading failover photos…</p>';

    AdminAuth.apiCall("api.php?action=admin-get-reception-photos")
      .then((res) => res.json())
      .then((json) => {
        if (json.success && Array.isArray(json.data)) {
          updateAdminPhotosCount(json.data.length);
          if (json.data.length === 0) {
            grid.innerHTML = '<p class="admin-empty">No failover photos stored locally yet.</p>';
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
    if (!confirm("Hide this photo from the admin failover gallery and venue wall?")) return;

    AdminAuth.apiCall("api.php?action=admin-hide-reception-photo", {
      method: "POST",
      body: JSON.stringify({ photo_id: photoId }),
    })
      .then((res) => res.json())
      .then((json) => {
        if (json.success) {
          loadAdminPhotos();
          showFlash("photos-message", "Photo hidden from failover gallery.", "success");
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
          if ($("responses-page-info")) $("responses-page-info").textContent = "Page 1 of 1";
          if ($("responses-prev")) $("responses-prev").disabled = true;
          if ($("responses-next")) $("responses-next").disabled = true;
          return;
        }

        allResponses = (data.data || [])
          .filter((item) => item.attending !== null)
          .sort((a, b) => responseActivityTime(b) - responseActivityTime(a));
        currentResponsesPage = 1;
        applyResponsesSearchFromInput();
        renderResponsesTable();
      })
      .catch(() => {
        $("responses-tbody").innerHTML =
          '<tr><td colspan="6" class="admin-empty">Failed to load responses.</td></tr>';
        if ($("responses-page-info")) $("responses-page-info").textContent = "Page 1 of 1";
        if ($("responses-prev")) $("responses-prev").disabled = true;
        if ($("responses-next")) $("responses-next").disabled = true;
      });
  };

  function responseActivityTime(item) {
    const raw = item.updated_at || item.submitted_at || 0;
    const time = new Date(raw).getTime();
    return Number.isFinite(time) ? time : 0;
  }

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

    filteredResponses = allResponses
      .filter((item) => responseMatchesSearch(item, responsesSearchTerm))
      .sort((a, b) => responseActivityTime(b) - responseActivityTime(a));
  }

  window.filterResponses = function filterResponses() {
    applyResponsesSearchFromInput();
    currentResponsesPage = 1;
    renderResponsesTable();
  };

  function getResponsesToShow() {
    return responsesSearchTerm ? filteredResponses : allResponses;
  }

  function renderGuestNamesCell(item) {
    const names = responseAttendeeNames(item);
    if (names.length) {
      return names.map((name) => `<div>${escapeHtml(name)}</div>`).join("");
    }
    return "<div>—</div>";
  }

  function renderResponsesTable() {
    const tbody = $("responses-tbody");
    if (!tbody) return;
    tbody.innerHTML = "";

    const responsesToShow = getResponsesToShow();
    const totalPages = Math.max(1, Math.ceil(responsesToShow.length / RESPONSES_PER_PAGE));
    if (currentResponsesPage > totalPages) currentResponsesPage = totalPages;

    const startIndex = (currentResponsesPage - 1) * RESPONSES_PER_PAGE;
    const pageResponses = responsesToShow.slice(startIndex, startIndex + RESPONSES_PER_PAGE);

    if (!pageResponses.length) {
      const emptyMsg = responsesSearchTerm
        ? `No responses found matching "${escapeHtml(responsesSearchTerm)}".`
        : "No responses yet.";
      tbody.innerHTML = `<tr><td colspan="6" class="admin-empty">${emptyMsg}</td></tr>`;
    } else {
      pageResponses.forEach((item) => {
        const submittedAt = item.updated_at || item.submitted_at
          ? new Date(item.updated_at || item.submitted_at).toLocaleString()
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
    }

    const total = responsesToShow.length;
    const from = total === 0 ? 0 : startIndex + 1;
    const to = Math.min(startIndex + RESPONSES_PER_PAGE, total);
    const pageInfo = $("responses-page-info");
    if (pageInfo) {
      pageInfo.textContent = total
        ? `Page ${currentResponsesPage} of ${totalPages} · ${from}–${to} of ${total}`
        : `Page ${currentResponsesPage} of ${totalPages}`;
    }

    const prevBtn = $("responses-prev");
    const nextBtn = $("responses-next");
    if (prevBtn) prevBtn.disabled = currentResponsesPage <= 1;
    if (nextBtn) nextBtn.disabled = currentResponsesPage >= totalPages || total === 0;
  }

  window.responsesPrevPage = function responsesPrevPage() {
    if (currentResponsesPage > 1) {
      currentResponsesPage -= 1;
      renderResponsesTable();
    }
  };

  window.responsesNextPage = function responsesNextPage() {
    const totalPages = Math.max(1, Math.ceil(getResponsesToShow().length / RESPONSES_PER_PAGE));
    if (currentResponsesPage < totalPages) {
      currentResponsesPage += 1;
      renderResponsesTable();
    }
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

  let floorPlanDraft = null;
  let floorPlanSelected = null;
  let floorPlanDirty = false;
  let floorEditorBound = false;

  function cloneFloorPlan(plan) {
    return JSON.parse(JSON.stringify(plan || {}));
  }

  function selectedFloorHint() {
    if (!floorPlanSelected) return "Drag tables onto the official Alta Terra seats, then save. VIP 1 is table 15 and VIP 2 is table 16 when assigning seats.";
    if (floorPlanSelected.type === "table") {
      const table = floorPlanDraft?.tables?.find((item) => item.number === floorPlanSelected.number);
      const name = tableDisplayName(floorPlanSelected.number, table);
      return `Selected ${name}. Drag to move, edit the label, or remove it.`;
    }
    return `Selected ${floorPlanSelected.id}. Drag to move, or edit the label.`;
  }

  function tableDisplayName(number, table) {
    const item = table || floorPlanDraft?.tables?.find((row) => Number(row.number) === Number(number));
    const label = String(item?.label || "").trim();
    if (item?.kind === "vip" || /^vip/i.test(label)) {
      return label || `VIP ${number}`;
    }
    return label && label !== String(number) ? `Table ${label}` : `Table ${number}`;
  }

  function updateFloorPlanToolbar() {
    const removeBtn = $("floor-remove-table-btn");
    const labelInput = $("floor-marker-label");
    if (removeBtn) removeBtn.disabled = !(floorPlanSelected && floorPlanSelected.type === "table");
    if (labelInput) {
      if (floorPlanSelected && floorPlanSelected.type === "table") {
        const table = floorPlanDraft?.tables?.find((item) => item.number === floorPlanSelected.number);
        labelInput.disabled = false;
        labelInput.value = table?.label || String(floorPlanSelected.number);
      } else if (floorPlanSelected && floorPlanSelected.type === "marker" && floorPlanDraft?.markers?.[floorPlanSelected.id]) {
        labelInput.disabled = false;
        labelInput.value = floorPlanDraft.markers[floorPlanSelected.id].label || "";
      } else {
        labelInput.disabled = true;
        labelInput.value = "";
      }
    }
    const hint = $("floor-plan-hint");
    if (hint) hint.textContent = selectedFloorHint();
  }

  function prepareAdminFloorRoom(room) {
    const parentWidth = room.parentElement ? room.parentElement.clientWidth : 960;
    const width = Math.max(300, Math.min(parentWidth, 960));
    const height = Math.max(220, Math.round(width * (721 / 1024)));
    room.style.position = "relative";
    room.style.width = `${width}px`;
    room.style.height = `${height}px`;
    room.style.minHeight = `${height}px`;
    room.style.paddingBottom = "0";
    room.style.overflow = "hidden";
    room.style.touchAction = "none";
    room.style.userSelect = "none";

    if (!room.querySelector(".admin-floor-plan-img")) {
      const img = document.createElement("img");
      img.className = "admin-floor-plan-img";
      img.src = "../reception/assets/alta-terra-floor-plan.png";
      img.alt = "Official Alta Terra Tagaytay floor layout";
      img.draggable = false;
      room.appendChild(img);
    }
  }

  function placeAdminFloorPiece(el, box, isTable) {
    el.style.position = "absolute";
    el.style.left = `${box.left}%`;
    el.style.top = `${box.top}%`;
    el.style.margin = "0";
    el.style.zIndex = isTable ? "4" : "3";
    if (isTable) {
      el.style.width = "auto";
      el.style.height = "auto";
      el.style.transform = "translate(-50%, -50%)";
    } else {
      el.style.width = `${box.width}%`;
      el.style.height = `${box.height}%`;
      el.style.transform = "none";
    }
  }

  function renderAdminFloorPlan() {
    const room = $("admin-floor-room");
    if (!room || !floorPlanDraft) return;
    prepareAdminFloorRoom(room);
    room.querySelectorAll("[data-kind]").forEach((el) => el.remove());

    (floorPlanDraft.tables || []).forEach((table) => {
      const el = document.createElement("button");
      el.type = "button";
      const isVip = table.kind === "vip";
      el.className = `admin-floor-piece admin-floor-piece--table${isVip ? " admin-floor-piece--vip" : ""}`;
      el.dataset.kind = "table";
      el.dataset.number = String(table.number);
      const name = tableDisplayName(table.number, table);
      el.setAttribute("aria-label", `${name}, drag to move`);
      el.innerHTML = `
        <span class="admin-floor-table-dot">${escapeHtml(isVip ? (table.label || "VIP") : String(table.number))}</span>
        <span class="admin-floor-piece__caption">${escapeHtml(name)} · drag</span>
      `;
      placeAdminFloorPiece(el, table, true);
      if (floorPlanSelected && floorPlanSelected.type === "table" && floorPlanSelected.number === table.number) {
        el.classList.add("is-selected");
      }
      room.appendChild(el);
    });

    updateFloorPlanToolbar();
  }

  function selectFloorItem(selection, shouldRender) {
    floorPlanSelected = selection;
    if (shouldRender !== false) renderAdminFloorPlan();
    else updateFloorPlanToolbar();
  }

  function percentFromPointer(event, room) {
    const rect = room.getBoundingClientRect();
    const x = ((event.clientX - rect.left) / rect.width) * 100;
    const y = ((event.clientY - rect.top) / rect.height) * 100;
    return {
      left: Math.max(2, Math.min(98, x)),
      top: Math.max(4, Math.min(96, y)),
    };
  }

  function bindFloorPlanEditor() {
    if (floorEditorBound) return;
    const room = $("admin-floor-room");
    if (!room) return;
    floorEditorBound = true;

    let drag = null;

    room.addEventListener("pointerdown", (event) => {
      const target = event.target.closest("[data-kind]");
      if (!target || !floorPlanDraft) return;
      event.preventDefault();
      room.setPointerCapture(event.pointerId);
      room.querySelectorAll(".is-selected").forEach((el) => el.classList.remove("is-selected"));
      target.classList.add("is-selected");
      if (target.dataset.kind === "table") {
        selectFloorItem({ type: "table", number: parseInt(target.dataset.number, 10) }, false);
      } else {
        selectFloorItem({ type: "marker", id: target.dataset.id }, false);
      }
      const point = percentFromPointer(event, room);
      drag = {
        target,
        kind: target.dataset.kind,
        number: parseInt(target.dataset.number || "0", 10),
        id: target.dataset.id || "",
        offsetX: 0,
        offsetY: 0,
      };
      if (drag.kind === "table") {
        const table = floorPlanDraft.tables.find((item) => item.number === drag.number);
        drag.offsetX = point.left - (table?.left || point.left);
        drag.offsetY = point.top - (table?.top || point.top);
      } else {
        const marker = floorPlanDraft.markers[drag.id];
        drag.offsetX = point.left - (marker?.left || point.left);
        drag.offsetY = point.top - (marker?.top || point.top);
      }
    });

    room.addEventListener("pointermove", (event) => {
      if (!drag || !floorPlanDraft) return;
      const point = percentFromPointer(event, room);
      const left = Math.round(Math.max(4, Math.min(96, point.left - drag.offsetX)) * 10) / 10;
      const top = Math.round(Math.max(6, Math.min(94, point.top - drag.offsetY)) * 10) / 10;
      if (drag.kind === "table") {
        const table = floorPlanDraft.tables.find((item) => item.number === drag.number);
        if (!table) return;
        table.left = left;
        table.top = top;
      } else {
        const marker = floorPlanDraft.markers[drag.id];
        if (!marker) return;
        marker.left = Math.round(Math.max(0, Math.min(92, left)) * 10) / 10;
        marker.top = Math.round(Math.max(0, Math.min(92, top)) * 10) / 10;
      }
      drag.target.style.left = `${left}%`;
      drag.target.style.top = `${top}%`;
      floorPlanDirty = true;
    });

    function endDrag() {
      drag = null;
    }
    room.addEventListener("pointerup", endDrag);
    room.addEventListener("pointercancel", endDrag);

    $("floor-add-table-btn")?.addEventListener("click", () => {
      if (!floorPlanDraft) return;
      const used = new Set(floorPlanDraft.tables.map((table) => table.number));
      let number = 1;
      while (used.has(number) && number < 40) number += 1;
      if (used.has(number)) {
        showFlash("floor-plan-message", "You already have 40 tables on the plan.", "error");
        return;
      }
      const col = floorPlanDraft.tables.length % 5;
      const row = Math.floor(floorPlanDraft.tables.length / 5);
      floorPlanDraft.tables.push({
        number,
        left: Math.min(90, 18 + col * 14),
        top: Math.min(88, 42 + row * 14),
        kind: "round",
        label: String(number),
      });
      floorPlanDraft.tables.sort((a, b) => a.number - b.number);
      floorPlanDirty = true;
      selectFloorItem({ type: "table", number });
      hideFlash("floor-plan-message");
    });

    $("floor-remove-table-btn")?.addEventListener("click", () => {
      if (!floorPlanDraft || !floorPlanSelected || floorPlanSelected.type !== "table") return;
      if (floorPlanDraft.tables.length <= 1) {
        showFlash("floor-plan-message", "Keep at least one table on the plan.", "error");
        return;
      }
      floorPlanDraft.tables = floorPlanDraft.tables.filter((table) => table.number !== floorPlanSelected.number);
      floorPlanDirty = true;
      floorPlanSelected = null;
      renderAdminFloorPlan();
    });

    $("floor-marker-label")?.addEventListener("input", (event) => {
      if (!floorPlanDraft || !floorPlanSelected) return;
      const nextLabel = String(event.target.value || "").slice(0, 24);
      if (floorPlanSelected.type === "table") {
        const table = floorPlanDraft.tables.find((item) => item.number === floorPlanSelected.number);
        if (!table) return;
        table.label = nextLabel;
        floorPlanDirty = true;
        const title = document.querySelector(`.admin-floor-piece[data-number="${floorPlanSelected.number}"] .admin-floor-table-dot`);
        const caption = document.querySelector(`.admin-floor-piece[data-number="${floorPlanSelected.number}"] .admin-floor-piece__caption`);
        if (title) title.textContent = table.kind === "vip" ? (table.label || "VIP") : (table.label || String(table.number));
        if (caption) caption.textContent = `${tableDisplayName(table.number, table)} · drag`;
        return;
      }
      if (floorPlanSelected.type !== "marker") return;
      const marker = floorPlanDraft.markers[floorPlanSelected.id];
      if (!marker) return;
      marker.label = nextLabel.slice(0, 32);
      floorPlanDirty = true;
      const title = document.querySelector(`.admin-floor-piece[data-id="${floorPlanSelected.id}"] .admin-floor-piece__title`);
      if (title) title.textContent = marker.label || floorPlanSelected.id;
    });

    $("floor-save-btn")?.addEventListener("click", saveFloorPlanEditor);
    window.addEventListener("resize", () => {
      if (floorPlanDraft && $("admin-floor-room")) renderAdminFloorPlan();
    });
  }

  function loadFloorPlanEditor() {
    bindFloorPlanEditor();
    AdminAuth.apiCall("api.php?action=admin-get-floor-plan")
      .then((res) => res.json())
      .then((payload) => {
        if (!payload || !payload.success) throw new Error(payload?.error || "Could not load floor plan");
        floorPlanDraft = cloneFloorPlan(payload.data);
        floorPlanSelected = null;
        floorPlanDirty = false;
        renderAdminFloorPlan();
        populateTableNumberSelect();
        renderSeatingGuestTable();
      })
      .catch((error) => {
        showFlash("floor-plan-message", error.message || "Could not load the floor plan.", "error");
      });
  }

  function saveFloorPlanEditor() {
    if (!floorPlanDraft) return;
    const saveBtn = $("floor-save-btn");
    if (saveBtn) saveBtn.disabled = true;
    AdminAuth.apiCall("api.php?action=admin-save-floor-plan", {
      method: "POST",
      body: JSON.stringify({ plan: floorPlanDraft }),
    })
      .then((res) => res.json())
      .then((payload) => {
        if (!payload || !payload.success) throw new Error(payload?.error || "Save failed");
        floorPlanDraft = cloneFloorPlan(payload.data);
        floorPlanDirty = false;
        renderAdminFloorPlan();
        showFlash("floor-plan-message", "Floor plan saved. Guests will see this layout on the reception Floor tab.", "success");
      })
      .catch((error) => {
        showFlash("floor-plan-message", error.message || "Could not save the floor plan.", "error");
      })
      .finally(() => {
        if (saveBtn) saveBtn.disabled = false;
      });
  }

  let menuDraft = null;
  let menuEditorBound = false;

  function cloneMenu(menu) {
    return JSON.parse(JSON.stringify(menu || {}));
  }

  function menuTagKeys(menu) {
    return Object.keys(menu?.tagLegend || {});
  }

  function uniqueMenuId(title, used) {
    let base = String(title || "course").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
    if (!base) base = "course";
    let id = base.slice(0, 32);
    let n = 2;
    while (used.has(id)) {
      id = `${base.slice(0, 28)}-${n}`;
      n += 1;
    }
    used.add(id);
    return id;
  }

  function blankMenuItem() {
    return { name: "", description: "", tags: [], recommended: false };
  }

  function renderMenuTagsEditor() {
    const root = $("menu-tags-root");
    if (!root || !menuDraft) return;
    const entries = Object.entries(menuDraft.tagLegend || {});
    if (!entries.length) {
      root.innerHTML = '<p class="admin-empty">No dietary tags yet.</p>';
      return;
    }
    root.innerHTML = entries.map(([code, label], index) => `
      <div class="admin-menu-tag-row" data-tag-index="${index}" data-tag-code="${escapeHtml(code)}">
        <input type="text" class="admin-menu-tag-code" data-tag-field="code" maxlength="6" value="${escapeHtml(code)}" aria-label="Tag code">
        <input type="text" class="admin-menu-tag-label" data-tag-field="label" maxlength="32" value="${escapeHtml(label)}" aria-label="Tag label">
        <button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" data-remove-tag="${escapeHtml(code)}">Remove</button>
      </div>
    `).join("");
  }

  function renderMenuEditor() {
    const root = $("menu-editor-root");
    if (!root || !menuDraft) return;
    const tags = menuTagKeys(menuDraft);
    const sections = Array.isArray(menuDraft.sections) ? menuDraft.sections : [];
    if (!sections.length) {
      root.innerHTML = '<p class="admin-empty">No courses yet. Add a course to start the menu.</p>';
      renderMenuTagsEditor();
      return;
    }

    root.innerHTML = sections.map((section, sIndex) => {
      const items = Array.isArray(section.items) ? section.items : [];
      const itemHtml = items.map((item, iIndex) => {
        const tagBoxes = tags.map((code) => {
          const checked = (item.tags || []).includes(code) ? "checked" : "";
          return `<label class="admin-checkbox admin-menu-tag-check"><input type="checkbox" data-menu-tag="${escapeHtml(code)}" ${checked}><span>${escapeHtml(code)}</span></label>`;
        }).join("");
        return `
          <div class="admin-menu-item" data-item-index="${iIndex}">
            <div class="admin-form-grid">
              <div class="admin-field">
                <label>Dish name</label>
                <input type="text" data-item-field="name" maxlength="96" value="${escapeHtml(item.name || "")}">
              </div>
              <div class="admin-field">
                <label>Description</label>
                <textarea data-item-field="description" maxlength="280" rows="2">${escapeHtml(item.description || "")}</textarea>
              </div>
            </div>
            <div class="admin-menu-item-meta">
              <div class="admin-menu-item-tags">${tagBoxes || '<span class="admin-muted">Add dietary tags below.</span>'}</div>
              <label class="admin-checkbox"><input type="checkbox" data-item-field="recommended" ${item.recommended ? "checked" : ""}><span>Highlight</span></label>
              <button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" data-remove-item="${iIndex}">Remove dish</button>
            </div>
          </div>
        `;
      }).join("");
      return `
        <article class="admin-menu-section" data-section-index="${sIndex}">
          <div class="admin-menu-section-head">
            <div class="admin-field" style="margin:0;flex:1">
              <label>Course title</label>
              <input type="text" data-section-field="title" maxlength="48" value="${escapeHtml(section.title || "")}">
            </div>
            <button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" data-remove-section="${sIndex}">Remove course</button>
          </div>
          ${itemHtml}
          <button type="button" class="admin-btn admin-btn-secondary admin-btn-sm" data-add-item="${sIndex}">Add dish</button>
        </article>
      `;
    }).join("");
    renderMenuTagsEditor();
  }

  function collectMenuLegendFromDom() {
    if (!menuDraft) return;
    const next = {};
    document.querySelectorAll("#menu-tags-root .admin-menu-tag-row").forEach((row) => {
      const code = String(row.querySelector("[data-tag-field='code']")?.value || "").toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 6);
      const label = String(row.querySelector("[data-tag-field='label']")?.value || "").trim().slice(0, 32);
      if (!code || next[code]) return;
      next[code] = label || code;
    });
    menuDraft.tagLegend = next;
  }

  function bindMenuEditor() {
    if (menuEditorBound) return;
    menuEditorBound = true;

    $("menu-editor-root")?.addEventListener("input", (event) => {
      if (!menuDraft) return;
      const sectionEl = event.target.closest("[data-section-index]");
      if (!sectionEl) return;
      const sIndex = parseInt(sectionEl.dataset.sectionIndex, 10);
      const section = menuDraft.sections[sIndex];
      if (!section) return;
      if (event.target.matches("[data-section-field='title']")) {
        section.title = String(event.target.value || "").slice(0, 48);
        return;
      }
      const itemEl = event.target.closest("[data-item-index]");
      if (!itemEl) return;
      const item = section.items[parseInt(itemEl.dataset.itemIndex, 10)];
      if (!item) return;
      if (event.target.matches("[data-item-field='name']")) item.name = String(event.target.value || "").slice(0, 96);
      if (event.target.matches("[data-item-field='description']")) item.description = String(event.target.value || "").slice(0, 280);
    });

    $("menu-editor-root")?.addEventListener("change", (event) => {
      if (!menuDraft) return;
      const sectionEl = event.target.closest("[data-section-index]");
      if (!sectionEl) return;
      const section = menuDraft.sections[parseInt(sectionEl.dataset.sectionIndex, 10)];
      const itemEl = event.target.closest("[data-item-index]");
      if (!section || !itemEl) return;
      const item = section.items[parseInt(itemEl.dataset.itemIndex, 10)];
      if (!item) return;
      if (event.target.matches("[data-item-field='recommended']")) {
        item.recommended = !!event.target.checked;
        return;
      }
      if (event.target.matches("[data-menu-tag]")) {
        const code = event.target.getAttribute("data-menu-tag");
        const tags = new Set(item.tags || []);
        if (event.target.checked) tags.add(code);
        else tags.delete(code);
        item.tags = [...tags];
      }
    });

    $("menu-editor-root")?.addEventListener("click", (event) => {
      if (!menuDraft) return;
      const addItem = event.target.closest("[data-add-item]");
      if (addItem) {
        const sIndex = parseInt(addItem.getAttribute("data-add-item"), 10);
        if (!menuDraft.sections[sIndex]) return;
        if ((menuDraft.sections[sIndex].items || []).length >= 20) {
          showFlash("menu-editor-message", "Each course can have up to 20 dishes.", "error");
          return;
        }
        menuDraft.sections[sIndex].items.push(blankMenuItem());
        renderMenuEditor();
        return;
      }
      const removeItem = event.target.closest("[data-remove-item]");
      if (removeItem) {
        const sectionEl = event.target.closest("[data-section-index]");
        const sIndex = parseInt(sectionEl?.dataset.sectionIndex, 10);
        const iIndex = parseInt(removeItem.getAttribute("data-remove-item"), 10);
        const section = menuDraft.sections[sIndex];
        if (!section) return;
        if ((section.items || []).length <= 1) {
          showFlash("menu-editor-message", "Keep at least one dish in each course, or remove the course.", "error");
          return;
        }
        section.items.splice(iIndex, 1);
        renderMenuEditor();
        return;
      }
      const removeSection = event.target.closest("[data-remove-section]");
      if (removeSection) {
        if (menuDraft.sections.length <= 1) {
          showFlash("menu-editor-message", "Keep at least one course on the menu.", "error");
          return;
        }
        menuDraft.sections.splice(parseInt(removeSection.getAttribute("data-remove-section"), 10), 1);
        renderMenuEditor();
      }
    });

    $("menu-tags-root")?.addEventListener("input", (event) => {
      if (!menuDraft || !event.target.matches("[data-tag-field='label']")) return;
      const row = event.target.closest(".admin-menu-tag-row");
      const codeInput = row?.querySelector("[data-tag-field='code']");
      const code = String(codeInput?.value || "").toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 6);
      if (code && menuDraft.tagLegend[code] !== undefined) {
        menuDraft.tagLegend[code] = String(event.target.value || "").trim().slice(0, 32) || code;
      }
    });

    $("menu-tags-root")?.addEventListener("focusout", (event) => {
      if (!menuDraft || !event.target.matches("[data-tag-field='code']")) return;
      const row = event.target.closest(".admin-menu-tag-row");
      const oldCode = row?.dataset.tagCode || "";
      collectMenuLegendFromDom();
      const newCode = String(event.target.value || "").toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 6);
      if (oldCode && newCode && oldCode !== newCode) {
        menuDraft.sections.forEach((section) => {
          (section.items || []).forEach((item) => {
            item.tags = (item.tags || []).map((tag) => (tag === oldCode ? newCode : tag));
            item.tags = [...new Set(item.tags)];
          });
        });
      }
      renderMenuEditor();
    });

    $("menu-tags-root")?.addEventListener("click", (event) => {
      const btn = event.target.closest("[data-remove-tag]");
      if (!btn || !menuDraft) return;
      const code = btn.getAttribute("data-remove-tag");
      delete menuDraft.tagLegend[code];
      menuDraft.sections.forEach((section) => {
        (section.items || []).forEach((item) => {
          item.tags = (item.tags || []).filter((tag) => tag !== code);
        });
      });
      renderMenuEditor();
    });

    $("menu-add-section-btn")?.addEventListener("click", () => {
      if (!menuDraft) return;
      if ((menuDraft.sections || []).length >= 12) {
        showFlash("menu-editor-message", "You can have up to 12 courses.", "error");
        return;
      }
      const used = new Set((menuDraft.sections || []).map((section) => section.id));
      menuDraft.sections.push({
        id: uniqueMenuId("course", used),
        title: "New course",
        items: [blankMenuItem()],
      });
      renderMenuEditor();
      hideFlash("menu-editor-message");
    });

    $("menu-add-tag-btn")?.addEventListener("click", () => {
      if (!menuDraft) return;
      collectMenuLegendFromDom();
      if (menuTagKeys(menuDraft).length >= 12) {
        showFlash("menu-editor-message", "You can have up to 12 dietary tags.", "error");
        return;
      }
      let n = 1;
      while (menuDraft.tagLegend[`T${n}`]) n += 1;
      menuDraft.tagLegend[`T${n}`] = "New tag";
      renderMenuEditor();
    });

    $("menu-save-btn")?.addEventListener("click", saveMenuEditor);
  }

  function loadMenuEditor() {
    bindMenuEditor();
    AdminAuth.apiCall("api.php?action=admin-get-menu")
      .then((res) => res.json())
      .then((payload) => {
        if (!payload || !payload.success) throw new Error(payload?.error || "Could not load menu");
        menuDraft = cloneMenu(payload.data);
        if (!menuDraft.tagLegend) menuDraft.tagLegend = {};
        if (!Array.isArray(menuDraft.sections)) menuDraft.sections = [];
        renderMenuEditor();
        hideFlash("menu-editor-message");
      })
      .catch((error) => {
        showFlash("menu-editor-message", error.message || "Could not load the menu.", "error");
      });
  }

  function saveMenuEditor() {
    if (!menuDraft) return;
    collectMenuLegendFromDom();
    const used = new Set();
    menuDraft.sections = (menuDraft.sections || []).map((section) => ({
      ...section,
      id: uniqueMenuId(section.id || section.title, used),
      title: String(section.title || "").trim() || "Course",
      items: (section.items || []).filter((item) => String(item.name || "").trim()),
    })).filter((section) => section.items.length);
    if (!menuDraft.sections.length) {
      showFlash("menu-editor-message", "Add at least one dish before saving.", "error");
      return;
    }
    const saveBtn = $("menu-save-btn");
    if (saveBtn) saveBtn.disabled = true;
    AdminAuth.apiCall("api.php?action=admin-save-menu", {
      method: "POST",
      body: JSON.stringify({ menu: menuDraft }),
    })
      .then((res) => res.json())
      .then((payload) => {
        if (!payload || !payload.success) throw new Error(payload?.error || "Save failed");
        menuDraft = cloneMenu(payload.data);
        renderMenuEditor();
        showFlash("menu-editor-message", "Menu saved. Guests will see this on the reception Menu tab.", "success");
      })
      .catch((error) => {
        showFlash("menu-editor-message", error.message || "Could not save the menu.", "error");
      })
      .finally(() => {
        if (saveBtn) saveBtn.disabled = false;
      });
  }

  window.loadTableAssignments = function loadTableAssignments() {
    AdminAuth.apiCall("api.php?action=get-table-assignments")
      .then((r) => r.json())
      .then((assignmentsRes) => {
        if (!assignmentsRes || !assignmentsRes.success) {
          throw new Error(assignmentsRes?.error || "Could not load seating.");
        }
        applySeatingGuests(assignmentsRes.data || []);
        hideFlash("seating-assignments-message");
      })
      .catch((error) => {
        showFlash("seating-assignments-message", error.message || "Failed to load table assignments.", "error");
      });
  };

  function applySeatingGuests(guests) {
    globalSeatingGuests = Array.isArray(guests) ? guests : [];
    globalAssignments = globalSeatingGuests.filter((guest) => Number(guest.table_number) > 0);
    updateTablePlanningSummary(globalSeatingGuests);
    populateTableNumberSelect();
    attachTableSelectionListener();
    attachSeatingSearchListener();
    renderSeatingGuestTable();
    const tableSearchValue = ($("table-search")?.value || "").trim();
    if (tableSearchValue) {
      filterTableOverview();
    } else {
      populateTableOverview(globalSeatingGuests);
    }
  }

  function seatingGuestMatchesQuery(guest, query) {
    if (!query) return true;
    const haystack = `${guest.guest_name || ""} ${guest.party_name || ""}`.toLowerCase();
    return haystack.includes(query);
  }

  function filteredSeatingGuests() {
    const query = ($("table-guest-search")?.value || "").trim().toLowerCase();
    const filter = $("table-number-select")?.value || "";
    return globalSeatingGuests.filter((guest) => {
      if (!seatingGuestMatchesQuery(guest, query)) return false;
      if (filter === "unassigned") return !Number(guest.table_number);
      if (filter) return parseInt(guest.table_number, 10) === parseInt(filter, 10);
      return true;
    });
  }

  function buildSeatingGuestRow(guest) {
    const tr = document.createElement("tr");
    const tableLabel = Number(guest.table_number)
      ? escapeHtml(tableDisplayName(guest.table_number))
      : '<span class="admin-muted">Not assigned</span>';
    tr.innerHTML = `
      <td>${escapeHtml(guest.guest_name)}</td>
      <td>${escapeHtml(guest.party_name || "—")}</td>
      <td>${tableLabel}</td>
      <td class="admin-seating-actions"></td>
    `;
    const actions = tr.lastElementChild;
    const assignBtn = document.createElement("button");
    assignBtn.type = "button";
    assignBtn.className = "admin-btn admin-btn-secondary admin-btn-sm";
    assignBtn.textContent = Number(guest.table_number) ? "Edit" : "Assign";
    assignBtn.addEventListener("click", () => openAssignTableModal(guest));
    actions.appendChild(assignBtn);
    if (Number(guest.table_number) || guest.assignment_id) {
      const deleteBtn = document.createElement("button");
      deleteBtn.type = "button";
      deleteBtn.className = "admin-btn admin-btn-danger admin-btn-sm";
      deleteBtn.textContent = "Delete";
      deleteBtn.addEventListener("click", () => deleteTableAssignment(guest));
      actions.appendChild(deleteBtn);
    }
    return tr;
  }

  function renderSeatingGuestTable() {
    const tbody = document.querySelector("#table-assignments-table tbody");
    if (!tbody) return;
    const guests = filteredSeatingGuests();
    tbody.innerHTML = "";
    const count = $("seating-guest-count");
    if (!globalSeatingGuests.length) {
      tbody.innerHTML = '<tr><td colspan="4" class="admin-empty">No confirmed guests to seat yet.</td></tr>';
      if (count) count.textContent = "";
      return;
    }
    if (!guests.length) {
      tbody.innerHTML = '<tr><td colspan="4" class="admin-empty">No guests match this search or filter.</td></tr>';
      if (count) count.textContent = `Showing 0 of ${globalSeatingGuests.length} confirmed guests.`;
      return;
    }
    guests.forEach((guest) => tbody.appendChild(buildSeatingGuestRow(guest)));
    if (count) {
      count.textContent = `Showing ${guests.length} of ${globalSeatingGuests.length} confirmed guests.`;
    }
  }

  function populateTableNumberSelect() {
    const select = $("table-number-select");
    if (!select) return;
    const current = select.value;
    const planTables = Array.isArray(floorPlanDraft?.tables) ? floorPlanDraft.tables : [];
    const maxFromPlan = planTables.reduce((max, table) => Math.max(max, Number(table.number) || 0), 0);
    const assignedMax = globalSeatingGuests.reduce((max, guest) => Math.max(max, Number(guest.table_number) || 0), 0);
    const totalCapacity = parseInt($("total-capacity")?.value, 10) || 200;
    const seatsPerTable = parseInt($("seats-per-table")?.value, 10) || 10;
    const tablesNeeded = Math.max(1, Math.ceil(totalCapacity / seatsPerTable));
    const maxTable = Math.max(tablesNeeded, maxFromPlan, assignedMax, 16);

    select.innerHTML = '<option value="">All confirmed guests</option><option value="unassigned">Unassigned</option>';
    for (let i = 1; i <= maxTable; i += 1) {
      const option = document.createElement("option");
      option.value = String(i);
      option.textContent = tableDisplayName(i);
      select.appendChild(option);
    }
    select.value = [...select.options].some((option) => option.value === current) ? current : "";
  }

  function attachTableSelectionListener() {
    const select = $("table-number-select");
    if (!select || tableSelectBound) return;
    tableSelectBound = true;
    select.addEventListener("change", renderSeatingGuestTable);
  }

  function attachSeatingSearchListener() {
    const input = $("table-guest-search");
    if (!input || tableGuestSearchBound) return;
    tableGuestSearchBound = true;
    input.addEventListener("input", renderSeatingGuestTable);
  }

  function populateTableOverview(guests) {
    const overviewDiv = $("table-overview");
    if (!overviewDiv) return;
    overviewDiv.innerHTML = "";

    const tableGroups = {};
    (guests || []).forEach((guest) => {
      const tableNumber = parseInt(guest.table_number, 10);
      if (!tableNumber) return;
      if (!tableGroups[tableNumber]) tableGroups[tableNumber] = [];
      tableGroups[tableNumber].push(guest);
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
        const seated = tableGroups[tableNum];
        const listItems = seated
          .map((guest) => {
            const party = guest.party_name && guest.party_name !== guest.guest_name
              ? ` <span class="admin-muted">(${escapeHtml(guest.party_name)})</span>`
              : "";
            return `<li>${escapeHtml(guest.guest_name)}${party}</li>`;
          })
          .join("");
        const card = document.createElement("div");
        card.className = "admin-card admin-table-overview-card";
        card.innerHTML = `
          <h3>${escapeHtml(tableDisplayName(tableNum))}</h3>
          <p><strong>${seated.length}</strong> guest(s)</p>
          <ul>${listItems}</ul>
        `;
        grid.appendChild(card);
      });

    overviewDiv.appendChild(grid);
  }

  window.updateTableCalculations = function updateTableCalculations() {
    populateTableNumberSelect();
    updateTablePlanningSummary(globalSeatingGuests);
    renderSeatingGuestTable();
  };

  function updateTablePlanningSummary(guests) {
    const list = Array.isArray(guests) ? guests : globalSeatingGuests;
    const totalCapacity = parseInt($("total-capacity")?.value, 10) || 200;
    const seatsPerTable = parseInt($("seats-per-table")?.value, 10) || 10;
    const tablesNeeded = Math.ceil(totalCapacity / seatsPerTable);
    const assignedGuests = list.filter((guest) => Number(guest.table_number) > 0);
    const assignedTableNumbers = new Set(assignedGuests.map((guest) => parseInt(guest.table_number, 10)));
    const tablesAssigned = assignedTableNumbers.size;
    const confirmedGuests = list.length;
    const unassignedGuests = confirmedGuests - assignedGuests.length;
    const coverage = confirmedGuests > 0 ? Math.round((assignedGuests.length / confirmedGuests) * 100) : 0;

    if ($("tables-needed")) $("tables-needed").textContent = String(tablesNeeded);
    if ($("tables-assigned")) $("tables-assigned").textContent = String(tablesAssigned);

    const summary = $("table-planning-summary");
    if (summary) {
      summary.innerHTML = `
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
            <strong>Status</strong><br>${tablesAssigned >= tablesNeeded ? "Complete" : `${Math.max(0, tablesNeeded - tablesAssigned)} table(s) still open`}<br>
            <strong>Coverage</strong><br>${coverage}% of confirmed guests assigned
          </div>
        </div>
      `;
    }

    window.currentTableData = { guests: list, assignments: assignedGuests };
  }

  window.filterTableOverview = function filterTableOverview() {
    const query = ($("table-search")?.value || "").trim().toLowerCase();
    const seated = globalSeatingGuests.filter((guest) => Number(guest.table_number) > 0);
    const matched = query
      ? seated.filter((guest) => seatingGuestMatchesQuery(guest, query))
      : seated;
    populateTableOverview(matched);
    const result = $("table-overview-search-result");
    if (!result) return;
    if (!query) {
      result.textContent = "";
      return;
    }
    result.textContent = matched.length
      ? `${matched.length} matching seated guest(s).`
      : "No matching seated guests found.";
  };

  window.openAssignTableModal = function openAssignTableModal(guest) {
    $("assign-invitation-id").value = guest.invitation_id || "";
    $("assign-guest-name").value = guest.guest_name || "";
    $("assign-assignment-id").value = guest.assignment_id || "";
    $("assign-guest-label").textContent = guest.guest_name || "";
    if ($("assign-party-label")) $("assign-party-label").textContent = guest.party_name || "—";
    const title = $("assign-table-title");
    if (title) title.textContent = Number(guest.table_number) ? "Edit table" : "Assign table";
    const filterValue = $("table-number-select")?.value || "";
    $("assign-table-number").value = Number(guest.table_number)
      ? guest.table_number
      : (/^\d+$/.test(filterValue) ? filterValue : "");
    openModal("assign-table-modal");
  };

  window.saveTableAssignment = function saveTableAssignment(event) {
    event.preventDefault();
    const invitationId = $("assign-invitation-id").value;
    const guestName = $("assign-guest-name").value;
    const tableNumber = parseInt($("assign-table-number").value, 10);
    const submitBtn = $("assign-table-submit");

    if (!invitationId || !guestName) {
      showFlash("seating-assignments-message", "Choose a confirmed guest first.", "error");
      return;
    }
    if (!Number.isInteger(tableNumber) || tableNumber < 1 || tableNumber > 40) {
      showFlash("seating-assignments-message", "Enter a table from 1 to 40.", "error");
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = "Saving…";

    AdminAuth.apiCall("api.php?action=assign-table", {
      method: "POST",
      body: JSON.stringify({
        invitation_id: invitationId,
        guest_name: guestName,
        table_number: tableNumber,
      }),
    })
      .then((response) => response.json())
      .then((data) => {
        submitBtn.disabled = false;
        submitBtn.textContent = "Save assignment";
        if (data.success) {
          closeModal("assign-table-modal");
          if (Array.isArray(data.data)) applySeatingGuests(data.data);
          else loadTableAssignments();
          showFlash("seating-assignments-message", `${guestName} is now at ${tableDisplayName(tableNumber)}.`, "success");
        } else {
          showFlash("seating-assignments-message", data.message || data.error || "Save failed.", "error");
        }
      })
      .catch((error) => {
        submitBtn.disabled = false;
        submitBtn.textContent = "Save assignment";
        showFlash("seating-assignments-message", error.message || "Save failed.", "error");
      });
  };

  window.deleteTableAssignment = function deleteTableAssignment(guest) {
    const name = guest.guest_name || "this guest";
    const tableLabel = Number(guest.table_number) ? tableDisplayName(guest.table_number) : "their table";
    if (!window.confirm(`Remove ${name} from ${tableLabel}?`)) return;

    AdminAuth.apiCall("api.php?action=delete-table-assignment", {
      method: "POST",
      body: JSON.stringify({
        assignment_id: guest.assignment_id || 0,
        invitation_id: guest.invitation_id,
        guest_name: guest.guest_name,
      }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          if (Array.isArray(data.data)) applySeatingGuests(data.data);
          else loadTableAssignments();
          showFlash("seating-assignments-message", `${name} is no longer assigned to a table.`, "success");
        } else {
          showFlash("seating-assignments-message", data.message || data.error || "Could not remove that assignment.", "error");
        }
      })
      .catch((error) => {
        showFlash("seating-assignments-message", error.message || "Could not remove that assignment.", "error");
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

  function initAdminFailoverUpload() {
    const input = $("admin-failover-photo-input");
    if (!input) return;

    input.addEventListener("change", async () => {
      const files = [...(input.files || [])];
      if (!files.length) return;

      hideFlash("photos-message");
      let uploaded = 0;
      let failed = 0;
      let lastError = "";

      for (const file of files) {
        const form = new FormData();
        form.append("photo", file, file.name || "photo.jpg");
        form.append("uploader_name", "Admin failover");
        try {
          const res = await AdminAuth.apiCall("api.php?action=admin-upload-reception-photo", {
            method: "POST",
            body: form,
          });
          const json = await res.json();
          if (json && json.success) {
            uploaded += 1;
          } else {
            failed += 1;
            lastError = (json && json.error) || "Upload failed";
          }
        } catch (err) {
          failed += 1;
          lastError = err.message || "Upload failed";
        }
      }

      input.value = "";
      loadAdminPhotos();

      if (uploaded && !failed) {
        showFlash("photos-message", `Saved ${uploaded} failover photo(s) locally.`, "success");
      } else if (uploaded) {
        showFlash("photos-message", `Saved ${uploaded}; ${failed} failed. ${lastError}`, "error");
      } else {
        showFlash("photos-message", lastError || "Failover upload failed.", "error");
      }
    });
  }

  window.initAdminDashboard = function initAdminDashboard() {
    bindDelegatedActions();
    initAdminFailoverUpload();
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
