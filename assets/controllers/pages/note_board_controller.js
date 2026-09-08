import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = [
    "search",
    "filter",
    "sort",
    "card",
    "column",
    "columnCount",
    "noResults",
    "createModal",
    "detailsModal",
    "detailsTitle",
    "detailsContent",
    "detailsStatus",
    "detailsDue",
    "detailsOwner",
    "copyFeedback",
    "copyButton",
    "editButton",
    "editForm",
    "editTitle",
    "editContent",
    "editDue",
    "editFeedback",
    "saveButton",
  ];

  static values = {
    openCreate: Boolean,
    copySuccess: String,
    copyError: String,
    saving: String,
    saveError: String,
  };

  connect() {
    this.storageKey = `mykeynest:notes:${window.location.pathname}`;
    this.activeFilter = "all";
    this.restorePreferences();
    this.applyFilters();

    if (this.openCreateValue && this.hasCreateModalTarget) {
      this.createModalTarget.showModal();
    }

    this.handleShortcut = this.handleShortcut.bind(this);
    window.addEventListener("keydown", this.handleShortcut);
  }

  disconnect() {
    window.removeEventListener("keydown", this.handleShortcut);
  }

  search() {
    this.applyFilters();
  }

  setFilter(event) {
    this.activeFilter = event.currentTarget.dataset.filter || "all";
    this.filterTargets.forEach((button) => {
      const active = button.dataset.filter === this.activeFilter;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-pressed", active ? "true" : "false");
    });
    this.savePreferences();
    this.applyFilters();
  }

  sort() {
    this.sortColumns();
    this.savePreferences();
  }

  navigateWorkspace(event) {
    if (event.currentTarget.value) window.location.assign(event.currentTarget.value);
  }

  submitSelect(event) {
    event.currentTarget.form?.requestSubmit();
  }

  confirmDelete(event) {
    if (!window.confirm(event.currentTarget.dataset.confirm || "")) event.preventDefault();
  }

  openCreate() {
    if (this.hasCreateModalTarget && !this.createModalTarget.open) this.createModalTarget.showModal();
  }

  closeCreate() {
    if (this.hasCreateModalTarget) this.createModalTarget.close();
  }

  openDetails(event) {
    if (!this.hasDetailsModalTarget) return;
    const card = event.currentTarget.closest(".note-card");
    if (!card) return;

    this.currentDetailsContent = card.dataset.noteContent?.trim() || "";
    this.currentCard = card;
    this.detailsTitleTarget.textContent = card.dataset.noteTitle || "";
    this.detailsContentTarget.textContent = this.currentDetailsContent || card.dataset.emptyContent || "";
    this.detailsContentTarget.classList.toggle("is-empty", !this.currentDetailsContent);
    this.detailsStatusTarget.textContent = card.dataset.noteStatusLabel || "";
    this.detailsDueTarget.textContent = card.dataset.noteDueLabel || "";
    this.detailsOwnerTarget.textContent = card.dataset.noteOwner || "";
    this.copyFeedbackTarget.textContent = "";
    this.copyButtonTarget.disabled = !this.currentDetailsContent;
    this.editButtonTarget.hidden = card.dataset.noteCanEdit !== "1";
    this.editTitleTarget.value = card.dataset.noteTitle || "";
    this.editContentTarget.value = card.dataset.noteContent || "";
    this.editDueTarget.value = card.dataset.noteDueInput || "";
    this.setEditing(false);
    this.detailsModalTarget.showModal();
  }

  closeDetails() {
    if (this.hasDetailsModalTarget) {
      this.setEditing(false);
      this.detailsModalTarget.close();
    }
  }

  closeOnBackdrop(event) {
    if (event.target !== event.currentTarget) return;
    event.currentTarget.close();
  }

  async copyDetails() {
    const content = this.currentDetailsContent;
    if (!content) return;

    try {
      await navigator.clipboard.writeText(content);
      this.copyFeedbackTarget.textContent = this.copySuccessValue;
    } catch {
      this.copyFeedbackTarget.textContent = this.copyErrorValue;
    }
  }

  enterEdit() {
    if (this.currentCard?.dataset.noteCanEdit !== "1") return;
    this.setEditing(true);
    this.editTitleTarget.focus();
    this.editTitleTarget.select();
  }

  cancelEdit() {
    if (!this.currentCard) return;
    this.editTitleTarget.value = this.currentCard.dataset.noteTitle || "";
    this.editContentTarget.value = this.currentCard.dataset.noteContent || "";
    this.editDueTarget.value = this.currentCard.dataset.noteDueInput || "";
    this.editFeedbackTarget.textContent = "";
    this.setEditing(false);
  }

  submitEdit() {
    this.editFormTarget.requestSubmit();
  }

  async saveDetails(event) {
    event.preventDefault();
    if (!this.currentCard?.dataset.noteUpdateUrl || !this.editFormTarget.reportValidity()) return;

    this.saveButtonTarget.disabled = true;
    this.editFeedbackTarget.textContent = this.savingValue;

    try {
      const response = await fetch(this.currentCard.dataset.noteUpdateUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
          "X-Requested-With": "XMLHttpRequest",
          Accept: "application/json",
        },
        body: new URLSearchParams({
          _token: this.currentCard.dataset.noteUpdateToken || "",
          field: "all",
          title: this.editTitleTarget.value,
          content: this.editContentTarget.value,
          dueAt: this.editDueTarget.value,
        }),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.success !== true) throw new Error(data.error || this.saveErrorValue);
      window.location.reload();
    } catch (error) {
      this.editFeedbackTarget.textContent = error.message || this.saveErrorValue;
      this.saveButtonTarget.disabled = false;
    }
  }

  setEditing(editing) {
    if (!this.hasDetailsModalTarget) return;
    const view = this.detailsModalTarget.querySelector(".notes-dialog__view");
    const viewActions = this.detailsModalTarget.querySelector(".notes-dialog__view-actions");
    const editActions = this.detailsModalTarget.querySelector(".notes-dialog__edit-actions");
    if (view) view.hidden = editing;
    if (viewActions) viewActions.hidden = editing;
    if (editActions) editActions.hidden = !editing;
    if (this.hasEditFormTarget) this.editFormTarget.hidden = !editing;
    if (this.hasEditButtonTarget) this.editButtonTarget.hidden = editing || this.currentCard?.dataset.noteCanEdit !== "1";
    this.detailsModalTarget.classList.toggle("is-editing", editing);
  }

  resetFilters() {
    this.searchTarget.value = "";
    this.activeFilter = "all";
    this.filterTargets.forEach((button) => {
      const active = button.dataset.filter === "all";
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-pressed", active ? "true" : "false");
    });
    this.applyFilters();
  }

  applyFilters() {
    const query = this.normalize(this.searchTarget.value);
    const now = Math.floor(Date.now() / 1000);
    const nextWeek = now + (7 * 24 * 60 * 60);
    let visibleTotal = 0;

    this.cardTargets.forEach((card) => {
      const due = Number(card.dataset.due || 0);
      const matchesSearch = !query || this.normalize(card.dataset.search || "").includes(query);
      const matchesFilter = this.activeFilter === "all"
        || (this.activeFilter === "assigned" && card.dataset.assigned === "1")
        || (this.activeFilter === "overdue" && card.dataset.status !== "done" && due > 0 && due < now)
        || (this.activeFilter === "soon" && card.dataset.status !== "done" && due >= now && due <= nextWeek);

      card.hidden = !(matchesSearch && matchesFilter);
      if (!card.hidden) visibleTotal += 1;
    });

    this.sortColumns();
    this.updateColumnCounts();
    if (this.hasNoResultsTarget) this.noResultsTarget.hidden = visibleTotal !== 0;
  }

  sortColumns() {
    const mode = this.sortTarget.value;
    this.columnTargets.forEach((column) => {
      const body = column.querySelector(".notes-column__body");
      if (!body) return;
      const cards = [...body.querySelectorAll(".note-card")];
      cards.sort((a, b) => this.compareCards(a, b, mode));
      cards.forEach((card) => body.insertBefore(card, body.querySelector(".notes-column-empty")));
    });
  }

  compareCards(a, b, mode) {
    if (mode === "title") {
      return (a.dataset.title || "").localeCompare(b.dataset.title || "", document.documentElement.lang, { sensitivity: "base" });
    }

    if (mode === "due") {
      const aDue = Number(a.dataset.due || Number.MAX_SAFE_INTEGER);
      const bDue = Number(b.dataset.due || Number.MAX_SAFE_INTEGER);
      return aDue - bDue;
    }

    return Number(b.dataset.updated || 0) - Number(a.dataset.updated || 0);
  }

  updateColumnCounts() {
    this.columnTargets.forEach((column, index) => {
      const visible = column.querySelectorAll(".note-card:not([hidden])").length;
      column.classList.toggle("has-visible-cards", visible > 0);
      if (this.columnCountTargets[index]) this.columnCountTargets[index].textContent = String(visible);
    });
  }

  handleShortcut(event) {
    if (event.ctrlKey || event.metaKey || event.altKey || this.element.querySelector("dialog[open]")) return;
    const tag = event.target?.tagName?.toLowerCase();
    const isTyping = tag === "input" || tag === "textarea" || tag === "select" || event.target?.isContentEditable;

    if (event.key === "/" && !isTyping) {
      event.preventDefault();
      this.searchTarget.focus();
    }

    if (event.key.toLowerCase() === "n" && !isTyping && this.hasCreateModalTarget) {
      event.preventDefault();
      this.openCreate();
    }
  }

  restorePreferences() {
    try {
      const stored = JSON.parse(localStorage.getItem(this.storageKey) || "{}");
      if (["recent", "due", "title"].includes(stored.sort)) this.sortTarget.value = stored.sort;
    } catch {
      // Ignore invalid local preferences.
    }
  }

  savePreferences() {
    try {
      localStorage.setItem(this.storageKey, JSON.stringify({ sort: this.sortTarget.value }));
    } catch {
      // Preferences are optional when storage is unavailable.
    }
  }

  normalize(value) {
    return String(value || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim();
  }
}
