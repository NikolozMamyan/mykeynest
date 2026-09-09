import { Controller } from "@hotwired/stimulus"

const CREDENTIAL_COUNT_TTL = 120000

export default class extends Controller {
  static targets = ["sidebar", "backdrop", "passLength", "mobileMenuButton"]
  static values = { userId: Number }

  connect() {
    this.handleBeforeVisit = this.handleBeforeVisit.bind(this)
    this.handleVisitError = this.hideContentSkeleton.bind(this)
    this.handleBeforeCache = this.hideContentSkeleton.bind(this)
    this.handleTurboLoad = this.handleTurboLoad.bind(this)
    this.handleNavigationClick = this.handleNavigationClick.bind(this)
    document.addEventListener("keydown", this.handleEscape)
    document.addEventListener("turbo:before-visit", this.handleBeforeVisit)
    document.addEventListener("turbo:fetch-request-error", this.handleVisitError)
    document.addEventListener("turbo:before-cache", this.handleBeforeCache)
    document.addEventListener("turbo:load", this.handleTurboLoad)
    this.element.addEventListener("click", this.handleNavigationClick, true)
    this.syncSidebarState(false)
    this.updateNavigationState()
    this.loadCredentialCount()
  }

  disconnect() {
    document.removeEventListener("keydown", this.handleEscape)
    document.removeEventListener("turbo:before-visit", this.handleBeforeVisit)
    document.removeEventListener("turbo:fetch-request-error", this.handleVisitError)
    document.removeEventListener("turbo:before-cache", this.handleBeforeCache)
    document.removeEventListener("turbo:load", this.handleTurboLoad)
    this.element.removeEventListener("click", this.handleNavigationClick, true)
    this.syncSidebarState(false)
  }

  toggleSidebar() {
    if (!this.hasSidebarTarget || !this.hasBackdropTarget) return
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

  handleTurboLoad() {
    this.hideContentSkeleton()
    this.updateNavigationState()
    this.loadCredentialCount()
  }

  handleBeforeVisit() {
    this.closeSidebar()
    this.showContentSkeleton()
  }

  handleNavigationClick(event) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return

    const link = event.target.closest("#user-sidebar a.nav-item, #mobile-bottom-navigation a.mobile-bottom-nav__item")
    if (!link || link.target === "_blank" || link.origin !== window.location.origin) return

    this.updateNavigationState(new URL(link.href).pathname)
  }

  showContentSkeleton() {
    const main = document.getElementById("main-content")
    if (!main || main.querySelector(".app-navigation-skeleton")) return

    const skeleton = document.createElement("div")
    skeleton.className = "app-navigation-skeleton"
    skeleton.setAttribute("aria-hidden", "true")
    skeleton.setAttribute("data-turbo-temporary", "")
    skeleton.innerHTML = `
      <div class="app-navigation-skeleton__header">
        <div class="app-navigation-skeleton__heading">
          <div class="app-navigation-skeleton__line app-navigation-skeleton__line--eyebrow"></div>
          <div class="app-navigation-skeleton__line app-navigation-skeleton__line--title"></div>
          <div class="app-navigation-skeleton__line app-navigation-skeleton__line--subtitle"></div>
        </div>
        <div class="app-navigation-skeleton__actions">
          <div class="app-navigation-skeleton__button"></div>
          <div class="app-navigation-skeleton__button"></div>
        </div>
      </div>
      <div class="app-navigation-skeleton__grid">
        ${Array.from({ length: 4 }, () => `
          <div class="app-navigation-skeleton__card">
            <div class="app-navigation-skeleton__card-line"></div>
            <div class="app-navigation-skeleton__card-line"></div>
            <div class="app-navigation-skeleton__card-line"></div>
          </div>
        `).join("")}
      </div>`

    main.setAttribute("aria-busy", "true")
    main.appendChild(skeleton)
  }

  hideContentSkeleton() {
    document.querySelector(".app-navigation-skeleton")?.remove()
    document.getElementById("main-content")?.removeAttribute("aria-busy")
  }

