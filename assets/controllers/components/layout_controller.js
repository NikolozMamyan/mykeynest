import { Controller } from "@hotwired/stimulus"

export default class extends Controller {
  static targets = ["sidebar", "backdrop", "passLength"]

  connect() {
    this.loadCredentialCount()
    document.addEventListener("keydown", this.handleEscape)
    this.syncSidebarState(false)
  }

  disconnect() {
    document.removeEventListener("keydown", this.handleEscape)
    this.syncSidebarState(false)
  }

  toggleSidebar() {
    if (!this.hasSidebarTarget || !this.hasBackdropTarget) {
      return
    }

    this.syncSidebarState(!this.sidebarTarget.classList.contains("open"))
  }

  closeSidebar() {
    this.syncSidebarState(false)
  }

  handleEscape = (event) => {
    if (this.hasSidebarTarget && event.key === "Escape" && this.sidebarTarget.classList.contains("open")) {
      this.closeSidebar()
    }
  }

  syncSidebarState(isOpen) {
    if (!this.hasSidebarTarget || !this.hasBackdropTarget) {
      return
    }

    this.sidebarTarget.classList.toggle("open", isOpen)
    this.backdropTarget.classList.toggle("active", isOpen)
    document.documentElement.classList.toggle("is-sidebar-open", isOpen)
    document.body.classList.toggle("is-sidebar-open", isOpen)
  }

  async loadCredentialCount() {
    try {
      const response = await fetch("/api/credentials/length", {
        credentials: "include",
        headers: { "Content-Type": "application/json" },
      })

      if (!response.ok) {
        throw new Error("Erreur reseau")
      }

      const data = await response.json()
      if (this.hasPassLengthTarget) {
        this.passLengthTarget.textContent = data.count ?? 0
      }
    } catch (error) {
      console.error("Erreur lors de la recuperation du count :", error)
    }
  }
}
