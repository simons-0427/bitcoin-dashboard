# Bitcoin-Dashboard v1.0

Bitcoin-Dashboard mit deutscher und englischer Oberfläche, 49 sichtbaren Kennzahlen, historischen Charts und automatischen Datenimporten. Die separate EUR-Preisreihe wird intern erhalten; im Dashboard gibt es einen Bitcoin-Preis mit Währungsauswahl.

## Funktionen

- Marktüberblick, durchsuchbarer Datenkatalog und CSV-Export.
- Anzeige in USD, EUR, GBP, JPY, CNY oder in der Originaleinheit.
- Relative und absolute Änderungen zum vorherigen gespeicherten Wert sowie zu historischen Vergleichswerten.
- Frei wählbare Zeiträume, nominale oder logarithmische Chart-Skala.
- Sprachwechsel zwischen Deutsch und Englisch mit erhaltenen Anzeigeeinstellungen.
- PHP-Importe für Coin Metrics, ECB, Open Bitcoin Metrics, Alternative.me und CFTC.

## Inhalt und Voraussetzungen

Die Ordnerstruktur entspricht der Website:

```text
assets/                         Gemeinsamer Header
dashboards/bitcoin/             Deutsche Oberfläche, PHP-API, Importe und Daten
en/dashboards/bitcoin/          Englische Oberfläche mit gemeinsamer Bitcoin-API
```

Erforderlich sind PHP 8.3 mit PDO MySQL, cURL, OpenSSL und ZIP, MySQL 8 oder MariaDB 10.5 sowie ein Webserver. Die täglichen Skripte benötigen eine Unix-Shell. Apache muss die enthaltenen `.htaccess`-Dateien auswerten; unter anderen Webservern müssen `config`, `var`, `includes`, `cron`, `tools` und `sql` entsprechend gegen HTTP-Zugriff gesperrt werden. Der eingebaute PHP-Server wertet `.htaccess` nicht aus und eignet sich nur für eine abgeschottete lokale Entwicklung.

