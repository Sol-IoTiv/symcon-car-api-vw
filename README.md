# Car API VW für IP-Symcon

Das Modul fragt den Ladezustand eines Volkswagen ID.3 direkt bei der Webschnittstelle von myVolkswagen ab. Es verwendet eine Sitzung deiner Volkswagen ID und benötigt Symcon 7.1 oder neuer sowie cURL mit HTTP/2. Wenn die PHP-Erweiterung kein HTTP/2 unterstützt, verwendet das Modul das vorhandene System-cURL. Volkswagen kann den Webzugriff jederzeit ändern.

## Test in Symcon

1. Diese Bibliothek über Module Control einbinden und eine Instanz `Car API VW` unter `Auto` anlegen.
2. Den VW-Test aktivieren, die FIN hinterlegen und die Einstellungen übernehmen.
3. Mit der Volkswagen ID anmelden. Einen gegebenenfalls angeforderten E-Mail-Code im Formular bestätigen. Das Passwort und der Code werden für den jeweiligen Anmeldeschritt entgegengenommen. Symcon speichert die Sitzung als Instanzattribut.
4. `Ladedaten abrufen` klicken und den Akku-SoC mit dem Fahrzeug vergleichen. Der manuelle Abruf zeigt zusätzlich die verfügbaren Lade-, Stecker- und Servicedaten sowie die Anzahl aktiver Warnleuchten. Fehlende Felder stehen auf `unbekannt`.
5. Für den Test des Ziel-SoC das Ladelimit am Fahrzeug ändern und erneut abfragen. Das Modul zeigt das Ladelimit an, wenn VW es in den Ladeeinstellungen mitsendet. Das von VW gemeldete Navigationsziel erscheint separat als Diagnosewert.

Ein erfolgreicher Abruf liefert den zuletzt bei VW verfügbaren Fahrzeugzustand. Die Instanz zeigt zusätzlich den Abrufzeitpunkt und, falls im Akkustatus verfügbar, den Fahrzeugzeitpunkt an. Der Wert `0` im Zeitstempel steht für einen unbekannten Zeitpunkt. Das Feld `Ist-SoC vorhanden` zeigt an, ob der letzte Abruf einen gültigen Ladezustand geliefert hat.

## Automatischer Abruf

In der Instanzkonfiguration kann der automatische Abruf eingeschaltet und ein Intervall von 5 bis 120 Minuten eingestellt werden. Der Schalter ist standardmäßig aus, das voreingestellte Intervall beträgt 5 Minuten. Der manuelle Abruf bleibt verfügbar. Bei einer VW-Antwort mit HTTP 429 wartet das Modul gemäß `Retry-After` oder bei fehlender Angabe mindestens 5 Minuten bis zum nächsten Abruf.

Die Implementierung orientiert sich am Webabruf der MIT-lizenzierten Integration [Volkswagen Connect von Rafael Hutter](https://github.com/rafaelhutter/ha-volkswagen-connect).

MIT-Lizenz.

## ❤️ Unterstützung

Du möchtest die Weiterentwicklung unterstützen? Wir freuen uns über eine kleine Spende:

<table>
  <tr>
    <td align="center">
      <a href="https://www.paypal.com/donate/?business=PR9P7V7RMFHFQ&no_recurring=0&item_name=Spende+als+Dankesch%C3%B6n+f%C3%BCr+die+Modulentwicklung+Symcon&currency_code=EUR" target="_blank" rel="noopener noreferrer">
        <img src="imgs/paypal_logo.png" alt="Spenden mit PayPal" style="max-width: 300px;">
      </a>
    </td>
    <td align="center">
      <a href="https://www.paypal.com/donate/?business=PR9P7V7RMFHFQ&no_recurring=0&item_name=Spende+als+Dankesch%C3%B6n+f%C3%BCr+die+Modulentwicklung+Symcon&currency_code=EUR" target="_blank" rel="noopener noreferrer">
        <img src="imgs/paypal_qr.png" alt="QR-Code zur PayPal-Spende" style="max-width: 200px;">
      </a>
    </td>
  </tr>
</table>

> ☕ Vielen Dank für deine Unterstützung!  
> 📜 Dieses Modul bleibt selbstverständlich frei verfügbar und quelloffen.
