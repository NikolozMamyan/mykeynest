import { Controller } from "@hotwired/stimulus";

const TYPE_ICONS = {
  info: "fa-circle-info",
  success: "fa-circle-check",
  warning: "fa-triangle-exclamation",
  error: "fa-circle-xmark",
};

export default class extends Controller {
  static targets = [
    "notificationButton", "notificationPanel", "notificationBadge", "notificationCount",
    "notificationList", "notificationFilter", "notificationUnreadTabCount", "markAllReadButton",
    "profileButton", "profileDropdown",
  ];

  static values = { translations: String, userId: Number };

  connect() {
    this.translations = this.parseTranslations();
    this.activeNotificationFilter = "all";
    this.notifications = [];
    this.feedLoadedAt = 0;
    this.summaryLoadedAt = 0;
    this.feedRequest = null;
    this.cacheKey = `mykeynest:notifications:${this.userIdValue}:${document.documentElement.lang || "fr"}`;
    this.handleOutsideClick = this.handleOutsideClick.bind(this);
    this.handleVisibilityChange = this.handleVisibilityChange.bind(this);
    this.handleBeforeVisit = this.handleBeforeVisit.bind(this);
    this.handleTurboLoad = this.handleTurboLoad.bind(this);
    document.addEventListener("click", this.handleOutsideClick);
    document.addEventListener("visibilitychange", this.handleVisibilityChange);
    document.addEventListener("turbo:before-visit", this.handleBeforeVisit);
    document.addEventListener("turbo:load", this.handleTurboLoad);
    window.addEventListener("focus", this.handleVisibilityChange);

    const cached = this.readCache();
    if (cached) {
      this.notifications = cached.notifications;
      this.feedLoadedAt = cached.feedLoadedAt;
      this.summaryLoadedAt = cached.summaryLoadedAt;
      this.updateUnreadCount(cached.unreadCount);
    } else {
      this.updateUnreadCount(0);
    }

    if (window.location.pathname === "/app/notifications") this.syncNotificationCenterVisit();

    this.refreshSummaryIfStale();
  }

  disconnect() {
    document.removeEventListener("click", this.handleOutsideClick);
    document.removeEventListener("visibilitychange", this.handleVisibilityChange);
    document.removeEventListener("turbo:before-visit", this.handleBeforeVisit);
    document.removeEventListener("turbo:load", this.handleTurboLoad);
    window.removeEventListener("focus", this.handleVisibilityChange);
  }

  toggleNotifications(event) {
    event.stopPropagation();
    const isOpen = this.notificationPanelTarget.classList.contains("show");
    this.closeProfile();
    if (isOpen) {
      this.closeNotifications();
      return;
    }

    this.notificationPanelTarget.classList.add("show");
    this.notificationButtonTarget.classList.add("active");
    this.notificationButtonTarget.setAttribute("aria-expanded", "true");
    this.loadNotifications();
  }

  toggleProfile(event) {
    event.stopPropagation();
    const isOpen = this.profileDropdownTarget.classList.contains("show");
    this.closeNotifications();
    if (isOpen) {
      this.closeProfile();
      return;
    }
    this.profileDropdownTarget.classList.add("show");
    this.profileButtonTarget.classList.add("active");
  }

  filterNotifications(event) {
    this.activeNotificationFilter = event.currentTarget.dataset.filter || "all";
    this.notificationFilterTargets.forEach((button) => {
      const active = button === event.currentTarget;
      button.classList.toggle("active", active);
      button.setAttribute("aria-selected", active ? "true" : "false");
    });
    this.renderNotifications();
  }

