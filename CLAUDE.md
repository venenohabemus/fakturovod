# FAKTÚROVOD — projektový dokument (v2, júl 2026)

> E-faktúra služba pre firmy so starými/vlastnými systémami. SK trh, zákonný deadline 1. 1. 2027.
> Ulož ako `CLAUDE.md` do koreňa repa — Claude Code ho číta automaticky.
> **Pokrok sa eviduje v sekcii 15 (PROGRESS LOG) na konci — udržiavať aktuálny pri každom sedení.**
> Solo projekt: 1 developer (PHP, MySQL, Oracle, XML), večery/víkendy popri zamestnaní.

---

## 1. Čo staviame

Kompletná e-fakturačná služba pod vlastnou značkou pre firmy, ktorých systém sa e-faktúru „nenaučí sám":
starý systém klienta ostáva nedotknutý → my z neho dostaneme dáta (export, agent, DBF, DB) → preložíme do UBL 2.1 / EN 16931 → zvalidujeme → doručíme cez Peppol (backend: ePošťák white-label) → prijaté faktúry preložíme späť do formátu klientovho systému → archív, monitoring, support.

**Predávame zachovanie, nie zmenu:** „U vás sa nezmení nič — vaši ľudia robia to, čo 15 rokov. Zmení sa len to, čo sa deje s faktúrou po stlačení tlačidla."

**Čo NIE sme:** nie sme certifikovaný poštár (certifikáciu, Peppol infraštruktúru, doručovanie a FS reporting nesie ePošťák/Kaja Solutions). Sme posledná míľa: extrakcia, mapovanie, validácia, integrácia, vzťah s klientom.

---

## 2. Regulačný kontext (overené 07/2026)

- Zákon 385/2025 Z. z.: povinná tuzemská B2B e-fakturácia od **1. 1. 2027** pre platiteľov DPH; faktúra = XML (UBL 2.1, EN 16931) cez certifikovaného digitálneho poštára. PDF/papier prestáva byť platnou B2B faktúrou.
- Prijímať musia prakticky všetci podnikatelia (aj neplatitelia DPH, SZČO).
- Prechodné obdobie 1. 1.–30. 6. 2027 (alternatívne kanály), od **1. 7. 2027 výlučne Peppol**. Cezhranične ~2030 (ViDA).
- Prijímateľ potvrdzuje údaje v zákonnej lehote (⚠️ overiť presnú lehotu v zákone; pracovná hypotéza 5 dní).
- Trh: ~370 000 subjektov; 54 poštárov (45 cert. + 9 v akreditácii) → doručovanie je komodita (0–8 €/mes). Peniaze sú v poslednej míli.

---

## 3. Zákazník

**Profil:** SK firma 10–100 zamestnancov (veľkoobchod, výroba, doprava, servis), založená pred ~2012, fakturuje z vlastného/starého programu bez živého dodávateľa; na systém je naviazaný sklad, cenníky, 15 rokov dát → nechce a nemôže ho vyhodiť.

**Stav dnes:** fakturantka vyklika faktúru v starom okne → tlač/PDF mailom → šanón → účtovníčka prepisuje. Prijaté PDF sa tlačia a prepisujú. Funguje — klient to nevníma ako problém, kým mu to zákon nezakáže.

**Stav po nás:** fakturantka robí presne to isté; faktúra automaticky tečie: export/agent → UBL → validácia → Peppol → odberateľ + FS → archív. Chyba = zrozumiteľný slovenský mail čo opraviť. Prijaté: XML → čitateľné PDF + importný súbor pre ich systém a účtovníčku.

**Technologické odtlačky cieľových systémov:** Visual FoxPro/dBase (**DBF** súbory — ľahko čitateľné, náš tromf), Delphi + BDE/Paradox/Firebird, MS Access (.mdb), staré PHP+MySQL intranety (domáci terén), klient-server (Oracle Forms, Btrieve) = „Komplexný" segment.
**NIE sú terč:** firmy na živej Pohode/KROS/Money/MRP — výrobca im to vyrieši updatom (Stormware má vlastného poštára). Nesúťažiť tam.

---

## 4. Konkurenčná pozícia

**Hodnotový reťazec:** [dáta v starom systéme] → extrakcia (agent/DBF/DB) → mapovanie a čistenie → UBL/validácia → [poštár] → Peppol.
Poštári (vrátane ePošťák Connectora) pokrývajú reťazec od poštára doprava. My všetko doľava — práca, ktorá sa nedá škálovať kódom, preto ju infra hráči nechcú.

