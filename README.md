# Pay. plugin voor Zencart 1.5.7d

Bijgewerkte versie van de Pay. betaalmodule voor Zencart 1.5.7d.
Gebruikt de nieuwe [Pay. PHP SDK](https://github.com/paynl/php-sdk) (paynl/php-sdk ≥ 1.2).

---

## Wat is er veranderd t.o.v. de oude plugin?

| Oud (v1.x) | Nieuw (v3.x) |
|---|---|
| `Pay_Api_Start` (eigen CURL) | `OrderCreateRequest` (Pay. PHP SDK) |
| `Pay_Api_Info` voor statuscheck | `OrderStatusRequest` (Pay. PHP SDK) |
| `SERVICE_ID` + `API_TOKEN` | **AT-code** (username) + **API Token** (password) |
| PHP 5.x / 7.x | **PHP 8.1+** |
| Handmatige autoload klassen | Composer autoload |
| `v3` REST-API direct | Actuele Pay. API via SDK |

---

## Installatie

### 1. Composer dependencies installeren

Voer dit commando uit vanuit de map waar `composer.json` staat (de plugin root):

```bash
composer install
```

Dit installeert de Pay. PHP SDK in:
`includes/modules/payment/paynl/vendor/`

### 2. Bestanden kopiëren naar Zencart

Kopieer de mapstructuur naar je Zencart root:

```
includes/modules/payment/paynl/          → [zencart]/includes/modules/payment/paynl/
includes/modules/payment/paynl_*.php     → [zencart]/includes/modules/payment/
includes/languages/dutch/modules/payment/paynl_*.php  → [zencart]/includes/languages/dutch/modules/payment/
includes/languages/english/modules/payment/paynl_*.php → [zencart]/includes/languages/english/modules/payment/
ext/modules/payment/paynl/               → [zencart]/ext/modules/payment/paynl/
```

### 3. Module activeren in Zencart admin

1. Ga naar **Admin → Modules → Payment**
2. Selecteer een Pay. betaalmethode (bijv. `Pay. iDEAL`)
3. Klik op **Install**
4. Vul in:
   - **AT-code** – je AT-####-#### code (vind je in [Pay. dashboard](https://my.pay.nl) → Mijn account)
   - **API Token** – je API token (zelfde plek)
   - **Service ID / SL-code** – optioneel, bijv. SL-####-####

---

## Authenticatie

De nieuwe SDK gebruikt **Basic Authentication**:

- **Username**: je `AT-code` (bijv. `AT-1234-5678`)
- **Password**: je `API Token` (bijv. een lang token)

De oude `SERVICE_ID` (`SL-code`) is optioneel en kan nog steeds worden ingevuld als je meerdere verkooppunten hebt.

---

## Bestandsstructuur

```
zencart-plugin-157/
├── composer.json
├── includes/
│   ├── modules/payment/
│   │   ├── paynl/
│   │   │   ├── paynl.php          ← Hoofd klasse (basis voor alle methoden)
│   │   │   └── vendor/            ← Composer vendor (na composer install)
│   │   ├── paynl_ideal.php
│   │   ├── paynl_visamastercard.php
│   │   └── ... (60+ betaalmethoden)
│   └── languages/
│       ├── dutch/modules/payment/
│       │   ├── paynl_ideal.php
│       │   └── ...
│       └── english/modules/payment/
│           ├── paynl_ideal.php
│           └── ...
└── ext/modules/payment/paynl/
    ├── paynl_exchange.php     ← Exchange / webhook handler (server-to-server)
    └── return.php             ← Return URL handler (klant redirect)
```

---

## Exchange URL (webhook)

Pay. stuurt een server-to-server POST naar:
```
https://jouwwinkel.nl/ext/modules/payment/paynl/paynl_exchange.php?method=IDEAL
```

De `method` parameter is de UPPERCASE beschrijving van de betaalmethode.

---

## Vereisten

- Zencart 1.5.7d
- PHP 8.1 of hoger
- Composer
- `ext-curl` en `ext-json` PHP extensies
