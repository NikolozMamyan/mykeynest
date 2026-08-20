# Configuration Stripe MYKEYNEST

## Catalogue mensuel

Créer deux produits récurrents en EUR, sans tarif annuel ni trimestriel :

| Produit | Tarif mensuel | Quantité Checkout |
| --- | ---: | --- |
| MYKEYNEST Pro | 6,99 € / utilisateur | 1 compte |
| MYKEYNEST Team | 5,49 € / utilisateur | 6 minimum, ajustable jusqu'à la limite configurée |

Pour un nouvel abonnement Team Stripe, cette quantité devient aussi la limite d'installations d'extension. Les abonnements Team historiques sans Price Stripe associé restent illimités afin de préserver les clients déjà en production.

Conserver les deux identifiants `price_...` créés par Stripe. Un tarif Stripe ne se modifie pas : lorsqu'un montant change, archiver l'ancien tarif, créer le nouveau, puis remplacer l'identifiant d'environnement.

Avant d'ouvrir Checkout, l'application relit le Price et vérifie qu'il est actif, mensuel, en EUR et égal au montant affiché. Un ancien Price à 2,99 € ou un Price Team absent sera donc refusé au lieu de facturer un montant différent de la landing.

## Variables Sandbox et Live

Configurer les deux jeux de valeurs une seule fois dans les secrets de l'hébergeur, jamais dans Git :

```dotenv
APP_URL=https://key-nest.com

STRIPE_SANDBOX_SECRET_KEY=sk_test_...
STRIPE_SANDBOX_PRICE_PRO=price_...
STRIPE_SANDBOX_PRICE_TEAM=price_...
STRIPE_SANDBOX_WEBHOOK_SECRET=whsec_...

STRIPE_PRODUCTION_SECRET_KEY=sk_live_...
STRIPE_PRODUCTION_PRICE_PRO=price_...
STRIPE_PRODUCTION_PRICE_TEAM=price_...
STRIPE_PRODUCTION_WEBHOOK_SECRET=whsec_...

STRIPE_TEAM_MIN_SEATS=6
STRIPE_TEAM_MAX_SEATS=250
```

Ensuite, utiliser **Admin > Subscriptions > Paiement** pour basculer les nouveaux Checkouts entre Sandbox et Live. Les secrets ne sont ni enregistrés en base ni affichés dans l'administration : seule la valeur `sandbox` ou `production` du mode actif est persistée.

## Où récupérer chaque valeur

- La clé `SECRET_KEY` : **Stripe > Développeurs > Clés API**, en vérifiant le sélecteur mode test/live. La clé publique n'est pas requise par le Checkout hébergé actuel.
- Les Prices Pro et Team : **Catalogue de produits > produit > tarif mensuel**. Copier l'identifiant du tarif `price_...`, pas l'identifiant produit `prod_...`.
- Chaque `WEBHOOK_SECRET` : **Workbench > Webhooks > destination MYKEYNEST > secret de signature**. Révéler puis copier la valeur `whsec_...` propre au mode.

Le mode test et le mode live ont chacun leurs propres clés, Prices, destination webhook et secret `whsec_...`.

## Endpoint webhook

Créer dans Stripe Workbench une destination Sandbox et une destination Live utilisant toutes les deux :

```text
https://key-nest.com/stripe/webhook
```

Choisir le type de payload **Snapshot**. Le contrôleur actuel reçoit l'objet Stripe complet et n'est pas un endpoint `Thin`. Ne pas réutiliser le secret d'une destination Thin : chaque destination possède son propre secret de signature.

Événements nécessaires :

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `invoice.paid`
- `invoice.payment_succeeded` (compatibilité)
- `invoice.payment_failed`
- `customer.subscription.created`
- `customer.subscription.updated`
- `customer.subscription.deleted`
- `customer.subscription.paused`
- `customer.subscription.resumed`

Le endpoint essaie les deux secrets configurés, identifie automatiquement le mode, déduplique chaque `event.id`, puis relit l'abonnement dans le bon environnement Stripe. Le switch admin n'interrompt donc pas les webhooks Live pendant un test Sandbox.

Si un secret `whsec_...` a été publié dans une capture ou une conversation, utiliser **Roll secret** dans la destination Stripe et enregistrer uniquement le nouveau secret dans la configuration du serveur.

## Portail client

Activer au minimum :

- mise à jour du moyen de paiement ;
- consultation et téléchargement des factures ;
- résiliation en fin de période ;
- modification d'abonnement uniquement si les produits Pro et Team et leurs règles de quantité ont été correctement ajoutés au portail.

## Ordre de déploiement

1. Créer les deux Prices en mode test puis en mode live.
2. Créer et activer les endpoints webhook dans les deux modes.
3. Déployer les variables d'environnement correspondantes.
4. Préparer la nouvelle release sans encore basculer le trafic dessus.
5. Depuis cette release, exécuter `php bin/console doctrine:migrations:migrate --no-interaction` puis `php bin/console asset-map:compile`.
6. Basculer le trafic sur la release et vérifier immédiatement la santé de l'application et du endpoint webhook.
7. Effectuer un paiement Pro et un paiement Team en mode test, vérifier le plan, le nombre de sièges et la ligne dans l'administration.
8. Effectuer un paiement live contrôlé, puis une résiliation depuis le portail.

Références : [abonnements Checkout](https://docs.stripe.com/payments/checkout/build-subscriptions), [webhooks d'abonnement](https://docs.stripe.com/billing/subscriptions/webhooks), [quantités ajustables](https://docs.stripe.com/payments/checkout/adjustable-quantity), [bonnes pratiques webhook](https://docs.stripe.com/webhooks).
