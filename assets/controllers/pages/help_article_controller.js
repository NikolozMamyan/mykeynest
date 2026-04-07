import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  connect() {
    this.progressBar = this.element.querySelector("#artProgress");
    this.content = this.element.querySelector("#artContent");
    this.card = this.element.querySelector("#artHelpfulCard");
    this.copyButton = this.element.querySelector("#artShareCopy");
    this.tocLinks = Array.from(this.element.querySelectorAll(".art-toc-link"));
    this.sections = Array.from(this.element.querySelectorAll(".art-section"));
    this.handleScroll = this.handleScroll.bind(this);
    window.addEventListener("scroll", this.handleScroll, { passive: true });
    this.restoreVote();
    this.observeSections();
    this.handleScroll();
  }

  disconnect() {
    window.removeEventListener("scroll", this.handleScroll);
    this.sectionObserver?.disconnect();
  }

  voteYes() {
    this.vote("yes");
  }

  voteNo() {
    this.vote("no");
  }

  copyLink() {
    if (!this.copyButton) return;

    const markCopied = () => {
      const original = this.copyButton.innerHTML;
      this.copyButton.innerHTML = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Copie !';
      this.copyButton.classList.add("is-copied");
      window.setTimeout(() => {
        this.copyButton.innerHTML = original;
        this.copyButton.classList.remove("is-copied");
      }, 2000);
    };

    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(window.location.href).then(markCopied);
      return;
    }

    markCopied();
  }

  smoothScroll(event) {
    event.preventDefault();
    const href = event.currentTarget.getAttribute("href");
    const target = href ? this.element.querySelector(href) : null;
    target?.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  handleScroll() {
    if (!this.progressBar || !this.content) return;

    const rect = this.content.getBoundingClientRect();
    const total = this.content.offsetHeight - window.innerHeight;
    const scrolled = -rect.top;
    const progress = total > 0 ? Math.min(100, Math.max(0, (scrolled / total) * 100)) : 0;

    this.progressBar.style.width = `${progress}%`;
    this.progressBar.setAttribute("aria-valuenow", String(Math.round(progress)));
  }

  observeSections() {
    if (!this.sections.length || !this.tocLinks.length || !("IntersectionObserver" in window)) return;

    this.sectionObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          this.tocLinks.forEach((link) => link.classList.remove("active"));
          this.element.querySelector(`[data-toc="${entry.target.id}"]`)?.classList.add("active");
        }
      });
    }, { rootMargin: "-20% 0px -70% 0px" });

    this.sections.forEach((section) => this.sectionObserver.observe(section));
  }

  restoreVote() {
    const slug = this.card?.dataset.article;
    if (!slug) return;

    const previousVote = window.localStorage.getItem(`helpful_${slug}`);
    if (previousVote === "yes") this.markVoteState("yes");
    if (previousVote === "no") this.markVoteState("no");
  }

  vote(type) {
    const slug = this.card?.dataset.article;
    if (!slug || window.localStorage.getItem(`helpful_${slug}`)) return;

    const countNode = this.element.querySelector(type === "yes" ? "#countYes" : "#countNo");
    const thankNode = this.element.querySelector("#helpfulThank");
    const count = Number.parseInt(countNode?.textContent || "0", 10);

    if (countNode) {
      countNode.textContent = String(count + 1);
    }

    this.markVoteState(type);

    if (thankNode) {
      thankNode.textContent = type === "yes"
        ? "Merci pour votre retour ! Nous sommes ravis que cet article vous ait aide."
        : "Merci ! Nous allons ameliorer cet article. Besoin d'aide supplementaire ? Contactez-nous.";
      thankNode.style.display = "block";
    }

    window.localStorage.setItem(`helpful_${slug}`, type);
  }

  markVoteState(type) {
    const yesButton = this.element.querySelector("#btnHelpYes");
    const noButton = this.element.querySelector("#btnHelpNo");

    if (type === "yes") {
      yesButton?.classList.add("voted-yes");
      noButton?.classList.add("is-muted");
    } else {
      noButton?.classList.add("voted-no");
      yesButton?.classList.add("is-muted");
    }
  }
}
