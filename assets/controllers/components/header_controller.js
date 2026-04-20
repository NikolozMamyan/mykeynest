import { Controller } from "@hotwired/stimulus";

const TYPE_ICONS = {
  info: "fa-info-circle",
  success: "fa-check-circle",
  warning: "fa-exclamation-triangle",
  error: "fa-times-circle",
};

export default class extends Controller {
  static targets = ["notificationButton", "notificationPanel", "notificationBadge", "notificationCount", "notificationList", "markAllReadButton", "profileButton", "profileDropdown"];
  static values = { translations: String };

  connect() {
    this.translations = this.parseTranslations();
    this.handleOutsideClick = this.handleOutsideClick.bind(this);
    document.addEventListener("click", this.handleOutsideClick);
    this.loadNotificationsCount();
    this.pollingId = window.setInterval(() => this.loadNotificationsCount(), 30000);
  }

  disconnect() {
    document.removeEventListener("click", this.handleOutsideClick);
    if (this.pollingId) window.clearInterval(this.pollingId);
  }

  async toggleNotifications(event) {
    event.stopPropagation();
    const isOpen = this.notificationPanelTarget.classList.contains("show");
    this.closeProfile();
    if (isOpen) return this.closeNotifications();
    await this.loadNotifications();
    this.notificationPanelTarget.classList.add("show");
    this.notificationButtonTarget.classList.add("active");
  }

  toggleProfile(event) {
    event.stopPropagation();
    const isOpen = this.profileDropdownTarget.classList.contains("show");
    this.closeNotifications();
    if (isOpen) return this.closeProfile();
    this.profileDropdownTarget.classList.add("show");
    this.profileButtonTarget.classList.add("active");
  }

