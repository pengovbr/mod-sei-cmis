<?php

namespace MGI\CMIS;

use CMIS\Session\SessionFactory;
use CMIS\Entities\Document as CmisDocument;
use CMIS\Entities\Folder as CmisFolder;

/**
 * Classe de serviço para operações CMIS
 *
 * Esta classe encapsula as principais operações de interação
 * com repositórios CMIS usando a biblioteca optigov/php-cmis-client
 */
class CMISService
{
    private $session = null;
    private CMISConfig $config;

    public function __construct(CMISConfig $config)
    {
        $this->config = $config;
        $this->initializeSession();
    }

    /**
     * Inicializa a sessão CMIS
     */
    private function initializeSession(): void
    {
        if (!$this->config->isValid()) {
            throw new \InvalidArgumentException('Configuração CMIS inválida');
        }

        try {
            $repositoryId = $this->config->getRepositoryId() ?: '-default-';
            $this->session = SessionFactory::create(
                $this->config->getBrowserUrl(),
                $repositoryId,
                $this->config->getUsername(),
                $this->config->getPassword()
            );
        } catch (\Exception $e) {
            throw new \RuntimeException('Erro ao conectar com repositório CMIS: ' . $e->getMessage());
        }
    }

    /**
     * Lista repositórios disponíveis
     */
    public function getRepositories(): array
    {
        try {
            // Fazer requisição real para obter repositórios via CMIS Browser Binding
            $request = $this->session->request()
                ->addUrlParameter('cmisselector', 'repositoryInfo');

            $response = $this->session->getHttpClient()->get($request);
            $data = json_decode($response->getBody()->getContents(), true);

            // Se conseguimos dados reais, usar eles
            if (isset($data['repositoryInfo'])) {
                return [
                    [
                        'id' => $data['repositoryInfo']['repositoryId'] ?? $this->session->getRepositoryId(),
                        'name' => $data['repositoryInfo']['repositoryName'] ?? 'Alfresco Repository',
                        'description' => $data['repositoryInfo']['repositoryDescription'] ?? 'Repositório Alfresco Community',
                        'rootFolderId' => $data['repositoryInfo']['rootFolderId'] ?? 'root'
                    ]
                ];
            }

            // Fallback: retornar info do repositório atual
            return [
                [
                    'id' => $this->session->getRepositoryId(),
                    'name' => 'Alfresco Repository',
                    'description' => 'Repositório Alfresco Community',
                    'rootFolderId' => 'root'
                ]
            ];
        } catch (\Exception $e) {
            // Em caso de erro, retornar info básica
            return [
                [
                    'id' => $this->session->getRepositoryId(),
                    'name' => 'Alfresco Repository',
                    'description' => 'Repositório Alfresco Community',
                    'rootFolderId' => 'root'
                ]
            ];
        }
    }

