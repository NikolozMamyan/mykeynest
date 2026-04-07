import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["searchInput", "clearButton", "category", "noResults"];

  connect() {
    this.filter();
  }

  filter() {
    const query = this.searchInputTarget.value.toLowerCase().trim();
    let visibleCount = 0;

    this.categoryTargets.forEach((card) => {
      const title = card.querySelector(".hc-cat-title")?.textContent ?? "";
      const haystack = `${card.dataset.tags || ""} ${title}`.toLowerCase();
      const matches = !query || haystack.includes(query);

      card.hidden = !matches;

      if (matches) {
        visibleCount += 1;
      }
    });

    this.noResultsTarget.hidden = !(query && visibleCount === 0);
    this.clearButtonTarget.hidden = query.length === 0;
  }

  clear() {
    this.searchInputTarget.value = "";
    this.filter();
    this.searchInputTarget.focus();
  }
}
