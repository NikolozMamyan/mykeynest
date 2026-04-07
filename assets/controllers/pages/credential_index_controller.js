import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  connect() {
    this.activeFilter = this.element.querySelector(".filter-tab.active")?.dataset.filter ?? "all";
    this.searchInput = this.element.querySelector("#searchInput");
    this.noResults = this.element.querySelector("#noResults");
    this.searchTimer = null;
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
    image.hidden = true;
    const fallback = image.nextElementSibling;
    if (fallback) {
      fallback.style.display = "flex";
    }
  }

  confirmDelete(event) {
    const message = event.currentTarget.dataset.confirmMessage;
    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
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
