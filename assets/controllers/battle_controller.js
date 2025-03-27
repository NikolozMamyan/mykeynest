import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static values = {
        battleUrl: String,
        char1Hp: Number,
        char2Hp: Number,
    };

    connect() {
        console.log("Char1 HP:", this.char1HpValue);
        console.log("Char2 HP:", this.char2HpValue);

        this.isPaused = false;
        this.isGameOver = false;
        this.isAttacking = false; // Flag pour éviter les attaques en double
        this.turnCount = 1;

        this.createPixelDust();
        this.initializeUI();
    }

    // Créer des particules pour l'effet visuel
    createPixelDust() {
        const container = document.getElementById("pixel-dust-container");
        for (let i = 0; i < 15; i++) {
            const pixelDust = document.createElement("div");
            pixelDust.classList.add("pixel-dust");
            pixelDust.style.left = Math.random() * 100 + "%";
            pixelDust.style.top = Math.random() * 100 + "%";
            pixelDust.style.animationDelay = Math.random() * 5 + "s";
            pixelDust.style.animationDuration = Math.random() * 10 + 5 + "s";
            container.appendChild(pixelDust);
        }
    }

    // Détermine la classe CSS du log en fonction du message
    getLogClass(message) {
        if (message.includes("CRITIQUE") || message.includes("critique")) {
            return "log-critical";
        } else if (message.includes("Round") || message.includes("tour") || message.includes("commence")) {
            return "log-system";
        } else if (message.includes(document.querySelector("#char1-panel .character-name")?.textContent || "")) {
            return "log-player1";
        } else {
            return "log-player2";
        }
    }

    // Met à jour les couleurs de la barre de santé
    updateHealthBarColor(bar, ratio) {
        if (ratio < 20) {
            bar.style.backgroundColor = "#ff0000";
        } else if (ratio < 50) {
            bar.style.backgroundColor = "#ff8c00";
        } else {
            bar.style.backgroundColor = "#32cd32";
        }
    }

    // Met à jour l'interface utilisateur
    updateUI(data) {
        const char1HP = data.battleState.char1.hp;
        const char2HP = data.battleState.char2.hp;
        const char1Stamina = data.battleState.char1.stamina;
        const char2Stamina = data.battleState.char2.stamina;

        console.log("🔄 Mise à jour HP & Stamina :", { char1HP, char2HP, char1Stamina, char2Stamina });

        // ✅ Mise à jour du texte HP principal (au-dessus de la barre de vie)
        const hpTextChar1 = document.getElementById("hp-char1-text");
        const hpTextChar2 = document.getElementById("hp-char2-text");

        if (hpTextChar1) hpTextChar1.textContent = `${char1HP}/100`;
        if (hpTextChar2) hpTextChar2.textContent = `${char2HP}/100`;

        // ✅ Mise à jour des barres de vie
        const healthBarChar1 = document.getElementById("health-bar-char1");
        const healthBarChar2 = document.getElementById("health-bar-char2");

        if (healthBarChar1) {
            healthBarChar1.style.width = `${(char1HP / 100) * 100}%`;
            this.updateHealthBarColor(healthBarChar1, char1HP);
            const healthTextChar1 = healthBarChar1.querySelector(".health-text");
            if (healthTextChar1) healthTextChar1.textContent = `${char1HP}/100`;
        }

        if (healthBarChar2) {
            healthBarChar2.style.width = `${(char2HP / 100) * 100}%`;
            this.updateHealthBarColor(healthBarChar2, char2HP);
            const healthTextChar2 = healthBarChar2.querySelector(".health-text");
            if (healthTextChar2) healthTextChar2.textContent = `${char2HP}/100`;
        }

        // ✅ Mise à jour du texte Stamina
        const staminaTextChar1 = document.getElementById("stamina-char1-text");
        const staminaTextChar2 = document.getElementById("stamina-char2-text");

        if (staminaTextChar1) staminaTextChar1.textContent = `${char1Stamina}/100`;
        if (staminaTextChar2) staminaTextChar2.textContent = `${char2Stamina}/100`;

        // ✅ Mise à jour des barres de stamina
        const staminaBarChar1 = document.getElementById("stamina-bar-char1");
        const staminaBarChar2 = document.getElementById("stamina-bar-char2");

        if (staminaBarChar1) {
            staminaBarChar1.style.width = `${(char1Stamina / 100) * 100}%`;
            this.updateStaminaBarColor(staminaBarChar1, char1Stamina);
        }

        if (staminaBarChar2) {
            staminaBarChar2.style.width = `${(char2Stamina / 100) * 100}%`;
            this.updateStaminaBarColor(staminaBarChar2, char2Stamina);
        }
        // Animation de dégâts et effet de hit
if (data.lastAttacker && data.damage > 0) {
    let targetPanel, damageEffect;
    if (data.lastAttacker === "char1") {
        targetPanel = document.getElementById("char2-panel");
        damageEffect = document.getElementById("damage-char2");
        document.getElementById("turn-char1").style.display = "none";
        document.getElementById("turn-char2").style.display = "block";
    } else {
        targetPanel = document.getElementById("char1-panel");
        damageEffect = document.getElementById("damage-char1");
        document.getElementById("turn-char1").style.display = "block";
        document.getElementById("turn-char2").style.display = "none";
    }
    targetPanel.classList.add("hit-effect");
    setTimeout(() => {
        targetPanel.classList.remove("hit-effect");
    }, 400);
    damageEffect.textContent = `-${data.damage}`;
    damageEffect.classList.remove("damage-anim");
    void damageEffect.offsetWidth;
    damageEffect.classList.add("damage-anim");
}


        // Logs de combat
        if (data.battleState.logs.length > 0) {
            const lastLog = data.battleState.logs[data.battleState.logs.length - 1];
            const logEl = document.createElement("div");
            logEl.classList.add("log-message", this.getLogClass(lastLog));
            logEl.textContent = lastLog;

            document.getElementById("log-messages").appendChild(logEl);
            document.getElementById("log-messages").scrollTop = document.getElementById("log-messages").scrollHeight;
        }

        // Vérifier la fin du combat
        if (data.battleState.isOver && !this.isGameOver) {
            this.isGameOver = true;
            clearInterval(this.battleInterval);

            const winner = char1HP <= 0 ? data.battleState.char2.name : data.battleState.char1.name;
            document.getElementById("winner-name").textContent = `${winner} Wins!`;
            document.getElementById("game-over").style.display = "block";

            document.getElementById("btn-pause").style.display = "none";
            document.getElementById("btn-start").textContent = "RESTART";
            document.getElementById("btn-start").style.display = "inline-block";
        }
    }
    updateStaminaBarColor(bar, stamina) {
        if (stamina < 20) {
            bar.style.backgroundColor = "#cc0000"; // Rouge foncé si très bas
        } else if (stamina < 50) {
            bar.style.backgroundColor = "#ffcc00"; // Jaune si moyen
        } else {
            bar.style.backgroundColor = "#00cc66"; // Vert si élevé
        }
    }
    startAutoAttack() {
        if (this.isPaused || this.isGameOver || this.isAttacking) return;
        this.isAttacking = true;
        this.autoAttack();
    }
    
    stopAutoAttack() {
        this.isAttacking = false;
    }

