<?php

final class CarAPIVWLoginRequired extends RuntimeException
{
}

final class CarAPIVWRateLimit extends RuntimeException
{
    public function __construct(public int $retryAfterSeconds)
    {
        parent::__construct('VW begrenzt gerade die Anfragen (HTTP 429). Der Abruf pausiert.');
    }
}

final class CarAPIVWWebClient
{
    private const PORTAL = 'https://www.volkswagen.de';
    private const IDENTITY = 'https://identity.vwgroup.io';
    private const HOME = self::PORTAL . '/de/besitzer-und-nutzer/myvolkswagen.html';
    private const AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';

    private $curl;
    private array $systemCookies;

    public function __construct(array $cookies)
    {
        if (!function_exists('curl_version') || (curl_version()['features'] & CURL_VERSION_HTTP2) === 0) {
            $this->curl = null;
            $this->systemCookies = $cookies;
            $version = $this->runCurl(['--version']);
            if (preg_match('/^Features:\s.*\bHTTP2\b/m', $version) !== 1) {
                throw new RuntimeException('Der VW-Abruf braucht eine cURL-Version mit HTTP/2.');
            }
            return;
        }
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [
            CURLOPT_COOKIEFILE => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => self::AGENT
        ]);
        foreach ($cookies as $cookie) {
            curl_setopt($this->curl, CURLOPT_COOKIELIST, $cookie);
        }
    }

    public function cookies(): array
    {
        if ($this->curl === null) {
            return $this->systemCookies;
        }
        return curl_getinfo($this->curl, CURLINFO_COOKIELIST) ?: [];
    }

    public function login(string $email, string $password): ?array
    {
        $page = $this->follow($this->request($this->loginUrl()));
        if (parse_url($page['url'], PHP_URL_HOST) === 'identity.vwgroup.io'
            && parse_url($page['url'], PHP_URL_PATH) === '/u/login') {
            parse_str(parse_url($page['url'], PHP_URL_QUERY) ?? '', $query);
            if (!is_string($query['state'] ?? null) || $query['state'] === '') {
                throw new RuntimeException('VW hat keinen gültigen Anmeldestatus geliefert.');
            }
            $page = $this->follow($this->request($page['url'], [
                'state' => $query['state'], 'username' => $email, 'password' => $password
            ]));
        }

        return $this->finishLogin($page);
    }

    public function confirm(array $challenge, string $code): ?array
    {
        $url = $challenge['url'];
        if (parse_url($url, PHP_URL_HOST) !== 'identity.vwgroup.io') {
            throw new RuntimeException('VW hat ein unerwartetes Ziel für den E-Mail-Code geliefert.');
        }
        $fields = $challenge['fields'];
        foreach (array_keys($fields) as $name) {
            if (in_array(strtolower($name), ['otp', 'mfa-code', 'token', 'code'], true)) {
                $fields[$name] = $code;
            }
        }
        $fields['code'] = $code;

        return $this->finishLogin($this->follow($this->request($url, $fields)));
    }

    public function charging(string $vin): array
    {
        $response = $this->vehicleResponse('vwag-weconnect', $vin, 'charging/status');
        if (in_array($response['status'], [301, 302, 303, 307, 308, 401, 412, 428], true)) {
            $challenge = $this->finishLogin($this->follow($this->request($this->loginUrl())));
            if ($challenge !== null) {
                throw new CarAPIVWLoginRequired('VW verlangt eine erneute Anmeldung im Modulformular.');
            }
            $response = $this->vehicleResponse('vwag-weconnect', $vin, 'charging/status');
            if ($response['status'] === 401) {
                throw new CarAPIVWLoginRequired('VW verlangt eine erneute Anmeldung im Modulformular.');
            }
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException('VW-Ladeabruf fehlgeschlagen (HTTP ' . $response['status'] . ').');
        }
        $data = json_decode($response['body'], true)['data'] ?? null;
        if (!is_array($data['batteryStatus'] ?? null)) {
            throw new RuntimeException('VW hat keinen Akkustatus geliefert. Ist die FIN dem Konto zugeordnet?');
        }

        return $data;
    }

    public function maintenance(string $vin): array
    {
        return $this->vehicleData('vw-de', $vin, 'maintenance/status');
    }

    public function inspectChargingSettings(string $vin): array
    {
        $response = $this->vehicleResponse('vwag-weconnect', $vin, 'charging/settings');
        $data = $response['status'] === 200 ? json_decode($response['body'], true) : null;
        return ['status' => $response['status'], 'data' => is_array($data) ? $data : []];
    }

    public function warningLights(string $vin): ?int
    {
        $data = $this->vehicleData('vwag-weconnect', $vin, 'warninglights/last');
        $lights = $data['warningLights'] ?? null;
        return is_array($lights) && array_is_list($lights) ? count($lights) : null;
    }

    private function vehicleData(string $service, string $vin, string $path): array
    {
        $response = $this->vehicleResponse($service, $vin, $path);
        if ($response['status'] !== 200) {
            throw new RuntimeException('HTTP ' . $response['status']);
        }
        $data = json_decode($response['body'], true)['data'] ?? null;
        if (!is_array($data)) {
            throw new RuntimeException('keine Fahrzeugdaten');
        }
        return $data;
    }

    private function vehicleResponse(string $service, string $vin, string $path): array
    {
        $url = self::PORTAL . '/app/authproxy/' . $service . '/proxy/vehicles/' . rawurlencode($vin)
            . '/' . $path . '?gdc=myvw-wcar-prod&resourceHost=myvw-vcf-prod';
        $csrf = '';
        foreach ($this->cookies() as $cookie) {
            $parts = explode("\t", $cookie, 7);
            $domain = ltrim(str_replace('#HttpOnly_', '', $parts[0]), '.');
            if (count($parts) === 7 && in_array($domain, ['volkswagen.de', 'www.volkswagen.de'], true)
                && $parts[5] === 'csrf_token') {
                $csrf = $parts[6];
            }
        }
        return $this->request($url, null, [
            'Accept: */*', 'user-id: __userId__', 'Referer: ' . self::HOME,
            'x-csrf-token: ' . $csrf, 'traceId: ' . bin2hex(random_bytes(16))
        ]);
    }

    private function loginUrl(): string
    {
        return self::PORTAL . '/app/authproxy/login?' . http_build_query([
            'fag' => 'vw-de,vwag-weconnect',
            'scope-vw-de' => 'profile,address,phone,carConfigurations,dealers,cars,vin,profession',
            'scope-vwag-weconnect' => 'openid,mbb', 'prompt-vwag-weconnect' => 'none',
            'redirectUrl' => self::HOME, 'sessionTimeout' => 1800
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function finishLogin(array $page): ?array
    {
        $path = parse_url($page['url'], PHP_URL_PATH) ?? '';
        $host = parse_url($page['url'], PHP_URL_HOST);
        if ($page['status'] === 200 && $host === 'identity.vwgroup.io' && str_contains($path, '/u/mfa-email-challenge')) {
            return $this->emailChallenge($page['url'], $page['body']);
        }
        parse_str(parse_url($page['url'], PHP_URL_QUERY) ?? '', $query);
        if (isset($query['error']) || $page['status'] !== 200 || $host !== 'www.volkswagen.de'
            || !str_ends_with($path, '/myvolkswagen.html')) {
            throw new CarAPIVWLoginRequired('VW-Anmeldung nicht abgeschlossen. Prüfe den Login und offene Bestätigungen in myVolkswagen.');
        }
        return null;
    }

    private function emailChallenge(string $url, string $html): array
    {
        if (!class_exists(DOMDocument::class)) {
            throw new RuntimeException('Für den VW-E-Mail-Code benötigt PHP die DOM-Erweiterung.');
        }
        $document = new DOMDocument();
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        foreach ($document->getElementsByTagName('form') as $form) {
            $fields = [];
            $hasCode = false;
            foreach ($form->getElementsByTagName('input') as $input) {
                $name = $input->getAttribute('name');
                if ($name === '') {
                    continue;
                }
                $hasCode = $hasCode || in_array(strtolower($name), ['otp', 'mfa-code', 'token', 'code'], true);
                if ($input->getAttribute('type') === 'hidden' || $input->hasAttribute('checked')) {
                    $fields[$name] = $input->getAttribute('value');
                } elseif (in_array(strtolower($name), ['otp', 'mfa-code', 'token', 'code'], true)) {
                    $fields[$name] = '';
                }
            }
            if ($hasCode) {
                return ['url' => $this->resolve($url, $form->getAttribute('action')), 'fields' => $fields];
            }
        }
        throw new RuntimeException('Das VW-Formular für den E-Mail-Code wurde nicht erkannt.');
    }

    private function follow(array $page): array
    {
        $start = time();
        for ($hop = 0; $hop < 30 && time() - $start < 40; $hop++) {
            if (!in_array($page['status'], [301, 302, 303, 307, 308], true) || $page['location'] === '') {
                return $page;
            }
            $page = $this->request($this->resolve($page['url'], $page['location']));
        }
        throw new RuntimeException('Die VW-Anmeldung hat zu lange gedauert.');
    }

    private function request(string $url, ?array $form = null, array $headers = []): array
    {
        $parts = parse_url($url) ?: [];
        if (($parts['scheme'] ?? '') !== 'https' || !in_array($parts['host'] ?? '', ['www.volkswagen.de', 'identity.vwgroup.io'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new RuntimeException('VW hat auf ein unbekanntes Ziel weitergeleitet.');
        }
        $location = '';
        $retryAfter = '';
        $headers[] = 'Accept-Language: de-DE';
        if ($this->curl === null) {
            return $this->systemRequest($url, $form, $headers);
        }
        curl_setopt_array($this->curl, [
            CURLOPT_URL => $url, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$location, &$retryAfter): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $location = '';
                    $retryAfter = '';
                } elseif (strncasecmp($line, 'Location:', 9) === 0) {
                    $location = trim(substr($line, 9));
                } elseif (strncasecmp($line, 'Retry-After:', 12) === 0) {
                    $retryAfter = trim(substr($line, 12));
                }
                return strlen($line);
            }
        ]);
        if ($form === null) {
            curl_setopt($this->curl, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($this->curl, CURLOPT_POST, true);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $body = curl_exec($this->curl);
        if ($body === false) {
            throw new RuntimeException('VW-Verbindung fehlgeschlagen (cURL ' . curl_errno($this->curl) . ').');
        }
        $status = curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);
        $this->checkRateLimit($status, $retryAfter);
        return ['url' => $url, 'status' => $status, 'location' => $location, 'body' => $body];
    }

    private function systemRequest(string $url, ?array $form, array $headers): array
    {
        $jar = tempnam(sys_get_temp_dir(), 'symcon-vw-');
        if ($jar === false) {
            throw new RuntimeException('Die temporäre VW-Sitzung konnte nicht vorbereitet werden.');
        }
        $responseHeaders = false;
        try {
            $responseHeaders = tempnam(sys_get_temp_dir(), 'symcon-vw-response-');
            if ($responseHeaders === false) {
                throw new RuntimeException('Die temporären VW-Antwortdaten konnten nicht vorbereitet werden.');
            }
            if (file_put_contents($jar, "# Netscape HTTP Cookie File\n" . implode("\n", $this->systemCookies) . "\n") === false) {
                throw new RuntimeException('Die temporäre VW-Sitzung konnte nicht geschrieben werden.');
            }
            $options = [
                'url' => $url, 'cookie' => $jar, 'cookie-jar' => $jar,
                'user-agent' => self::AGENT, 'dump-header' => $responseHeaders
            ];
            if ($form !== null) {
                $options['data'] = http_build_query($form);
            }
            $config = '';
            foreach ($options as $name => $value) {
                $config .= $this->curlOption($name, $value);
            }
            foreach ($headers as $header) {
                $config .= $this->curlOption('header', $header);
            }

            $output = $this->runCurl([
                '--http2', '--proto', '=https', '--silent', '--compressed', '--connect-timeout', '5',
                '--max-time', '15', '--write-out', '\n%{http_code}\n%{redirect_url}', '--config', '-'
            ], $config);
            $cookies = file($jar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($cookies)) {
                $this->systemCookies = array_values(array_filter($cookies, static function (string $line): bool {
                    return !str_starts_with($line, '#') || str_starts_with($line, '#HttpOnly_');
                }));
            }
            if (preg_match('/\n(\d{3})\n([^\r\n]*)$/D', $output, $metadata, PREG_OFFSET_CAPTURE) !== 1) {
                throw new RuntimeException('Die HTTP-Antwort von VW konnte nicht gelesen werden.');
            }
            $status = (int) $metadata[1][0];
            if ($status === 429) {
                $rawHeaders = file_get_contents($responseHeaders) ?: '';
                preg_match_all('/^Retry-After:[ \t]*([^\r\n]*)/im', $rawHeaders, $matches);
                $this->checkRateLimit($status, trim(end($matches[1]) ?: ''));
            }
            return ['url' => $url, 'status' => $status, 'location' => $metadata[2][0],
                'body' => substr($output, 0, $metadata[0][1])];
        } finally {
            if ($responseHeaders !== false) {
                unlink($responseHeaders);
            }
            unlink($jar);
        }
    }

    private function checkRateLimit(int $status, string $retryAfter): void
    {
        if ($status !== 429) {
            return;
        }
        $now = time();
        $headerDate = $retryAfter === '' ? false : strtotime($retryAfter, $now);
        $seconds = ctype_digit($retryAfter) ? (int) $retryAfter : ($headerDate === false ? 300 : $headerDate - $now);
        throw new CarAPIVWRateLimit(max(60, min($seconds, PHP_INT_MAX - $now - 60)));
    }

    private function runCurl(array $arguments, string $input = ''): string
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('System-cURL ist aus Symcon nicht verfügbar.');
        }
        $process = @proc_open(array_merge(['curl', '--disable'], $arguments), [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]
        ], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('System-cURL konnte in Symcon nicht gestartet werden.');
        }
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);
        if ($exit !== 0 || $output === false) {
            throw new RuntimeException('System-cURL konnte VW nicht abrufen (Exit-Code ' . $exit . ').');
        }
        return $output;
    }

    private function curlOption(string $name, string $value): string
    {
        return $name . ' = "' . addcslashes($value, "\\\"\n\r\t\v") . '"' . "\n";
    }

    private function resolve(string $base, string $location): string
    {
        if ($location === '') {
            return $base;
        }
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }
        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }
        $root = 'https://' . parse_url($base, PHP_URL_HOST);
        $path = parse_url($base, PHP_URL_PATH) ?: '/';
        if (str_starts_with($location, '/')) {
            return $root . $location;
        }
        if (str_starts_with($location, '?')) {
            return $root . $path . $location;
        }
        return $root . substr($path, 0, strrpos($path, '/') + 1) . $location;
    }
}
