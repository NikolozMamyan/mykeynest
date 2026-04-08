import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  connect() {
    this.timeouts = new Map();
    this.element.querySelectorAll(".toast").forEach((toast) => this.setupToast(toast));
  }

  disconnect() {
    this.timeouts.forEach((timeoutId) => window.clearTimeout(timeoutId));
    this.timeouts.clear();
  }

  close(event) {
    const toast = event.currentTarget.closest(".toast");
    if (toast) {
      this.hideToast(toast);
    }
  }

  pause(event) {
    const timeoutId = this.timeouts.get(event.currentTarget);
    if (timeoutId) {
      window.clearTimeout(timeoutId);
    }
  }

  resume(event) {
    this.scheduleClose(event.currentTarget);
  }

  setupToast(toast) {
    const duration = Number.parseInt(toast.dataset.duration || "4000", 10);
    const bar = toast.querySelector(".toast__bar");

    if (bar) {
      bar.style.animationDuration = `${duration}ms`;
    }

    this.scheduleClose(toast);
  }

  scheduleClose(toast) {
    const duration = Number.parseInt(toast.dataset.duration || "4000", 10);
    const timeoutId = window.setTimeout(() => this.hideToast(toast), duration);
    this.timeouts.set(toast, timeoutId);
  }

  hideToast(toast) {
    const timeoutId = this.timeouts.get(toast);
    if (timeoutId) {
      window.clearTimeout(timeoutId);
      this.timeouts.delete(toast);
    }

    toast.style.animation = "toastOut .18s ease forwards";
    window.setTimeout(() => toast.remove(), 220);
  }
}
