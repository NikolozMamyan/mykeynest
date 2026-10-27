import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['content', 'status', 'detail'];
    static values = { url: String };

    connect() {
        this.timer = window.setInterval(() => this.refresh(), 15000);
    }

    disconnect() {
        window.clearInterval(this.timer);
        this.pending?.abort();
    }

    showDay(event) {
        if (this.hasDetailTarget) this.detailTarget.textContent = event.currentTarget.dataset.label;
    }

    async refresh() {
        if (document.hidden || this.pending || this.contentTarget.contains(document.activeElement)) return;

        const pending = new AbortController();
        this.pending = pending;
        const timeout = window.setTimeout(() => pending.abort(), 8000);
        try {
            const response = await fetch(this.urlValue, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin', cache: 'no-store', signal: pending.signal,
            });
            if (response.redirected || [401, 403].includes(response.status)) {
                window.clearInterval(this.timer);
                this.statusTarget.textContent = 'Session expirée · Rechargez la page pour vous reconnecter.';
                return;
            }
            if (!response.ok) throw new Error('Refresh failed');
            const html = new DOMParser().parseFromString(await response.text(), 'text/html');
            const content = html.querySelector('[data-admin-visitors-target="content"]');
            if (!content) throw new Error('Missing visitor report');
            if (!this.element.isConnected || this.contentTarget.contains(document.activeElement)) return;
            const positions = [...this.contentTarget.querySelectorAll('.ds-table-wrap, .visitors-chart-scroll, .visitors-ranking')]
                .map(element => [element.scrollLeft, element.scrollTop]);
            this.contentTarget.replaceWith(content);
            this.contentTarget.querySelectorAll('.ds-table-wrap, .visitors-chart-scroll, .visitors-ranking').forEach((element, index) => {
                if (positions[index]) [element.scrollLeft, element.scrollTop] = positions[index];
            });
            this.statusTarget.textContent = 'À jour · Actualisation toutes les 15 s';
        } catch (error) {
            if (this.element.isConnected) this.statusTarget.textContent = 'Actualisation interrompue · Nouvel essai dans 15 s';
        } finally {
            window.clearTimeout(timeout);
            if (this.pending === pending) this.pending = null;
        }
    }
}
