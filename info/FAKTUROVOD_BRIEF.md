1. E-faktúra konektor pre firemné a legacy systémy. Fakturačné softvéry (KROS, SuperFaktúra, Pohoda) svojich klientov pokryjú. Kto ostáva visieť: tisíce firiem s vlastným/starým ERP, interné systémy písané na mieru — presne to, čo denne opravuješ v práci. Produkt: API vrstva, ktorá zoberie čokoľvek (CSV, proprietárne XML, DB export), zvaliduje a preloží do UBL 2.1 / EN 16931, pošle cez poštára a naopak prijaté XML preloží do formátu, ktorý starý systém vie importovať. Plus monitoring a archív. Zaujímavý detail: štandardizované rozhranie SAPI-SK momentálne oficiálne podporujú len dvaja poštári — abstrakcia nad viacerými poštármi má hodnotu sama o sebe. Vstup: 2–3 platené integračné projekty ručne (referencie + peniaze), potom produktizácia. Cena: setup 2–5 tis. € + 50–200 €/mes. Toto je tvoj najsilnejší skill-fit a deadline robí predaj za teba.

# FAKTÚROVOD (pracovný názov) — projektový brief

> E-faktúra konektor pre firemné legacy systémy. Slovenský trh, tvrdý zákonný deadline 1. 1. 2027.
> Tento súbor vlož do koreňa repozitára ako `CLAUDE.md` (alebo `docs/BRIEF.md`) — slúži ako kontext pre Claude Code.
> Solo projekt: 1 developer (PHP, MySQL, Oracle, XML), večery/víkendy, popri zamestnaní.

---

## 1. Čo staviame (jedna veta)

API/middleware vrstva, ktorá zoberie faktúry zo starého firemného systému v ľubovoľnom formáte (CSV, XML, JSON, DB export), preloží ich do UBL 2.1 podľa EN 16931, zvaliduje, odošle cez certifikovaného „digitálneho poštára" do siete Peppol — a opačným smerom prijaté e-faktúry preloží do formátu, ktorý starý systém vie importovať. Plus dashboard, monitoring, fronta chýb a zákonná archivácia.

**Čo NIE sme:** nie sme digitálny poštár (žiadna certifikácia FS, žiadne OpenPeppol členstvo — to je náklad poštára). Sme „posledná míľa" medzi klientom a poštárom.

---

## 2. Regulačný kontext (fakty overené 07/2026)

- Zákon č. 385/2025 Z. z.: povinná tuzemská B2B e-fakturácia od **1. 1. 2027** pre platiteľov DPH. Faktúra = štruktúrované XML (UBL 2.1, norma EN 16931), doručenie cez certifikovaného digitálneho poštára.
- Prijímať e-faktúry musia prakticky všetci podnikatelia (aj neplatitelia DPH, aj SZČO).
- Prechodné obdobie 1. 1. – 30. 6. 2027: možné alternatívne kanály; od **1. 7. 2027 výlučne Peppol**.
- Dobrovoľná fáza beží od Q2 2026. Cezhraničné transakcie ~2030 (EÚ ViDA).
- Poštár posiela údaje aj Finančnej správe; prijímateľ má zákonnú lehotu na potvrdenie údajov (⚠️ presnú lehotu overiť v zákone — v konverzácii sa pracovalo s 5 dňami).
- Trh: ~370 000 dotknutých subjektov. Poštárov je už 54 (45 certifikovaných + 9 v akreditácii), ceny 0–8 €/mes → komodita. Štandardizované API rozhranie **SAPI-SK** zatiaľ podporujú len ~2 poštári (ePošťák, Fakturix) → abstrakcia nad poštármi má hodnotu.

---

## 3. Cieľový zákazník

