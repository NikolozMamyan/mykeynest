import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static values = {
        battleUrl: String,
        inventoryUrl: String,
        equipUrl: String,
        readyUrl: String,
        characterKey: String
    };

    connect() {
        console.log("✅ InventoryController connecté !");
        this.isInventoryOpen = false;
        this.currentRound = 1;
        this.selectedPerkId = null;

        this.setupEventListeners();
        console.log("👂 InventoryController connecté et prêt !");
        document.addEventListener("round-pause", this.handleRoundPause.bind(this));
        
    }

    setupEventListeners() {
        this.element.addEventListener("round-pause", this.handleRoundPause.bind(this));

        const inventoryBtn = document.getElementById("btn-inventory");
        const readyBtn = document.getElementById("btn-ready");

        if (inventoryBtn) inventoryBtn.addEventListener("click", () => this.toggleInventory());
        if (readyBtn) readyBtn.addEventListener("click", () => this.setReady());
    }

    handleRoundPause(event) {
        console.log("🔄 Round en pause, affichage de l'interface d'équipement !");
        this.currentRound = event.detail.roundNumber;

        this.showRoundPause();
    }

    showRoundPause() {
        const pauseScreen = document.getElementById("round-pause");
        const battleControls = document.getElementById("battle-controls");
        const inventoryBtn = document.getElementById("btn-inventory");
    
        if (battleControls) battleControls.style.display = "none";
        if (pauseScreen) {
            pauseScreen.style.display = "block"; // ✅ Assurer l'affichage
            pauseScreen.style.opacity = "1";
            pauseScreen.style.visibility = "visible";
        }
        
        // Assurer que le bouton INVENTAIRE est bien visible
        if (inventoryBtn) {
            inventoryBtn.style.display = "block";
            inventoryBtn.style.width = "150px";  // ✅ Fixe une largeur
            inventoryBtn.style.height = "50px"; // ✅ Fixe une hauteur
            inventoryBtn.style.opacity = "1";
            inventoryBtn.style.visibility = "visible";
            inventoryBtn.style.position = "relative";
            inventoryBtn.style.zIndex = "9999";
        }
    
        const roundNumber = document.getElementById("round-number");
        const pauseInstructions = document.getElementById("pause-instructions");
    
        if (roundNumber) roundNumber.textContent = `Round ${this.currentRound} terminé`;
        if (pauseInstructions) pauseInstructions.textContent = "Équipez vos objets et préparez-vous pour le prochain round!";
    }
    
    async toggleInventory() {
        const inventoryPanel = document.getElementById("inventory-panel");

        if (this.isInventoryOpen) {
            inventoryPanel.style.display = "none";
            this.isInventoryOpen = false;
            return;
        }

        try {
            const response = await fetch(`${this.inventoryUrlValue}`);
            const data = await response.json();

            if (data.error) {
                console.error("❌ Erreur lors de l'ouverture de l'inventaire :", data.error);
                return;
            }

            this.renderInventory(data.inventory);
            inventoryPanel.style.display = "block";
            this.isInventoryOpen = true;
        } catch (error) {
            console.error("❌ Erreur réseau :", error);
        }
    }

    renderInventory(inventory) {
        const perksContainer = document.getElementById("perks-list");
        const slotsContainer = document.getElementById("perk-slots");

        if (!perksContainer || !slotsContainer) {
            console.error("❌ Impossible de rendre l'inventaire : éléments HTML introuvables.");
            return;
        }

        perksContainer.innerHTML = "";
        slotsContainer.innerHTML = "";

        inventory.perks.forEach(perk => {
            const perkElement = document.createElement("div");
            perkElement.classList.add("perk-item");
            perkElement.dataset.perkId = perk.id;
            perkElement.innerHTML = `<h4>${perk.name}</h4><p>${perk.effect}</p>`;
            perkElement.addEventListener("click", () => this.selectPerk(perk.id));
            perksContainer.appendChild(perkElement);
        });

        inventory.slots.forEach(slot => {
            const slotElement = document.createElement("div");
            slotElement.classList.add("perk-slot");
            slotElement.dataset.slotId = slot.id;
            slotElement.innerHTML = `<h4>${slot.name}</h4><div class="slot-content">${slot.perkId ? `Perk #${slot.perkId}` : "Vide"}</div>`;
            slotElement.addEventListener("click", () => this.selectSlot(slot.id));
            slotsContainer.appendChild(slotElement);
        });
    }

    selectPerk(perkId) {
        document.querySelectorAll(".perk-item").forEach(el => el.classList.remove("selected"));
        document.querySelector(`.perk-item[data-perk-id="${perkId}"]`).classList.add("selected");
        this.selectedPerkId = perkId;
    }

    async selectSlot(slotId) {
        if (!this.selectedPerkId) {
            alert("Veuillez d'abord sélectionner un perk !");
            return;
        }
    
        console.log("🔍 equipUrlValue :", this.equipUrlValue);
        console.log("🔍 characterKeyValue :", this.characterKeyValue);
        console.log("🔍 slotId :", slotId);
        console.log("🔍 selectedPerkId :", this.selectedPerkId);
    
        try {
            
            const response = await fetch(`${this.equipUrlValue.replace('/0/0', '')}/${slotId}/${this.selectedPerkId}`);


            const data = await response.json();
    
            console.log("🛠 Réponse reçue :", data);
    
            if (data.error) {
                console.error("❌ Erreur :", data.error);
                return;
            }
    
            const slotElement = document.querySelector(`.perk-slot[data-slot-id="${slotId}"]`);
            if (slotElement) {
                slotElement.querySelector(".slot-content").textContent = `Perk #${this.selectedPerkId}`;
            }
        } catch (error) {
            console.error("❌ Erreur lors de l'équipement du perk :", error);
        }
    }
    

    async setReady() {
        console.log("🔍 readyUrlValue :", this.readyUrlValue);
        console.log("🔍 characterKeyValue :", this.characterKeyValue);
    
        try {
            const response = await fetch(`${this.readyUrlValue}`);
            const data = await response.json();
    
            console.log("🛠 Réponse reçue :", data);
    
            if (data.error) {
                console.error("❌ Erreur :", data.error);
                return;
            }
    
            const readyButton = document.getElementById("btn-ready");
            if (readyButton) {
                readyButton.textContent = "PRÊT ✓";
                readyButton.disabled = true;
            }
    
            // ✅ Si `char1` a validé, on démarre le combat immédiatement
            if (data.battleState.round.readyStatus.char1) {
                console.log("✅ Joueur prêt, lancement du round !");
                this.resetToNextRound(data.battleState);
            }
    
        } catch (error) {
            console.error("❌ Erreur lors de la préparation :", error);
        }
    }
    
    

    resetToNextRound(battleState) {
        console.log("🛠 Démarrage du round :", battleState.round);
    
        const inventoryPanel = document.getElementById("inventory-panel");
        const roundPause = document.getElementById("round-pause");
        const battleControls = document.getElementById("battle-controls");
        const readyButton = document.getElementById("btn-ready");
    
        if (!inventoryPanel) {
            console.error("❌ ERREUR : Élément 'inventory-panel' non trouvé !");
        } else {
            inventoryPanel.style.display = "none";
        }
    
        if (!roundPause) {
            console.error("❌ ERREUR : Élément 'round-pause' non trouvé !");
        } else {
            roundPause.style.display = "none";
        }
    
        if (!battleControls) {
            console.error("❌ ERREUR : Élément 'battle-controls' non trouvé !");
        } else {
            battleControls.style.display = "block";
        }
    
        this.isInventoryOpen = false;
    
        if (readyButton) {
            readyButton.textContent = "PRÊT";
            readyButton.disabled = false;
        }
    
        const battleController = this.application.getControllerForElementAndIdentifier(
            document.querySelector("[data-controller='battle']"),
            "battle"
        );
    
        if (battleController) {
            console.log("✅ Début du combat !");
            battleController.startNewRound(battleState);
        } else {
            console.error("❌ ERREUR : Aucun contrôleur 'battle' trouvé !");
        }
    }
    
}
