import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["icon", "option"];

  connect() {
    this.themeChangeHandler = (event) => this.syncControls(event.detail.theme);
    this.storageHandler = (event) => {
      if (event.key === "theme" && (event.newValue === "light" || event.newValue === "dark")) {
        this.applyTheme(event.newValue, false);
      }
    };
    window.addEventListener("mykeynest:theme-change", this.themeChangeHandler);
    window.addEventListener("storage", this.storageHandler);
    this.applyTheme(document.documentElement.dataset.theme || "light", false);
  }

  disconnect() {
    window.removeEventListener("mykeynest:theme-change", this.themeChangeHandler);
    window.removeEventListener("storage", this.storageHandler);
  }

  toggle() {
    this.element.classList.add("switching");

    const currentTheme = document.documentElement.getAttribute("data-theme") || "light";
    const nextTheme = currentTheme === "dark" ? "light" : "dark";
    this.applyTheme(nextTheme);

    window.setTimeout(() => {
      this.element.classList.remove("switching");
    }, 500);
  }

  select(event) {
    this.applyTheme(event.currentTarget.dataset.themeValue);
  }

  applyTheme(theme, persist = true) {
    const selectedTheme = theme === "dark" ? "dark" : "light";

    document.documentElement.setAttribute("data-theme", selectedTheme);
    document.documentElement.style.colorScheme = selectedTheme;
    if (persist) {
      try {
        localStorage.setItem("theme", selectedTheme);
      } catch (error) {
        // Le theme reste applique meme si le stockage du navigateur est indisponible.
      }
    }
    document
      .querySelector('meta[name="theme-color"]')
      ?.setAttribute("content", selectedTheme === "dark" ? "#040d0b" : "#f8fafc");

    window.dispatchEvent(new CustomEvent("mykeynest:theme-change", {
      detail: { theme: selectedTheme },
    }));

    this.syncControls(selectedTheme);
  }

  syncControls(selectedTheme) {
    if (this.hasIconTarget) {
      this.iconTargets.forEach((icon) => {
        icon.classList.toggle("fa-sun", selectedTheme === "light");
        icon.classList.toggle("fa-moon", selectedTheme === "dark");
      });
    }

    if (this.hasOptionTarget) {
      this.optionTargets.forEach((option) => {
        const isSelected = option.dataset.themeValue === selectedTheme;
        option.classList.toggle("is-active", isSelected);
        option.setAttribute("aria-pressed", isSelected ? "true" : "false");
      });
    }
  }
}
