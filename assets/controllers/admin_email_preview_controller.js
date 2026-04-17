import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = [
        "htmlInput",
        "previewFrame",
        "manualRecipients",
        "count",
        "userSearch",
        "userItem",
        "userCheckbox",
        "selectedRecipients",
    ];

    connect() {
        this.renderPreview();
        this.updateSelectedRecipients();
        this.filterUsers();
    }

    renderPreview() {
        const html = this.htmlInputTarget.value.trim();
        const documentHtml = html === ""
            ? this.emptyStateMarkup()
            : `<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>${html}</body></html>`;

        this.previewFrameTarget.srcdoc = documentHtml;
    }

    updateSelectedRecipients() {
        const manualEmails = this.parseManualEmails();
        const checkedUsers = this.checkedUsers();
        const recipientSet = new Set([
            ...manualEmails,
            ...checkedUsers.map((entry) => entry.email),
        ]);

        this.countTarget.textContent = `${recipientSet.size} destinataire${recipientSet.size > 1 ? "s" : ""}`;

        if (checkedUsers.length === 0 && manualEmails.length === 0) {
            this.selectedRecipientsTarget.innerHTML = '<span class="emailing-recipient-empty">Aucun destinataire selectionne.</span>';
            return;
        }

        const manualMarkup = manualEmails.map((email) => (
            `<span class="emailing-recipient-chip emailing-recipient-chip--manual">${this.escapeHtml(email)}</span>`
        ));

        const userMarkup = checkedUsers.map((entry) => (
            `<span class="emailing-recipient-chip">${this.escapeHtml(entry.email)}</span>`
        ));

        this.selectedRecipientsTarget.innerHTML = [...userMarkup, ...manualMarkup].join("");
    }

    filterUsers() {
        const query = this.userSearchTarget.value.trim().toLowerCase();

        this.userItemTargets.forEach((item) => {
            const haystack = (item.dataset.search || "").toLowerCase();
            item.hidden = query !== "" && !haystack.includes(query);
        });
    }

    parseManualEmails() {
        return Array.from(new Set(
            this.manualRecipientsTarget.value
                .toLowerCase()
                .split(/[\s,;]+/)
                .map((value) => value.trim())
                .filter(Boolean)
        ));
    }

    checkedUsers() {
        return this.userCheckboxTargets
            .filter((checkbox) => checkbox.checked)
            .map((checkbox) => ({
                email: (checkbox.dataset.email || "").trim().toLowerCase(),
            }))
            .filter((entry) => entry.email !== "");
    }

    emptyStateMarkup() {
        return `<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      background: #f8fafc;
      color: #64748b;
      font-family: Arial, sans-serif;
    }
  </style>
</head>
<body>
  <div>La preview apparaitra ici.</div>
</body>
</html>`;
    }

    escapeHtml(value) {
        const map = {
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#039;",
        };

        return value.replace(/[&<>"']/g, (char) => map[char]);
    }
}