  syncSidebarState(isOpen) {
    if (!this.hasSidebarTarget || !this.hasBackdropTarget) return

    this.sidebarTarget.classList.toggle("open", isOpen)
    this.backdropTarget.classList.toggle("active", isOpen)
    if (this.hasMobileMenuButtonTarget) {
      this.mobileMenuButtonTarget.setAttribute("aria-expanded", isOpen ? "true" : "false")
    }
    document.documentElement.classList.toggle("is-sidebar-open", isOpen)
    document.body.classList.toggle("is-sidebar-open", isOpen)
  }

  async loadCredentialCount() {
    if (!this.hasPassLengthTarget) return

    const countFromPage = document.querySelector('[data-controller~="credential-index"] .filter-tab[data-filter="mine"] .tab-count')
    if (countFromPage) {
      this.storeCredentialCount(countFromPage.textContent)
      return
    }

    const cached = this.readCredentialCount()
    if (cached) {
      this.updateCredentialCount(cached.count)
      if (Date.now() - cached.updatedAt < CREDENTIAL_COUNT_TTL) return
    }

    try {
      const response = await fetch("/api/credentials/length", {
        credentials: "include",
        headers: { Accept: "application/json" },
      })
      if (!response.ok) throw new Error("Network error")

      const data = await response.json()
      this.storeCredentialCount(data.count ?? 0)
    } catch (error) {
      console.error("Credential count could not be refreshed:", error)
    }
  }

  updateCredentialCount(value) {
    const count = Math.max(0, Number.parseInt(value, 10) || 0)
    this.passLengthTargets.forEach((target) => {
      target.textContent = String(count)
    })
  }

  storeCredentialCount(value) {
    const count = Math.max(0, Number.parseInt(value, 10) || 0)
    this.updateCredentialCount(count)
    try {
      sessionStorage.setItem(this.credentialCountCacheKey(), JSON.stringify({ count, updatedAt: Date.now() }))
    } catch {}
  }

  readCredentialCount() {
    try {
      const cached = JSON.parse(sessionStorage.getItem(this.credentialCountCacheKey()) || "null")
      return cached && Number.isFinite(Number(cached.count)) ? cached : null
    } catch {
      return null
    }
  }

  credentialCountCacheKey() {
    return `mykeynest:credential-count:${this.userIdValue || "anonymous"}`
  }

  updateNavigationState(pathname = window.location.pathname) {
    const currentPath = this.normalizePath(pathname)
    const links = this.element.querySelectorAll("#user-sidebar a.nav-item, #mobile-bottom-navigation a.mobile-bottom-nav__item")
    let bestMatch = ""

    links.forEach((link) => {
      this.navigationPaths(link).forEach((path) => {
        if (path && this.pathMatches(currentPath, path) && path.length > bestMatch.length) bestMatch = path
      })
    })

    links.forEach((link) => {
      const active = this.navigationPaths(link).includes(bestMatch)
      link.classList.toggle("active", active)
      if (active) link.setAttribute("aria-current", "page")
      else link.removeAttribute("aria-current")
    })

    const more = this.element.querySelector("#user-sidebar .sidebar-more")
    if (more) {
      const hasActiveChild = Boolean(more.querySelector("a.nav-item.active"))
      more.open = hasActiveChild
      more.querySelector("summary")?.classList.toggle("has-active-child", hasActiveChild)
    }

    if (this.hasMobileMenuButtonTarget) {
      const mobileHasActiveLink = Boolean(this.element.querySelector("#mobile-bottom-navigation a.active"))
      this.mobileMenuButtonTarget.classList.toggle("active", !mobileHasActiveLink)
    }
  }

  linkPath(link) {
    try {
      return this.normalizePath(new URL(link.href, window.location.origin).pathname)
    } catch {
      return ""
    }
  }

  navigationPaths(link) {
    const configuredPaths = link.dataset.navigationPaths
    if (!configuredPaths) return [this.linkPath(link)]

    return configuredPaths.split(",").map((path) => this.normalizePath(path.trim())).filter(Boolean)
  }

  normalizePath(path) {
    const normalized = String(path || "/").replace(/\/+$/, "")
    return normalized || "/"
  }

  pathMatches(currentPath, linkPath) {
    return currentPath === linkPath || (linkPath !== "/" && currentPath.startsWith(`${linkPath}/`))
  }
}
