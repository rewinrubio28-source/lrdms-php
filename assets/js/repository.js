/**
 * Live filters for repository.php
 * - Typing in the search box, or changing a dropdown/date, updates the
 *   results automatically (debounced) via AJAX — no page reload.
 * - The "Filter" button submits the form the same way, for anyone who'd
 *   rather click Apply than wait on a field's live update.
 * - The URL is kept in sync (history.replaceState) so filters are still
 *   shareable/bookmarkable and survive a manual refresh.
 */
(function () {
  "use strict";

  const form = document.getElementById("repo-filter-form");
  if (!form) return; // not on the repository page

  const qInput = document.getElementById("repo-q");
  const statusSelect = document.getElementById("repo-status");
  const typeSelect = document.getElementById("repo-type");
  const committeeSelect = document.getElementById("repo-committee");
  const dateFromInput = document.getElementById("repo-date-from");
  const dateToInput = document.getElementById("repo-date-to");
  const resultsEl = document.getElementById("repo-results");
  const resetLink = document.getElementById("repo-reset");

  const DEBOUNCE_MS = 350;
  let debounceTimer = null;
  let activeController = null; // to cancel a stale in-flight request
  let requestSeq = 0;

  function buildParams() {
    const params = new URLSearchParams();
    if (qInput.value.trim() !== "") params.set("q", qInput.value.trim());
    if (statusSelect.value !== "All") params.set("status", statusSelect.value);
    if (typeSelect.value !== "All") params.set("type", typeSelect.value);
    if (committeeSelect && committeeSelect.value !== "All") params.set("committee", committeeSelect.value);
    if (dateFromInput && dateFromInput.value.trim() !== "") params.set("date_from", dateFromInput.value.trim());
    if (dateToInput && dateToInput.value.trim() !== "") params.set("date_to", dateToInput.value.trim());
    return params;
  }

  async function refreshResults() {
    const params = buildParams();

    // Keep the address bar (and back button / refresh / share links) in sync.
    const newUrl = params.toString()
      ? `${window.location.pathname}?${params.toString()}`
      : window.location.pathname;
    window.history.replaceState({}, "", newUrl);

    // Cancel any previous still-running request so results can't arrive out of order.
    if (activeController) activeController.abort();
    activeController = new AbortController();
    const thisRequest = ++requestSeq;

    resultsEl.setAttribute("aria-busy", "true");
    resultsEl.classList.add("repo-results--loading");

    try {
      const res = await fetch(`repository.php?${params.toString()}`, {
        headers: { "X-Requested-With": "XMLHttpRequest" },
        signal: activeController.signal,
      });

      if (thisRequest !== requestSeq) return; // a newer request superseded this one
      if (!res.ok) throw new Error(`Request failed: ${res.status}`);

      const html = await res.text();
      resultsEl.innerHTML = html;
    } catch (err) {
      if (err.name === "AbortError") return; // expected when a newer request cancels this one
      console.error("Repository filter failed:", err);
      resultsEl.innerHTML =
        '<p class="text-danger">Could not load results. Please try again.</p>';
    } finally {
      if (thisRequest === requestSeq) {
        resultsEl.removeAttribute("aria-busy");
        resultsEl.classList.remove("repo-results--loading");
      }
    }
  }

  function scheduleRefresh() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(refreshResults, DEBOUNCE_MS);
  }

  // Search box: filter as you type (debounced so it doesn't fire every keystroke).
  qInput.addEventListener("input", scheduleRefresh);

  // Dropdowns: filter immediately, no need to wait.
  statusSelect.addEventListener("change", refreshResults);
  typeSelect.addEventListener("change", refreshResults);
  if (committeeSelect) committeeSelect.addEventListener("change", refreshResults);

  // Date fields: plain native <input type="date">. "change" fires once a full
  // date is picked (or the field is blurred after typing), so this reacts the
  // same way the other dropdowns do. The "Filter" button next to them submits
  // the form for anyone who'd rather click Apply than wait on that event.
  if (dateFromInput) dateFromInput.addEventListener("change", refreshResults);
  if (dateToInput) dateToInput.addEventListener("change", refreshResults);

  // Never actually submit the form (e.g. pressing Enter in the search box) —
  // it's all handled live above.
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    clearTimeout(debounceTimer);
    refreshResults();
  });

  // Reset: clear fields and refresh live instead of doing a full page navigation.
  if (resetLink) {
    resetLink.addEventListener("click", function (e) {
      e.preventDefault();
      qInput.value = "";
      statusSelect.value = "All";
      typeSelect.value = "All";
      if (committeeSelect) committeeSelect.value = "All";
      if (dateFromInput) dateFromInput.value = "";
      if (dateToInput) dateToInput.value = "";
      clearTimeout(debounceTimer);
      refreshResults();
    });
  }
})();