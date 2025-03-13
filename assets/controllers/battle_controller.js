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

    console.log("🔄 Mise à jour HP :", { char1HP, char2HP });

    // Mise à jour du texte HP principal (en haut de la barre de vie)
    const hpTextChar1 = document.getElementById("hp-char1-text");
    const hpTextChar2 = document.getElementById("hp-char2-text");

    if (hpTextChar1) {
        hpTextChar1.textContent = `${char1HP}/100`;
    }
    if (hpTextChar2) {
        hpTextChar2.textContent = `${char2HP}/100`;
    }

    // Mise à jour de la barre de vie
    const healthBarChar1 = document.getElementById("health-bar-char1");
    const healthBarChar2 = document.getElementById("health-bar-char2");

    if (healthBarChar1) {
        healthBarChar1.style.width = `${(char1HP / 100) * 100}%`;
        this.updateHealthBarColor(healthBarChar1, char1HP);

        // ✅ Mise à jour du texte dans la barre de vie
        const healthTextChar1 = healthBarChar1.querySelector(".health-text");
        if (healthTextChar1) {
            healthTextChar1.textContent = `${char1HP}/100`;
        }
    }

    if (healthBarChar2) {
        healthBarChar2.style.width = `${(char2HP / 100) * 100}%`;
        this.updateHealthBarColor(healthBarChar2, char2HP);

        // ✅ Mise à jour du texte dans la barre de vie
        const healthTextChar2 = healthBarChar2.querySelector(".health-text");
        if (healthTextChar2) {
            healthTextChar2.textContent = `${char2HP}/100`;
        }
    }
        this.updateHealthBarColor(document.getElementById("health-bar-char1"), char1HP);
        this.updateHealthBarColor(document.getElementById("health-bar-char2"), char2HP)
        // Gestion des logs
        if (data.battleState.logs.length > 0) {
            const lastLog = data.battleState.logs[data.battleState.logs.length - 1];
            const logEl = document.createElement("div");
            logEl.classList.add("log-message", this.getLogClass(lastLog));
            logEl.textContent = lastLog;

            document.getElementById("log-messages").appendChild(logEl);
            document.getElementById("log-messages").scrollTop = document.getElementById("log-messages").scrollHeight;

            // Mise à jour du compteur de tours
            if (lastLog.includes("Round") || lastLog.includes("tour")) {
                this.turnCount++;
                document.getElementById("turn-counter").textContent = `TURN ${this.turnCount}`;
            }
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
    startAutoAttack() {
        if (this.isPaused || this.isGameOver || this.isAttacking) return;

        this.isAttacking = true; // Active le flag
        this.autoAttack();
    }
    
    stopAutoAttack() {
        this.isAttacking = false; // Désactive le flag pour arrêter les attaques
    }
    

    autoAttack() {
        if (this.isPaused || this.isGameOver || !this.isAttacking) return;

        fetch(this.battleUrlValue)
            .then(response => response.json())
            .then(data => {
                console.log("🛠️ Données reçues :", data);
                this.updateUI(data);
            })
            .catch(error => console.error("Erreur lors de l'attaque:", error))
            .finally(() => {
                if (this.isAttacking) {
                    setTimeout(() => this.autoAttack(), 1500); // Attente de 1.5 sec avant la prochaine attaque
                }
            });
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
