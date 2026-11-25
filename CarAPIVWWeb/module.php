<?php

require_once __DIR__ . '/VolkswagenWebClient.php';

class CarAPIVWWeb extends IPSModule
{
    private const DISPLAY_NAMES = [
        'ElectricRange' => 'Elektrische Restreichweite',
        'ChargingState' => 'Ladestatus',
        'ChargingPower' => 'Ladeleistung',
        'RemainingChargingTime' => 'Restladezeit',
        'PlugConnection' => 'Steckerstatus',
        'BatteryTemperature' => 'Akkutemperatur',
        'ChargingType' => 'Ladeart (AC/DC)',
        'ChargingMode' => 'Lademodus',
        'Odometer' => 'Kilometerstand',
        'InspectionDays' => 'Inspektion in Tagen',
        'InspectionDistance' => 'Inspektion in Kilometern',
        'WarningLights' => 'Aktive Warnleuchten'
    ];

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('TestEnabled', false);
        $this->RegisterPropertyString('VehicleVIN', '');
        $this->RegisterPropertyBoolean('AutoPollEnabled', false);
        $this->RegisterPropertyInteger('PollIntervalMinutes', 5);
        $this->RegisterAttributeString('VwCookies', '[]');
        $this->RegisterAttributeString('VwChallenge', '');
        $this->RegisterAttributeString('ConfiguredVIN', '');
        $this->RegisterAttributeInteger('PollBlockedUntil', 0);
        $this->RegisterTimer('VwPoll', 0, 'SCAVW_Poll(' . $this->InstanceID . ');');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (!$this->ReadPropertyBoolean('TestEnabled')) {
            $this->SetTimerInterval('VwPoll', 0);
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
        $changedVehicle = $this->ReadAttributeString('ConfiguredVIN') !== $vin;
        if ($changedVehicle) {
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
        $position = 80;
        foreach (self::DISPLAY_NAMES as $ident => $name) {
            $this->RegisterVariableString($ident, $name, '', $position);
            if ($changedVehicle || $this->GetValue($ident) === '') {
                $this->SetValue($ident, 'unbekannt');
            }
            $position += 10;
        }
        $minutes = max(5, min(120, $this->ReadPropertyInteger('PollIntervalMinutes')));
        $this->SetTimerInterval('VwPoll', $this->ReadPropertyBoolean('AutoPollEnabled') ? $minutes * 60000 : 0);
    }

    public function Poll(): void
    {
        if (!$this->ReadPropertyBoolean('TestEnabled') || !$this->ReadPropertyBoolean('AutoPollEnabled')
            || $this->ReadAttributeString('VwCookies') === '[]' || $this->ReadAttributeString('VwChallenge') !== ''
            || $this->ReadAttributeInteger('PollBlockedUntil') > time()) {
            return;
        }
        $this->ReadCharging();
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
            $data = $client->charging($vin);
            $battery = $data['batteryStatus'];
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
            return $this->displayAdditionalData($client, $vin, $data);
        }, false, true);
    }

    private function displayAdditionalData(CarAPIVWWebClient $client, string $vin, array $data): string
    {
        $battery = $data['batteryStatus'];
        $charging = $data['chargingStatus'] ?? [];
        $plug = $data['plugStatus'] ?? [];
        $settings = $data['chargingSettings'] ?? [];
        $temperature = $battery['temperatureHvBattery_K'] ?? null;
        $target = $this->percentage($settings['targetSOC_pct'] ?? null);
        $messages = ['Ladedaten empfangen.'];

        $this->SetValue('VehicleTarget', $target === null ? 'unbekannt' : $target . ' %');
        $values = [
            'ElectricRange' => $this->measurement($battery['cruisingRangeElectric_km'] ?? null, 'km'),
            'ChargingState' => $this->displayStatus($charging['chargingState'] ?? null),
            'ChargingPower' => $this->measurement($charging['chargePower_kW'] ?? null, 'kW', 1),
            'RemainingChargingTime' => $this->measurement($charging['remainingChargingTimeToComplete_min'] ?? null, 'min'),
            'PlugConnection' => $this->displayStatus($plug['plugConnectionState'] ?? null),
            'BatteryTemperature' => is_numeric($temperature) && $temperature > 0
                ? $this->measurement((float) $temperature - 273.15, '°C', 1) : 'unbekannt',
            'ChargingType' => $this->displayStatus($charging['chargeType'] ?? null),
            'ChargingMode' => $this->displayStatus($charging['chargeMode'] ?? null)
        ];
        foreach ($values as $ident => $value) {
            $this->SetValue($ident, $value);
        }

        try {
            $maintenance = $client->maintenance($vin);
        } catch (CarAPIVWRateLimit $error) {
            throw $error;
        } catch (RuntimeException $error) {
            $maintenance = [];
            $messages[] = 'Servicedaten: ' . $error->getMessage() . '.';
        }
        $this->SetValue('Odometer', $this->measurement($maintenance['mileage_km'] ?? null, 'km'));
        $this->SetValue('InspectionDays', $this->measurement($maintenance['inspectionDue_days'] ?? null, 'Tage'));
        $this->SetValue('InspectionDistance', $this->measurement($maintenance['inspectionDue_km'] ?? null, 'km'));

        try {
            $warningLights = $client->warningLights($vin);
        } catch (CarAPIVWRateLimit $error) {
            throw $error;
        } catch (RuntimeException $error) {
            $warningLights = null;
            $messages[] = 'Warnleuchten: ' . $error->getMessage() . '.';
        }
        $this->SetValue('WarningLights', $warningLights === null ? 'unbekannt' : (string) $warningLights);
        return implode(' ', $messages);
    }

    private function measurement($value, string $unit, int $decimals = 0): string
    {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            return 'unbekannt';
        }
        return number_format((float) $value, $decimals, ',', '.') . ' ' . $unit;
    }

    private function displayStatus($value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return 'unbekannt';
        }
        return match (strtolower($value)) {
            'invalid', 'unknown' => 'unbekannt',
            'ac', 'dc' => strtoupper($value),
            'connected' => 'Angesteckt',
            'disconnected' => 'Nicht angesteckt',
            'notreadyforcharging' => 'Nicht ladebereit',
            'readyforcharging' => 'Ladebereit',
            'charging' => 'Lädt',
            'chargepurposereached' => 'Ladeziel erreicht',
            'manual' => 'Manuell',
            default => $value
        };
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
            if ($this->ReadAttributeInteger('PollBlockedUntil') > time()) {
                $message = 'VW begrenzt gerade die Anfragen (HTTP 429). Der Abruf pausiert.';
                return $message;
            }
            $cookies = $newSession ? [] : json_decode($this->ReadAttributeString('VwCookies'), true);
            $client = new CarAPIVWWebClient(is_array($cookies) ? $cookies : []);
            $message = $operation($client);
        } catch (CarAPIVWRateLimit $error) {
            $this->WriteAttributeInteger('PollBlockedUntil', time() + $error->retryAfterSeconds);
            $message = $error->getMessage();
        } catch (CarAPIVWLoginRequired $error) {
            $client = null;
            $this->WriteAttributeString('VwCookies', '[]');
            $this->WriteAttributeString('VwChallenge', '');
            $this->SetValue('StateOfChargeAvailable', false);
            $message = $error->getMessage();
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
