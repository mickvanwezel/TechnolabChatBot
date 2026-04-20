# 🤖 Technolab Vraagbaak — Installatiehandleiding

## Wat is dit?
Een webgebaseerde RAG-chatbot (Retrieval-Augmented Generation) voor Technolab Leiden.
Upload PDF-documenten en stel vragen — de chatbot zoekt de antwoorden op in jouw documenten.

---

## Vereisten

| Tool | Versie | Waarvoor |
|------|--------|----------|
| PHP  | ≥ 8.0  | Backend server |
| Python 3 | ≥ 3.8 | PDF-tekst extractie |
| PyPDF2 of pdfminer | latest | PDF lezen |
| cURL (PHP ext) | ingebouwd | API-verzoeken |
| Apache of Nginx | – | Webserver |
| Anthropic API Key | – | Claude AI antwoorden |

---

## Installatie

### 1. Bestanden plaatsen
```
/var/www/technolab-chatbot/
├── index.html          ← Frontend
├── .htaccess           ← Beveiliging
├── api/
│   ├── chat.php        ← RAG chat endpoint
│   ├── upload.php      ← PDF upload & chunking
│   ├── delete.php      ← Document verwijderen
│   └── extract_pdf.py  ← PDF tekst extractor
├── uploads/            ← PDF bestanden (auto-aangemaakt)
└── chunks/             ← JSON chunks (auto-aangemaakt)
```

### 2. Python-bibliotheek installeren
```bash
pip3 install PyPDF2
# Of als backup:
pip3 install pdfminer.six
```

### 3. Anthropic API Key instellen

**Optie A — Apache virtualhost:**
```apache
SetEnv ANTHROPIC_API_KEY sk-ant-api03-...
```

**Optie B — PHP-FPM / `.env`:**
Voeg toe aan `/etc/environment` of `/etc/apache2/envvars`:
```bash
export ANTHROPIC_API_KEY="sk-ant-api03-..."
```

**Optie C — Direct in `api/chat.php`** (niet aanbevolen voor productie):
Zoek deze regel en vervang:
```php
define('ANTHROPIC_API_KEY', getenv('ANTHROPIC_API_KEY') ?: '');
```
Door:
```php
define('ANTHROPIC_API_KEY', 'sk-ant-api03-JOUW_SLEUTEL_HIER');
```

### 4. Mapmachtigingen instellen
```bash
chmod 755 /var/www/technolab-chatbot/
chmod 755 /var/www/technolab-chatbot/api/
chmod 777 /var/www/technolab-chatbot/uploads/
chmod 777 /var/www/technolab-chatbot/chunks/
chmod +x  /var/www/technolab-chatbot/api/extract_pdf.py
```

### 5. Apache virtualhost (voorbeeld)
```apache
<VirtualHost *:80>
    ServerName chatbot.technolab.nl
    DocumentRoot /var/www/technolab-chatbot

    SetEnv ANTHROPIC_API_KEY sk-ant-api03-...

    <Directory /var/www/technolab-chatbot>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

---

## Gebruik

1. Open `http://jouw-server/` in een browser
2. Klik op het uploadgebied of sleep een PDF erin
3. Wacht tot het document verwerkt is
4. Stel een vraag in het tekstvak onderaan

---

## Hoe werkt het? (RAG uitgelegd)

```
[PDF Upload]
    ↓
[Tekst extraheren via Python/PyPDF2]
    ↓
[Tekst opdelen in ~400-woord stukken (chunks)]
    ↓
[Opslaan als JSON in /chunks/]

[Gebruiker stelt een vraag]
    ↓
[BM25-scoring: welke chunks zijn het meest relevant?]
    ↓
[Top 6 chunks worden als context meegestuurd naar Claude API]
    ↓
[Claude genereert een antwoord op basis van de context]
    ↓
[Antwoord + bronvermelding terug naar gebruiker]
```

---

## Beveiliging

- Geen persoonlijke gegevens worden opgeslagen
- Directe toegang tot `/uploads/` en `/chunks/` is geblokkeerd
- Bestandsnamen worden gesaneerd voor opslag
- API-sleutel staat in omgevingsvariabele, niet in code

---

## Problemen oplossen

| Fout | Oplossing |
|------|-----------|
| "API-sleutel niet geconfigureerd" | Controleer `ANTHROPIC_API_KEY` env variabele |
| "PDF-tekst extractie mislukt" | Controleer of Python 3 + PyPDF2 geïnstalleerd zijn |
| "Upload mislukt" | Controleer schrijfrechten op `/uploads/` en `/chunks/` |
| Gescande PDF's werken niet | Voeg OCR toe met `pytesseract` + `pdf2image` |
| Lege antwoorden | Controleer of chunks niet leeg zijn; controleer API logs |

---

## Uitbreidingsmogelijkheden

- **OCR-ondersteuning** voor gescande PDF's (pytesseract)
- **Vector embeddings** voor betere semantische zoekresultaten (bijv. via OpenAI embeddings of lokale sentence-transformers)
- **Authenticatie** toevoegen voor intern gebruik
- **Chatgeschiedenis** bijhouden per sessie
- **Meertalige ondersteuning** (NL/EN)

---

## Deliverables checklist (uit briefing)

- [x] Werkende chatbot (via server beschikbaar)
- [x] Overzicht van gebruikte tools/services (zie hieronder)
- [x] Chatbot-persona: vriendelijk, bondig, Technolab-stijl
- [x] Documentatie van gebruikte data en bronnen (bronvermelding in UI)
- [ ] Testresultaten en evaluatie (zelf uitvoeren met echte gebruikers)

### Gebruikte tools & services
| Tool | Doel |
|------|------|
| PHP 8 | Backend server & API-endpoints |
| Python 3 + PyPDF2 | PDF tekst extractie |
| HTML/CSS/JavaScript | Frontend UI |
| Anthropic Claude API | AI antwoorden genereren |
| BM25 algoritme | Relevante chunks ophalen (RAG) |
| Apache .htaccess | Beveiliging & routing |
