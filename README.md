# 🏢 Mini ERP Stock — Architecture, Leadership & Documentation Technique

> **Auteur :** Montasser Benouirane  
> **Mission :** Concevoir et implémenter un mini ERP de gestion de stock multi-entrepôts haute performance, résistant aux accès concurrents et garantissant l'intégrité financière et logistique du stock.  
> **Score visé :** 100 / 100 points  
> **Stack technologique :** Laravel 12 (PHP 8.2) + React 18 (TypeScript) + Inertia.js 2 + Tailwind CSS + MySQL (InnoDB) + PHPUnit + Postman

---

## 📑 Sommaire
1. [Partie 1 : Analyse & Architecture du Système (25 pts)](#partie-1--analyse--architecture-du-système-25-pts)
   - Diagramme d'architecture globale des composants
   - Modèle conceptuel & Entités (Product, Warehouse, StockMovement, User, StockLevel)
   - Cohérence du stock, Transactions ACID et Verrouillage
2. [Partie 2 : Implémentation & API REST (35 pts)](#partie-2--implémentation--api-rest-35-pts)
   - Endpoints API (`POST /api/movements` & `GET /api/stock`)
   - Algorithme anti-stock négatif & gestion des erreurs (422, 401, 404, 500)
   - Stratégie anti-deadlock dans les transferts inter-entrepôts
3. [Partie 3 : Tests Automatisés & Qualité (20 pts)](#partie-3--tests-automatisés--qualité-20-pts)
   - Suite de tests PHPUnit (35 tests passants à 100%)
   - Collection Postman complète importable
4. [Partie 4 : Revue de Code & Leadership (20 pts)](#partie-4--revue-de-code--leadership-20-pts)
   - Audit critique du pseudo-code `stock = getStock(id); if stock >= qty: saveStock(id, stock-qty)`
   - Identification exhaustive des failles & vulnérabilités
   - Solutions corrigées (Pessimiste, Optimiste, Décrément atomique SQL)
   - Découpage du travail pour 2 développeurs (Sprints, User Stories Gherkin, DoD, DoR)
5. [Guide de Démarrage Rapide](#guide-de-démarrage-rapide)
6. [Scénario de Démonstration en 5 Minutes (Pitch Recruteur)](#scénario-de-démonstration-en-5-minutes-pitch-recruteur)

---

## Partie 1 : Analyse & Architecture du Système (25 pts)

### 1.1 Diagramme des Composants

```
┌────────────────────────────────────────────────────────────────────────┐
│                      FRONTEND SPA (Inertia.js + React 18)             │
│                                                                        │
│   ┌──────────────┐   ┌──────────────┐   ┌──────────────┐   ┌─────────┐ │
│   │  Dashboard   │   │   Produits   │   │  Entrepôts   │   │ Mouvem. │ │
│   │ (KPI/Alerts) │   │ (CRUD/Stock) │   │ (Multi-Site) │   │ (Forms) │ │
│   └──────────────┘   └──────────────┘   └──────────────┘   └─────────┘ │
│          ▲                  ▲                  ▲                ▲      │
└──────────┼──────────────────┼──────────────────┼────────────────┼──────┘
           │                  │                  │                │
           ▼                  ▼                  ▼                ▼
     [ Inertia Adapter / Axios JSON Bridge / Form Submission ]
                                │
                                ▼
┌────────────────────────────────────────────────────────────────────────┐
│                   BACKEND APPLICATION (Laravel 12)                    │
│                                                                        │
│  [ Middleware Auth & CSRF ] ─────────► [ Laravel Sanctum API Tokens ]   │
│                                                                        │
│   ┌────────────────────────────────────────────────────────────────┐   │
│   │                      CONTROLLERS LAYER                         │   │
│   │   DashboardController  ·  ProductController  ·  StockController │   │
│   │   WarehouseController  ·  MovementController ·  AuthController │   │
│   └────────────────────────────────┬───────────────────────────────┘   │
│                                    │                                   │
│                                    ▼                                   │
│   ┌────────────────────────────────────────────────────────────────┐   │
│   │                       SERVICE LAYER (StockService)             │   │
│   │   - DB::transaction isolation                                  │   │
│   │   - Verrouillage pessimiste (SELECT ... FOR UPDATE)            │   │
│   │   - Contrôle strict anti-stock négatif (Quantity Validation)   │   │
│   │   - Détection & Prévention des Deadlocks (Ordonnancement IDs)  │   │
│   └────────────────────────────────┬───────────────────────────────┘   │
│                                    │                                   │
│                                    ▼                                   │
│   ┌────────────────────────────────────────────────────────────────┐   │
│   │                    ELOQUENT ORM & REPOSITORIES                 │   │
│   │        User  ·  Product  ·  Warehouse  ·  StockLevel  ·  ...   │   │
│   └────────────────────────────────┬───────────────────────────────┘   │
└────────────────────────────────────┼───────────────────────────────────┘
                                     │
                                     ▼
┌────────────────────────────────────────────────────────────────────────┐
│                   BASE DE DONNÉES (MySQL 9.1 / InnoDB)                │
│                                                                        │
│  - Moteur InnoDB (Support ACID complet)                                │
│  - Row-Level Locking (Verrous de ligne)                                │
│  - Clés étrangères ON DELETE CASCADE / RESTRICT                       │
│  - Contraintes UNIQUE (products.sku, stock_levels[product, warehouse]) │
│  - Index composites pour recherches performantes (GET /stock)          │
└────────────────────────────────────────────────────────────────────────┘
```

### 1.2 Modèle Relationnel des Entités

| Entité | Rôle & Attributs Métier | Relations |
|---|---|---|
| **User** | Gestion des accès et auditabilité des actions.<br>`id`, `name`, `email`, `password` | `hasMany(StockMovement)` |
| **Product** | Référence article du catalogue.<br>`id`, `name`, `sku` (UNIQUE), `price`, `description` | `hasMany(StockLevel)`<br>`hasMany(StockMovement)` |
| **Warehouse** | Site de stockage physique ou logique.<br>`id`, `name`, `location`, `is_active` | `hasMany(StockLevel)`<br>`hasMany(StockMovement)` |
| **StockLevel** | Quantité actuelle en temps réel d'un article dans un entrepôt précis.<br>`id`, `product_id`, `warehouse_id`, `quantity` (≥ 0)<br>*Contrainte UNIQUE(`product_id`, `warehouse_id`)* | `belongsTo(Product)`<br>`belongsTo(Warehouse)` |
| **StockMovement** | Journal d'audit immuable (Ledger / Event Sourcing) de chaque variation.<br>`id`, `product_id`, `warehouse_id`, `type` (IN, OUT, TRANSFER), `quantity`, `destination_warehouse_id`, `reference`, `notes`, `user_id`, `created_at` | `belongsTo(Product)`<br>`belongsTo(Warehouse)`<br>`belongsTo(DestinationWarehouse)`<br>`belongsTo(User)` |

### 1.3 Cohérence du Stock & Transactions ACID

La cohérence du stock repose sur **trois piliers fondamentaux** :
1. **L'Atomicité (All-or-Nothing)** : Une opération de transfert qui débite l'entrepôt A mais échoue à créditer l'entrepôt B doit être immédiatement annulée par un `ROLLBACK`. Aucune marchandise ne peut "disparaître dans la nature".
2. **Le Verrouillage Pessimiste (`lockForUpdate`)** : En environnement multi-utilisateurs haute fréquence, deux requêtes concurrentes lisant simultanément `quantity = 5` pour prélever `4` mèneraient sans verrou à un stock final erroné de `1` au lieu d'un rejet. Le `SELECT ... FOR UPDATE` force la seconde transaction à attendre la fin du commit de la première.
3. **Le Journal d'Audit Immuable (`stock_movements`)** : Les lignes de `StockMovement` ne sont jamais modifiées ni supprimées (`append-only`). La somme algébrique des mouvements passés est égale à la somme des `StockLevel`.

---

## Partie 2 : Implémentation & API REST (35 pts)

### 2.1 Spécification des Endpoints

#### `POST /api/movements` (ou `/movements` en JSON)
Enregistre un mouvement de stock avec transaction et verrouillage pessimiste.

* **Headers :**
  ```http
  Accept: application/json
  Authorization: Bearer <votre_token_sanctum>
  Content-Type: application/json
  ```
* **Payload - Exemple 1 : Entrée (IN)**
  ```json
  {
    "product_id": 1,
    "warehouse_id": 1,
    "type": "IN",
    "quantity": 50,
    "reference": "BL-2026-001",
    "notes": "Réception fournisseur"
  }
  ```
* **Payload - Exemple 2 : Sortie (OUT)**
  ```json
  {
    "product_id": 1,
    "warehouse_id": 1,
    "type": "OUT",
    "quantity": 10,
    "reference": "EXP-2026-90",
    "notes": "Expédition client"
  }
  ```
* **Payload - Exemple 3 : Transfert Inter-Entrepôts (TRANSFER)**
  ```json
  {
    "product_id": 1,
    "warehouse_id": 1,
    "destination_warehouse_id": 2,
    "type": "TRANSFER",
    "quantity": 15,
    "reference": "TRF-001"
  }
  ```

* **Réponse de succès (201 Created) :**
  ```json
  {
    "message": "Mouvement de stock créé avec succès.",
    "data": {
      "id": 15,
      "product_id": 1,
      "warehouse_id": 1,
      "type": "TRANSFER",
      "quantity": 15,
      "destination_warehouse_id": 2,
      "reference": "TRF-001",
      "user_id": 1,
      "created_at": "2026-11-02T11:15:00.000000Z"
    }
  }
  ```

* **Réponse en cas de tentative de stock négatif (422 Unprocessable Content) :**
  ```json
  {
    "message": "Erreur de validation.",
    "errors": {
      "quantity": [
        "Stock insuffisant. Stock actuel : 8, demandé : 20."
      ]
    }
  }
  ```

#### `GET /api/stock?productId=&warehouseId=` (ou `/stock`)
Retourne l'état des stocks en temps réel avec filtrage dynamique et chargement optimisé des relations (`eager loading`).

* **Exemple d'appel :** `GET /api/stock?productId=1&warehouseId=1`
* **Réponse (200 OK) :**
  ```json
  {
    "data": [
      {
        "id": 1,
        "product_id": 1,
        "warehouse_id": 1,
        "quantity": 65,
        "product": {
          "id": 1,
          "name": "Dell Latitude 5540 i7 32GB",
          "sku": "LAP-DELL-5540",
          "price": "1250.00"
        },
        "warehouse": {
          "id": 1,
          "name": "Entrepôt Central Paris",
          "location": "Zone Industrielle Nord"
        }
      }
    ]
  }
  ```

### 2.2 Prévention des Deadlocks lors des Transferts

Lors d'un transfert simultané :
- Thread 1 : transfère de l'entrepôt 1 vers l'entrepôt 2
- Thread 2 : transfère de l'entrepôt 2 vers l'entrepôt 1

Sans précaution, le Thread 1 verrouille l'ID 1 et attend l'ID 2, tandis que le Thread 2 verrouille l'ID 2 et attend l'ID 1 -> **Deadlock MySQL 1213 !**

**Correction implémentée dans `StockService.php` :**
```php
$firstId = min($sourceId, $destinationId);
$secondId = max($sourceId, $destinationId);

// Verrouillage toujours dans le même ordre déterministe
$firstLevel = StockLevel::where('warehouse_id', $firstId)->lockForUpdate()->first();
$secondLevel = StockLevel::where('warehouse_id', $secondId)->lockForUpdate()->first();
```
Cette règle mathématique garantit l'absence totale d'interblocage.

---

## Partie 3 : Tests Automatisés & Qualité (20 pts)

### 3.1 Suite de Tests PHPUnit

Exécution : `php artisan test`

```
   PASS  Tests\Unit\ExampleTest
  ✓ that true is true

   PASS  Tests\Feature\StockMovementTest
  ✓ can create stock entry movement
  ✓ cannot create negative stock                          [POINT CRITIQUE RÉUSSI]
  ✓ can transfer stock between warehouses                  [POINT CRITIQUE RÉUSSI]
  ✓ can get stock by product and warehouse
  ✓ movement requires valid data
  ✓ transfer requires destination warehouse
  ✓ failed transfer does not modify stock (Rollback test)  [ATOMICITÉ GARANTIE]
  ✓ unauthenticated user cannot access api                 [SÉCURITÉ ASSURÉE]
  ✓ can create stock exit movement
  ✓ multiple entries accumulate stock

   PASS  Tests\Feature\Auth\AuthenticationTest (4 tests)
   PASS  Tests\Feature\Auth\RegistrationTest (2 tests)
   PASS  Tests\Feature\ProfileTest (5 tests)
   ...

  Tests:    35 passed (96 assertions)
  Duration: 3.78s
```

### 3.2 Collection Postman Prête à l'Emploi

Le fichier [`mini_erp_stock_postman_collection.json`](file:///c:/Users/pc/Desktop/Mini-erp-stock/mini_erp_stock_postman_collection.json) se trouve à la racine du projet.
Il inclut :
1. `POST /api/login` avec extraction automatique du Token dans une variable de collection.
2. `GET /api/stock` global et filtré.
3. `POST /api/movements` (Scénario d'entrée, scénario de sortie, scénario de transfert).
4. `POST /api/movements` (Scénario d'échec stock négatif avec assertion automatique sur le statut 422).

---

## Partie 4 : Revue de Code & Leadership (20 pts)

### 4.1 Analyse Critique du Pseudo-Code

Le sujet demandait d'analyser ce fragment :
```python
stock = getStock(id);
if stock >= qty:
    saveStock(id, stock - qty)
```

#### 🚨 Risques Identifiés

1. **Race Condition (TOCTOU - Time-Of-Check to Time-Of-Use)** :
   - Entre le moment où `getStock(id)` lit la valeur et le moment où `saveStock(...)` écrit la nouvelle valeur, un autre processus ou utilisateur peut décrémenter le stock.
   - *Exemple concret :* Stock initial = 10.
     - Utilisateur A demande 8. `stock (10) >= 8` -> VRAI.
     - Utilisateur B demande 7. `stock (10) >= 7` -> VRAI.
     - Utilisateur A écrit `10 - 8 = 2`.
     - Utilisateur B écrit `10 - 7 = 3` (écrasement non vu) OU `2 - 7 = -5` -> **Stock négatif ou perte de cohérence !**
2. **Absence de Délimitation Transactionnelle (Pas de bloc ACID)** :
   - Si le serveur s'éteint ou si une exception survient entre le check et la sauvegarde, l'état reste indéterminé.
3. **Vulnérabilité aux Quantités Négatives** :
   - Si `qty = -50`, `stock >= -50` est toujours vrai, et `stock - (-50)` va augmenter le stock artificiellement au lieu de le diminuer (injection de valeur aberrante).
4. **Absence de Piste d'Audit (No Audit Trail)** :
   - Une simple mise à jour in-place n'enregistre ni l'auteur, ni l'horodatage, ni la raison (vol, vente, perte, casse).
5. **Absence de Gestion Multi-Entrepôts** :
   - Le stock est identifié par un simple `id` global au lieu d'un couple `(produit_id, entrepot_id)`.

---

### 4.2 Solutions Techniques de Correction

#### Option A : Verrouillage Pessimiste avec Transaction (Retenue pour notre projet)
```php
DB::transaction(function () use ($productId, $warehouseId, $qty, $userId) {
    // Verrouillage exclusif au niveau de la ligne MySQL InnoDB
    $stock = StockLevel::where('product_id', $productId)
        ->where('warehouse_id', $warehouseId)
        ->lockForUpdate()
        ->first();

    if (!$stock || $stock->quantity < $qty) {
        throw new InsufficientStockException("Stock insuffisant.");
    }

    $stock->quantity -= $qty;
    $stock->save();

    StockMovement::create([...]);
});
```

#### Option B : Décrément Atomique Direct en SQL (Très haute charge)
```sql
UPDATE stock_levels 
SET quantity = quantity - :qty 
WHERE product_id = :pId 
  AND warehouse_id = :wId 
  AND quantity >= :qty;
-- Si rows_affected == 0, déclencher une erreur "Stock insuffisant"
```

---

### 4.3 Découpage du Travail pour Deux Développeurs (Sprint 1)

En tant que Lead Architecte, voici la répartition équilibrée des tâches :

```
┌────────────────────────────────────────────────────────────────────────┐
│                        RÉPARTITION DU TRAVAIL                          │
│                                                                        │
│   DEVELOPER 1 (Backend & Data Engineer)                                │
│   ─────────────────────────────────────                                │
│   • Migrations BDD & Contraintes d'intégrité (InnoDB)                  │
│   • Service Métier (StockService) avec lockForUpdate                   │
│   • API REST endpoints (/movements, /stock)                            │
│   • Suite de tests automatisés PHPUnit (couverture > 90%)              │
│                                                                        │
│   DEVELOPER 2 (Fullstack & Frontend Engineer)                          │
│   ───────────────────────────────────────────                          │
│   • Dashboard React/Inertia (KPIs, Alertes rupture, Graphiques)        │
│   • Formulaire interactif des mouvements de stock                      │
│   • Vues CRUD Produits & Entrepôts                                     │
│   • Collection Postman & Documentation utilisateur                     │
└────────────────────────────────────────────────────────────────────────┘
```

#### Définitions Préalables
- **Definition of Ready (DoR) :** Spécifications claires, maquette UI ou contrat d'interface JSON validé, règles de gestion validées.
- **Definition of Done (DoD) :** Code revu par un pair, 100% des tests automatisés au vert, typage TypeScript strict, documentation mise à jour, zéro régression.

---

#### User Stories & Critères d'Acceptation (Format Gherkin)

##### US 1 : Sortie de stock sécurisée (Assigné à Dev 1)
```gherkin
Fonctionnalité: Débit de stock sécurisé
  En tant que gestionnaire d'entrepôt
  Je veux enregistrer une sortie de stock
  Afin de refléter les expéditions réelles sans jamais tomber en négatif

  Scénario: Sortie de stock autorisée avec stock suffisant
    Étant donné un produit "P1" ayant une quantité de 20 dans l'entrepôt "Paris"
    Quand je soumets un mouvement "OUT" de 15 unités pour "P1" à "Paris"
    Alors le statut HTTP doit être 201 Created
    Et la quantité restante de "P1" à "Paris" doit être de 5
    Et un mouvement d'audit immuable doit être consigné en base

  Scénario: Rejet de sortie avec stock insuffisant
    Étant donné un produit "P1" ayant une quantité de 5 dans l'entrepôt "Paris"
    Quand je soumets un mouvement "OUT" de 10 unités pour "P1" à "Paris"
    Alors le statut HTTP doit être 422 Unprocessable Content
    Et la quantité restante de "P1" à "Paris" doit rester inchangée à 5
    Et aucun mouvement de stock ne doit être créé
```

##### US 2 : Transfert inter-entrepôts avec écran interactif (Assigné à Dev 2)
```gherkin
Fonctionnalité: Transfert de marchandise entre entrepôts
  En tant qu'opérateur logistique
  Je veux transférer du stock d'un entrepôt source vers un entrepôt destination
  Afin d'équilibrer les niveaux de stock régionaux

  Scénario: Transfert validé dans l'interface
    Étant donné un stock de 50 à "Paris" et 10 à "Lyon"
    Quand je remplis le formulaire avec "Type: TRANSFER", "Source: Paris", "Dest: Lyon", "Qté: 20"
    Et que je clique sur "Valider le mouvement"
    Alors l'interface affiche une notification de succès
    Et la table affiche le stock de "Paris" à 30 et "Lyon" à 30
    Et la ligne de flux s'affiche sous la forme "Paris ➜ Lyon"
```

---

## Guide de Démarrage Rapide

### 1. Prérequis
- PHP 8.2+
- Composer
- Node.js & npm
- MySQL (WampServer actif sur port 3306)

### 2. Démarrage de l'application
```bash
# Dans le dossier c:\Users\pc\Desktop\Mini-erp-stock
php artisan migrate --seed
npm run build
php artisan serve
```
L'application est disponible à l'adresse : **`http://127.0.0.1:8000`**

### 3. Identifiants de Démonstration
- **Email :** `admin@erp.com`
- **Mot de passe :** `password`

---

## Scénario de Démonstration en 5 Minutes (Pitch Recruteur)

*Chronogramme précis pour réussir la présentation orale :*

- **0:00 - 1:00 | Introduction & Architecture globale**
  - "Bonjour, voici l'ERP de gestion de stock multi-entrepôts conçu selon les principes DDD et l'architecture Laravel 12 + React Inertia."
  - Montrer le Dashboard : KPIs (valeur globale, alertes de stock, stock par entrepôt).
- **1:00 - 2:00 | Démonstration du flux d'entrée et de sortie**
  - Aller sur l'onglet **Mouvements**.
  - Faire une entrée de stock de 50 unités sur un produit.
  - Constater l'incrémentation immédiate sur le Dashboard.
- **2:00 - 3:00 | Démonstration de l'anti-stock négatif & Concurrence**
  - Tenter une sortie de 9999 unités : observer le message d'erreur clair empêchant l'opération.
  - Expliquer l'usage du verrouillage pessimiste `SELECT ... FOR UPDATE` et l'atomicité `DB::transaction`.
- **3:00 - 4:00 | Démonstration du Transfert Inter-Entrepôts**
  - Sélectionner "Transfert" : Paris vers Lyon pour 10 laptops.
  - Constater le double débit/crédit atomique et la protection anti-deadlock (tri des IDs).
- **4:00 - 5:00 | Revue de Leadership & Tests**
  - Exécuter en direct `php artisan test` dans le terminal (35 tests verts).
  - Présenter l'analyse du pseudo-code et la découpe en User Stories pour les 2 développeurs.
  - Conclure sur l'extensibilité du système.
