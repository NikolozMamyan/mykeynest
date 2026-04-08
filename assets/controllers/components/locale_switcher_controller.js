import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  switch(event) {
    const locale = event.currentTarget.dataset.lang;

    if (!locale) {
      return;
    }

    const url = new URL(window.location.href);
    url.searchParams.set("lang", locale);
    window.location.href = url.toString();
  }
}