**ePošťák nie je konkurent, ale dodávateľ + generátor dopytu:**
- Ich Connector predpokladá, že na strane ERP niekto programuje („čo musí opraviť ERP" je problém klienta = naša práca). Cieľová skupina Connectora = výrobcovia softvéru, nie osirotené systémy.
- Špinavú prácu odmietajú cenníkom: individuálne mapovanie dát min. 120 €/h, konzultácie 95 €/h, asistované zavedenie od 490 € → validuje NÁŠ cenník (90 €/h, setupy 1 900–3 500 € sú trhové až mierne pod trhom).
- Ich blog (72 článkov, SEO na „e-faktúra 2027") vzdeláva trh a vyrába dopyt, z ktorého žneme.
- Reálna konkurencia = iní lokálni integrátori a IT firmy → vyhráva rýchlosť a referencie pred jeseňou 2026.

**Odpoveď na „prečo nejdem priamo za ePošťákom?":** „Pôjdete — cez nás. ePošťák je pošta; my naučíme váš 30-ročný systém písať listy, nosíme ich na poštu a triedime došlé. Pošta to za vás nespraví — sama to hovorí vo svojom cenníku."

**Pasce, ktorým sa vyhýbame:** (a) necieliť na firmy s moderným softvérom — komoditný boj; (b) nebyť „predajca prístupu k poštárovi" — to spraví ktokoľvek za 99 €; hodnota = mapovanie + prevádzka + vzťah; (c) nestavať vlastný Access Point — komodita za 99 €/mes, vlastniť diferenciáciu, prenajímať komoditu.

---

## 5. Infraštruktúra: ePošťák (Kaja Solutions s.r.o.)

**API pay-per-use (od 1. 6. 2026, pásma podľa mesačného objemu, kumuluje sa cez VŠETKY napojené firmy):**

| Pásmo (dok/mes) | Odoslanie | Príjem |
|---|---|---|
| 1–1 000 | 0,10 € | 0,08 € |
| 1 001–2 000 | 0,08 € | 0,07 € |
| 2 001–5 000 | 0,06 € | 0,06 € |
| 5 001–20 000 | 0,05 € | 0,05 € |
| 20 000+ | individuálne | individuálne |

- Produkčné API: 12-mes. minimum, potom 60-dňová výpoveď. SDK v 6 jazykoch vrátane PHP, OpenAPI 3.1, webhooky/OCR/idempotencia bez príplatku.
- **Sandbox zadarmo, dostupný hneď**, demo Peppol participanty `0245:0000000001` a `0245:0000000002`, bez signupu.
- **Connector API** (`/api/v1/connector/*`): zjednotený tok (overenie príjemcu, kontrola, opravy, odoslanie, fronta/outbox, stav, príjem, dôkazy) — použiť ako primárne rozhranie, ušetrí ~90 % integračnej práce na poštárskej strane; pri potrebe detailu prechod na plné Enterprise API bez výmeny kľúčov.
- **White-label Founder:** 99 €/mes bez DPH, garantované 12 mes., prvých 100 partnerov (do 31. 12. 2026); do 500 aktívnych firiem v cene, nad +0,50 €/firma/mes; API usage samostatne; 0 € setup; podmienka: do 90 dní od aktivácie prvá produkčná firma. Potom Bronze 199 €/mes.
- **Timing:** vývoj v sandboxe zadarmo TERAZ; produkčné API + white-label podpísať až s prvým platiacim klientom (spustí sa 12-mes. viazanosť a 90-dňové okno v správnom momente). Sledovať plnenie 100 Founder miest na jeseň.
- **OCR v Enterprise API (overené 17. 7. 2026):** `POST /api/v1/payloads/extract` — PDF/JPEG/PNG/WebP (20 MB, batch 50) → extrahované údaje + hotové UBL + confidence per pole + `needs_review`/`missing_fields` + `send_payload`; v cene dokumentu. K tomu `payloads/convert` (JSON↔UBL), `parse`, `validate`. Pre nás: PDF ingest adaptér = ich extract + naša fronta chýb na review — staviame len ak pilot ukáže potrebu; PDF je NÚDZOVÝ kanál (OCR je pravdepodobnostné), poradie preferencie: export dát > DBF/DB > PDF+OCR.
- **Posun ePošťáka hore po reťazci (17. 7. 2026):** Telegram bot (foto/PDF → UBL → odoslané) a webový formulár = mikrofirmy s PDF obslúžia sami → „malý PDF klient" NIE JE náš zákazník. Connector Studio mapper (vlož JSON/CSV, vytvorí mapovanie) = self-service pre technických, bez údržby a servisu — naša odpoveď: mapovanie ako udržiavaná SLUŽBA. Roadmapa Q2 2026: účtovnícke exporty (Pohoda, Money S3, Omega, ISDOC, CSV) → inbound diferenciácia sa zužuje na importné formáty systémov NA MIERU (náš segment aj tak).
- Architektonická poistka: adapter vrstva `PostarAdapterInterface` ostáva — ePošťák je len prvý adaptér (vyjednávacia pozícia navždy).
- ⚠️ Prečítať integrátorskú zmluvu: exit, osud klientov pri ukončení, garancie API cien.

---

## 6. Unit economics

**Marže na doručovaní (najhorší prípad = najdrahšie pásmo ~0,09 €/dok priemer):**
S (100 dok, 69 €): náklad ~9 € → marža ~87 % · M (500 dok, 149 €): ~45 € → ~70 % · L (2 000 dok, 290 €): ~180 € → ~38 %.

**Portfólio model — 20 klientov (10×S + 8×M + 2×L ≈ 9 000 dok/mes):** agregácia → pásmo 0,05 € → API ~450 € + white-label 99 € ≈ **550 €/mes nákladov vs. ~2 460 €/mes príjmov → hrubá marža ~78 %**, s rastom sa zlepšuje (pásma klesajú). Plus ~60 000 € kumulovane na setupoch.
Poistka: extrémne objemy v L/XL preceňovať individuálne.

---

## 7. Cenník (ponukový)

**Setup (jednorazovo):** Štandard 1 900 € (existujúci export, jednosmerne) · Rozšírený 3 500 € (mapovanie na mieru, obojsmerne, import) · Komplexný od 6 500 € (DB napojenie, multi-IČO, špeciálne doklady). Zmeny po spustení 90 €/h.
**Mesačne (odoslané+prijaté spolu):** S do 100 dok 69 € · M do 500 149 € · L do 2 000 290 € · XL od 490 € individuálne. Zahŕňa: prevod, validácie, monitoring, frontu chýb, archív, support do 24 h, **aktualizácie pri každej zmene legislatívy a schém** (kľúčový predajný bod — „o toto sa už nikdy nestaráte vy").
**Príplatky:** ďalšie IČO +50 % tieru · SLA 4 h +30 % · poplatky poštára per dokument = transparentný prenesený náklad (alebo zabalené v tieroch — rozhodnúť).
**White-label pre IT firmy:** 690 €/mes platforma + 39 €/mes per koncový klient; onboarding robia oni podľa našej dokumentácie (alebo my za 50 % cenníka); first-line support ich.
**Pravidlá:** kotva v každej ponuke (výmena ERP 50 000 €+ / integrácia na mieru 8–15 000 € / my); prví 3–5 klienti −40–50 % na setup za referenciu (mesačné plné!); viazanosť 12 mes.; ročná platba vopred −10 %; ročná indexácia.
**Námietka „ePošťák má plán za 8 €":** ten plán = ručné prepisovanie každej faktúry do webu navždy; my = nula zmien, automatizácia, validácia, archív.

---

## 8. Produkt — funkčná špecifikácia

### 8.1 Outbound (jadro)
Ingest → mapping → UBL builder → validátor → odoslanie (ePošťák Connector) → stavy → archív.
- **Ingest adaptéry (poradie implementácie):** CSV → XML → **DBF** (FoxPro/dBase — konkurenčná výhoda) → Access/MDB → priame DB (MySQL, Firebird, MSSQL, Oracle). Kanály: API endpoint, sledovaný SFTP/adresár cez sync agenta, upload v dashboarde. Idempotencia (dedup podľa externého ID).
- **Mapping engine:** mapovacia definícia = verzovaný JSON per klient (dáta, nie kód). Transformácie, číselníky (DPH kódy, UN/ECE jednotky, meny), defaulty, prepočty súm.
- **Validátor (3 vrstvy):** XSD UBL 2.1 → schematron EN 16931 + Peppol BIS 3.0 → vlastné SK biznis kontroly. Chyby → fronta s vysvetlením po slovensky. Pozn.: Connector robí časť validácií tiež — naša predvalidácia chytá chyby skôr a prekladá ich do ľudskej reči.
- **Sync agent** (jediné, čo beží u klienta, len ak treba): malý program/naplánovaná úloha — sleduje adresár/DBF, podpísaný upload na server, auto-update, hotový inštalátor (cieľ: 15-min. inštalácia, zvládne klientov ajťák podľa návodu).

### 8.2 Inbound (fáza 2)
Príjem cez Connector → validácia → konverzia do importného formátu klienta (podľa mapovacej definície) → čitateľné PDF (vizualizačný XSLT EN 16931) → notifikácia → potvrdenie v zákonnej lehote → archív.

### 8.3 Dashboard
Multi-tenant (tenant = náš klient alebo white-label IT partner → pod ním koncové firmy). Zoznam faktúr + stavy, **fronta chýb (najdôležitejšia obrazovka)**, detail validácie, znovuodoslanie, správa mapovaní, archív s vyhľadávaním, audit log, API kľúče, **metering dokumentov per klient/mesiac** (podklad fakturácie tierov + kontrola API nákladov). Alerting e-mailom.

---

## 9. MVP (~200–300 h)

**In:** outbound CSV+XML → mapping → UBL → validácia → ePošťák sandbox (Connector, demo participanty) → stavy; minimálny dashboard (login, faktúry, fronta chýb, JSON editor mapovania); archív do object storage; e-mail alerty; metering. 1 tenant/1 klient natvrdo OK.
**Out:** inbound, DBF/MDB/DB adaptéry, sync agent, white-label API, automatická fakturácia zákazníkov, SSO.
**Hotové =** reálna faktúra z CSV exportu pilotného klienta prejde celou rúrou do sandboxu bez ručného zásahu; chybná faktúra skončí vo fronte so zrozumiteľnou slovenskou hláškou.

---

## 10. Architektúra

**Zásada: nudný monolit.** Spoľahlivosť = jednoduchosť. Objem (20 klientov × 500 dok) je malý — priorita korektnosť a diagnostikovateľnosť, nie škála.
- PHP 8.3 + Laravel · PostgreSQL (JSONB na payloady a mapovania) · Laravel queues (DB driver, neskôr Redis) · S3-kompatibilný storage (Hetzner) na archív · Docker Compose · 1 VPS (EÚ) + oddelený prod/test · denné zálohy off-site.
- Schematron: KoSIT validator (Java) ako interná HTTP sidecar služba; neprepisovať do PHP.
- **Stavový automat faktúry:** `received → mapped → validated → queued → sent → delivered | rejected | failed → (confirmed)` + error vetvy s retry (backoff) a dead-letter frontou. Každý krok = job.
- **Dátový model:** tenants, users, client_companies, postar_accounts, mappings (verzované), invoices (direction, status, external_id, source_payload, ubl_xml, validation_report), invoice_events (append-only audit), deliveries, archive_objects, usage_meters, alerts.
- Bezpečnosť/GDPR (sme sprostredkovateľ): šifrovanie at rest/in transit, tenant izolácia (global scopes), audit všetkých zásahov, žiadne osobné údaje v logoch, EÚ hosting.

**Zdroje (overiť verzie):** OASIS UBL 2.1 + XSD · GitHub `ConnectingEurope/eInvoicing-EN16931` · OpenPeppol `peppol-bis-invoice-3` · `itplr-kosit/validator` · PHP UBL lib (napr. num-num/ubl-invoice) vs. vlastný builder · vizualizačný XSLT EN 16931 · ePošťák: /api/docs/enterprise (Connector: `#ep-connector-preflight`), SAPI-SK · FS SR: IS EFA, zákon 385/2025.

---

## 11. Onboarding klienta (proces)

1. **Analýza** (1 online stretnutie): z čoho fakturujú, čo vie exportovať, objemy, špeciality. Vypýtať **10–20 reálnych faktúr v ich exporte** (kľúčový artefakt — tam vylezú kostlivci).
2. **Napojenie** (3 varianty): (a) systém vie API/súbory → nič sa u nich neinštaluje; (b) vie len ukladať súbory → sync agent (15 min.); (c) nevie nič → read-only DB cez VPN = Komplexný setup.
3. **Mapovanie** (u nás): z ich vzorky napísať mapovaciu definíciu.
4. **Sandbox testovanie** (1–2 týždne): ladiť do 100 % priechodnosti; ukázať klientovi dashboard a frontu chýb.
5. **Paralelná prevádzka** (predajný tromf): od jesene 2026 naostro súbežne so starým spôsobom → 1. 1. 2027 klient „ani nezbadá".
6. **Odovzdanie:** 1 h školenie fakturantky + účtovníčky, alerting, support kontakty. Ďalej reaktívne: fronta chýb, zmeny mapovania (90 €/h), centrálne aktualizácie schém pre všetkých naraz.

**Kapacita:** ~15–25 h/klient (Štandard), 2–4 týždne kalendárne, ~2–3 onboardingy/mes popri práci → jeseň 2026 = úzke hrdlo → fronta s rezervačným poplatkom, zdvihnutie cien pri návale, white-label partneri s dobrou dokumentáciou (dokumentácia = škálovací nástroj).

---

## 12. Go-to-market

**Kanály (podľa efektivity):** 1) účtovníčky a účtovné kancelárie (provízia 10–15 % zo setupu; vidia klientov zhora); 2) trik s pätičkou faktúry („Vytlačené programom XY" na došlých faktúrach známych); 3) malé IT firmy = white-label (Finstat: NACE 62010, vznik pred 2015; ITAS); 4) inzeráty ako signál (Profesia: „údržba interného IS", Delphi, FoxPro); 5) Finstat filter (veľkoobchod/výroba/doprava, 10–100 zam., pred 2012, obrat 1–20 M €) → priamy outreach, nie spam; 6) landing page + články na „e-faktúra vlastný systém / starý program" + long-tail Ads (konkurencia na frázach ~nulová, panická vlna príde na jeseň 2026); 7) partnerstvo s poštármi — posielajú klientov, ktorých nevedia onboardnúť.
**Validácia trhu = 5 rozhovorov s účtovníčkami:** „Z akých programov vám klienti nosia doklady? Koľkí fakturujú z niečoho bez živého dodávateľa?"
**Prvý cieľ nie je 100 leadov, ale 1 pilot** (sieť, druhé-tretie podanie ruky).

