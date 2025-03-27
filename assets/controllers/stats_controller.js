import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = [
        'strength', 'defense', 'speed', 'agility', 'remaining', 'preview',
        'image', 'label', 'id' // anciens targets du hero controller
    ]
    static values = {
        heroes: Array // récupéré depuis le data-hero-heroes-value
    }

    connect() {
        console.log("stats")
        this.maxPoints = 5
        this.baseStat = 1
        this.index = 0
        this.updateRemaining()
        this.updatePreview() // pour afficher le premier héros
    }

    increase(event) {
        const stat = event.target.dataset.stat
        const input = this[`${stat}Target`]
        let current = parseInt(input.value)

        if (this.getTotalAllocated() < this.maxPoints + (4 * this.baseStat)) {
            input.value = current + 1
            this.updateRemaining()
        }
    }

    decrease(event) {
        const stat = event.target.dataset.stat
        const input = this[`${stat}Target`]
        let current = parseInt(input.value)

        if (current > this.baseStat) {
            input.value = current - 1
            this.updateRemaining()
        }
    }

    getTotalAllocated() {
        return ['strength', 'defense', 'speed', 'agility']
            .map(s => parseInt(this[`${s}Target`].value))
            .reduce((a, b) => a + b, 0)
    }

    updateRemaining() {
        const total = this.getTotalAllocated()
        const remaining = this.maxPoints + (4 * this.baseStat) - total
        this.remainingTarget.textContent = `Points left: ${remaining}`


    }



    // HÉROS
    previous() {
        this.index = (this.index - 1 + this.heroesValue.length) % this.heroesValue.length
        this.updatePreview()
    }

    next() {
        this.index = (this.index + 1) % this.heroesValue.length
        this.updatePreview()
    }

    updatePreview() {
        const hero = this.heroesValue[this.index]
        this.imageTarget.src = hero.image
        this.labelTarget.textContent = hero.className
        this.idTarget.value = hero.id
        console.log(this.idTarget.value)
    }

    submit(event) {
        if (this.getTotalAllocated() > this.maxPoints + (4 * this.baseStat)) {
            event.preventDefault()
            alert("You assigned too many points!")
        }
    }
}
