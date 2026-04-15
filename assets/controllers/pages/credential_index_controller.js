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
  ];

  connect() {
    this.activeFilter = this.element.querySelector(".filter-tab.active")?.dataset.filter ?? "all";
    this.searchInput = this.element.querySelector("#searchInput");
    this.noResults = this.element.querySelector("#noResults");
    this.searchTimer = null;
    this.faviconCache = this.readFaviconCache();
    this.initializeFavicons();
    this.applySearch();
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

    ["mine", "shared"].forEach((section) => {
      const label = this.element.querySelector(`#section-${section}`);
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