**Vysvetlenie v 3 úrovniach:**
- 1 veta: „Od 2027 musia firmy posielať faktúry elektronicky v novom formáte — robím prekladač, ktorý to naučí aj staré systémy."
- 30 s: PDF prestáva platiť → zákon vyžaduje štruktúrované dáta cez certifikovanú sieť → moderným to vyrieši update, tisíce firiem so systémami na mieru nemá kto prerobiť → náš medzikus: ich systém funguje ďalej, my prekladáme, odosielame, prijímame, archivujeme.
- Klient (začať rizikom, nie technológiou): „Od januára 2027 nevystavíte legálnu faktúru... tri možnosti: výmena systému (desiatky tisíc, rok chaosu), integrácia na mieru (8–15 tis.), alebo my: od 1 900 € + od 69 €/mes a nič nemeníte." Nikdy nezačínať slovami UBL/Peppol/XML. Vždy začať dátumom 1. 1. 2027.
- Analógia: prekladateľ + poštár v jednom.

---

## 13. Právne a finančné

- **Súhlas zamestnávateľa** (Zákonník práce § 83, zhodná zárobková činnosť) — vybaviť PRED prvým klientom; vývoj výhradne vlastný HW, vlastný čas, žiadny firemný kód/prístupy. Najväčšie riziko projektu.
- Živnosť elektronicky 0 € → prechod na s.r.o. pri prvých vážnych zmluvách (ručenie pri spracúvaní cudzích faktúr, dôveryhodnosť; založenie ~250–350 €, podvojné účt. ~700–1 200 €/rok, min. daň ~340 €/rok).
- Odvody popri zamestnaní (2026): zdravotné preddavky 0 € (súbeh; doplatok ~16 % zo zisku v ročnom zúčtovaní); sociálne od 6. mesiaca mikroodvod ~131,34 €/mes, nad ~9 144 €/rok príjmu min. ~303 €/mes → potvrdiť s účtovníčkou.
- Právne dokumenty: zmluva o službách, VOP, GDPR sprostredkovateľská zmluva, SLA 99,5 % (AI draft + advokát ~300–600 €). Poistenie profesijnej zodpovednosti ~150–300 €/rok od prvého ostrého klienta.
- Rozpočet: do prvého klienta ~500–1 000 € cash; rok 1 ~2 500–3 500 € vrátane odvodov; break-even = prvý setup.

