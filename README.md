# Suchis Simple Backup

Ein schlankes und benutzerfreundliches WordPress-Plugin für vollständige Website-Backups.

**Version:** 1.2.4
**Lizenz:** GPLv2 oder später
**Autor:** Christian Suchanek

---

## 📋 Inhaltsverzeichnis

- [Features](#features)
- [Installation](#installation)
- [Anforderungen](#anforderungen)
- [Verwendung](#verwendung)
- [Sicherheit](#sicherheit)
- [Häufig gestellte Fragen](#häufig-gestellte-fragen)
- [Fehlerbehandlung](#fehlerbehandlung)
- [Contributing](#contributing)
- [Lizenz](#lizenz)

---

## ✨ Features

- **Vollständige Backups:** Sichert alle Website-Dateien und die komplette WordPress-Datenbank
- **ZIP-Kompression:** Automatische Komprimierung für einfacheren Download und Speicher
- **Lokale Speicherung:** Backups werden in `wp-content/backups/` gespeichert
- **Benutzerfreundliches Interface:** Intuitives Admin-Panel im WordPress-Dashboard
- **Download-Management:** Backups direkt herunterladen oder löschen
- **Intelligente Ausschlüsse:** Der Backup-Ordner wird automatisch vom Backup ausgeschlossen
- **Sicherheitsmaßnahmen:** .htaccess-Schutz und Nonce-Verifizierung
- **Admin-Only:** Nur Administratoren können Backups verwalten

---

## 🚀 Installation

### Manuelle Installation

1. Lade die neueste Version herunter: [Releases](https://github.com/derperformer/suchis-simple-backup/releases)
2. Entpacke die ZIP-Datei
3. Lade den Ordner `suchis-simple-backup` in dein WordPress `wp-content/plugins/` Verzeichnis hoch
4. Aktiviere das Plugin im WordPress-Dashboard unter **Plugins**

### Installation über WordPress.org

1. Gehe zu **Plugins → Neu hinzufügen** in deinem WordPress-Dashboard
2. Suche nach "Suchis Simple Backup"
3. Klicke auf **Installieren** und dann **Aktivieren**

---

## 📋 Anforderungen

- **WordPress:** 6.0 oder höher
- **PHP:** 8.0 oder höher
- **PHP-Extensions:** `zip` (erforderlich)
- **Speicherplatz:** Mindestens so viel freier Platz wie deine gesamte Website groß ist
- **Schreibberechtigungen:** `wp-content/` Ordner muss schreibbar sein

### Überprüfung der Anforderungen

Gehe nach der Plugin-Aktivierung zu **Tools → Suchis Simple Backup**. Das Plugin überprüft automatisch, ob die zip-Extension verfügbar ist.

---

## 💻 Verwendung

### Zugriff auf das Plugin

1. Melde dich im WordPress-Dashboard als Administrator an
2. Navigiere zu **Tools → Suchis Simple Backup**

### Ein Backup erstellen

1. Klicke auf den Button **"Backup jetzt erstellen (Dateien + DB)"**
2. Warte, bis das Backup abgeschlossen ist (Dauer hängt von der Website-Größe ab)
3. Nach Abschluss siehst du eine Erfolgsmeldung mit Download-Link

### Backups verwalten

#### Ansicht und Details

Im Plugin-Interface siehst du eine Tabelle mit allen Backups:

| Spalte | Beschreibung |
|--------|-------------|
| **Datei** | Name des Backup-Files (Format: `wp-backup_files-db_hostname_YYYY-MM-DD_HHmmss.zip`) |
| **Größe** | Dateigröße in MB/GB |
| **Datum** | Erstellungsdatum und -uhrzeit |
| **Aktionen** | Download oder Löschen |

#### Backup herunterladen

1. Klicke in der Backup-Liste auf **Download**
2. Die ZIP-Datei wird auf deinen Computer heruntergeladen

#### Backup löschen

1. Klicke in der Backup-Liste auf **Löschen**
2. Bestätige die Löschung
3. Das Backup wird vom Server entfernt

---

## 🔒 Sicherheit

### Automatische Schutzmaßnahmen

Das Plugin implementiert mehrere Sicherheitsmaßnahmen automatisch:

#### .htaccess Schutz

```apache
# wp-content/backups/.htaccess
Deny from all
```

Verhindert direkten Browser-Zugriff auf den Backup-Ordner (Apache-Server).

#### Directory Listing Schutz

Eine leere `index.html` verhindert Directory Listing.

#### Nonce-Verifizierung

Alle Aktionen (Backup, Download, Löschen) sind durch WordPress-Nonces geschützt.

#### Admin-Only Access

Nur Benutzer mit `manage_options` Capability (normalerweise nur Administratoren) können auf das Plugin zugreifen.

#### SQL-Injection Prävention

Alle Datenbankwerte werden korrekt escaped:
- Tabellen- und Spalten-Namen: Backticks (`)
- Werte: Proper escaping basierend auf Datentyp

---

## ❓ Häufig gestellte Fragen

### F: Wie lange dauert ein Backup?

**A:** Das hängt von der Größe deiner Website ab:
- Kleine Sites (< 100 MB): 30 Sekunden bis 1 Minute
- Mittlere Sites (100 MB - 1 GB): 2-5 Minuten
- Große Sites (> 1 GB): 5-30+ Minuten

### F: Können Backups vom Browser heruntergeladen werden?

**A:** Nein, das Plugin schützt den Backup-Ordner durch .htaccess. Backups können nur über das Plugin-Interface heruntergeladen werden.

### F: Kann ich automatische Backups planen?

**A:** Aktuell nicht direkt im Plugin. Du kannst aber WP-Cron oder Webhooks verwenden:

```php
// Beispiel: Daily Backup via wp-cron
add_action('daily_backup_schedule', 'trigger_ssbhf_run_backup');

if (!wp_next_scheduled('daily_backup_schedule')) {
    wp_schedule_event(time(), 'daily', 'daily_backup_schedule');
}
```

### F: Wo werden Backups gespeichert?

**A:** In `wp-content/backups/` auf dem Server. **WICHTIG:** Sichere diese Backups zusätzlich extern (Cloud, externe Festplatte)!

### F: Kann ich Backups auf Cloud-Speicher sichern?

**A:** Das Plugin speichert lokal. Für Cloud-Backups nutze:
- Manuelle Download + Upload zu Google Drive, Dropbox, etc.
- Zusätz-Plugins wie BackWPup oder UpdraftPlus
- FTP/SFTP-Sync des Backup-Ordners

### F: Was passiert bei Speicherplatzproblemen?

**A:** Das Plugin kann kein Backup erstellen. Löschen Sie alte Backups oder vergrößern Sie den Speicher.

---

## 🐛 Fehlerbehandlung

### „ZipArchive fehlt"

**Fehler:**
```
ZipArchive fehlt. Bitte PHP-Extension zip aktivieren.
```

**Ursache:** Die PHP-Extension `zip` ist nicht aktiviert.

**Lösung:**
1. Kontaktiere deinen Hosting-Provider
2. Bitte um Aktivierung der `zip`-Extension
3. Falls nicht möglich, wechsle den Hoster

**Selbst überprüfen:**
```php
phpinfo();
// Suche nach "zip" in der Ausgabe
```

### „ZIP konnte nicht erstellt werden"

**Fehler:**
```
ZIP konnte nicht erstellt werden (Schreibrechte?)
```

**Ursache:** Keine Schreibberechtigungen für `wp-content/backups/`.

**Lösung:**
1. Via FTP: Ändere Dateirechte auf 755 oder 775
2. Via SSH:
```bash
chmod 755 wp-content/backups/
```

### „ZIP wurde nicht korrekt geschrieben"

**Fehler:**
```
ZIP wurde nicht korrekt geschrieben (Datei fehlt/zu klein).
```

**Ursache:** Backup-Prozess wurde unterbrochen oder ist zu klein.

**Lösung:**
1. Erhöhe PHP Timeout und Memory Limit in `wp-config.php`:
```php
define('WP_MEMORY_LIMIT', '512M');
set_time_limit(300); // 5 Minuten
```
2. Versuche es erneut
3. Bei großen Sites: Kontaktiere deinen Hosting-Provider

### Backup dauert sehr lange / wird unterbrochen

**Ursachen:**
- Website ist zu groß
- Server-Ressourcen begrenzt
- PHP Timeout zu niedrig

**Lösungen:**
```php
// In wp-config.php:
define('WP_MEMORY_LIMIT', '256M');
define('WP_MAX_MEMORY_LIMIT', '512M');
set_time_limit(600); // 10 Minuten
```

---

## 🤝 Contributing

Beiträge sind willkommen! Bitte beachte:

1. **Fork** das Repository
2. Erstelle einen Feature-Branch (`git checkout -b feature/AmazingFeature`)
3. Commit deine Änderungen (`git commit -m 'Add some AmazingFeature'`)
4. Push zum Branch (`git push origin feature/AmazingFeature`)
5. Öffne einen Pull Request

### Code-Standards

- PHP-Code: WordPress Coding Standards
- Dokumentation: PHPDoc für alle Funktionen
- Test: Funktionalität vor Pull Request testen

---

## 📝 Lizenz

Dieses Plugin ist unter der **GPLv2 oder später** Lizenz lizenziert.

```
Suchis Simple Backup – Ein WordPress Backup Plugin
Copyright (C) 2024 Christian Suchanek

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

Vollständige Lizenz: [GPL 2.0](https://www.gnu.org/licenses/gpl-2.0.html)

---

## 📞 Support und Kontakt

**Autor:** Christian Suchanek
**Website:** [www.derperformer.com](https://www.derperformer.com)
**E-Mail:** [christian.suchanek@gmail.com](mailto:christian.suchanek@gmail.com)
**GitHub:** [derperformer/suchis-simple-backup](https://github.com/derperformer/suchis-simple-backup)

---

## 🎯 Roadmap

- [ ] Automatische Backup-Planung (WP-Cron)
- [ ] Cloud-Integration (Google Drive, Dropbox, AWS S3)
- [ ] E-Mail-Benachrichtigungen
- [ ] Backup-Restore-Funktion
- [ ] Selektive Backup-Optionen
- [ ] Backup-Verschlüsselung

---

## 📋 Changelog

### Version 1.2.3
- ✅ Code-Refactoring für WordPress-Standards
- ✅ Verbesserte PHPDoc-Dokumentation
- ✅ Translatable Strings hinzugefügt
- ✅ Sicherheitsverbesserungen

### Version 1.2.0
- ✅ Erstrelease auf GitHub
- ✅ Benutzerfreundliche Admin-Interface
- ✅ Database Dump mit SQL Export

---

## ⚖️ Haftungsausschluss

Dieses Plugin wird "AS IS" bereitgestellt. Der Autor übernimmt keine Haftung für:
- Datenverlust
- Backup-Fehler
- Kompatibilitätsprobleme

**Wichtig:** Teste deine Backups regelmäßig und speichere sie an mehreren Orten!

---

**Zuletzt aktualisiert:** 16. Februar 2026
