<?php

class BusTracker
{
    /**
     * @var string Live location endpoint
     * @see https://data.bus-data.dft.gov.uk/guidance/requirements/?section=api
     */
    const BODS_API_URL = 'https://data.bus-data.dft.gov.uk/api/v1/datafeed/';

    /**
     * @var array $env variables loaded from .env file
     */
    private array $env;

    public function __construct()
    {
        $this->env = parse_ini_file(__DIR__ . '/.env');
    }

    /**
     * Gets live buses in the bounding box and buzzes if our bus is there
     */
    function getLiveLocation()
    {
        $params = [
            'boundingBox' => $this->env['BOUNDING_BOX'],
            'api_key' => $this->env['BODS_API_KEY'],
        ];

        $endpoint = self::BODS_API_URL . '?' . http_build_query($params);
        $response = $this->makeRequest('GET', $endpoint);

        $xml = simplexml_load_string($response);
        if ($xml === false) {
            throw new Exception("Invalid XML response from BODS API");
        }

        $buses = $xml->ServiceDelivery->VehicleMonitoringDelivery->VehicleActivity ?? [];

        $lines = array_map('strtoupper', explode(',', $this->env['LINE_REFS']));

        foreach ($buses as $bus) {
            if (!isset($bus->MonitoredVehicleJourney)) {
                continue;
            }
            $journey = $bus->MonitoredVehicleJourney;

            if (!isset($journey->LineRef, $journey->DirectionRef, $journey->DestinationName, $journey->VehicleRef)) {
                continue;
            }
            $line = strtoupper($journey->LineRef);

            echo $line . ' | '
            . $journey->DirectionRef . ' | '
            . $journey->DestinationName . ' | '
            . $journey->VehicleRef . "\n";

            if (in_array($line, $lines) && $journey->DirectionRef === 'inbound') {
                $this->sendNotification('The Bus ' . $line . ' is ~5 min away, go!!!');
                echo "Buzzed!\n";
                return;
            }
        }

        echo "No matching bus\n";
    }

    /**
     * Makes a request to an API endpoint and returns the raw response
     * 
     * @param string $method The HTTP method to use
     * @param string $endpoint The API endpoint to call
     * @param string|null $body The request body
     * @return string The raw response from the API
     * @throws Exception If there is a cURL error
     */
function makeRequest($method, $endpoint, $body = null, $headers = []): string
    {
        $ch = curl_init($endpoint);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ($body !== null && $method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);

        // Check for cURL errors
        if (curl_errno($ch)) {
            throw new Exception("cURL error: " . curl_error($ch));
        }

        return $response;
    }

    /**
     * Sends a push notification to ntfy 
     *
     * @param string $message The notification text
     */
    function sendNotification($message)
    {
        $this->makeRequest('POST', 'https://ntfy.sh/' . $this->env['NTFY_TOPIC'], $message, [
            'Priority: urgent',
            'Title: Bus is coming!',
            'Tags: bus',
        ]);
    }
}

$tracker = new BusTracker();
$tracker->getLiveLocation();