## 14. Otvorené otázky
- [ ] Presná zákonná lehota potvrdenia prijatej faktúry + náležitosti archivácie + existencia SK CIUS (zákon 385/2025).
- [ ] Integrátorská zmluva ePošťák (exit, osud klientov, garancie cien).
- [ ] Súhlas zamestnávateľa — stav?
- [ ] Názov + doména: favorit **Faktúrovod** (fakturovod.sk + .cz), alt. Prípojka, Mostík, Prevodník; overiť ÚPV SR, OR SR; vyhnúť sa „Peppol"/„eFaktúra" v názve. Firma ≠ produkt (produkt sa dá prebrandovať).
- [ ] Rozhodnúť: poplatky poštára prenesené 1:1 vs. zabalené v tieroch.

---

## 15. PROGRESS LOG (udržiavať pri každom sedení!)

### Fáza 0 — príprava
- [x] Trhová analýza, výber nápadu, cenník, unit economics, ePošťák stratégia (júl 2026, chat s Claude)
- [x] Git nainštalovaný, Claude Code funkčný, projekt `faktura` založený
- [x] Prečítaná dokumentácia ePošťák Connector (/api/docs/enterprise) — base URL sandbox `https://dev.epostak.sk/api/v1`, OAuth client_credentials (token 15 min), send cez `POST /documents/send` (JSON alebo raw UBL `xml`), stav `GET /documents/{id}/status`, idempotencia hlavičkou; sk_int_* kľúč cieli firmu cez `X-Firm-Id`
- [x] Sandbox: prvá UBL faktúra odoslaná na demo participanta — **16. 7. 2026 status `delivered`** (`php artisan postar:send --status`); demo client_secret je v lokálnom .env (EPOSTAK_CLIENT_SECRET, mimo gitu)
- [ ] 5 rozhovorov s účtovníčkami (validácia + leady)
- [ ] Súhlas zamestnávateľa
- [ ] Živnosť ohlásená