// Ajouter cette méthode à ton battle_controller.js
autoAttack() {
    if (this.isPaused || this.isGameOver || !this.isAttacking) return;

    fetch(this.battleUrlValue, {
        credentials: 'include' // ✅ ESSENTIEL pour que AUTH_TOKEN soit envoyé
    })
    .then(response => response.json())
    .then(data => {
        console.log("🛠️ Données reçues :", data);
        this.updateUI(data);

        if (data.pauseForInventory) {
            this.pauseForInventory(data.battleState);
        }

        return data;
    })
    .catch(error => {
        console.error("Erreur lors de l'attaque:", error);
        return null;
    })
    .then(data => {
        if (this.isAttacking && data && !data.pauseForInventory) {
            setTimeout(() => this.autoAttack(), 1500);
        }
    });
}


pauseForInventory(battleState) {
    console.log("Pause pour l'inventaire! ⏸️");
    this.isPaused = true;
    this.isAttacking = false;

    // Créer un événement pour notifier le contrôleur d'inventaire
    const pauseEvent = new CustomEvent("round-pause", {
        detail: {
            roundNumber: battleState.round.current,
            battleState: battleState
        }
    });

    console.log("📢 Dispatch de l'événement round-pause...");
    document.dispatchEvent(pauseEvent);
    console.log("✅ Événement round-pause dispatché !");
}


// Ajouter cette méthode pour redémarrer après la pause
startNewRound(battleState) {
    console.log("Démarrage du nouveau round!");
    this.isPaused = false;
    this.isAttacking = true;
    this.updateUI({ battleState });
    this.autoAttack();
}

// Ajouter cette méthode pour les logs
addLogMessage(message) {
    const logEl = document.createElement("div");
    logEl.classList.add("log-message", this.getLogClass(message));
    logEl.textContent = message;
    document.getElementById("log-messages").appendChild(logEl);
    document.getElementById("log-messages").scrollTop = document.getElementById("log-messages").scrollHeight;
}


    toggleBattle() {
        const startBtn = document.getElementById("btn-start");
        const pauseBtn = document.getElementById("btn-pause");

        if (startBtn.textContent === "RESTART") {
            window.location.reload();
            return;
        }

        if (this.isPaused || !this.isAttacking) {
            this.isPaused = false;
            startBtn.style.display = "none";
            pauseBtn.style.display = "inline-block";
            document.getElementById("game-over").style.display = "none";

            this.startAutoAttack(); // 🔥 On démarre les attaques proprement
        }
    }

    togglePause() {
        this.isPaused = !this.isPaused;
        document.getElementById("btn-pause").textContent = this.isPaused ? "RESUME" : "PAUSE";

        if (this.isPaused) {
            this.stopAutoAttack();
        } else {
            this.startAutoAttack();
        }
    }
    initializeUI() {
        document.getElementById("btn-start").addEventListener("click", () => this.toggleBattle());
        document.getElementById("btn-pause").addEventListener("click", () => this.togglePause());
    }
}
