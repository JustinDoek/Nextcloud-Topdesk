<?php
declare(strict_types=1);

namespace OCA\TopdeskIntegration\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class TopdeskService {
    private IClientService $clientService;
    private IConfig $config;
    private IUserManager $userManager; 
    private IUserSession $userSession;
    private LoggerInterface $logger;

    public function __construct(IClientService $clientService, 
                              IConfig $config, 
			      IUserManager $userManager,
                              IUserSession $userSession,
                              LoggerInterface $logger) {
        $this->clientService = $clientService;
        $this->config = $config;
        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->logger = $logger;
    }

    public function getUserIncidents(?string $userId = null): array {
        $baseUrl = rtrim($this->config->getAppValue('topdesk_integration', 'base_url', ''), '/');
        $appUser = $this->config->getAppValue('topdesk_integration', 'app_user', '');
        $appToken = $this->config->getAppValue('topdesk_integration', 'app_token', '');
        
        if (empty($baseUrl) || empty($appUser) || empty($appToken)) {
            $this->logger->error('Topdesk config incomplete');
            return [];
        }

        // ✅ CORRECTE ternary operator syntax
        $user = $userId ? $this->userManager->get($userId) : $this->userSession->getUser();
        if ($user === null) {
            $this->logger->error('No logged-in user found for: ' . ($userId ?? 'current user'));
            return [];
        }
        
        $email = $user->getEMailAddress() ?: $user->getUID(); // Gebruik UID voor email query
        $client = $this->clientService->newClient();
        $auth = base64_encode($appUser . ':' . $appToken);
        
        try {
            // Stap 1: Haal Topdesk ID op met e-mail via persons endpoint
            $personsUrl = "$baseUrl/tas/api/persons?query=email=='$email';archived==false";

            $responsePersons = $client->get($personsUrl, [
                'headers' => [
                    'Authorization' => "Basic $auth",
                    'Accept' => 'application/json'
                ]
            ]);

            $bodyPersons = (string) $responsePersons->getBody();
            
            $dataPersons = json_decode($bodyPersons, true);

            if (empty($dataPersons['data'][0]['id']) && empty($dataPersons[0]['id'])) {
                $this->logger->error('Geen Topdesk user gevonden voor: ' . $userIdForQuery);
                return [];
            }
            
            $personId = $dataPersons['data'][0]['id'] ?? $dataPersons[0]['id'] ?? null;

            if (empty($personId)) {
                return [];
            }

            // Stap 2: Haal incidents op
            $incidentsUrl = "$baseUrl/tas/api/incidents?query=caller.id=='$personId'&pageSize=5&fields=id,number,briefDescription,creationDate,closed";
            $response = $client->get($incidentsUrl, [
                'headers' => [
                    'Authorization' => "Basic $auth",
                    'Accept' => 'application/json'
                ]
            ]);

            $bodyIncidents = (string) $response->getBody();
            $dataIncidents = json_decode($bodyIncidents, true);
           
            $incidents = $dataIncidents['incidents'] ?? $dataIncidents['data'] ?? $dataIncidents ?? [];
            
            return is_array($incidents) ? $incidents : [];
            
        } catch (\Exception $e) {
            $this->logger->error('Topdesk API error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return [];
        }
    }

    public function getNewIncidentUrl(): string {
        return $this->config->getAppValue('topdesk_integration', 'new_incident_url', '');
    }
}