### Fáza 1 — MVP (cieľ: +3–4 mesiace)
- [x] Laravel 13 skeleton (PHP 8.3, lokálne SQLite) · [x] dátový model — jadro (invoices + invoice_events append-only audit) + stavový automat s testami; ostatné tabuľky (tenants, client_companies, mappings, usage_meters…) pri dashboarde/meteringu
- [x] Ingest: CSV adaptér (delimiter/enclosure, CP1250 cez iconv, BOM) · [x] XML adaptér (record_xpath, relatívne XPath polia)
- [x] Mapping engine — základ (JSON definície, from/const/default/map, transformácie date+decimal, slovenské chybové hlášky s kontextom riadku, **zber všetkých chýb faktúry naraz**, testy) · [x] verzovanie definícií (tabuľka `mappings`, uloženie v editore zvýši verziu; faktúra si drží vlastný snapshot definície) · [~] fixtures: dobropis ✅, oslobodenie (kat. E + dôvod, AE s defaultom) ✅; zostáva cudzia mena, zálohová (bežná + viac sadzieb DPH hotové)
- [x] UBL 2.1 builder — Invoice (EN 16931 / Peppol BIS 3.0, sumy a DPH rozpis cez brick/math) · [x] CreditNote/dobropis (`type: credit_note` v kanonickom modeli, CreditNoteTypeCode 381, BillingReference na pôvodnú faktúru, bez DueDate; XSD aj schematron scenár per typ)
- [x] Validátor: XSD (OASIS schémy vendorované v resources/schemas) · [x] schematron sidecar (KoSIT validator 1.6.2 ako HTTP daemon, `php artisan schematron:serve`, EN 16931 + Peppol BIS 2026.5 XSLT; sidecar down = soft skip, poštár validuje tiež) · [x] SK biznis kontroly + slovenské hlášky (`BusinessValidator` — IČ DPH mod 11, IČO, SK sadzby 23/19/5, kategórie DPH, Peppol polia, dátumy; beží na kanonickom modeli pred UBL)
- [~] ePošťák Connector adaptér: send + stavy hotové (`PostarAdapterInterface`, `EpostakConnectorAdapter`, token caching, mapovanie chýb na slovenské hlášky, validačné chyby zo status endpointu, `php artisan postar:send`) · [x] ostrý beh cez sandbox (delivered) · [ ] outbox/box
- [x] Dashboard: login, faktúry (zoznam + filtre + detail s auditom, UBL download), **fronta chýb** (všetky chyby faktúry naraz, retry tlačidlom), JSON editor mapovania, upload exportu v prehliadači — Blade + vlastné CSS, žiadny build krok; používateľ cez `php artisan dashboard:user <email>` · [ ] multi-tenant (zatiaľ 1 klient natvrdo, per MVP OK)
- [x] Archív (object storage) + audit events + metering (`InvoiceArchiver` → disk `archive` s SHA-256 dôkazom, `usage_meters` per mesiac, obrazovka Spotreba; S3/Hetzner = zmena drivera v configu)
- [x] E-mail alerting (`InvoiceAlerts` + `InvoiceAlertMail` — pri páde do failed/rejected, všetky chyby po slovensky + odkaz na frontu chýb; ALERT_EMAIL v .env, prázdne = vypnuté; lokálne mailer `log`)
- [ ] E2E: reálna vzorka faktúr prejde bez zásahu

