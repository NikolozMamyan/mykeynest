import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  connect() {
    this.modal = this.element.querySelector("#credentialsModal");
    this.searchInput = this.element.querySelector("#credentialsSearch");
    this.clearButton = this.element.querySelector("#clearCredentialsSearch");
    this.counter = this.element.querySelector("#credentialsCounter");
    this.emptyState = this.element.querySelector("#credentialsEmpty");
    this.handleKeydown = this.handleKeydown.bind(this);
    document.addEventListener("keydown", this.handleKeydown);
    this.syncCredentialsUI();
  }

  disconnect() {
    document.removeEventListener("keydown", this.handleKeydown);
  }

  openCredentialsModal() {
    if (!this.modal) return;
    this.modal.classList.add("active");
    this.modal.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";
    this.searchInput?.focus();
    this.syncCredentialsUI();
  }

  closeCredentialsModal() {
    if (!this.modal) return;
    this.modal.classList.remove("active");
    this.modal.setAttribute("aria-hidden", "true");
    document.body.style.overflow = "";
  }

  searchCredentials() {
    const query = (this.searchInput?.value || "").trim().toLowerCase();
    this.credentialItems().forEach((item) => {
      item.style.display = (item.dataset.label || "").includes(query) ? "" : "none";
    });
    if (this.clearButton) {
      this.clearButton.hidden = query.length === 0;
    }
    this.syncCredentialsUI();
  }

  clearSearch() {
    if (!this.searchInput) return;
    this.searchInput.value = "";
    this.searchCredentials();
    this.searchInput.focus();
  }

  selectAll() {
    this.credentialItems().forEach((item) => {
      if (item.style.display === "none") return;
      const checkbox = item.querySelector('input[type="checkbox"]');
      if (checkbox) checkbox.checked = true;
    });
    this.syncCredentialsUI();
  }

  unselectAll() {
    this.credentialItems().forEach((item) => {
      if (item.style.display === "none") return;
      const checkbox = item.querySelector('input[type="checkbox"]');
      if (checkbox) checkbox.checked = false;
    });
    this.syncCredentialsUI();
  }

  syncSelection() {
    this.syncCredentialsUI();
  }

  confirmDeleteTeam() {
    const form = this.element.querySelector("#deleteTeamForm");
    if (!form) {
      window.alert("Erreur: formulaire de suppression introuvable.");
      return;
    }
    if (window.confirm("Are you sure you want to delete this team? This action is irreversible and will also remove all members.")) {
      form.submit();
    }
  }

  confirmForm(event) {
    const message = event.currentTarget.dataset.confirmMessage;
    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  }

  credentialItems() {
    return Array.from(this.element.querySelectorAll("#credentialsUl .credential-li"));
  }

  syncCredentialsUI() {
    const items = this.credentialItems();
    let checked = 0;
    let visible = 0;

    items.forEach((item) => {
      const checkbox = item.querySelector('input[type="checkbox"]');
      const option = item.querySelector(".credential-option");
      const isVisible = item.style.display !== "none";

      if (checkbox && option) {
        option.classList.toggle("is-checked", checkbox.checked);
        if (checkbox.checked) checked += 1;
        if (isVisible) visible += 1;
      }
    });

    if (this.counter) {
      this.counter.textContent = `${checked} / ${items.length}`;
    }
    if (this.emptyState) {
      this.emptyState.hidden = visible !== 0 || items.length === 0;
    }
  }

  handleKeydown(event) {
    if (event.key === "Escape") {
      this.closeCredentialsModal();
    }
  }
}
