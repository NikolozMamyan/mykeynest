import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["banner", "text", "installButton"];

  connect() {
    this.dismissStorageKey = "pwa-install:dismissed";
    this.deferredPrompt = null;

    this.applyStandaloneState();
    this.registerServiceWorker();

    window.addEventListener("beforeinstallprompt", this.handleBeforeInstallPrompt);
    window.addEventListener("appinstalled", this.handleAppInstalled);

    if (this.shouldShowIosInstructions()) {
      this.showBanner("ios");
    }
  }

  disconnect() {
    window.removeEventListener("beforeinstallprompt", this.handleBeforeInstallPrompt);
    window.removeEventListener("appinstalled", this.handleAppInstalled);
  }

  handleBeforeInstallPrompt = (event) => {
    event.preventDefault();
    this.deferredPrompt = event;
    this.showBanner("android");
  };

  handleAppInstalled = () => {
    this.deferredPrompt = null;
    this.hideBanner();
    this.applyStandaloneState();
  };

  async install() {
    if (!this.deferredPrompt) {
      return;
    }

    this.installButtonTarget.disabled = true;

    try {
      await this.deferredPrompt.prompt();
      await this.deferredPrompt.userChoice;
    } finally {
      this.deferredPrompt = null;
      this.installButtonTarget.disabled = false;
      this.hideBanner();
    }
  }

  dismiss() {
    try {
      window.localStorage.setItem(this.dismissStorageKey, "1");
    } catch {
      // Ignore storage errors.
    }

    this.hideBanner();
  }

  showBanner(mode) {
    if (!this.hasBannerTarget || !this.hasTextTarget || !this.hasInstallButtonTarget) {
      return;
    }

    if (this.isStandalone() || this.isDismissed()) {
      return;
    }

    this.textTarget.textContent = this.getBannerText(mode);
    this.installButtonTarget.hidden = mode !== "android";
    this.bannerTarget.hidden = false;
  }

  getBannerText(mode) {
    const { iosText, androidText } = this.bannerTarget.dataset;

    if (mode === "ios") {
      return iosText || "Add MYKEYNEST from Share, then Add to Home Screen.";
    }

    return androidText || "Install MYKEYNEST on your phone for quicker access.";
  }

  hideBanner() {
    if (this.hasBannerTarget) {
      this.bannerTarget.hidden = true;
    }
  }

  applyStandaloneState() {
    document.documentElement.classList.toggle("is-standalone-app", this.isStandalone());
  }

  isStandalone() {
    return window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;
  }

  shouldShowIosInstructions() {
    const userAgent = window.navigator.userAgent || "";
    const isIos = /iPad|iPhone|iPod/.test(userAgent);
    return isIos && !this.isStandalone() && !this.isDismissed();
  }

  isDismissed() {
    try {
      return window.localStorage.getItem(this.dismissStorageKey) === "1";
    } catch {
      return false;
    }
  }

  async registerServiceWorker() {
    if (!("serviceWorker" in navigator) || !window.isSecureContext) {
      return;
    }

    try {
      await navigator.serviceWorker.register("/sw.js", { scope: "/" });
    } catch (error) {
      console.error("Service worker registration failed", error);
    }
  }
}