### Fáza 2 — pilot (cieľ: jeseň 2026)
- [ ] Pilotný klient podpísaný (zľava za referenciu) · [ ] vzorka 10–20 faktúr získaná
- [ ] Mapovanie pilota + sandbox prevádzka 100 %
- [ ] Produkčné API + white-label Founder aktivované (⚠️ až tu — 12-mes. viazanosť, 90-dňové okno; sledovať plnenie 100 miest)
- [ ] Paralelná ostrá prevádzka beží
- [ ] Právne dokumenty + poistenie
- [ ] Landing page + 2–3 SEO články živé
- [ ] Referencia/case study z pilota

### Fáza 3 — škálovanie (2027)
- [ ] Inbound flow · [ ] DBF adaptér · [ ] sync agent (inštalátor) · [ ] MDB/DB adaptéry
- [ ] Druhý poštár adaptér (poistka neutrality)
- [ ] White-label program pre IT firmy + onboarding dokumentácia
- [ ] Klienti: 5 → 10 → 20 · [ ] prechod na s.r.o.

### Denník
- **07/2026:** Rozhodnutie ísť do projektu. Stratégia: ePošťák sandbox teraz, white-label pri prvom klientovi. — *(sem pripisovať: dátum + čo sa spravilo/rozhodlo)*
- **17. 7. 2026 (prieskum epostak.sk):** Súhlas zamestnávateľa VYBAVENÝ ✅. Preštudovaný celý web ePošťák → sekcia 5 doplnená: OCR endpoint živý (PDF→UBL v cene), Telegram bot, Connector Studio mapper, roadmapa Q2 účtovnícke exporty. Stratégia potvrdená: sme posledná míľa pre systémy na mieru; malý PDF klient nie je náš zákazník. Kanály dnes reálne: upload v dashboarde + CLI (sync agent = fáza 3, API ingest endpoint zatiaľ nie je). Ďalší krok: PILOT (ručný upload stačí).
- **15.–16. 7. 2026:** Dev prostredie (PHP 8.3.32 + Composer 2.10.2 cez winget), Laravel 13 skeleton, git repo, initial commit. UBL 2.1 builder + XSD validátor + `php artisan ubl:hello`. Mapping engine: CSV/XML → kanonický model → UBL (`php artisan invoice:convert`), ukážkový legacy export prechádza end-to-end; 36 testov zelených. Prijatý brief v2 — ePošťák Connector ako primárny backend, vlastná značka, white-label stratégia.
- **16. 7. 2026:** Naštudovaná dokumentácia ePošťák Enterprise/Connector API (sandbox `dev.epostak.sk`, OAuth client_credentials, `/documents/send`, `/documents/{id}/status`, idempotencia, X-Firm-Id). Postavená poštár vrstva: `PostarAdapterInterface` + `EpostakConnectorAdapter` (token caching, slovenské chybové hlášky), `postar:send`, konfig `config/postar.php` + EPOSTAK_* v .env. 43 testov zelených.
- **16. 7. 2026 (večer): 🎉 PRVÁ FAKTÚRA DORUČENÁ CEZ SANDBOX.** Postup: (1) PHP na Windows chýbal CA bundle → stiahnutý cacert.pem, nastavené curl.cainfo/openssl.cafile v php.ini; (2) sandbox vrátil 2 Peppol validačné chyby → doplnené `cbc:BuyerReference` (BT-10) a `cbc:EndpointID` (BT-34/49, z `peppol_id` v tvare scheme:value) do buildera, mappera aj vzoriek; (3) `postar:send --status` → **SENT → delivered**. Poznatky: sandbox deduplikuje podľa čísla faktúry (číslo vzorky je odteraz unikátne per beh); dodávateľa v dokumente prepíše podľa autentifikovanej firmy (X-Firm-Id); validačné chyby vracia v `validationResult.errors` status endpointu → premietnuté do `DeliveryStatus->validationErrors`.
- **16. 7. 2026 (noc): Dátový model + stavový automat + pipeline.** Tabuľky `invoices` (source_payload/canonical/ubl_xml/validation_report ako audit dát, unique external_id per direction = idempotencia) a `invoice_events` (append-only). Enum `InvoiceStatus` s vynútenými prechodmi `received→mapped→validated→queued→sent→delivered|rejected` + `failed→received` (retry). `InvoicePipeline`: ingest izoluje faktúry per skupina (jedna chybná neblokuje ostatné), kroky map/validate/send, `refreshStatus` mapuje stavy poštára (validation_failed→rejected s chybami). Príkazy `invoice:ingest [--process]`, `invoice:process [--retry=ID]`, `invoice:list [--events=ID]`. Ostrý E2E: vzorový CSV → 2× delivered cez sandbox, re-ingest = duplicity preskočené. 56 testov.
- **17. 7. 2026: Prostredie obnovené na novom stroji.** Čerstvý klon repa → `composer install`, `.env` z examplu, SQLite + migrácie, 70 testov zelených, dashboard beží (`dashboard:user` účet vytvorený). Lokálne PHP je tu **8.5.4** (`C:\_app\php-8.5.4`) — opravená cesta v `.claude/launch.json`; jedna deprecation vo `fgetcsv` (CsvReader.php:50). CA bundle znovu chýbal → stiahnutý cacert.pem do `extras\ssl`, nastavené curl.cainfo/openssl.cafile v php.ini. ePošťák: prepnuté na **Demo Integrátor** kľúč (sk_int_*, client_id 8924a11d-…, X-Firm-Id = Test Odosielateľ f2cea3cf-…) — verejné demo credentials z dev.epostak.sk docs; `postar:send --status` → SENT → **delivered**.
- **17. 7. 2026 (5): Dobropis + oslobodenia od DPH.** Builder podporuje `type: invoice|credit_note` — CreditNote-2 namespace, `CreditNoteTypeCode` 381, `CreditNoteLine`/`CreditedQuantity`, `BillingReference/InvoiceDocumentReference` na pôvodnú faktúru (`invoice_reference` v kanonickom modeli), bez DueDate (UBL CreditNote ho nemá); sumy ostávajú kladné. Oslobodenia: `vat_exemption_reason` per položka → `TaxExemptionReason` v DPH rozpise (BT-121); kategória AE dostane default „Prenesenie daňovej povinnosti (reverse charge)" (BR-AE-10), kategória E bez dôvodu = biznis chyba (BR-E-10). Mapper: nepovinné polia `type` (map F/D), `invoice_reference`, `vat_exemption_reason`. Pipeline vyberá XSD podľa typu; scenarios.xml má druhý scenár pre CreditNote. Vzorky: `legacy-export-dobropis.csv` + rozšírené mapovanie (spätne kompatibilné). Ostrý E2E: DBP-2026-0001 → XSD aj schematron prešli → delivered cez sandbox. POZOR: SCHEMATRON_JAVA v .env musí byť s forward slashes (dotenv escape). 116 testov zelených.
- **17. 7. 2026 (4): Schematron sidecar (vrstva 2 validácie).** OpenJDK: Temurin JRE 21 cez winget (`C:\Program Files\Eclipse Adoptium\jre-21.0.11.10-hotspot`). KoSIT validator 1.6.2 (`tools/schematron/validator.jar`, v .gitignore — stiahnuť z github itplr-kosit/validator) + vlastná scenárová konfigurácia `tools/schematron/scenarios.xml`: UBL 2.1 XSD (vendorované schémy), EN16931-UBL-validation.xslt (CEN 1.3.16) + PEPPOL-EN16931-UBL.xslt (BIS 2026.5 z phax/phive-rules — POZOR: `stylesheet-ubl.xslt` z OpenPEPPOL master NIE JE plná sada pravidiel, chýba mu napr. R003!), report.xsl z XRechnung konfigurácie. Beh: `php artisan schematron:serve` (port 8081). `SchematronValidator` (HTTP klient, parsuje VARL rep:message level error/fatal) v pipeline po XSD; sidecar down/vypnutý (SCHEMATRON_URL prázdne — testy) = soft skip s poznámkou v audite. Overené: pokazený dokument bez BuyerReference → 406 + PEPPOL-EN16931-R003; zdravá faktúra → „XSD aj schematron validácia prešla" → delivered cez sandbox. 106 testov zelených.
- **17. 7. 2026 (3): E-mail alerting.** `InvoiceAlerts::invoiceNeedsAttention` volané z `Invoice::fail()` (jediný vstup do failed) a z rejected vetvy `refreshStatus`. Mail `InvoiceAlertMail` po slovensky: stav, error_message, všetky chyby zo všetkých sekcií validation_report, odkazy na frontu chýb a detail. Príjemcovia `ALERT_EMAIL` (čiarkou oddelené, prázdne = vypnuté, config/alerts.php); zlyhanie mailu nikdy nezhodí pipeline (report + pokračuje). APP_URL opravené na http://localhost:8000 (odkazy v mailoch). Overené: chybný export → mail v laravel.log so všetkými 3 chybami. 100 testov zelených. K tomu fix uploadu v dashboarde: php.ini mal `upload_tmp_dir=C:\windows\Temp` (užívateľ nevie listovať → realpath=false → „Path must not be empty"); zakomentované + controller číta upload cez `->get()` namiesto `getRealPath()`. Mapovanie `legacy-csv` v1 nasadené do DB (dropdown importu bol prázdny).
- **17. 7. 2026 (2): Archív + metering.** `InvoiceArchiver` — po úspešnom odoslaní uloží presné UBL XML (`faktura.xml`) + zdrojové riadky (`zdroj.json`) na disk `archive` (lokálny Flysystem, cesta `outbound/RRRR/MM/{id}-{slug}/`; na VPS sa prepne na S3/Hetzner v config/filesystems). Tabuľka `archive_objects` drží SHA-256 + veľkosť = dôkaz pri spore; idempotentné (retry neprepíše), archivácia zapísaná do audit trailu, zlyhanie archívu NEzhodí odoslanú faktúru (len event). Tabuľka `usage_meters` (period × metric, atomický upsert) — `documents_sent` sa počíta pri prechode na `sent`; nová obrazovka **Spotreba** (/spotreba) s mesačnými počtami a veľkosťou archívu. Ostrý beh: faktúra delivered cez sandbox, súbory na disku, hash sedí s DB. 96 testov zelených.
- **17. 7. 2026: SK biznis kontroly (vrstva 3 validácie).** `App\Services\Validation\BusinessValidator` — beží v pipeline na kanonickom modeli PRED stavbou UBL, zbiera všetky chyby naraz (štýl mappera): platnosť dátumov + splatnosť ≥ vystavenie, ISO mena/krajiny, povinný buyer_reference a peppol_id oboch strán (tvar `\d{4}:...`), SK IČ DPH = 10 číslic deliteľných 11 (chytá preklepy!), SK IČO 8 číslic, sadzby DPH pre SK dodávateľa len 23/19/5 (zákon 278/2024), kategórie UNCL5305 (S vyžaduje nenulovú sadzbu + supplier.vat_id per BR-S-02; Z/E/AE/K/G/O vyžadujú 0 %). Report v `validation_report['business']`, zobrazené vo fronte chýb aj detaile (nová sekcia „Biznis kontroly (SK)"). Vzorky a fixtures opravené na mod-11 platné IČ DPH (SK2020123457, SK2020111115…). Ostrý E2E: zlý export → failed s 3 slovenskými chybami, zdravá faktúra → delivered cez sandbox. 90 testov zelených.
- **16. 7. 2026 (noc): Dashboard.** Blade + vlastné CSS (`public/css/dashboard.css`), žiadny Node/build — v duchu „nudného monolitu". Obrazovky: login (`dashboard:user <email>` vytvorí účet), zoznam faktúr s filtrami stavov + upload exportu priamo v prehliadači, detail faktúry (kanonické údaje, položky, audit trail, UBL XML + download), **fronta chýb** s počítadlom v navigácii a tlačidlom „Skúsiť znova", CRUD mapovaní s JSON editorom (uloženie = nová verzia, faktúry si držia vlastný snapshot). Mapper prepísaný z fail-fast na **zber všetkých chýb naraz** (`MappingException::withErrors`, slovenské skloňovanie 1 chybu / 2–4 chyby / 5+ chýb) — overené v prehliadači: pokazený export ukáže všetky 4 chyby s číslami riadkov naraz. Review pass: rate limit na login (5/min), vlastný pagination view (Laravel default predpokladá Tailwind), počítadlo chýb cez view composer namiesto query v Blade, import s chybami presmeruje rovno do fronty chýb. 70 testov.

---

## 16. Zásady vývoja (pre Claude Code)

- Slovenské hlášky pre používateľov, anglický kód a komentáre.
- Mapovanie je DÁTA (verzovaný JSON per klient), nie kód. Nový klient = konfigurácia, nie vetva.
- Testy povinné: mapping, sumy/DPH, UBL builder, validačné scenáre vrátane chybných vstupov.
- Append-only audit každej faktúry — pri spore rekonštruovateľné, čo sa kedy stalo.
- Idempotencia všade, retry s backoffom, dead-letter fronta.
- Žiadna predčasná optimalizácia na škálu; feature flags per tenant namiesto forkov.
- Primárne rozhranie k poštárovi = ePošťák Connector, ale VŽDY cez vlastný `PostarAdapterInterface`.

---

## 17. Lokálne vývojové prostredie (Windows, stav 07/2026)

- PHP 8.3.32 (winget balík `PHP.PHP.8.3`): `%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe` — adresár je v user PATH, v novom termináli funguje `php` aj `composer` (shim `composer.cmd` vedľa php.exe, Composer 2.10.2).
- Zapnuté PHP rozšírenia: curl, fileinfo, gd, intl, mbstring, openssl, pdo_mysql, pdo_pgsql, pdo_sqlite, sqlite3, zip. Pozor: mbstring na tomto builde nepodporuje CP1250 — na prekódovanie používať iconv.
- Framework: Laravel 13 (framework v13.20.0). Lokálna DB zatiaľ SQLite (`database/database.sqlite`) — na PostgreSQL prejsť, keď začne dávať zmysel (pipeline + JSONB).
- Java: Temurin JRE 21 (winget, `C:\Program Files\Eclipse Adoptium\jre-21.0.11.10-hotspot\bin\java.exe`) — beží na ňom KoSIT schematron sidecar. Docker/WSL stále nie sú.
- Testy: `php artisan test`. Dev server: `php artisan serve` → http://localhost:8000 (pre Claude Code: `.claude/launch.json`).
