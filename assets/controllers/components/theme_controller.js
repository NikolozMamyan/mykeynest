import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["icon", "option"];

  connect() {
    this.applyTheme(localStorage.getItem("theme") || "light");
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

  applyTheme(theme) {
    const selectedTheme = theme === "dark" ? "dark" : "light";

    document.documentElement.setAttribute("data-theme", selectedTheme);
    localStorage.setItem("theme", selectedTheme);
    document
      .querySelector('meta[name="theme-color"]')
      ?.setAttribute("content", selectedTheme === "dark" ? "#0f172a" : "#f8fafc");

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