1. **Priamo:** firmy 10–100 zamestnancov (veľkoobchod, výroba, doprava), založené pred ~2012, s ERP/IS písaným na mieru (PHP, Delphi, FoxPro, Oracle Forms…), ktorého dodávateľ zanikol/nereaguje. Firma nechce meniť systém (migrácia = desiatky tisíc € + rok chaosu).
2. **White-label kanál:** malé slovenské IT firmy, ktoré v 2005–2015 postavili klientom systémy a neoplatí sa im písať Peppol vrstvu pre 3 klientov → licencujú náš konektor. 1 IT firma = 10–30 koncových klientov.
3. **Účtovné kancelárie:** odporúčací kanál (provízia 10–15 % zo setupu) + neskôr produkt „multi-klient inbox prijatých faktúr".

Predajný argument (kotva): výmena ERP 50 000 €+ / integrácia na mieru 8–15 000 € / my: setup od 1 900 € + od 69 €/mes, systém klienta ostáva nedotknutý, o zmeny legislatívy sa staráme my.

---

## 4. Funkčná špecifikácia

### 4.1 Outbound (vystavené faktúry) — JADRO PRODUKTU
1. **Ingest:** prijatie dát klientovho systému — API endpoint (JSON/XML), sledovaný SFTP adresár, upload v dashboarde, neskôr DB polling. Idempotencia (dedup podľa externého ID).
2. **Mapping engine:** konfiguračne riadené mapovanie (mapovacia definícia = verzovaný JSON/YAML per klient, NIE kód per klient). Transformácie polí, číselníky (kódy DPH, merné jednotky UN/ECE, meny), defaulty, výpočty súm.
3. **UBL builder:** generovanie UBL 2.1 Invoice / CreditNote XML podľa EN 16931 (+ SK CIUS, ak existuje — overiť).
4. **Validátor:** 3 vrstvy — (a) XSD schéma UBL 2.1, (b) schematron EN 16931 + Peppol BIS 3.0 pravidlá, (c) vlastné biznis kontroly (IČ DPH formát, súčty, rozpis DPH, povinné polia). Chyby → fronta chýb s čitateľným vysvetlením po slovensky.
5. **Odoslanie:** adapter na API poštára, retry s exponenciálnym backoffom, sledovanie stavov (odoslané / doručené / odmietnuté), webhooky/polling stavov.
6. **Archív:** originál vstupu + UBL + validačný report + potvrdenia; WORM prístup, retencia 10 rokov.

### 4.2 Inbound (prijaté faktúry) — FÁZA 2
- Príjem UBL od poštára (webhook/poll) → validácia → konverzia do klientovho import formátu (CSV/XML/JSON podľa mapovacej definície) → vygenerovanie čitateľného PDF (vizualizačný XSLT k EN 16931) → notifikácia → potvrdenie prijatia v zákonnej lehote → archív.

### 4.3 Dashboard (webová aplikácia)
- Multi-tenant: tenant = náš zákazník (firma alebo white-label IT partner), pod ním koncové firmy.
- Zoznam faktúr + stavy, **fronta chýb** (najdôležitejšia obrazovka!), detail validačného reportu, ručné znovuodoslanie, správa mapovaní, archív s vyhľadávaním, audit log, správa API kľúčov.
- Alerting: e-mail (neskôr SMS) pri zlyhaní doručenia a pri hromadení chýb.

### 4.4 Multi-poštár vrstva
- Adapter pattern: `PostarAdapterInterface` (sendInvoice, getStatus, fetchIncoming, confirmReceipt). Implementácie: ePošťák (prvý — má bezplatný sandbox), SAPI-SK generický adaptér, ďalší poštár vo fáze 2. Klient si vyberá poštára, my sme neutrálni.

---

## 5. Rozsah MVP (postaviť PRVÉ, ~200–300 h)

**In:** outbound flow (ingest CSV + XML → mapping → UBL → validácia → ePošťák sandbox → stav), minimálny dashboard (login, faktúry, chyby, mapovanie ako JSON editor), archív do object storage, e-mail alerty, 1 tenant / 1 klient natvrdo je OK na pilota.
**Out (zatiaľ):** inbound, viac poštárov, white-label API, fakturácia zákazníkov, SSO, mobilná appka, DB polling.

