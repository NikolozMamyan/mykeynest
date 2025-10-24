import { Controller } from "@hotwired/stimulus"

/**
 * Contrôleur global de layout :
 * - Gère la sidebar (ouverture / fermeture / backdrop / ESC)
 * - Met à jour le compteur de mots de passe
 */
export default class extends Controller {
  static targets = ["sidebar", "backdrop", "passLength"]

  connect() {
    console.log("✅ LayoutController connecté")
    this.loadCredentialCount()
    document.addEventListener("keydown", this.handleEscape)
  }

  disconnect() {
    document.removeEventListener("keydown", this.handleEscape)
  }

  // --- OUVERTURE / FERMETURE ---

  toggleSidebar() {
    this.sidebarTarget.classList.toggle("open")
    this.backdropTarget.classList.toggle("active")
  }

  closeSidebar() {
    this.sidebarTarget.classList.remove("open")
    this.backdropTarget.classList.remove("active")
  }

  handleEscape = (e) => {
    if (e.key === "Escape" && this.sidebarTarget.classList.contains("open")) {
      this.closeSidebar()
    }
  }

  // --- FETCH du nombre de credentials ---
  async loadCredentialCount() {
    try {
      const response = await fetch("/api/credentials/length")
      if (!response.ok) throw new Error("Erreur réseau")
      const data = await response.json()
      if (this.hasPassLengthTarget) {
        this.passLengthTarget.textContent = data.count ?? 0
      }
    } catch (error) {
      console.error("❌ Erreur lors de la récupération du count :", error)
    }
  }
}