Die Währungsumrechnung verwendet wie die Originalinstallation das parallel installierte [Weltmarkt-Dashboard](https://github.com/simons-0427/weltmarkt-dashboard). Dessen API muss unter `/dashboards/weltmarkt/api/weltmarkt/series.php` die Reihen `eurusd`, `gbpusd`, `usdjpy` und `usdcny` bereitstellen. Dieses Paket enthält das Bitcoin-Projekt; das Weltmarkt-Projekt wird separat installiert. USD und Originaleinheiten benötigen diese zusätzliche API nicht. Allgemeine Navigationslinks verweisen auf die übergeordnete Website.

## Neue Installation

1. Den Inhalt dieses Paketordners in das Web-Stammverzeichnis kopieren. Die Ordner `assets`, `dashboards` und `en` müssen auf derselben Ebene liegen. Bei einer vorhandenen Website gemeinsame Header-Dateien vorher vergleichen.
2. Eine eigene MySQL-Datenbank und einen Datenbankbenutzer bereitstellen.
3. `dashboards/bitcoin/config/bitcoin.example.php` nach `bitcoin.php` im selben Ordner kopieren und die eigenen Datenbankdaten eintragen.
4. In dieser Datenbank zuerst `dashboards/bitcoin/sql/001_schema.sql`, danach `002_active_catalog.sql` ausführen. Diese Dateien bilden die Basis für eine neue Installation. Bei einer bestehenden Installation vorher eine Sicherung erstellen und die vorhandene Migration berücksichtigen.
5. Im Terminal in `dashboards/bitcoin` wechseln und die mitgelieferten CSV-Dateien importieren:

```sh
php cron/import-observations.php --trigger=initial_backfill var/bitcoin-core-master.csv
php cron/import-observations.php --trigger=initial_backfill var/bitcoin-core-inputs.csv
php cron/derive-metrics.php --trigger=manual
```

Die beiden CSV-Dateien enthalten 290.291 Kernbeobachtungen und 172.056 Eingangsbeobachtungen. Identische Überschneidungen werden vom Importer unverändert übernommen. Der Stand der einzelnen Reihen und ihre Herkunft stehen in `var/bitcoin-core-manifest.json`. Der Datenstand ist ein historischer Ausschnitt; neue Beobachtungen entstehen erst durch weitere erfolgreiche Importe. Unterschiedliche Veröffentlichungsfrequenzen und Quelllücken bleiben erhalten.

6. Dem PHP-Prozess Schreibrechte für `dashboards/bitcoin/var` geben. Für die einmalige Web-Cron-Einrichtung muss außerdem der Konfigurationsordner beschreibbar sein.
7. `/dashboards/bitcoin/` beziehungsweise `/en/dashboards/bitcoin/` aufrufen. Die englische Oberfläche verwendet dieselbe Datenbank und dieselben Importprozesse.

Das Paket enthält keine Datenbankzugänge, vorhandenen Cron-Tokens, privaten Forschungsreihen oder Server-Datenbankexporte.

## Automatische Aktualisierung

Manueller Test aus `dashboards/bitcoin`:

```sh
BITCOIN_PHP_BIN=/pfad/zum/php-cli /bin/sh cron/run-daily.sh
```

Für einen externen HTTP-Zeitplan wie cron-job.org auf dem Server einmalig ausführen:

```sh
/pfad/zum/php-cli cron/setup-web-cron.php --php-bin=/pfad/zum/php-cli
```

Den ausgegebenen Token privat halten. Im Scheduler die URL der eigenen Installation mit `/dashboards/bitcoin/api/bitcoin/cron.php`, Methode `POST` und Header `X-Bitcoin-Cron-Token` verwenden. Die vom Hilfsskript ausgegebene Domain gegebenenfalls an die eigene Installation anpassen. Alle fünf Minuten aufrufen; nach dem ersten Test startet der eigentliche Datenimport höchstens einmal pro Tag ab 15 Uhr in `Europe/Berlin`.

HTTP `202` mit `queued` oder `running` bestätigt nur den Start. Ein erfolgreicher Abschluss ist HTTP `200` mit `status: succeeded` und `exit_code: 0`. Nach einem Fehler zuerst die Ursache prüfen. Anschließend kann `php cron/setup-web-cron.php --retry` einen erneuten Lauf freigeben. Die Währungsreihen werden vom separaten Weltmarkt-Import gepflegt.

## Daten und Quellen

Die 50 aktiven gespeicherten Kennzahlen ergeben 49 sichtbare Einträge. Zusätzliche Rohreihen dienen nur als interne Eingangsgrößen. Die enthaltene Datenbankbasis registriert ausschließlich diese benötigten Reihen; ältere Forschungs-Migrationen sind nicht erforderlich.

Für Währungsumrechnungen gilt der letzte vorhandene Handelstag, höchstens sieben Kalendertage zuvor. Fehlende Kurse bleiben Lücken. Prozent- und BTC-Werte bleiben unverändert. Die logarithmische Skala benötigt ausschließlich positive Werte; andernfalls verwendet der Chart die nominale Skala.

Die Quellen haben unterschiedliche Nutzungsbedingungen. Insbesondere enthalten Coin-Metrics-Daten und daraus berechnete Reihen eine Beschränkung auf nichtkommerzielle Nutzung. Quellenangaben und Hinweise bleiben Bestandteil des Pakets. Details und Links stehen in [SOURCES.md](SOURCES.md).

## Prüfung

```sh
python dashboards/bitcoin/tools/validate-core-dashboard.py
php dashboards/bitcoin/tools/test-core-revisions.php
php dashboards/bitcoin/tools/test-core-series.php
```

`MANIFEST.sha256` enthält die Prüfsummen der Paketdateien. Die zusätzliche `.zip.sha256` neben dem Download prüft das gesamte Archiv. Die Historienprüfung benötigt Python 3, die beiden PHP-Prüfungen benötigen PDO SQLite und laufen ohne Produktionsdatenbank.
