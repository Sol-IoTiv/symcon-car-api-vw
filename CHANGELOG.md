# Changelog

## 0.4

- Zeigt verfügbare Reichweite, Ladestatus, Ladeleistung, Restladezeit, Steckerstatus, Akkutemperatur, Ladeart, Lademodus, Kilometerstand, Inspektionsintervall und aktive Warnleuchten in Symcon an.
- Zeigt den Ziel-SoC, sofern die Webschnittstelle ihn in den Ladeeinstellungen liefert.

## 0.3

- Verwendet vorhandenes System-cURL mit HTTP/2, wenn die PHP-Erweiterung des Symcon-Servers kein HTTP/2 anbietet.

## 0.2

- Manueller Ladeabruf für den Volkswagen ID.3 über myVolkswagen mit Anzeige des Ist-SoC und des Abrufstatus.
- Der alte Passwort-Token-Login und die Markenumschaltung wurden durch den Volkswagen-Weblogin ersetzt.
- Das Navigationsziel wird als Diagnosewert angezeigt.
- Die Symcon-Bibliothek und das Modul haben eigene IDs für die getrennte Installation neben dem Entwurf von 2025.

## 0.1

- Erster Entwurf der Modulstruktur und des Verbindungstests.
