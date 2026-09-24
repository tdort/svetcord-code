# Světcord

> [!WARNING]
> Tenhle projekt má dost bugů a chyb. Nepsal jsem ho jako produkční/komerční
> řešení, spíš jako projekt pro sebe — možná narazíte na nedodělané
> edge-case, chyby v konzoli, nebo věci, co nefungují úplně podle
> očekávání. Berte to tak a podle toho k tomu přistupujte (rozhodně bych
> na tom zatím nestavěl nic ostrého).
> Backdoory a zranitelnosti nahlašujte [tady](https://github.com/tdort/svetcord-code/issues)

> [!NOTE]
> Na projektu budu ještě dál pracovat, ale servery, na kterých to běželo,
> už možná nikdy nebudou zpátky nahoře — takže tohle beru spíš jako
> pokračování vývoje pro sebe/komunitu na GitHubu, ne jako obnovení
> nějaké veřejně běžící instance. Jestli chcete push request tak můžete.

Vlastní klon Discordu postavený od nuly v čistém PHP (PDO) + MySQL a
vanilla JS — bez frameworků, bez build kroku. Servery, textové a hlasové
kanály, chat (na pollingu), DMka, role s oprávněními, správa členů,
avatary a ikony serverů.

## Co to umí

- **Auth** — registrace/login, hashovaná hesla, PHP session
- **Servery** — vytvoření, join přes invite kód, leave, smazání (jen owner)
- **Kanály** — textové a hlasové (hlasové jsou zatím jen vizuální, bez přenosu zvuku)
- **Zprávy** — poslání/editace/smazání, polling každé 3s
- **DMka** — 1:1 konverzace, vyhledávání uživatelů
- **Role a oprávnění** — bitmaskový systém ve stylu Discordu (`VIEW_CHANNELS`,
  `SEND_MESSAGES`, `MANAGE_MESSAGES`, `MANAGE_CHANNELS`, `MANAGE_ROLES`,
  `KICK_MEMBERS`, `BAN_MEMBERS`, `MANAGE_SERVER`, `ADMINISTRATOR`).
  Owner serveru má vždy všechna práva.
- **Správa členů** — kick (podle oprávnění), přiřazování/odebírání rolí
  přímo z profilové karty
- **Profily** — kliknutím na člena profilová karta (avatar, pronouns, bio,
  banner v accent barvě, role, datum připojení)
- **Nastavení uživatele** — avatar, pronouns, bio, accent barva profilu
- **Uploady** — avatary a ikony serverů (PNG/JPEG/GIF/WEBP, max 4 MB)
- **Volání** — WebRTC hovory přes Cloudflare Realtime TURN (nebo vlastní
  TURN server / STUN fallback)

## Struktura projektu

```
svetcord/
├── schema.sql                  # MySQL schema — spustit jako první
├── config/
│   ├── config.example.php      # šablona — zkopírovat a upravit
│   └── config.php              # vaše skutečná konfigurace (negitovaná)
├── includes/
│   ├── Database.php            # PDO singleton
│   ├── Auth.php                 # session/auth logika
│   ├── Permissions.php          # bitmaskové helpery pro oprávnění
│   ├── helpers.php              # JSON response helpery, validace
│   └── models/                  # ServerModel, ChannelModel, MessageModel, ...
├── api/                         # JSON endpointy (?action=... routing)
├── public/                      # web-facing soubory (index.php, app.php, assets)
└── uploads/{avatars,banners,icons,emojis,badges}  # nahraný obsah uživatelů
```

## Zprovoznění

### 1. Co potřebujete
- PHP 8.1+ s rozšířeními `pdo_mysql`, `mbstring`, `fileinfo`
- MySQL 5.7+ nebo MariaDB 10.3+
- Apache/Nginx, nebo pro lokální testování stačí vestavěný PHP server

#### Windows

Nejjednodušší cesta na Windows je nepoužívat systémové PHP/MySQL ručně,
ale balík, který dá dohromady oboje najednou:

- **[Laragon](https://laragon.org/download/)** (doporučeno, lehčí a rychlejší) — po instalaci
  stačí `svetcord/` hodit do `laragon/www/`, spustit Apache + MySQL z
  Laragon UI a projekt je pak dostupný přes `http://svetcord.test`
  (Laragon si automaticky vytvoří virtuální host podle názvu složky).
- **[XAMPP](https://www.apachefriends.org/)** — po instalaci hodit
  `svetcord/` do `C:\xampp\htdocs\`, ve XAMPP Control Panelu zapnout
  **Apache** a **MySQL**, phpMyAdmin běží na `http://localhost/phpmyadmin`.

Oba obsahují PHP, MySQL i phpMyAdmin, takže nemusíte nic instalovat
zvlášť. Kroky 2–5 níže pak dělejte přes phpMyAdmin (import `schema.sql`
přes záložku *Import*) místo příkazové řádky, případně v PowerShellu/CMD
otevřeném přímo ve složce projektu (u Laragonu/XAMPP je `mysql` a `php`
už v PATH, případně je najdete v `laragon\bin\mysql\...\bin` nebo
`xampp\mysql\bin`).

### 2. Založení databáze

```bash
mysql -u root -p < schema.sql
```

*(Windows/PowerShell: stejný příkaz funguje, pokud máte `mysql` v PATH;
jinak použijte phpMyAdmin → záložka Import → vyberte `schema.sql`.)*

Pak postupně spusťte i migrace v `migrations/` (podle čísel, od nejnižšího) —
na Windows opět buď přes `mysql ... < migrations/001_....sql` v PowerShellu,
nebo přes Import v phpMyAdmin, soubor po souboru.

### 3. Konfigurace

```bash
cp config/config.example.php config/config.php
```

a v `config/config.php` doplňte vlastní hodnoty — hlavně:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'discord_clone');
define('DB_USER', 'root');
define('DB_PASS', 'vase-heslo');
```

*(Windows: `cp` v PowerShellu nefunguje — použijte `copy config\config.example.php config\config.php`, nebo soubor prostě zkopírujte a přejmenujte v Průzkumníku.)*

Pokud chcete e-maily pro reset hesla a hovory přes TURN, doplňte i
`SMTP_*` a `CF_TURN_*` hodnoty — komentáře přímo v souboru vysvětlují,
kde tyhle údaje získat (Resend, Cloudflare Realtime).

**`config/config.php` je v `.gitignore` a nikdy by neměl skončit v gitu** —
obsahuje ostrá hesla a API klíče.

### 4. Spuštění

Z rootu projektu (ne z `public/`):

```bash
php -S localhost:8000
```

a otevřít **http://localhost:8000/public/index.php**

> Document root musí být root projektu, ne `public/` — frontend volá API
> na `../api/...` a uploady se servírují z `/uploads/...`. Při nasazení
> na Apache/Nginx nasměrujte vhost docroot sem, nebo upravte cesty
> v `public/assets/js/app.js` a `UPLOAD_URL` v configu.

### 5. Práva na `uploads/`

```bash
chmod -R 755 uploads/
```

*(Windows: `chmod` není potřeba — NTFS práva na složky bývají v pořádku
rovnou; pokud by upload avatarů/ikon přesto selhával na "permission
denied", klikněte pravým na `uploads/` → Vlastnosti → Zabezpečení a
ověřte, že uživatel, pod kterým běží Apache/PHP (u Laragonu/XAMPP běžně
stejný účet, pod kterým jste přihlášení), má právo zápisu.)*

## Jak fungují oprávnění

Každý server má automaticky roli `@everyone` s `VIEW_CHANNELS` a
`SEND_MESSAGES`, kterou dostane každý nový člen. Owner má implicitně
všechna práva bez ohledu na role. Kdokoliv s `MANAGE_ROLES` může
vytvářet další role a přiřazovat je přes Server Settings → Roles.
Výsledná oprávnění člena jsou bitwise-OR všech jeho rolí
(viz `includes/Permissions.php`).

## Poznámky a omezení

- **Real-time je jen polling** (3s interval), ne WebSockety — jednodušší
  stack, žádné další služby navíc, za cenu malého zpoždění.
- **Hlasové kanály bez zvuku** — jsou vidět v seznamu kanálů, ale audio
  přenáší až modul volání přes WebRTC/TURN, ne samotné "hlasové kanály".
- Hesla přes `password_hash()` (bcrypt), všechny dotazy přes PDO
  prepared statements.

## Než to budete sami dál upravovat/nasazovat

- V repu **nejsou** žádná reálná uživatelská data ani API klíče — složky
  v `uploads/` jsou prázdné (jen s `.gitkeep`), takže po instalaci
  začínáte s čistým stavem.
- Pokud budete přidávat vlastní SMTP/Cloudflare klíče, dejte si pozor,
  ať je náhodou nepushnete — `config.php` je v `.gitignore`, ale kontrola
  přes `git status` / `git diff --cached` před pushem nikdy neuškodí.

## Rozšiřování

Kód je záměrně modulární — každá entita (servery, kanály, zprávy, role,
DMka, uživatelé) má vlastní model v `includes/models/` a vlastní tenký
routing soubor v `api/`. Pro novou funkci (např. reakce na zprávy,
připnuté zprávy, žádosti o přátelství) stačí přidat tabulku do
`schema.sql`, model a API akci — vzory ve frontend `app.js` (fetch
helpery, modal systém, polling) se dají použít stejným způsobem.
