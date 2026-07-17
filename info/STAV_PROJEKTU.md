# FAKTÚROVOD — stav projektu

> Snímka k **16. 7. 2026** (po ~2 dňoch vývoja, 9 commitov, 70 testov zelených).
> Živý dokument s postupom je `CLAUDE.md` v koreni repa (sekcia 15 — PROGRESS LOG);
> tento súbor je prehľadové zhrnutie.

---

## ✅ Čo je naprogramované

### Základ
- **Dev prostredie (Windows):** PHP 8.3.32 + Composer 2.10.2 (winget), CA bundle pre SSL,
  Laravel 13, lokálne SQLite. Bez Dockeru/Node — všetko beží priamo.
- **Git repo** s disciplinovanými commitmi per milestone. ⚠️ Zatiaľ len lokálne — bez zálohy na GitHube!

### Outbound rúra (jadro produktu) — funguje end-to-end
`CSV/XML export → mapping → UBL 2.1 → XSD validácia → ePošťák sandbox → doručené`

| Komponent | Súbory | Stav |
|---|---|---|
| **Ingest CSV** | `app/Services/Mapping/Readers/CsvReader.php` | ✅ delimiter/enclosure, CP1250 cez iconv, BOM, prázdne riadky |
| **Ingest XML** | `app/Services/Mapping/Readers/XmlReader.php` | ✅ record_xpath, polia ako relatívne XPath |
| **Mapping engine** | `app/Services/Mapping/` | ✅ JSON definície (dáta, nie kód): from/const/default, číselníkové mapy, transformácie date+decimal; **všetky chyby faktúry naraz**, slovenské hlášky s číslom riadku |
| **UBL 2.1 builder** | `app/Services/Ubl/UblInvoiceBuilder.php` | ✅ EN 16931 / Peppol BIS 3.0 (BuyerReference, EndpointID), sumy a DPH rozpis cez brick/math (HALF_UP) |
| **XSD validátor** | `app/Services/Ubl/XsdValidator.php` | ✅ oficiálne OASIS schémy vendorované v `resources/schemas/ubl-2.1/` |
| **Poštár adaptér** | `app/Services/Postar/` | ✅ `PostarAdapterInterface` (poistka proti lock-inu) + `EpostakConnectorAdapter`: OAuth s token cachingom, idempotentné odoslanie, stavy, chyby poštára po slovensky |
| **Stavový automat** | `app/Enums/InvoiceStatus.php`, `app/Models/Invoice.php` | ✅ `received→mapped→validated→queued→sent→delivered\|rejected`, `failed→received` (retry); prechody len cez `transitionTo()` |
| **Dátový model** | `invoices`, `invoice_events`, `mappings` | ✅ idempotencia (unique external_id), append-only audit, verzované mapovania, snapshot definície per faktúra |
| **Pipeline** | `app/Services/Pipeline/InvoicePipeline.php` | ✅ chybná faktúra neblokuje ostatné zo súboru; stavy poštára sa mapujú späť (validation_failed → rejected s chybami) |

**Overené naživo:** faktúry z ukážkového legacy CSV doručené cez `dev.epostak.sk`
na demo participanta `0245:0000000002` (stav `delivered`), vrátane opravy 2 reálnych
Peppol chýb, ktoré chytil sandbox.

### Dashboard (Blade + vlastné CSS, bez build kroku)
- **Login** s rate limitom (5/min); používatelia cez `php artisan dashboard:user <email>`
- **Faktúry:** zoznam s filtrami stavov a stránkovaním, upload exportu priamo v prehliadači,
  detail s kanonickými údajmi, položkami, audit trailom a UBL XML (zobrazenie + download)
- **Fronta chýb** (kľúčová obrazovka): všetky chyby faktúry naraz po slovensky,
  tlačidlo „Skúsiť znova", počítadlo v navigácii
- **Mapovania:** CRUD + JSON editor, uloženie zvýši verziu, staré faktúry nedotknuté