    /**
     * Lista conteúdo de uma pasta
     */
    public function listFolderContents(string $folderPath = '/'): array
    {
        try {
            // Usar API REST do Alfresco para listar conteúdo real
            $nodeId = $this->getNodeIdFromPath($folderPath);

            // Construir URL da API REST
            // Exemplo: http://host.docker.internal:8080/alfresco/api/-default-/public/cmis/versions/1.1/browser
            // Resultado desejado: http://host.docker.internal:8080/alfresco/api/-default-/public/alfresco/versions/1/nodes/{nodeId}/children
            $browserUrl = $this->config->getBrowserUrl();

            // Extrair base URL (http://host.docker.internal:8080)
            preg_match('#^(https?://[^/]+)#', $browserUrl, $matches);
            $baseUrl = $matches[1] ?? '';

            $url = $baseUrl . '/alfresco/api/-default-/public/alfresco/versions/1/nodes/' . $nodeId . '/children';

            // Fazer requisição HTTP com cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, $this->config->getUsername() . ':' . $this->config->getPassword());
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode !== 200) {
                curl_close($ch);
                throw new \RuntimeException("Erro HTTP $httpCode ao listar conteúdo");
            }

            curl_close($ch);

            $data = json_decode($response, true);

            if (!isset($data['list']['entries'])) {
                return [];
            }

            $items = [];
            foreach ($data['list']['entries'] as $entry) {
                $item = $entry['entry'];
                $items[] = [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'type' => $item['nodeType'],
                    'created' => $this->formatDate($item['createdAt']),
                    'modified' => $this->formatDate($item['modifiedAt']),
                    'size' => isset($item['content']['sizeInBytes']) ? $item['content']['sizeInBytes'] : null,
                    'isFolder' => $item['isFolder'],
                    'mimeType' => isset($item['content']['mimeType']) ? $item['content']['mimeType'] : null,
                    'createdBy' => $item['createdByUser']['displayName'] ?? 'Unknown',
                    'modifiedBy' => $item['modifiedByUser']['displayName'] ?? 'Unknown'
                ];
            }

            return $items;

        } catch (\Exception $e) {
            throw new \RuntimeException('Erro ao listar conteúdo da pasta: ' . $e->getMessage());
        }
    }

    /**
     * Converte caminho para Node ID do Alfresco
     * Se o caminho não estiver no mapeamento estático, busca dinamicamente
     */
    private function getNodeIdFromPath(string $path): string
    {
        // Mapear caminhos conhecidos para Node IDs
        $pathMap = [
            '/' => '9b3bb45b-0d2a-45ff-bbb4-5b0d2aa5ffb1', // Root
            '/sites' => 'f5902ac4-5c77-48fd-902a-c45c77e8fd88',
            '/sites/swsdp' => 'b4cff62a-664d-4d45-9302-98723eac1319',
            '/sites/swsdp/documentLibrary' => '8f2105b4-daaf-4874-9e8a-2152569d109b',
            '/sites/swsdp/documentLibrary/Agency Files' => '8bb36efb-c26d-4d2b-9199-ab6922f53c28',
            '/sites/swsdp/documentLibrary/Agency Files/Images' => '880a0f47-31b1-4101-b20b-4d325e54e8b1'
        ];

        // Se o caminho está no mapeamento, retornar
        if (isset($pathMap[$path])) {
            return $pathMap[$path];
        }

        // Se for raiz, retornar o mapeamento de raiz
        if ($path === '/') {
            return $pathMap['/'];
        }

        // Buscar o Node ID dinamicamente percorrendo o caminho
        return $this->resolveNodeIdFromPath($path);
    }

    /**
     * Resolve o Node ID de um caminho navegando pelo Alfresco
     */
    private function resolveNodeIdFromPath(string $path): string
    {
        // Limpar e normalizar o caminho
        $path = trim($path, '/');
        if (empty($path)) {
            return '9b3bb45b-0d2a-45ff-bbb4-5b0d2aa5ffb1'; // Root
        }

        $parts = explode('/', $path);
        $currentNodeId = '9b3bb45b-0d2a-45ff-bbb4-5b0d2aa5ffb1'; // Começar pela raiz

        // Percorrer cada parte do caminho
        foreach ($parts as $part) {
            if (empty($part)) continue;

            $currentNodeId = $this->findChildNodeId($currentNodeId, $part);
            if (empty($currentNodeId)) {
                throw new \RuntimeException("Caminho não encontrado: $path");
            }
        }

        return $currentNodeId;
    }

    /**
     * Busca o Node ID de um item dentro de uma pasta
     */
    private function findChildNodeId(string $parentNodeId, string $childName): ?string
    {
        try {
            $browserUrl = $this->config->getBrowserUrl();
            preg_match('#^(https?://[^/]+)#', $browserUrl, $matches);
            $baseUrl = $matches[1] ?? '';

            $url = $baseUrl . '/alfresco/api/-default-/public/alfresco/versions/1/nodes/' . $parentNodeId . '/children';

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, $this->config->getUsername() . ':' . $this->config->getPassword());
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            if ($httpCode !== 200) {
                return null;
            }

            $data = json_decode($response, true);

            if (!isset($data['list']['entries'])) {
                return null;
            }

            // Procurar o item pelo nome (case insensitive)
            foreach ($data['list']['entries'] as $entry) {
                $item = $entry['entry'];
                if (strtolower($item['name']) === strtolower($childName)) {
                    return $item['id'];
                }
            }

            return null;

        } catch (\Exception $e) {
            error_log("Erro ao buscar Node ID: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Formata data ISO para formato brasileiro
     */
    private function formatDate(string $isoDate): string
    {
        try {
            $date = new \DateTime($isoDate);
            return $date->format('d/m/Y H:i:s');
        } catch (\Exception $e) {
            return $isoDate;
        }
    }

    /**
     * Faz upload de um documento
     */
    public function uploadDocument(string $filePath, string $folderPath = '/', array $properties = []): array
    {
        try {
            if (!file_exists($filePath)) {
                throw new \InvalidArgumentException('Arquivo não encontrado: ' . $filePath);
            }

            // Determinar nome do arquivo: preferir $properties['name'], senão nome original do upload
            $fileName = $properties['name'] ?? basename($filePath);
            $fileContent = file_get_contents($filePath);
            $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

            // Obter Node ID da pasta de destino
            $parentNodeId = $this->getNodeIdFromPath($folderPath);

            // Construir URL da API REST para upload
            $browserUrl = $this->config->getBrowserUrl();
            preg_match('#^(https?://[^/]+)#', $browserUrl, $matches);
            $baseUrl = $matches[1] ?? '';

            $url = $baseUrl . '/alfresco/api/-default-/public/alfresco/versions/1/nodes/' . $parentNodeId . '/children';

            // Preparar dados para multipart/form-data
            $boundary = uniqid();
            $delimiter = '-------------' . $boundary;

            $postData = '';

            // Adicionar metadados JSON
            $metadata = [
                'name' => $fileName,
                'nodeType' => 'cm:content',
                'relativePath' => ''
            ];

            $postData .= '--' . $delimiter . "\r\n";
            $postData .= 'Content-Disposition: form-data; name="filedata"; filename="' . $fileName . '"' . "\r\n";
            $postData .= 'Content-Type: ' . $mimeType . "\r\n\r\n";
            $postData .= $fileContent . "\r\n";

            $postData .= '--' . $delimiter . "\r\n";
            $postData .= 'Content-Disposition: form-data; name="name"' . "\r\n\r\n";
            $postData .= $fileName . "\r\n";

            $postData .= '--' . $delimiter . "\r\n";
            $postData .= 'Content-Disposition: form-data; name="nodeType"' . "\r\n\r\n";
            $postData .= 'cm:content' . "\r\n";

            $postData .= '--' . $delimiter . '--';

            // Fazer requisição HTTP com cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_USERPWD, $this->config->getUsername() . ':' . $this->config->getPassword());
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: multipart/form-data; boundary=' . $delimiter,
                'Accept: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode !== 201) {
                curl_close($ch);
                throw new \RuntimeException("Erro HTTP $httpCode ao fazer upload: " . substr($response, 0, 200));
            }

            curl_close($ch);

            $data = json_decode($response, true);

            if (!isset($data['entry'])) {
                throw new \RuntimeException('Resposta inválida do Alfresco');
            }

            $entry = $data['entry'];

            return [
                'id' => $entry['id'],
                'name' => $entry['name'],
                'path' => $folderPath . '/' . $fileName,
                'size' => $entry['content']['sizeInBytes'] ?? strlen($fileContent),
                'mimeType' => $entry['content']['mimeType'] ?? $mimeType,
                'created' => $this->formatDate($entry['createdAt']),
                'createdBy' => $entry['createdByUser']['displayName'] ?? 'Unknown'
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException('Erro ao fazer upload do documento: ' . $e->getMessage());
        }
    }

    /**
     * Faz download de um documento
     */
    public function downloadDocument(string $documentId): array
    {
        try {
            // Construir URL da API REST para download
            $baseUrl = str_replace('/api/-default-/public/cmis/versions/1.1/browser', '', $this->config->getBrowserUrl());
            $url = $baseUrl . '/api/-default-/public/alfresco/versions/1/nodes/' . $documentId . '/content';

            // Fazer requisição HTTP com cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, $this->config->getUsername() . ':' . $this->config->getPassword());
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: */*']);

            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode !== 200) {
                curl_close($ch);
                throw new \RuntimeException("Erro HTTP $httpCode ao fazer download do documento");
            }

            // Obter informações do documento
            $infoUrl = $baseUrl . '/api/-default-/public/alfresco/versions/1/nodes/' . $documentId;
            curl_setopt($ch, CURLOPT_URL, $infoUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

            $info = curl_exec($ch);
            curl_close($ch);

            $infoData = json_decode($info, true);

            return [
                'id' => $documentId,
                'name' => $infoData['entry']['name'] ?? null,
                'content' => $content,
                'mimeType' => $infoData['entry']['content']['mimeType'] ?? 'application/octet-stream',
                'size' => strlen($content),
                'created' => $this->formatDate($infoData['entry']['createdAt'] ?? ''),
                'modified' => $this->formatDate($infoData['entry']['modifiedAt'] ?? '')
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException('Erro ao fazer download do documento: ' . $e->getMessage());
        }
    }

    /**
     * Cria uma nova pasta
     */
    public function createFolder(string $folderName, string $parentPath = '/'): array
    {
        try {
            // Obter Node ID da pasta pai
            $parentNodeId = $this->getNodeIdFromPath($parentPath);

            // Construir URL da API REST para criar pasta
            $baseUrl = str_replace('/api/-default-/public/cmis/versions/1.1/browser', '', $this->config->getBrowserUrl());
            $url = $baseUrl . '/api/-default-/public/alfresco/versions/1/nodes/' . $parentNodeId . '/children';

            // Preparar dados JSON para criação
            $postData = json_encode([
                'name' => $folderName,
                'nodeType' => 'cm:folder'
            ]);

            // Fazer requisição HTTP com cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_USERPWD, $this->config->getUsername() . ':' . $this->config->getPassword());
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode !== 201) {
                curl_close($ch);
                throw new \RuntimeException("Erro HTTP $httpCode ao criar pasta: " . substr($response, 0, 200));
            }

            curl_close($ch);

            $data = json_decode($response, true);

            if (!isset($data['entry'])) {
                throw new \RuntimeException('Resposta inválida do Alfresco');
            }

            $entry = $data['entry'];

            return [
                'id' => $entry['id'],
                'name' => $entry['name'],
                'path' => $parentPath . '/' . $folderName,
                'created' => $this->formatDate($entry['createdAt']),
                'createdBy' => $entry['createdByUser']['displayName'] ?? 'Unknown',
                'parentId' => $entry['parentId'] ?? $parentNodeId
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException('Erro ao criar pasta: ' . $e->getMessage());
        }
    }

    /**
     * Busca documentos por query (busca recursiva por nome)
     * @param string $query Termo de busca
     * @param int $maxItems Número máximo de resultados
     * @param string $startPath Caminho inicial para busca (padrão: '/' para buscar em todo o repositório)
     */
    public function searchDocuments(string $query, int $maxItems = 100, string $startPath = '/'): array
    {
        try {
            $allResults = [];

            // Buscar recursivamente a partir do caminho especificado
            $this->searchRecursive($startPath, $query, $allResults, $maxItems);

            return $allResults;

        } catch (\Exception $e) {
            throw new \RuntimeException('Erro na busca: ' . $e->getMessage());
        }
    }

    /**
     * Busca recursiva em todas as pastas
     */
    private function searchRecursive(string $path, string $query, array &$results, int $maxItems): void
    {
        try {
            $items = $this->listFolderContents($path);

            foreach ($items as $item) {
                // Se já atingiu o limite, parar
                if (count($results) >= $maxItems) {
                    return;
                }

                // Buscar pelo termo no nome (case insensitive)
                if (stripos($item['name'], $query) !== false) {
                    // Normalizar o searchPath
                    $searchPath = $path === '/' ? '/' : rtrim($path, '/');
                    $item['searchPath'] = $searchPath;
                    $results[] = $item;
                }

                // Se for pasta, buscar recursivamente
                if ($item['isFolder']) {
                    $nextPath = $path === '/' ? '/' . $item['name'] : $path . '/' . $item['name'];
                    $this->searchRecursive($nextPath, $query, $results, $maxItems);
                }
            }
        } catch (\Exception $e) {
            // Continuar buscando mesmo se uma pasta der erro
            error_log("Erro ao buscar em $path: " . $e->getMessage());
        }
    }

    /**
     * Obtém informações de um objeto específico
     */
    public function getObjectInfo(string $objectId): array
    {
        try {
            // Usar request direto para obter propriedades do objeto
            $request = $this->session->request()
                ->addUrlParameter('cmisselector', 'properties')
                ->addUrlParameter('objectId', $objectId);

            $response = $this->session->getHttpClient()->get($request);
            $data = json_decode($response->getBody()->getContents(), true);

            $properties = $data['properties'] ?? [];

            return [
                'id' => $objectId,
                'name' => $properties['cmis:name']['value'] ?? null,
                'type' => $properties['cmis:objectTypeId']['value'] ?? null,
                'created' => $properties['cmis:creationDate']['value'] ?? null,
                'modified' => $properties['cmis:lastModificationDate']['value'] ?? null,
                'createdBy' => $properties['cmis:createdBy']['value'] ?? null,
                'modifiedBy' => $properties['cmis:lastModifiedBy']['value'] ?? null,
                'paths' => [$properties['cmis:path']['value'] ?? null],
                'properties' => $properties
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException('Erro ao obter informações do objeto: ' . $e->getMessage());
        }
    }

    /**
     * Obtém a sessão CMIS para operações avançadas
     */
    public function getSession()
    {
        return $this->session;
    }
}
