<?php

require_once __DIR__ . '/VolkswagenWebClient.php';

class CarAPIVWWeb extends IPSModule
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('TestEnabled', false);
        $this->RegisterPropertyString('VehicleVIN', '');
        $this->RegisterAttributeString('VwCookies', '[]');
        $this->RegisterAttributeString('VwChallenge', '');
        $this->RegisterAttributeString('ConfiguredVIN', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (!$this->ReadPropertyBoolean('TestEnabled')) {
            return;
        }
        $this->RegisterVariableInteger('StateOfCharge', 'Ist-SoC', '~Intensity.100', 10);
        $this->RegisterVariableBoolean('StateOfChargeAvailable', 'Ist-SoC vorhanden', '', 20);
        $this->RegisterVariableString('ReportedNavigationTarget', 'Navigations-Ziel-SoC (Diagnose)', '', 30);
        $this->RegisterVariableString('VehicleTarget', 'Ziel-SoC vom Fahrzeug', '', 40);
        $this->RegisterVariableInteger('VehicleReportedAt', 'Fahrzeugdatenstand', '~UnixTimestamp', 50);
        $this->RegisterVariableInteger('FetchedAt', 'Letzter erfolgreicher Abruf', '~UnixTimestamp', 60);
        $this->RegisterVariableString('FetchStatus', 'Abrufstatus', '', 70);

        $vin = $this->vin();
        if ($this->ReadAttributeString('ConfiguredVIN') !== $vin) {
            $this->WriteAttributeString('ConfiguredVIN', $vin);
            $this->SetValue('StateOfCharge', 0);
            $this->SetValue('StateOfChargeAvailable', false);
            $this->SetValue('ReportedNavigationTarget', 'unbekannt');
            $this->SetValue('VehicleReportedAt', 0);
            $this->SetValue('FetchedAt', 0);
            $this->SetValue('FetchStatus', 'Bereit für den manuellen Test.');
        }
        $this->SetValue('VehicleTarget', 'unbekannt');
        if ($this->GetValue('FetchStatus') === '') {
            $this->SetValue('FetchStatus', 'Bereit für den manuellen Test.');
        }
    }

    public function Login(string $Email, string $Password): string
    {
        if (!filter_var(trim($Email), FILTER_VALIDATE_EMAIL) || $Password === '') {
            return 'Bitte E-Mail-Adresse und Passwort der Volkswagen ID eingeben.';
        }
        return $this->useSession(function (CarAPIVWWebClient $client) use ($Email, $Password): string {
            $this->WriteAttributeString('VwChallenge', '');
            $challenge = $client->login(trim($Email), $Password);
            return $this->recordChallenge($challenge);
        }, true);
    }

    public function ConfirmLogin(string $Code): string
    {
        if (!preg_match('/^[0-9]{6,8}$/D', trim($Code))) {
            return 'Bitte den Code aus der VW-E-Mail eingeben.';
        }
        return $this->useSession(function (CarAPIVWWebClient $client) use ($Code): string {
            $challenge = json_decode($this->ReadAttributeString('VwChallenge'), true);
            if (!is_array($challenge) || ($challenge['requestedAt'] ?? 0) < time() - 600) {
                throw new RuntimeException('Keine aktive Code-Anfrage. Bitte die VW-Anmeldung starten.');
            }
            return $this->recordChallenge($client->confirm($challenge, trim($Code)));
        });
    }

    public function ReadCharging(): string
    {
        return $this->useSession(function (CarAPIVWWebClient $client): string {
            $vin = $this->vin();
            if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/D', $vin)) {
                throw new RuntimeException('Bitte eine gültige FIN in der Instanz speichern.');
            }
            if ($client->cookies() === [] || $this->ReadAttributeString('VwChallenge') !== '') {
                throw new RuntimeException('Bitte zuerst die VW-Anmeldung abschließen.');
            }
            $battery = $client->charging($vin);
            $soc = $this->percentage($battery['currentSOC_pct'] ?? null);
            if ($soc === null) {
                throw new RuntimeException('VW hat keinen gültigen Ist-SoC geliefert.');
            }
            $navigationTarget = $this->percentage($battery['navigationTargetSOC_pct'] ?? null);
            $vehicleTime = $battery['carCapturedTimestamp'] ?? null;
            $captured = is_string($vehicleTime) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $vehicleTime) === 1
                ? (strtotime($vehicleTime) ?: 0) : 0;
            $this->SetValue('StateOfCharge', $soc);
            $this->SetValue('ReportedNavigationTarget', $navigationTarget === null ? 'unbekannt' : $navigationTarget . ' %');
            $this->SetValue('VehicleReportedAt', $captured);
            $this->SetValue('FetchedAt', time());
            $this->SetValue('StateOfChargeAvailable', true);
            return 'Ladedaten empfangen.';
        }, false, true);
    }

    public function ClearSession(): string
    {
        $semaphore = 'CarAPIVW-' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 0)) {
            return 'Die Instanz bearbeitet gerade eine VW-Anfrage.';
        }
        try {
            $this->WriteAttributeString('VwCookies', '[]');
            $this->WriteAttributeString('VwChallenge', '');
            $message = 'Lokale VW-Sitzung gelöscht.';
            if ($this->ReadPropertyBoolean('TestEnabled')) {
                $this->SetValue('StateOfChargeAvailable', false);
                $this->SetValue('FetchStatus', $message);
            }
            return $message;
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function useSession(callable $operation, bool $newSession = false, bool $reading = false): string
    {
        if (!$this->ReadPropertyBoolean('TestEnabled')) {
            return 'Bitte den VW-Test aktivieren und die Einstellungen übernehmen.';
        }
        $semaphore = 'CarAPIVW-' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 0)) {
            return 'Die Instanz bearbeitet gerade eine VW-Anfrage.';
        }
        $client = null;
        try {
            $cookies = $newSession ? [] : json_decode($this->ReadAttributeString('VwCookies'), true);
            $client = new CarAPIVWWebClient(is_array($cookies) ? $cookies : []);
            $message = $operation($client);
        } catch (RuntimeException $error) {
            $message = $error->getMessage();
            if ($reading) {
                $this->SetValue('StateOfChargeAvailable', false);
            }
        } catch (Throwable $error) {
            $message = 'Die VW-Anfrage konnte nicht verarbeitet werden.';
            if ($reading) {
                $this->SetValue('StateOfChargeAvailable', false);
            }
        } finally {
            try {
                if ($client !== null) {
                    $this->WriteAttributeString('VwCookies', json_encode($client->cookies(), JSON_INVALID_UTF8_SUBSTITUTE));
                }
                $this->SetValue('FetchStatus', $message);
            } finally {
                IPS_SemaphoreLeave($semaphore);
            }
        }
        return $message;
    }

    private function recordChallenge(?array $challenge): string
    {
        if ($challenge === null) {
            $this->WriteAttributeString('VwChallenge', '');
            return 'VW-Anmeldung abgeschlossen. Du kannst die Ladedaten abrufen.';
        }
        $challenge['requestedAt'] = time();
        $this->WriteAttributeString('VwChallenge', json_encode($challenge, JSON_INVALID_UTF8_SUBSTITUTE));
        return 'Bitte den VW-E-Mail-Code bestätigen.';
    }

    private function vin(): string
    {
        return strtoupper(trim($this->ReadPropertyString('VehicleVIN')));
    }

    private function percentage($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d{1,3}$/D', $value))) {
            return null;
        }
        $number = (int) $value;
        return $number >= 0 && $number <= 100 ? $number : null;
    }
}