  async markAllRead(event) {
    event.stopPropagation();
    const button = this.markAllReadButtonTarget;
    const original = button.innerHTML;
    button.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${this.t("notifications.marking", "Marquage...")}`;
    button.disabled = true;

    try {
      await fetch("/api/notifications/mark-all-read", { method: "POST" });
      await this.loadNotificationsCount();
      await this.loadNotifications();
      button.innerHTML = `<i class="fas fa-check"></i> ${this.t("notifications.marked", "Marque !")}`;
    } catch (error) {
      console.error(error);
      button.innerHTML = `<i class="fas fa-times"></i> ${this.t("notifications.error", "Erreur")}`;
    } finally {
      window.setTimeout(() => {
        button.innerHTML = original;
        button.disabled = false;
      }, 1500);
    }
  }

  async deleteNotification(event) {
    event.stopPropagation();
    event.preventDefault();

    const button = event.currentTarget;
    const { id } = button.dataset;
    if (!id) return;

    const original = button.innerHTML;
    button.disabled = true;
    button.classList.add("is-loading");
    button.innerHTML = `<i class="fas fa-spinner fa-spin"></i>`;

    try {
      const response = await fetch(`/api/notifications/${id}`, { method: "DELETE" });
      if (!response.ok) throw new Error("Erreur reseau");

      await this.loadNotificationsCount();
      await this.loadNotifications();
    } catch (error) {
      console.error(error);
      button.disabled = false;
      button.classList.remove("is-loading");
      button.innerHTML = `<i class="fas fa-times"></i>`;

      window.setTimeout(() => {
        button.innerHTML = original;
      }, 1200);
    }
  }

  openNotification(event) {
    const { url } = event.currentTarget.dataset;
    if (url && url !== "/app/dashboard") window.location.href = url;
  }

  async loadNotificationsCount() {
    try {
      const response = await fetch("/api/notifications/length");
      if (!response.ok) throw new Error("Erreur reseau");
      const data = await response.json();
      const count = data.count ?? 0;
      this.notificationBadgeTarget.textContent = count > 0 ? (count > 9 ? "9+" : String(count)) : "";
      this.notificationCountTarget.textContent = String(count);
    } catch (error) {
      console.error(error);
    }
  }

  async loadNotifications() {
    this.notificationListTarget.innerHTML = this.emptyStateMarkup("fa-circle-notch fa-spin", this.t("notifications.loading_list", "Chargement des notifications..."));

    try {
      const response = await fetch("/api/notifications");
      if (!response.ok) throw new Error("Erreur reseau");
      const data = await response.json();
      this.renderNotifications(data.notifications ?? []);
    } catch (error) {
      console.error(error);
      this.notificationListTarget.innerHTML = this.emptyStateMarkup("fa-exclamation-triangle", this.t("notifications.load_error", "Erreur de chargement"));
    }
  }

  renderNotifications(notifications) {
    if (!notifications.length) {
      this.notificationListTarget.innerHTML = this.emptyStateMarkup("fa-bell-slash", this.t("notifications.empty", "Aucune notification pour le moment"));
      return;
    }

    this.notificationListTarget.innerHTML = notifications.map((notification) => {
      const priority = notification.priority && notification.priority !== "low"
        ? `<span class="priority-badge ${notification.priority}">${this.escapeHtml(this.t(`priority.${notification.priority}`, notification.priority))}</span>`
        : "";

      return `
        <div class="notification-item ${notification.isRead ? "" : "unread"}">
          <div class="notification-icon ${notification.type}">
            <i class="fas ${TYPE_ICONS[notification.type] || "fa-bell"}"></i>
          </div>
          <button
            type="button"
            class="notification-main"
            data-action="click->header#openNotification"
            data-url="${this.escapeAttribute(notification.actionUrl || "")}"
          >
            <div class="notification-content">
              <div class="notification-title">
                ${this.escapeHtml(this.translateMaybe(notification.title))}
                ${priority}
                ${notification.icon ? `<i class="fas ${this.escapeAttribute(notification.icon)}"></i>` : ""}
              </div>
              <div class="notification-message">${this.escapeHtml(this.translateMaybe(notification.message))}</div>
              <div class="notification-time">
                <i class="fas fa-clock"></i>
                ${this.escapeHtml(notification.timeAgo || "")}
              </div>
            </div>
          </button>
          <button
            type="button"
            class="notification-delete"
            data-id="${this.escapeAttribute(notification.id)}"
            data-action="click->header#deleteNotification"
            aria-label="${this.escapeAttribute(this.t("notifications.delete", "Supprimer"))}"
            title="${this.escapeAttribute(this.t("notifications.delete", "Supprimer"))}"
          >
            <i class="fas fa-times"></i>
          </button>
        </div>
      `;
    }).join("");
  }

  closeNotifications() {
    this.notificationPanelTarget.classList.remove("show");
    this.notificationButtonTarget.classList.remove("active");
  }

  closeProfile() {
    this.profileDropdownTarget.classList.remove("show");
    this.profileButtonTarget.classList.remove("active");
  }

  handleOutsideClick(event) {
    if (!this.element.contains(event.target)) {
      this.closeNotifications();
      this.closeProfile();
    }
  }

  emptyStateMarkup(iconClass, text) {
    return `<div class="notification-empty"><div class="notification-empty-icon"><i class="fas ${iconClass}"></i></div><div class="notification-empty-text">${this.escapeHtml(text)}</div></div>`;
  }

  parseTranslations() {
    if (!this.hasTranslationsValue) return {};
    try {
      return JSON.parse(this.translationsValue);
    } catch (error) {
      console.error(error);
      return {};
    }
  }

  translateMaybe(value) {
    if (typeof value !== "string" || !value.includes(".") || !/^[a-z0-9_.-]+$/i.test(value)) return value || "";
    return this.t(value, value);
  }

  t(path, fallback = "") {
    return path.split(".").reduce((carry, key) => (carry && typeof carry === "object" ? carry[key] : undefined), this.translations) ?? fallback;
  }

  escapeHtml(value) {
    const div = document.createElement("div");
    div.textContent = value ?? "";
    return div.innerHTML;
  }

  escapeAttribute(value) {
    return String(value ?? "").replaceAll("&", "&amp;").replaceAll('"', "&quot;").replaceAll("<", "&lt;").replaceAll(">", "&gt;");
  }
}