**Definícia hotového MVP:** reálna faktúra z CSV exportu pilotného klienta prejde celou rúrou do sandboxu poštára bez ručného zásahu a chybová faktúra skončí vo fronte s pochopiteľnou slovenskou hláškou.

---

## 6. Architektúra a stack

**Zásada: nudný monolit.** Spoľahlivosť sa dosahuje jednoduchosťou. Žiadne mikroslužby, žiadne experimenty.

- **Backend:** PHP 8.3 + Laravel (developer je PHP profík — nevymýšľať nový jazyk), alternatívne Symfony.
- **DB:** PostgreSQL (alebo MySQL/MariaDB — čo je bližšie). JSONB na payloady a mapovacie definície.
- **Queue:** Laravel queues (DB driver na štart, Redis + Horizon keď treba). Každý krok pipeline = job; stavový automat na faktúre.
- **Storage:** S3-kompatibilný object storage (Hetzner Object Storage / MinIO) na archív.
- **Validácia schematron:** pragmaticky = KoSIT validator (Java) ako interná HTTP sidecar služba v Dockeri; alternatíva phax ph-schematron. Neprepisovať schematron do PHP.
- **Infra:** 1 VPS (Hetzner, EÚ) + Docker Compose; oddelený prod a test. Zálohy DB + storage denne, off-site.
- **Bezpečnosť/GDPR:** sme sprostredkovateľ (faktúry obsahujú osobné údaje) → šifrovanie at rest aj in transit, izolácia tenantov na úrovni query (global scopes), audit log všetkých zásahov, žiadne osobné údaje v logoch, EÚ hosting.

**Stavový automat faktúry:** `received → mapped → validated → queued → sent → delivered | rejected | failed → (confirmed)` + `error` vetvy s retry počítadlom.

**Dátový model (jadro):** `tenants`, `users`, `client_companies`, `postar_accounts`, `mappings` (verzované), `invoices` (direction, status, external_id, source_payload, ubl_xml, validation_report), `invoice_events` (append-only audit), `deliveries`, `archive_objects`, `alerts`.

---

## 7. Technické zdroje (⚠️ overiť aktuálne verzie a názvy)

- UBL 2.1 špecifikácia (OASIS) + XSD schémy
- EN 16931 validačné artefakty — GitHub `ConnectingEurope/eInvoicing-EN16931`
- Peppol BIS Billing 3.0 pravidlá — GitHub OpenPeppol (`peppol-bis-invoice-3`)
- KoSIT validator — GitHub `itplr-kosit/validator`
- PHP UBL knižnice — napr. `num-num/ubl-invoice` (posúdiť vs. vlastný builder)
- Vizualizačný XSLT pre EN 16931 (render PDF prijatých faktúr)
- ePošťák: sandbox (zadarmo) + dokumentácia SAPI-SK
- Finančná správa SR: dokumentácia k IS EFA / e-faktúre, znenie zákona 385/2025 Z. z.

---

## 8. Roadmapa 90 dní

| Týždne | Kód | Biznis (súbežne!) |
|---|---|---|
| 1–2 | Štúdium UBL/EN16931, sandbox účet, „hello world" faktúra ručne do sandboxu | 5 rozhovorov s účtovníčkami (validácia trhu) |
| 3–6 | Core pipeline: ingest → mapping → UBL → validácia → odoslanie; testy na mapping a validácie | Súhlas zamestnávateľa (§ 83 ZP), živnosť, landing page |
| 7–8 | Dashboard, fronta chýb, archív, alerting | Hľadanie pilotného klienta (sieť, účtovníčky, IT firmy) |
| 9–12 | Onboarding pilota: mapovanie jeho exportu, sandbox prevádzka, oprava reality | Pilot za zvýhodnenú cenu výmenou za referenciu; cenníky poštárov |

