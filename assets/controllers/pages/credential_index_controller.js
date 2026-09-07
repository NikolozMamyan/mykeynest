import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  faviconCacheKey = "credential-index:favicon-cache:v1";
  faviconSuccessTtlMs = 7 * 24 * 60 * 60 * 1000;
  faviconFailureTtlMs = 24 * 60 * 60 * 1000;

  static targets = [
    "quickShareModal",
    "quickShareCredentialId",
    "quickShareName",
    "quickShareDomain",
    "quickShareInitials",
    "quickShareEmail",
    "launchDialog",
    "launchName",
    "launchUsername",
    "launchInitial",
    "launchUrl",
    "launchLink",
    "launchError",
    "launchState",
    "launchDescription",
    "launchFill",
    "launchFeedback",
    "launchSave",
    "launchSaveFeedback",
    "launchExtension",
  ];

  static values = { launchMessages: Object, userId: Number };

  connect() {
    this.activeFilter = this.element.querySelector(".filter-tab.active")?.dataset.filter ?? "all";
    this.searchInput = this.element.querySelector("#searchInput");
    this.noResults = this.element.querySelector("#noResults");
    this.searchTimer = null;
    this.faviconCache = this.readFaviconCache();
    this.initializeFavicons();
    this.initializeSiteLinks();
    this.applySearch();
    this.extensionRequests = new Map();
    this.handleExtensionMessage = this.handleExtensionMessage.bind(this);
    this.handleExtensionFocus = () => this.detectExtension();
    window.addEventListener("message", this.handleExtensionMessage);
    window.addEventListener("focus", this.handleExtensionFocus);
    this.detectExtension();
  }

  disconnect() {
    window.clearTimeout(this.searchTimer);
    window.clearTimeout(this.extensionRetryTimer);
    window.removeEventListener("message", this.handleExtensionMessage);
    window.removeEventListener("focus", this.handleExtensionFocus);
    this.extensionRequests.forEach(({ reject, timeout }) => {
      window.clearTimeout(timeout);
      reject(new Error("Controller disconnected"));
    });
    this.extensionRequests.clear();
    if (this.hasLaunchDialogTarget && this.launchDialogTarget.open) {
      this.launchDialogTarget.close();
    }
    document.body.classList.remove("credential-launch-open");
  }

  siteUrl(value) {
    const raw = (value || "").trim();
    if (!raw || /[\s\\]/u.test(raw)) return null;

    try {
      const url = new URL(raw.includes("://") ? raw : `https://${raw}`);
      if (!["https:", "http:"].includes(url.protocol) || url.username || url.password) return null;
      if (!url.hostname.includes(".") && url.hostname !== "localhost" && !url.hostname.startsWith("[")) return null;
      return url.href;
    } catch {
      return null;
    }
  }

  initializeSiteLinks() {
    this.element.querySelectorAll("[data-site-domain]").forEach((link) => {
      const url = this.siteUrl(link.dataset.loginUrl || link.dataset.siteDomain);
      if (url) {
        link.href = url;
        link.removeAttribute("aria-disabled");
      } else {
        link.removeAttribute("href");
        link.setAttribute("aria-disabled", "true");
      }
    });
  }

  openLaunch(event) {
    const card = event.currentTarget.closest(".cred-card");
    this.launchCard = card;
    this.launchNameTarget.textContent = card.querySelector(".site-name").textContent;
    this.launchUsernameTarget.textContent = card.querySelector(".cred-field-value").textContent;
    this.launchInitialTarget.textContent = this.launchNameTarget.textContent.trim().slice(0, 1).toUpperCase();
    const siteLink = card.querySelector("[data-site-domain]");
    this.launchUrlTarget.value = this.siteUrl(siteLink.dataset.loginUrl || siteLink.dataset.siteDomain) || "";
    this.launchSaveTarget.hidden = !card.dataset.saveUrl;
    this.launchSaveFeedbackTarget.textContent = "";
    this.launchFeedbackTarget.textContent = "";
    this.updateLaunchUrl();
    this.launchDialogTarget.showModal();
    document.body.classList.add("credential-launch-open");
  }

  closeLaunch() {
    this.launchDialogTarget.close();
  }

  launchClosed() {
    document.body.classList.remove("credential-launch-open");
  }

  dismissLaunch(event) {
    if (event.target !== this.launchDialogTarget) return;
    const bounds = this.launchDialogTarget.getBoundingClientRect();
    if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) {
      this.closeLaunch();
    }
  }

  updateLaunchUrl() {
    const url = this.siteUrl(this.launchUrlTarget.value);
    this.launchErrorTarget.hidden = Boolean(url);
    this.launchUrlTarget.setAttribute("aria-invalid", String(!url));
    this.launchLinkTarget.setAttribute("aria-disabled", String(!url));
    if (url) {
      this.launchLinkTarget.href = url;
    } else {
      this.launchLinkTarget.removeAttribute("href");
    }
    this.launchFillTarget.disabled = !url || this.extensionState !== "ready" || this.launchBusy === true;
    this.launchSaveTarget.disabled = (!url && this.launchUrlTarget.value.trim() !== "") || this.savingLoginUrl === true;
    this.launchSaveFeedbackTarget.textContent = "";
  }

  openLaunchSite(event) {
    if (!this.siteUrl(this.launchUrlTarget.value)) {
      event.preventDefault();
      this.launchUrlTarget.focus();
    }
  }

  handleExtensionMessage(event) {
    const message = event.data;
    if (event.source !== window || event.origin !== window.location.origin
        || message?.source !== "MYKEYNEST_EXTENSION" || message?.type !== "MYKEYNEST_EXTENSION_RESPONSE") return;
    const pending = this.extensionRequests.get(message.requestId);
    if (!pending) return;
    window.clearTimeout(pending.timeout);
    this.extensionRequests.delete(message.requestId);
    pending.resolve(message.response);
  }

  requestExtension(payload, timeoutMs = 1200) {
    return new Promise((resolve, reject) => {
      const requestId = crypto.randomUUID();
      const timeout = window.setTimeout(() => {
        this.extensionRequests.delete(requestId);
        reject(new Error("Extension unavailable"));
      }, timeoutMs);
      this.extensionRequests.set(requestId, { resolve, reject, timeout });
      window.postMessage({ source: "MYKEYNEST_WEB", type: "MYKEYNEST_EXTENSION_REQUEST", requestId, payload }, window.location.origin);
    });
  }

  async detectExtension(attempt = 0) {
    if (this.detectingExtension || this.launchBusy) return;
    window.clearTimeout(this.extensionRetryTimer);
    this.detectingExtension = true;
    this.setExtensionState("checking");
    try {
      const response = await this.requestExtension({ type: "MYKEYNEST_EXTENSION_PING" });
      if (!response?.ok) throw new Error("Extension unavailable");
      this.setExtensionState(!response.paired ? "unpaired" : response.capabilities?.launchCredential === true ? "ready" : "outdated");
    } catch {
      if (!this.element.isConnected) return;
      this.setExtensionState("missing");
      if (attempt < 2) this.extensionRetryTimer = window.setTimeout(() => this.detectExtension(attempt + 1), 600);
    } finally {
      this.detectingExtension = false;
    }
  }

  setExtensionState(state) {
    this.extensionState = state;
    this.launchStateTarget.textContent = this.launchMessagesValue[state];
    this.launchDescriptionTarget.textContent = this.launchMessagesValue[state + "_description"];
    this.launchFillTarget.hidden = state !== "ready";
    this.launchExtensionTarget.hidden = state === "ready";
    this.launchExtensionTarget.href = state === "unpaired" ? this.launchExtensionTarget.dataset.reconnectUrl : this.launchExtensionTarget.dataset.settingsUrl;
    this.launchExtensionTarget.textContent = this.launchMessagesValue[state === "unpaired" ? "reconnect" : "extension"];
    this.element.querySelectorAll(".credential-launch-button").forEach((button) => {
      button.querySelector("[data-launch-label]").textContent = this.launchMessagesValue[state === "ready" ? "button" : "open_site"];
      button.querySelector(".credential-launch-badge").hidden = state !== "ready";
      button.querySelector(".credential-launch-badge").textContent = this.launchMessagesValue.ready;
    });
    this.updateLaunchUrl();
  }

  async saveLoginUrl() {
    if (!this.launchCard?.dataset.saveUrl || this.savingLoginUrl) return;
    const card = this.launchCard;
    this.savingLoginUrl = true;
    this.launchSaveTarget.disabled = true;
    this.launchSaveFeedbackTarget.textContent = this.launchMessagesValue.saving;
    try {
      const response = await fetch(card.dataset.saveUrl, {
        method: "POST",
        headers: { Accept: "application/json" },
        body: new URLSearchParams({ loginUrl: this.launchUrlTarget.value.trim(), _token: card.dataset.saveToken }),
      });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error("Unable to save URL");
      const link = card.querySelector("[data-site-domain]");
      link.dataset.loginUrl = data.loginUrl || "";
      this.initializeSiteLinks();
      if (this.launchCard !== card || !this.element.isConnected) return;
      this.launchUrlTarget.value = this.siteUrl(data.loginUrl || link.dataset.siteDomain) || "";
      this.updateLaunchUrl();
      this.launchSaveFeedbackTarget.textContent = this.launchMessagesValue.saved;
    } catch {
      if (this.launchCard === card) this.launchSaveFeedbackTarget.textContent = this.launchMessagesValue.save_error;
    } finally {
      this.savingLoginUrl = false;
      this.launchSaveTarget.disabled = false;
    }
  }

  async launchAndFill() {
    const url = this.siteUrl(this.launchUrlTarget.value);
    if (!url || this.extensionState !== "ready" || this.launchBusy) return;
    this.launchBusy = true;
    this.launchFillTarget.disabled = true;
    this.launchFeedbackTarget.textContent = this.launchMessagesValue.opening;
    try {
      const response = await this.requestExtension({
        type: "MYKEYNEST_EXTENSION_LAUNCH_CREDENTIAL",
        credentialId: Number(this.launchCard.dataset.credentialId),
        userId: this.userIdValue,
        url,
      }, 20000);
      if (!response?.ok) {
        if (response?.code === "extension_account_mismatch") this.setExtensionState("unpaired");
        this.launchFeedbackTarget.textContent = this.launchMessagesValue[response?.code] || this.launchMessagesValue.launch_error;
        return;
      }
      this.launchFeedbackTarget.textContent = this.launchMessagesValue.opened;
    } catch {
      this.launchFeedbackTarget.textContent = this.launchMessagesValue.launch_error;
    } finally {
      this.launchBusy = false;
      this.updateLaunchUrl();
    }
  }

  search() {
    window.clearTimeout(this.searchTimer);
    this.searchTimer = window.setTimeout(() => this.applySearch(), 250);
  }

  setFilter(event) {
    this.activeFilter = event.currentTarget.dataset.filter ?? "all";
    this.element.querySelectorAll(".filter-tab").forEach((button) => {
      button.classList.toggle("active", button === event.currentTarget);
    });
    this.applySearch();
  }

  handleFaviconError(event) {
    const image = event.currentTarget;
    image.classList.remove("is-loaded");
    image.removeAttribute("src");

    const domain = this.normalizeDomain(image.dataset.domain);
    if (domain) {
      this.writeFaviconCacheEntry(domain, "error");
    }
  }

  handleFaviconLoad(event) {
    const image = event.currentTarget;
    image.classList.add("is-loaded");

    const domain = this.normalizeDomain(image.dataset.domain);
    if (domain) {
      this.writeFaviconCacheEntry(domain, "ok");
    }
  }

  confirmDelete(event) {
    const message = event.currentTarget.dataset.confirmMessage;
    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  }

  async togglePin(event) {
    const button = event.currentTarget;
    const card = button.closest(".cred-card");
    if (!button || !card || button.classList.contains("is-loading")) {
      return;
    }

    const url = button.dataset.toggleUrl;
    const token = button.dataset.toggleToken;

    if (!url || !token) {
      return;
    }

    button.classList.add("is-loading");

    try {
      const body = new URLSearchParams({ _token: token });
      const response = await fetch(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
          "X-Requested-With": "XMLHttpRequest",
          Accept: "application/json",
        },
        body: body.toString(),
        credentials: "same-origin",
      });

      const payload = await response.json();
      if (!response.ok || !payload?.success) {
        throw new Error(payload?.message || "Pin request failed");
      }

      this.updatePinnedCard(card, button, payload);
      this.reorderMineCards();
      this.applySearch();
    } catch (error) {
      console.error(error);
    } finally {
      button.classList.remove("is-loading");
    }
  }

  openQuickShare(event) {
    if (!this.hasQuickShareModalTarget) {
      return;
    }

    const button = event.currentTarget;
    this.quickShareCredentialIdTarget.value = button.dataset.credentialId ?? "";
    this.quickShareNameTarget.textContent = button.dataset.credentialName ?? "-";
    this.quickShareDomainTarget.textContent = button.dataset.credentialDomain ?? "-";
    this.quickShareInitialsTarget.textContent = button.dataset.credentialInitials ?? "--";
    this.quickShareEmailTarget.value = "";

    this.quickShareModalTarget.classList.add("is-open");
    this.quickShareModalTarget.setAttribute("aria-hidden", "false");
    document.body.classList.add("quick-share-open");

    window.setTimeout(() => this.quickShareEmailTarget.focus(), 0);
  }

  closeQuickShare() {
    if (!this.hasQuickShareModalTarget) {
      return;
    }

    this.quickShareModalTarget.classList.remove("is-open");
    this.quickShareModalTarget.setAttribute("aria-hidden", "true");
    document.body.classList.remove("quick-share-open");
  }

  initializeFavicons() {
    const images = this.element.querySelectorAll("[data-favicon-url][data-domain]");

    images.forEach((image) => {
      const domain = this.normalizeDomain(image.dataset.domain);
      const faviconUrl = image.dataset.faviconUrl;

      if (!domain || !faviconUrl) {
        return;
      }

      const cachedState = this.getCachedFaviconState(domain);
      if (cachedState === "error") {
        image.classList.remove("is-loaded");
        return;
      }

      image.src = faviconUrl;
    });
  }

  readFaviconCache() {
    try {
      const rawCache = window.localStorage.getItem(this.faviconCacheKey);
      return rawCache ? JSON.parse(rawCache) : {};
    } catch {
      return {};
    }
  }

  persistFaviconCache() {
    try {
      window.localStorage.setItem(this.faviconCacheKey, JSON.stringify(this.faviconCache));
    } catch {
      // Ignore storage failures and continue without persistent caching.
    }
  }

  getCachedFaviconState(domain) {
    const cacheEntry = this.faviconCache[domain];
    if (!cacheEntry?.state || !cacheEntry?.updatedAt) {
      return null;
    }

    const now = Date.now();
    const ttl = cacheEntry.state === "ok" ? this.faviconSuccessTtlMs : this.faviconFailureTtlMs;

    if ((now - cacheEntry.updatedAt) > ttl) {
      delete this.faviconCache[domain];
      this.persistFaviconCache();
      return null;
    }

    return cacheEntry.state;
  }

  writeFaviconCacheEntry(domain, state) {
    this.faviconCache[domain] = {
      state,
      updatedAt: Date.now(),
    };
    this.persistFaviconCache();
  }

  normalizeDomain(domain) {
    return (domain || "").trim().toLowerCase();
  }

  updatePinnedCard(card, button, payload) {
    const pinned = Boolean(payload.pinned);
    const pinPosition = payload.pinPosition ?? "";

    card.classList.toggle("is-pinned", pinned);
    card.dataset.pinPosition = pinPosition === null ? "" : String(pinPosition);

    button.classList.toggle("is-active", pinned);
    button.setAttribute("aria-pressed", pinned ? "true" : "false");
    button.title = pinned ? "Retirer des epingles" : "Epingler en haut";
  }

  reorderMineCards() {
    const grid = this.element.querySelector("#grid-mine");
    if (!grid) {
      return;
    }

    const cards = Array.from(grid.querySelectorAll(".cred-card"));
    cards
      .sort((left, right) => this.compareCards(left, right))
      .forEach((card) => grid.appendChild(card));
  }

  compareCards(left, right) {
    const leftPinned = this.readPinPosition(left);
    const rightPinned = this.readPinPosition(right);

    if (leftPinned !== null && rightPinned !== null) {
      return leftPinned - rightPinned;
    }

    if (leftPinned !== null) {
      return -1;
    }

    if (rightPinned !== null) {
      return 1;
    }

    const domainCompare = (left.dataset.domain || "").localeCompare(right.dataset.domain || "");
    if (domainCompare !== 0) {
      return domainCompare;
    }

    return (left.dataset.name || "").localeCompare(right.dataset.name || "");
  }

  readPinPosition(card) {
    const rawValue = card.dataset.pinPosition;
    if (!rawValue) {
      return null;
    }

    const parsedValue = Number.parseInt(rawValue, 10);
    return Number.isNaN(parsedValue) ? null : parsedValue;
  }

  applySearch() {
    const query = this.searchInput?.value.toLowerCase().trim() ?? "";
    const cards = this.element.querySelectorAll(".cred-card");
    let visible = 0;

    cards.forEach((card) => {
      const inFilter = this.activeFilter === "all" || card.dataset.section === this.activeFilter;
      const inSearch = !query
        || (card.dataset.name || "").includes(query)
        || (card.dataset.domain || "").includes(query)
        || (card.dataset.username || "").includes(query);

      const show = inFilter && inSearch;
      card.style.display = show ? "" : "none";
      if (show) visible += 1;
    });

    this.element.querySelectorAll("[id^='section-']").forEach((label) => {
      const section = label.id.replace("section-", "");
      const grid = this.element.querySelector(`#grid-${section}`);
      if (!label) return;

      const anyVisible = Array.from(grid?.querySelectorAll(".cred-card") ?? []).some((card) => card.style.display !== "none");
      label.style.display = anyVisible ? "" : "none";
      if (grid) {
        grid.style.display = anyVisible ? "" : "none";
      }
    });

    if (this.noResults) {
      this.noResults.style.display = visible === 0 ? "block" : "none";
    }
  }
}
