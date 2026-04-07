import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["item", "button"];

  connect() {
    this.closeAll();
  }

  toggle(event) {
    const button = event.currentTarget;
    const item = button.closest('[data-faq-accordion-target="item"]');
    const isOpen = item?.classList.contains("open");

    this.closeAll();

    if (!isOpen && item) {
      item.classList.add("open");
      button.setAttribute("aria-expanded", "true");
    }
  }

  closeAll() {
    this.itemTargets.forEach((item) => {
      item.classList.remove("open");
      const button = item.querySelector('[data-faq-accordion-target="button"]');
      if (button) {
        button.setAttribute("aria-expanded", "false");
      }
    });
  }
}