  async markAllRead(event) {
    event.stopPropagation();
    const button = this.markAllReadButtonTarget;
    const original = button.innerHTML;
    button.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${this.escapeHtml(this.t("notifications.marking", "Marquage..."))}`;
    button.disabled = true;

    try {
      const response = await fetch("/api/notifications/mark-all-read", {
        method: "POST",
        headers: { Accept: "application/json" },
      });
      if (!response.ok) throw new Error("Network error");

      this.notifications = this.notifications.map((notification) => ({ ...notification, isRead: true }));
      this.updateUnreadCount(0);
      this.writeCache();
      this.renderNotifications();
      button.innerHTML = `<i class="fas fa-check"></i> ${this.escapeHtml(this.t("notifications.marked", "Marqué"))}`;
    } catch (error) {
      console.error(error);
      button.innerHTML = `<i class="fas fa-times"></i> ${this.escapeHtml(this.t("notifications.error", "Erreur"))}`;
    } finally {
      window.setTimeout(() => {
        button.innerHTML = original;
        button.disabled = false;
        this.updateMarkAllButton();
      }, 1200);
    }
  }

  async deleteNotification(event) {
    event.stopPropagation();
    event.preventDefault();
    const button = event.currentTarget;
    const id = Number(button.dataset.id);
    if (!id) return;

    const notification = this.notifications.find((item) => Number(item.id) === id);
    button.disabled = true;
    button.classList.add("is-loading");

    try {
      const response = await fetch(`/api/notifications/${id}`, {
        method: "DELETE",
        headers: { Accept: "application/json" },
      });
      if (!response.ok) throw new Error("Network error");

      this.notifications = this.notifications.filter((item) => Number(item.id) !== id);
      if (notification && !notification.isRead) {
        this.updateUnreadCount(Math.max(0, this.unreadCount - 1));
      }
      this.writeCache();
      this.renderNotifications();
    } catch (error) {
      console.error(error);
      button.disabled = false;
      button.classList.remove("is-loading");
    }
  }

  openNotification(event) {
    const id = Number(event.currentTarget.dataset.id);
    const url = this.safeActionUrl(event.currentTarget.dataset.url);
    const notification = this.notifications.find((item) => Number(item.id) === id);

    if (notification && !notification.isRead) {
      notification.isRead = true;
      this.updateUnreadCount(Math.max(0, this.unreadCount - 1));
      this.writeCache();
      fetch(`/api/notifications/${id}/read`, {
        method: "POST",
        headers: { Accept: "application/json" },
        keepalive: true,
      }).catch(() => {});
    }

    if (url && url !== "/app/dashboard") {
      window.location.assign(url);
    } else {
      this.renderNotifications();
    }
  }

  async refreshSummaryIfStale() {
    if (document.visibilityState === "hidden" || Date.now() - this.summaryLoadedAt < 120000) return;

    try {
      const response = await fetch("/api/notifications/summary", { headers: { Accept: "application/json" } });
      if (!response.ok) throw new Error("Network error");
      const data = await response.json();
      const nextUnreadCount = Number(data.count) || 0;
      if (nextUnreadCount !== this.unreadCount) this.feedLoadedAt = 0;
      this.summaryLoadedAt = Date.now();
      this.updateUnreadCount(nextUnreadCount);
      this.writeCache();
    } catch (error) {
      console.error(error);
    }
  }

  async loadNotifications(force = false) {
    if (!force && this.feedLoadedAt > 0 && Date.now() - this.feedLoadedAt < 60000) {
      this.renderNotifications();
      return;
    }
    if (this.feedRequest) return this.feedRequest;

    if (this.notifications.length === 0) {
      this.notificationListTarget.innerHTML = this.loadingMarkup();
    } else {
      this.renderNotifications();
      this.notificationListTarget.classList.add("is-refreshing");
    }

    this.feedRequest = fetch("/api/notifications?limit=12", { headers: { Accept: "application/json" } })
      .then(async (response) => {
        if (!response.ok) throw new Error("Network error");
        const data = await response.json();
        this.notifications = Array.isArray(data.notifications) ? data.notifications : [];
        this.feedLoadedAt = Date.now();
        this.summaryLoadedAt = this.feedLoadedAt;
        this.updateUnreadCount(data.unreadCount ?? 0);
        this.writeCache();
        this.renderNotifications();
      })
      .catch((error) => {
        console.error(error);
        if (this.notifications.length === 0) {
          this.notificationListTarget.innerHTML = this.emptyStateMarkup("fa-triangle-exclamation", this.t("notifications.load_error", "Erreur de chargement"));
        }
      })
      .finally(() => {
        this.notificationListTarget.classList.remove("is-refreshing");
        this.feedRequest = null;
      });

    return this.feedRequest;
  }

  renderNotifications() {
    const notifications = this.activeNotificationFilter === "unread"
      ? this.notifications.filter((notification) => !notification.isRead)
      : this.notifications;

    if (!notifications.length) {
      const message = this.activeNotificationFilter === "unread"
        ? this.t("notifications.empty_unread", "Tout est lu")
        : this.t("notifications.empty", "Aucune notification pour le moment");
      this.notificationListTarget.innerHTML = this.emptyStateMarkup("fa-bell-slash", message);
      return;
    }

    this.notificationListTarget.innerHTML = notifications.map((notification) => {
      const priority = notification.priority && !["low", "normal"].includes(notification.priority)
        ? `<span class="priority-badge ${this.escapeAttribute(notification.priority)}">${this.escapeHtml(this.t(`priority.${notification.priority}`, notification.priority))}</span>`
        : "";
      const customIcon = this.safeIconClass(notification.icon);
      const icon = customIcon || TYPE_ICONS[notification.type] || "fa-bell";

      return `
        <article class="notification-item ${notification.isRead ? "" : "unread"}">
          <div class="notification-icon ${this.escapeAttribute(notification.type || "info")}"><i class="fas ${icon}"></i></div>
          <button type="button" class="notification-main" data-action="click->header#openNotification" data-id="${this.escapeAttribute(notification.id)}" data-url="${this.escapeAttribute(notification.actionUrl || "")}">
            <span class="notification-title-row"><strong class="notification-title">${this.escapeHtml(this.translateMaybe(notification.title))}</strong>${priority}${notification.isRead ? "" : `<span class="notification-unread-dot" title="${this.escapeAttribute(this.t("notifications.new", "Nouveau"))}"></span>`}</span>
            <span class="notification-message">${this.escapeHtml(this.translateMaybe(notification.message))}</span>
            <span class="notification-time"><i class="far fa-clock"></i>${this.escapeHtml(notification.timeAgo || "")}</span>
          </button>
          <button type="button" class="notification-delete" data-id="${this.escapeAttribute(notification.id)}" data-action="click->header#deleteNotification" aria-label="${this.escapeAttribute(this.t("notifications.delete", "Supprimer"))}" title="${this.escapeAttribute(this.t("notifications.delete", "Supprimer"))}"><i class="fas fa-times"></i></button>
        </article>`;
    }).join("");
  }

  updateUnreadCount(value) {
    this.unreadCount = Math.max(0, Number(value) || 0);
    this.notificationBadgeTarget.textContent = this.unreadCount > 0 ? (this.unreadCount > 9 ? "9+" : String(this.unreadCount)) : "";
    this.notificationCountTarget.textContent = String(this.unreadCount);
    if (this.hasNotificationUnreadTabCountTarget) {
      this.notificationUnreadTabCountTarget.textContent = String(this.unreadCount);
    }
    this.updateMarkAllButton();
  }

  updateMarkAllButton() {
    if (this.hasMarkAllReadButtonTarget) {
      this.markAllReadButtonTarget.hidden = this.unreadCount === 0;
    }
  }

  handleVisibilityChange() {
    if (document.visibilityState !== "hidden") this.refreshSummaryIfStale();
  }

  handleTurboLoad() {
    if (window.location.pathname === "/app/notifications") {
      this.syncNotificationCenterVisit();
      return;
    }

    this.handleVisibilityChange();
  }

  syncNotificationCenterVisit() {
    this.notifications = this.notifications.map((notification) => ({ ...notification, isRead: true }));
    this.feedLoadedAt = 0;
    this.summaryLoadedAt = Date.now();
    this.updateUnreadCount(0);
    this.writeCache();
  }

  handleBeforeVisit() {
    this.closeNotifications();
    this.closeProfile();
  }

  closeNotifications() {
    this.notificationPanelTarget.classList.remove("show");
    this.notificationButtonTarget.classList.remove("active");
    this.notificationButtonTarget.setAttribute("aria-expanded", "false");
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

  loadingMarkup() {
    return `<div class="notification-skeleton-list">${Array.from({ length: 3 }, () => '<div class="notification-skeleton"><span></span><div><i></i><i></i><i></i></div></div>').join("")}</div>`;
  }

  emptyStateMarkup(iconClass, text) {
    return `<div class="notification-empty"><div class="notification-empty-icon"><i class="fas ${this.escapeAttribute(iconClass)}"></i></div><div class="notification-empty-text">${this.escapeHtml(text)}</div></div>`;
  }

  readCache() {
    try {
      const cached = JSON.parse(sessionStorage.getItem(this.cacheKey) || "null");
      if (!cached || !Array.isArray(cached.notifications)) return null;
      return {
        notifications: cached.notifications,
        unreadCount: Number(cached.unreadCount) || 0,
        feedLoadedAt: Number(cached.feedLoadedAt) || 0,
        summaryLoadedAt: Number(cached.summaryLoadedAt) || 0,
      };
    } catch {
      return null;
    }
  }

  writeCache() {
    try {
      sessionStorage.setItem(this.cacheKey, JSON.stringify({
        notifications: this.notifications,
        unreadCount: this.unreadCount,
        feedLoadedAt: this.feedLoadedAt,
        summaryLoadedAt: this.summaryLoadedAt,
      }));
    } catch {}
  }

  parseTranslations() {
    if (!this.hasTranslationsValue) return {};
    try {
      return JSON.parse(this.translationsValue);
    } catch {
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

  safeIconClass(value) {
    const icon = String(value || "").trim();
    const glyph = icon.split(/\s+/).find((token) => /^fa-(?!solid$|regular$|brands$)[a-z0-9-]+$/i.test(token));
    if (glyph) return glyph;
    return /^[a-z0-9-]+$/i.test(icon) ? `fa-${icon}` : "";
  }

  safeActionUrl(value) {
    try {
      const url = new URL(String(value || ""), window.location.origin);
      if (url.origin !== window.location.origin || (url.pathname !== "/app" && !url.pathname.startsWith("/app/"))) return "";

      return `${url.pathname}${url.search}${url.hash}`;
    } catch {
      return "";
    }
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
