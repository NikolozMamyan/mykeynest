import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["panel", "body", "messages", "quickies", "email", "message", "submit", "error"];
    static values = {
        sendUrl: String,
        stateUrl: String,
        csrfToken: String,
        initialConversation: String,
        visitorLabel: String,
        adminLabel: String,
        autoReply: String,
        requiredError: String,
        sendError: String,
        networkError: String,
    };

    connect() {
        this.isOpen = false;
        this.errorMessage = "";
        this.conversation = this.parseInitialConversation();
        this.hasUnread = Boolean(this.conversation?.unreadForVisitor);
        this.autoMessages = [];

        this.renderConversation();
        this.syncFlags();
        this.startPolling();
    }

    disconnect() {
        if (this.pollTimer) {
            window.clearInterval(this.pollTimer);
        }
    }

    toggle() {
        this.isOpen = !this.isOpen;
        if (this.isOpen) {
            this.hasUnread = false;
        }

        this.syncFlags();
    }

    close() {
        this.isOpen = false;
        this.syncFlags();
    }

    quickReply(event) {
        const message = event.currentTarget.dataset.messageTemplate || "";
        this.messageTarget.value = message;
        this.resizeMessage();
        this.quickiesTarget.hidden = true;
        this.messageTarget.focus();
    }

    resizeMessage() {
        this.messageTarget.style.height = "auto";
        this.messageTarget.style.height = `${Math.min(this.messageTarget.scrollHeight, 110)}px`;
    }

    async send(event) {
        event.preventDefault();
        this.setError("");

        const email = this.emailTarget.value.trim();
        const message = this.messageTarget.value.trim();

        if (email === "" || message === "") {
            this.setError(this.requiredErrorValue);
            return;
        }

        this.submitTarget.disabled = true;

        const formData = new FormData();
        formData.set("_token", this.csrfTokenValue);
        formData.set("email", email);
        formData.set("message", message);

        try {
            const response = await fetch(this.sendUrlValue, {
                method: "POST",
                body: formData,
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                },
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                this.setError(payload.error || this.sendErrorValue);
                return;
            }

            this.conversation = payload.conversation || null;
            this.messageTarget.value = "";
            this.resizeMessage();
            this.isOpen = true;
            this.hasUnread = false;
            this.renderConversation();
            this.syncFlags();
            this.showAutoReplyOnce();
        } catch (error) {
            this.setError(this.networkErrorValue);
        } finally {
            this.submitTarget.disabled = false;
        }
    }

    async refresh() {
        try {
            const response = await fetch(this.stateUrlValue, {
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                },
            });

            if (!response.ok) {
                return;
            }

            const payload = await response.json().catch(() => ({}));
            const nextConversation = payload.conversation || null;

            if (!nextConversation) {
                return;
            }

            const previousCount = this.conversation?.messages?.length || 0;
            const nextCount = nextConversation.messages?.length || 0;

            this.conversation = nextConversation;
            this.renderConversation();

            if (!this.isOpen && nextCount > previousCount) {
                this.hasUnread = true;
            }

            this.syncFlags();
        } catch (error) {
            // Keep polling silent for transient network failures.
        }
    }

    startPolling() {
        this.pollTimer = window.setInterval(() => {
            this.refresh();
        }, 12000);
    }

    renderConversation() {
        if (this.conversation?.email) {
            this.emailTarget.value = this.conversation.email;
        }

        const messages = this.conversation?.messages || [];
        const renderedMessages = [
            ...messages.map((message) => ({ ...message, localType: "stored" })),
            ...this.autoMessages,
        ].sort((a, b) => this.messageTimestamp(a) - this.messageTimestamp(b));

        if (renderedMessages.length === 0) {
            this.messagesTarget.innerHTML = "";
            return;
        }

        this.messagesTarget.innerHTML = renderedMessages
            .map((message) => {
                const isVisitor = message.authorType === "visitor";
                const label = isVisitor ? this.visitorLabelValue : this.adminLabelValue;
                const body = message.typing
                    ? '<span class="support-chat__typing"><span></span><span></span><span></span></span>'
                    : this.escapeHtml(message.body || "");

                return `
                    <div class="support-chat__bubble ${isVisitor ? "support-chat__bubble--visitor" : "support-chat__bubble--admin"}">
                        ${body}
                        <div class="support-chat__meta">${label} - ${this.formatDate(message.createdAt)}</div>
                    </div>
                `;
            })
            .join("");

        this.scrollToBottom();
    }

    showAutoReplyOnce() {
        const conversationId = this.conversation?.id || this.conversation?.email || "anonymous";
        const storageKey = `support-chat-auto-reply:${conversationId}`;

        if (window.sessionStorage.getItem(storageKey) === "1") {
            return;
        }

        window.sessionStorage.setItem(storageKey, "1");
        this.showAutoReply();
    }

    showAutoReply() {
        const id = `auto-${Date.now()}`;
        this.autoMessages = [
            ...this.autoMessages,
            {
                id,
                authorType: "admin",
                body: "",
                createdAt: new Date().toISOString(),
                typing: true,
            },
        ];
        this.renderConversation();

        window.setTimeout(() => {
            this.autoMessages = this.autoMessages.map((message) => (
                message.id === id
                    ? { ...message, body: this.autoReplyValue, typing: false }
                    : message
            ));
            this.renderConversation();
        }, 1200);
    }

    scrollToBottom() {
        const scrollTarget = this.hasBodyTarget ? this.bodyTarget : this.messagesTarget;
        scrollTarget.scrollTop = scrollTarget.scrollHeight;
    }

    parseInitialConversation() {
        const raw = this.initialConversationValue;
        if (!raw || raw === "null") {
            return null;
        }

        try {
            return JSON.parse(raw);
        } catch (error) {
            return null;
        }
    }

    formatDate(value) {
        if (!value) {
            return "";
        }

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return "";
        }

        return new Intl.DateTimeFormat("fr-FR", {
            day: "2-digit",
            month: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
        }).format(date);
    }

    messageTimestamp(message) {
        const date = new Date(message.createdAt || "");

        return Number.isNaN(date.getTime()) ? 0 : date.getTime();
    }

    syncFlags() {
        this.element.dataset.supportChatOpenValue = this.isOpen ? "1" : "0";
        this.element.dataset.supportChatHasUnreadValue = this.hasUnread ? "1" : "0";
        this.element.dataset.supportChatHasErrorValue = this.errorMessage !== "" ? "1" : "0";
    }

    setError(message) {
        this.errorMessage = message;
        this.errorTarget.textContent = message;
        this.syncFlags();
    }

    escapeHtml(value) {
        const map = {
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#039;",
        };

        return value.replace(/[&<>"']/g, (char) => map[char]).replace(/\n/g, "<br>");
    }
}
