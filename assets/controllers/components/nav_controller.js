import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["menu", "toggle", "overlay"];

  connect() {
    this.handleScroll = this.handleScroll.bind(this);
    this.handleKeydown = this.handleKeydown.bind(this);
    window.addEventListener("scroll", this.handleScroll, { passive: true });
    document.addEventListener("keydown", this.handleKeydown);
    this.handleScroll();
  }

  disconnect() {
    window.removeEventListener("scroll", this.handleScroll);
    document.removeEventListener("keydown", this.handleKeydown);
    document.body.style.overflow = "";
  }

  toggleMenu() {
    this.setOpen(!this.menuTarget.classList.contains("active"));
  }

  closeMenu() {
    this.setOpen(false);
  }

  closeOnLink(event) {
    if (event.target.closest("a")) {
      this.closeMenu();
    }
  }

  smoothScroll(event) {
    const href = event.currentTarget.getAttribute("href");
    if (!href || !href.startsWith("#") || href === "#" || href === "#!") {
      return;
    }

    const target = document.querySelector(href);
    if (!target) {
      return;
    }

    event.preventDefault();
    this.closeMenu();

    const offset = (this.element.offsetHeight || 0) + 20;
    const top = target.getBoundingClientRect().top + window.scrollY - offset;

    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
      window.scrollTo(0, top);
      return;
    }

    window.scrollTo({ top, behavior: "smooth" });
  }

  handleScroll() {
    this.element.classList.toggle("scrolled", window.scrollY > 50);
  }

  handleKeydown(event) {
    if (event.key === "Escape") {
      this.closeMenu();
    }
  }

  setOpen(isOpen) {
    this.menuTarget.classList.toggle("active", isOpen);
    this.overlayTarget.classList.toggle("active", isOpen);
    this.toggleTarget.setAttribute("aria-expanded", String(isOpen));
    document.body.style.overflow = isOpen ? "hidden" : "";
  }
}