---

## 9. Biznis parametre (zhrnutie z analýzy)

**Setup (jednorazovo):** Štandard 1 900 € (existujúci export, jednosmerne) / Rozšírený 3 500 € (mapovanie na mieru, obojsmerne) / Komplexný od 6 500 € (DB napojenie, multi-IČO). Zmeny po spustení 90 €/h.
**Mesačne (vystavené+prijaté):** S do 100 faktúr 69 € / M do 500 – 149 € / L do 2 000 – 290 € / XL od 490 €. Zahŕňa aktualizácie pri zmene legislatívy a schém (kľúčový predajný bod).
**White-label:** 690 €/mes platforma + 39 €/mes per koncový klient.
**Pravidlá:** prvých 3–5 klientov zľava 40–50 % na setup za referenciu (mesačné ceny plné!); viazanosť 12 mes.; ročná platba vopred −10 %; poplatky poštára = prenesený náklad 1:1; ročná indexácia.
**Cieľ roka 1:** pilot + 5–10 platiacich. Kontrolný bod: 20 klientov ≈ 60 000 € setupy + ~2 800 €/mes MRR.

---

## 10. Otvorené otázky — overiť PRED/POČAS vývoja (nekódovať naslepo)

1. Presné znenie zákona 385/2025: lehota potvrdenia prijatej faktúry, náležitosti archivácie, či existuje SK CIUS nad EN 16931.
2. Cenníky API pre integrátorov u 2–3 poštárov (každý má samostatný cenník) → vstupuje do kalkulácie.
3. **Písomný súhlas zamestnávateľa** so zárobkovou činnosťou (Zákonník práce § 83) — vybaviť pred prvým klientom; vývoj výhradne na vlastnom HW a vo vlastnom čase.
4. Právne dokumenty: zmluva o poskytovaní služieb, VOP, GDPR sprostredkovateľská zmluva, SLA 99,5 % (draft s AI + kontrola advokátom, ~300–600 €).
5. Poistenie profesijnej zodpovednosti IT (~150–300 €/rok) od prvého ostrého klienta.
6. Odvody 2026 (živnosť popri zamestnaní): zdravotné preddavky 0 € (súbeh so zamestnaním, doplatok v ročnom zúčtovaní ~16 % zo zisku); sociálne od 6. mesiaca mikroodvod ~131,34 €/mes, nad 9 144 €/rok príjmu min. ~303 €/mes → potvrdiť s účtovníčkou. Prechod na s.r.o. pri prvých vážnych zmluvách (ručenie, dôveryhodnosť).
7. Názov + doména: favorit **Faktúrovod** (fakturovod.sk), alternatívy Prípojka, Mostík, Prevodník. Overiť doménu .sk aj .cz, ÚPV SR známky, obchodný register. Vyhnúť sa „Peppol" a „eFaktúra" v názve.

---

## 11. Zásady vývoja (pre Claude Code)

- Slovenské chybové hlášky pre používateľov, anglický kód a komentáre.
- Mapovanie je DÁTA (verzovaný JSON per klient), nie kód. Nový klient = nová konfigurácia, nie nová vetva.
- Testy povinné na: mapping engine, výpočty súm/DPH, UBL builder, validačné scenáre (vrátane chybných vstupov). Fixture faktúry: bežná, dobropis, viac sadzieb DPH, oslobodenie, cudzia mena, zálohová.
- Každá faktúra má kompletný audit trail (append-only events) — pri spore musí byť rekonštruovateľné, čo sa kedy stalo.
- Idempotencia všade (opakovaný import nesmie duplikovať), retry s backoffom, dead-letter fronta.
- Žiadna optimalizácia na škálu vopred: 20 klientov × 500 faktúr/mes je malý objem; priorita je korektnosť a diagnostikovateľnosť.
- Feature flags per tenant namiesto forkov.
