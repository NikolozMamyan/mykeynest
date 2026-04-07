import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  connect() {
    this.modal = this.element.querySelector("#revoke-modal");
    this.confirmButton = this.element.querySelector("#confirm-revoke");
    this.currentForm = null;
    this.handleKeydown = this.handleKeydown.bind(this);
    document.addEventListener("keydown", this.handleKeydown);
    this.restoreActiveTab();
    this.setupAlerts();
    this.setupCardAnimations();
  }

  disconnect() {
    document.removeEventListener("keydown", this.handleKeydown);
  }

  switchTab(event) {
    const targetTab = event.currentTarget.dataset.tab;
    this.showTab(targetTab);
    window.localStorage.setItem("sharedAccess.activeTab", targetTab);
    window.scrollTo({
      top: 0,
      behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth",
    });
  }

  openRevokeModal(event) {
    this.currentForm = event.currentTarget.closest(".revoke-form");
    if (!this.modal) return;
    this.modal.style.display = "flex";
    document.body.style.overflow = "hidden";
    window.setTimeout(() => this.confirmButton?.focus(), 100);
  }

  closeRevokeModal() {
    if (!this.modal) return;
    this.modal.style.display = "none";
    document.body.style.overflow = "";
    this.currentForm = null;

    if (this.confirmButton) {
      this.confirmButton.disabled = false;
      const defaultText = this.confirmButton.dataset.defaultText;
      if (defaultText) {
        this.confirmButton.innerHTML = `<i class="fas fa-user-slash"></i><span>${defaultText}</span>`;
      }
    }
  }

  confirmRevoke() {
    if (!this.currentForm || !this.confirmButton) return;
    const loadingText = this.modal?.dataset.loadingText || "Loading...";
    this.confirmButton.disabled = true;
    this.confirmButton.innerHTML = `<i class="fas fa-spinner fa-spin"></i><span>${loadingText}</span>`;
    this.currentForm.submit();
  }

  closeAlert(event) {
    const alert = event.currentTarget.closest(".alert");
    if (!alert) return;
    alert.style.animation = "slideInDown 0.3s ease reverse";
    window.setTimeout(() => alert.remove(), 300);
  }

  handleFormSubmit(event) {
    const submitButton = event.currentTarget.querySelector('button[type="submit"]');
    if (submitButton && !submitButton.disabled) {
      submitButton.disabled = true;
      submitButton.classList.add("loading");
    }
  }

  restoreActiveTab() {
    const savedTab = window.localStorage.getItem("sharedAccess.activeTab");
    if (savedTab) {
      this.showTab(savedTab);
    }
  }

  showTab(targetTab) {
    this.element.querySelectorAll(".tab-btn").forEach((button) => {
      button.classList.toggle("active", button.dataset.tab === targetTab);
    });
    this.element.querySelectorAll(".tab-panel").forEach((panel) => {
      panel.classList.toggle("active", panel.id === targetTab);
    });
  }

  setupAlerts() {
    this.element.querySelectorAll(".alert").forEach((alert) => {
      window.setTimeout(() => alert.querySelector(".alert-close")?.click(), 5000);
    });
  }

  setupCardAnimations() {
    if (!("IntersectionObserver" in window)) return;

    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry, index) => {
        if (entry.isIntersecting) {
          window.setTimeout(() => {
            entry.target.style.opacity = "1";
            entry.target.style.transform = "translateY(0)";
          }, index * 50);
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.1, rootMargin: "0px 0px -50px 0px" });

    this.element.querySelectorAll(".access-card").forEach((card) => {
      card.style.opacity = "0";
      card.style.transform = "translateY(20px)";
      card.style.transition = "opacity 0.5s ease, transform 0.5s ease";
      observer.observe(card);
    });
  }

  handleKeydown(event) {
    if (event.key === "Escape" && this.modal?.style.display === "flex") {
      this.closeRevokeModal();
    }
  }
}
