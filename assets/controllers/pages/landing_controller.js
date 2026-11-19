import { Controller } from '@hotwired/stimulus';

const CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%&*?';

export default class extends Controller {
  static targets = ['stepTab', 'stepPanel', 'caseTab', 'casePanel', 'genOutput', 'flow'];

  connect() {
    this.reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    this.element.classList.add('is-enhanced');
    this.observeReveal();
    this.observeFlow();
    this.startGenerator();
  }

  disconnect() {
    this.revealObserver?.disconnect();
    this.flowObserver?.disconnect();
    clearInterval(this.genTimer);
    clearInterval(this.scrambleTimer);
  }

  observeReveal() {
    const items = this.element.querySelectorAll('[data-reveal]');
    if (this.reduced || !('IntersectionObserver' in window)) {
      items.forEach((el) => el.classList.add('is-in'));
      return;
    }
    this.revealObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-in');
        this.revealObserver.unobserve(entry.target);
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    items.forEach((el) => this.revealObserver.observe(el));
  }

  // ---- How it works stepper ----
  observeFlow() {
    if (!this.hasFlowTarget) return;
    this.flowTarget.classList.add('is-paused');
    if (!('IntersectionObserver' in window)) return;
    this.flowObserver = new IntersectionObserver(([entry]) => {
      this.flowVisible = entry.isIntersecting;
      this.flowTarget.classList.toggle('is-paused', !entry.isIntersecting || this.flowHover);
    }, { threshold: 0.35 });
    this.flowObserver.observe(this.flowTarget);
  }

  pauseFlow() {
    this.flowHover = true;
    this.flowTarget.classList.add('is-paused');
  }

  resumeFlow() {
    this.flowHover = false;
    if (this.flowVisible) this.flowTarget.classList.remove('is-paused');
  }

  selectStep(event) {
    this.activate(this.stepTabTargets, this.stepPanelTargets, Number(event.currentTarget.dataset.index));
  }

  nextStep() {
    const current = this.stepTabTargets.findIndex((tab) => tab.getAttribute('aria-selected') === 'true');
    this.activate(this.stepTabTargets, this.stepPanelTargets, (current + 1) % this.stepTabTargets.length);
  }

  // ---- Use cases ----
  selectCase(event) {
    this.activate(this.caseTabTargets, this.casePanelTargets, Number(event.currentTarget.dataset.index));
  }

  tabKeys(event) {
    if (!['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp'].includes(event.key)) return;
    const tabs = [...event.currentTarget.querySelectorAll('[role="tab"]')];
    const index = tabs.indexOf(document.activeElement);
    if (index < 0) return;
    event.preventDefault();
    const step = ['ArrowRight', 'ArrowDown'].includes(event.key) ? 1 : -1;
    const next = tabs[(index + step + tabs.length) % tabs.length];
    next.click();
    next.focus();
  }

  activate(tabs, panels, index) {
    tabs.forEach((tab, i) => {
      const active = i === index;
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
      tab.tabIndex = active ? 0 : -1;
      // Restart the progress animation on the newly active tab
      if (active) {
        const bar = tab.querySelector('.flow-progress i');
        if (bar) { bar.style.animation = 'none'; void bar.offsetWidth; bar.style.animation = ''; }
      }
    });
    panels.forEach((panel, i) => { panel.hidden = i !== index; });
  }

  // ---- Password generator demo ----
  startGenerator() {
    if (!this.hasGenOutputTarget || this.reduced) return;
    this.genTimer = setInterval(() => {
      if (document.hidden) return;
      const rect = this.genOutputTarget.getBoundingClientRect();
      if (rect.bottom < 0 || rect.top > window.innerHeight) return;
      this.scramble(this.randomPassword(16));
    }, 3200);
  }

  scramble(finalValue) {
    let frame = 0;
    clearInterval(this.scrambleTimer);
    this.scrambleTimer = setInterval(() => {
      frame += 1;
      const settled = Math.floor((frame / 14) * finalValue.length);
      this.genOutputTarget.textContent = finalValue
        .split('')
        .map((char, i) => (i < settled ? char : CHARS[Math.floor(Math.random() * CHARS.length)]))
        .join('');
      if (frame >= 14) {
        clearInterval(this.scrambleTimer);
        this.genOutputTarget.textContent = finalValue;
      }
    }, 45);
  }

  randomPassword(length) {
    const values = new Uint32Array(length);
    crypto.getRandomValues(values);
    return Array.from(values, (v) => CHARS[v % CHARS.length]).join('');
  }
}
