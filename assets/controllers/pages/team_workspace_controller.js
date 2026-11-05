import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["panel", "tab", "search", "row", "empty", "dialog", "favicon"];

  connect() {
    this.element.classList.add("is-enhanced");
    this.activePanel = this.panelTargets[0]?.dataset.panel;
    this.mobile = window.matchMedia("(max-width: 1100px)");
    this.handleResize = () => this.updatePanels();
    this.mobile.addEventListener("change", this.handleResize);
    this.updatePanels();
    const header = document.querySelector(".header_top");
    if (header) {
      this.headerObserver = new ResizeObserver(() => {
        document.body.style.setProperty("--tw-header-height", `${header.getBoundingClientRect().height}px`);
      });
      this.headerObserver.observe(header);
    }
    this.faviconTargets.forEach(image => {
      if (image.complete && image.naturalWidth > 0) image.parentElement.classList.add("has-favicon");
    });
    this.dialogTargets.find(dialog => dialog.dataset.autoOpen === "true")?.showModal();
  }

  disconnect() {
    this.mobile.removeEventListener("change", this.handleResize);
    this.headerObserver?.disconnect();
    document.body.style.removeProperty("--tw-header-height");
    this.dialogTargets.forEach(dialog => { if (dialog.open) dialog.close(); });
  }

  updatePanels() {
    this.panelTargets.forEach(panel => {
      panel.hidden = this.mobile.matches && this.tabTargets.length > 0 && panel.dataset.panel !== this.activePanel;
      if (this.mobile.matches && this.tabTargets.length > 0) panel.setAttribute("role", "tabpanel");
      else panel.removeAttribute("role");
    });
    this.tabTargets.forEach(tab => {
      const active = tab.dataset.panel === this.activePanel;
      tab.setAttribute("aria-selected", String(active));
      tab.tabIndex = active ? 0 : -1;
    });
  }

  switchPanel(event) {
    this.activePanel = event.currentTarget.dataset.panel;
    this.updatePanels();
  }

  tabKeydown(event) {
    const keys = ["ArrowLeft", "ArrowRight", "Home", "End"];
    if (!keys.includes(event.key)) return;
    event.preventDefault();
    const index = this.tabTargets.indexOf(event.currentTarget);
    const next = event.key === "Home" ? 0 : event.key === "End" ? this.tabTargets.length - 1
      : (index + (event.key === "ArrowRight" ? 1 : -1) + this.tabTargets.length) % this.tabTargets.length;
    this.activePanel = this.tabTargets[next].dataset.panel;
    this.updatePanels();
    this.tabTargets[next].focus();
  }

  filter(event) {
    const panel = event.currentTarget.dataset.panel;
    const query = event.currentTarget.value.trim().toLowerCase();
    const rows = this.rowTargets.filter(row => row.dataset.panel === panel);
    rows.forEach(row => { row.hidden = !row.dataset.search.includes(query); });
    this.emptyTargets.filter(empty => empty.dataset.panel === panel).forEach(empty => {
      empty.hidden = !query || rows.some(row => !row.hidden);
    });
  }

  openDialog(event) {
    this.dialogTargets.find(dialog => dialog.id === event.currentTarget.dataset.dialog)?.showModal();
  }

  closeDialog(event) {
    event.currentTarget.closest("dialog").close();
  }

  dismissDialog(event) {
    if (event.target !== event.currentTarget) return;
    const dialog = event.currentTarget;
    const bounds = dialog.getBoundingClientRect();
    if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
  }

  faviconLoaded(event) {
    event.currentTarget.parentElement.classList.add("has-favicon");
  }

  faviconError(event) {
    event.currentTarget.parentElement.classList.remove("has-favicon");
    event.currentTarget.remove();
  }
}
