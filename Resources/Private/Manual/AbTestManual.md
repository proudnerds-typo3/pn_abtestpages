---
version: '1.3'
date: '2026-10-20'
---

# A/B-testen

Met een A/B-test vergelijk je twee versies van een pagina. Bezoekers krijgen
willekeurig de bestaande pagina (A) of een alternatieve pagina (B) te zien, op
hetzelfde adres. Zo meet je in je statistieken welke versie beter werkt.

## Zo werkt een A/B-test

- De bezoeker opent het adres van pagina A en krijgt pagina A of pagina B te zien.
  Het adres in de adresbalk blijft dat van pagina A.
- Welke versie de bezoeker krijgt, wordt onthouden in een cookie. Bij een volgend
  bezoek krijgt de bezoeker dezelfde versie, tot de cookie verloopt. Daarna wordt opnieuw
  geloot.
- Zoekmachines krijgen altijd pagina A te zien en indexeren alleen het adres van
  pagina A. Pagina B verwijst naar het adres van pagina A, dus ook als een zoekmachine
  het adres van pagina B vindt, telt alleen pagina A.
- De zoekfunctie van de website toont alleen pagina A, met de inhoud van pagina A.
- Is pagina B verborgen, verwijderd of afgeschermd, dan loopt de test niet en ziet
  iedereen pagina A.
- De test loopt ook op de vertalingen van pagina A, met de instellingen van de
  standaardtaal; in een vertaling kun je ze niet apart instellen. Is pagina B niet
  vertaald, dan zien bezoekers van die taal pagina A. Alleen als de taal is ingesteld
  om terug te vallen op de standaardtaal, krijgen ze pagina B in de standaardtaal.
- Een test kan alleen op een gewone pagina. Een nieuwsbericht (paginatype PN News)
  heeft de instellingen voor een A/B-test niet.

## Een test opzetten

### Pagina B maken

1. Maak een kopie van pagina A, bijvoorbeeld door pagina A in de paginaboom te
   kopiëren en direct eronder te plakken.
2. Pas de kopie aan tot de versie die je wilt testen.
3. Zet in de paginaeigenschappen van pagina B op het tabblad **Access** de schakelaar
   **Page enabled in menus** uit, zodat pagina B niet in de navigatie verschijnt.
4. Zet pagina B zichtbaar. Een verborgen pagina B doet niet mee.

### De test starten

1. Open de paginaeigenschappen van pagina A.
2. Ga op het tabblad **General** naar het blok **A/B test settings**.
3. Vul de velden in en sla op.

| Veld | Wat vul je in |
| --- | --- |
| B page | De alternatieve pagina. Kies de pagina met de knop ernaast. |
| Percentage shown as B | Welk deel van de nieuwe bezoekers pagina B krijgt, van 1 tot 99. Standaard 50. |
| Cookie Lifetime | Hoe lang een bezoeker dezelfde versie blijft zien: van 1 minuut tot 1 maand. Standaard 1 dag. |

Bij het opslaan zet TYPO3 bij pagina B op het tabblad **Behaviour** de schakelaar
**Include in Search** uit, zodat pagina B niet als dubbele pagina in de zoekresultaten
van de website verschijnt. Zet die schakelaar niet terug zolang de test loopt.

### De test stoppen

Maak het veld **B page** leeg in de paginaeigenschappen van pagina A en sla op.
Iedereen ziet daarna weer pagina A. Wil je pagina B houden, verplaats dan de inhoud
van pagina B naar pagina A en verwijder pagina B. Laat je pagina B staan als gewone
pagina, zet dan **Include in Search** weer aan.

## Resultaten meten

Je hoeft zelf geen meetcode toe te voegen. Bij elke bezoeker die aan de test meedoet,
geeft de pagina door welke versie de bezoeker kreeg, in de `dataLayer` die onder meer
Google Tag Manager gebruikt:

| Gegeven | Inhoud |
| --- | --- |
| `event` | Altijd `ab_test_view` |
| `abTestId` | Het paginanummer (ID) van pagina A. Dit blijft gelijk als je de titel wijzigt. |
| `abTestName` | De titel van pagina A in de standaardtaal, ook bij bezoekers van een vertaling |
| `abTestVariant` | `A` of `B` |

Vraag de beheerder van de statistieken om deze gegevens als eigen dimensie of
eigenschap vast te leggen. Zoekmachines en bezoekers die pagina B rechtstreeks
openen, tellen niet mee.

## Aandachtspunten

- Test één verschil tegelijk, anders weet je niet welke wijziging het effect had.
- Laat een test lang genoeg lopen voor genoeg bezoekers per versie.
- Wijzig het percentage niet halverwege. Bezoekers met een cookie houden hun
  versie, dus de verdeling klopt dan niet meer.
- Een korte **Cookie Lifetime**, zoals 1 minuut, is handig om de test zelf te
  controleren, maar niet voor een echte meting: een bezoeker die terugkomt, kan dan
  de andere versie krijgen.
