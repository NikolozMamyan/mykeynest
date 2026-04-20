import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["icon"];

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

  applyTheme(theme) {
    document.documentElement.setAttribute("data-theme", theme);
    localStorage.setItem("theme", theme);
    document
      .querySelector('meta[name="theme-color"]')
      ?.setAttribute("content", theme === "dark" ? "#0f172a" : "#f8fafc");

    if (!this.hasIconTarget) {
      return;
    }

    this.iconTarget.classList.toggle("fa-sun", theme === "light");
    this.iconTarget.classList.toggle("fa-moon", theme === "dark");
  }
}