### CLI príkazy
| Príkaz | Účel |
|---|---|
| `php artisan ubl:hello` | vygeneruj + zvaliduj ukážkovú UBL faktúru |
| `php artisan invoice:convert <export> <mapping>` | konverzia súboru bez DB (rýchly test mapovania) |
| `php artisan invoice:ingest <export> <mapping> [--process]` | prijatie do rúry (idempotentné) |
| `php artisan invoice:process [--retry=ID]` | spracovanie čakajúcich + obnova stavov |
| `php artisan invoice:list [--events=ID]` | prehľad / audit trail faktúry |
| `php artisan postar:send [--file=…] [--status]` | priame odoslanie do sandboxu |
| `php artisan dashboard:user <email>` | vytvorenie/zmena používateľa dashboardu |

### Testy
70 testov / 230 assertions: mapping (vrátane chybných vstupov), sumy/DPH/zaokrúhľovanie,
UBL builder, XSD scenáre, adaptér poštára (HTTP fake), stavový automat, pipeline
(izolácia chýb, výpadok+retry, odmietnutie), dashboard (auth, throttle, upload, fronta chýb).

---

## 🔜 Čo sa ešte má spraviť

### Najbližšie (dohodnuté poradie)
1. **Záloha na GitHub** — privátne repo, remote, push (⚠️ jediná kópia práce je na jednom disku).
   Potrebná akcia používateľa: `gh auth login` alebo založiť repo na github.com.
2. **Metering** — tabuľka `usage_meters`: počet dokumentov per klient/mesiac/smer,
   počítané pri odovzdaní poštárovi (retry sa neráta dvakrát). Obrazovka „Spotreba"
   v dashboarde: tier klienta, odhad nákladu na ePošťák, marža. Podklad fakturácie tierov.
3. **Archív** — pri konečnom stave faktúry uložiť do object storage: originál vstupu,
   UBL XML, validačný report, dôkaz o doručení; SHA-256 kontrolné súčty, WORM princíp,
   retencia 10 rokov. Tabuľka `archive_objects`, download v dashboarde.
   Lokálne disk cez Laravel filesystem → prod Hetzner S3 len zmenou `.env`.

### Zvyšok MVP (Fáza 1)
- **E-mail alerting** — pri zlyhaní doručenia a hromadení chýb (má zmysel až na serveri)
- **Schematron sidecar (KoSIT)** — 2. validačná vrstva lokálne; vyžaduje OpenJDK;
  zatiaľ supluje sandbox (validuje Peppol pravidlá a vracia štruktúrované chyby)
- **SK biznis kontroly** — 3. vrstva: IČ DPH formát, súčty, povinné polia + slovenské hlášky
- **Mapping fixtures** — dobropis (CreditNote v builderi), oslobodenie od DPH, cudzia mena, zálohová faktúra
- **E2E s reálnou vzorkou** — 10–20 skutočných faktúr od pilotného klienta bez ručného zásahu
- **Multi-tenant** — tabuľky tenants/client_companies; zatiaľ 1 klient natvrdo (per MVP OK)
- Batch status endpoint poštára (optimalizácia pri väčších objemoch)

### Fáza 2 — pilot (jeseň 2026)
- Pilotný klient + vzorka faktúr, mapovanie pilota, 100 % priechodnosť v sandboxe
- Produkčné API + white-label Founder (⚠️ podpísať až s prvým platiacim — 12-mes. viazanosť)
- Paralelná prevádzka, právne dokumenty + poistenie, landing page + SEO články, case study
- Nasadenie na VPS (EÚ), PostgreSQL, zálohy

### Fáza 3 — škálovanie (2027)
- Inbound flow (príjem faktúr → import formát klienta + čitateľné PDF + potvrdenie v lehote)
- DBF adaptér (FoxPro/dBase — konkurenčná výhoda) · MDB/DB adaptéry · sync agent (inštalátor)
- Druhý poštár adaptér · white-label program pre IT firmy · 5 → 10 → 20 klientov · s.r.o.

### Netechnické (na používateľovi, kód ich nevyrieši)
- [ ] **Súhlas zamestnávateľa** (§ 83 ZP) — brief: najväčšie riziko projektu
- [ ] **5 rozhovorov s účtovníčkami** (validácia trhu + leady)
- [ ] Živnosť · doména fakturovod.sk · integrátorská zmluva ePošťák (exit, ceny)
- [ ] Overiť v zákone 385/2025: lehota potvrdenia, náležitosti archivácie, SK CIUS
- [ ] Rozhodnúť: poplatky poštára 1:1 vs. zabalené v tieroch (metering dodá čísla)
