<?php

namespace MGI\CMIS;

/**
 * API REST Simples para CMIS
 *
 * Esta classe fornece endpoints REST para que sistemas externos
 * possam interagir com o CMIS de forma simples e direta
 */
class CMISRestAPI
{
    private CMISService $cmisService;

    public function __construct(CMISService $cmisService)
    {
        $this->cmisService = $cmisService;
    }

    /**
     * Processa requisições HTTP
     */
    public function handleRequest(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Remover /api/ do início
        if (strpos($path, '/api/') === 0) {
            $path = substr($path, 5);
        }

        // CORS headers
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Content-Type: application/json');

        if ($method === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        try {
            $response = $this->routeRequest($method, $path);
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Roteia requisições para métodos apropriados
     */
    private function routeRequest(string $method, string $path): array
    {
        $segments = explode('/', trim($path, '/'));
        $endpoint = $segments[0] ?? '';

        switch ($endpoint) {
            case 'repositories':
                return $this->handleRepositories($method, $segments);
            case 'contents':
                return $this->handleContents($method, $segments);
            case 'upload':
                return $this->handleUpload($method, $segments);
            case 'download':
                return $this->handleDownload($method, $segments);
            case 'folders':
                return $this->handleFolders($method, $segments);
            case 'search':
                return $this->handleSearch($method, $segments);
            case 'locks':
                return $this->handleLocks($method, $segments);
            case 'health':
                return $this->handleHealth($method, $segments);
            case 'capabilities':
                return $this->handleCapabilities($method, $segments);
            default:
                http_response_code(404);
                return [
                    'success' => false,
                    'error' => 'Endpoint não encontrado',
                    'available_endpoints' => [
                        'GET /api/repositories',
                        'GET /api/contents?path=/caminho',
                        'GET /api/capabilities',
                        'POST /api/upload',
                        'GET /api/download?id=xyz',
                        'POST /api/folders?name=xyz&path=/caminho',
                        'GET /api/search?q=termo',
                        'POST /api/locks?documentId=xyz&systemId=sys1',
                        'DELETE /api/locks?documentId=xyz&systemId=sys1',
                        'GET /api/locks?documentId=xyz',
                        'GET /api/health'
                    ]
                ];
        }
    }

    private function handleRepositories(string $method, array $segments): array
    {
        if (!$this->requireMethod($method, ['GET'])) {
            return $this->methodNotAllowed();
        }

        $repos = $this->cmisService->getRepositories();
        return [
            'success' => true,
            'data' => $repos,
            'count' => count($repos)
        ];
    }

    private function handleContents(string $method, array $segments): array
    {
        if (!$this->requireMethod($method, ['GET'])) {
            return $this->methodNotAllowed();
        }

        try {
            $folderPath = $_GET['path'] ?? '/';
            $items = $this->cmisService->listFolderContents($folderPath);

            return [
                'success' => true,
                'data' => $items,
                'count' => count($items),
                'path' => $folderPath
            ];
        } catch (\Exception $e) {
            error_log("Erro em handleContents: " . $e->getMessage());
            throw $e;
        }
    }

    private function handleUpload(string $method, array $segments): array
    {
        if (!$this->requireMethod($method, ['POST'])) {
            return $this->methodNotAllowed();
        }

        $folderPath = $_POST['folderPath'] ?? $_GET['folderPath'] ?? '/';

        if (!isset($_FILES['file'])) {
            http_response_code(400);
            return [
                'success' => false,
                'error' => 'Nenhum arquivo enviado. Use multipart/form-data com campo "file".'
            ];
        }

        $tempFile = $_FILES['file']['tmp_name'];
        $originalName = $_POST['fileName'] ?? ($_FILES['file']['name'] ?? null);
        $properties = [];
        if (!empty($originalName)) {
            $properties['name'] = $originalName;
        }
        $result = $this->cmisService->uploadDocument($tempFile, $folderPath, $properties);

        return [
            'success' => true,
            'data' => $result,
            'message' => 'Arquivo enviado com sucesso'
        ];
    }

    private function handleDownload(string $method, array $segments): array
    {
        if (!$this->requireMethod($method, ['GET'])) {
            return $this->methodNotAllowed();
        }

        $documentId = $_GET['id'] ?? null;

        if (!$documentId) {
            http_response_code(400);
            return ['success' => false, 'error' => 'ID do documento não fornecido'];
        }

        $result = $this->cmisService->downloadDocument($documentId);

        return [
            'success' => true,
            'data' => [
                'id' => $result['id'],
                'name' => $result['name'],
                'content' => base64_encode($result['content']),
                'mimeType' => $result['mimeType'],
                'size' => $result['size'],
                'created' => $result['created'],
                'modified' => $result['modified']
            ]
        ];
    }

    private function handleFolders(string $method, array $segments): array
    {
        if (!$this->requireMethod($method, ['POST'])) {
            return $this->methodNotAllowed();
        }

        $folderName = $_POST['name'] ?? $_GET['name'] ?? null;
        $parentPath = $_POST['path'] ?? $_GET['path'] ?? '/';

        if (!$folderName) {
            http_response_code(400);
            return ['success' => false, 'error' => 'Nome da pasta não fornecido'];
        }

        $result = $this->cmisService->createFolder($folderName, $parentPath);

        return [
            'success' => true,
            'data' => $result,
            'message' => 'Pasta criada com sucesso'
        ];
    }

    private function handleSearch(string $method, array $segments): array
    {
        if (!$this->requireMethod($method, ['GET'])) {
            return $this->methodNotAllowed();
        }

        $query = $_GET['q'] ?? '';
        $maxItems = isset($_GET['maxItems']) ? (int)$_GET['maxItems'] : 100;
        $startPath = $_GET['startPath'] ?? '/';

        if (empty($query)) {
            http_response_code(400);
            return ['success' => false, 'error' => 'Query de busca não fornecida'];
        }

        $results = $this->cmisService->searchDocuments($query, $maxItems, $startPath);

        return [
            'success' => true,
            'data' => $results,
            'count' => count($results),
            'query' => $query,
            'startPath' => $startPath
        ];
    }

    private function handleLocks(string $method, array $segments): array
    {
        $locker = new CMISDocumentLocker($this->cmisService);

        if ($method === 'POST') {
            $documentId = $_POST['documentId'] ?? $_GET['documentId'] ?? null;
            $systemId = $_POST['systemId'] ?? $_GET['systemId'] ?? null;
            $timeout = isset($_POST['timeout']) ? (int)$_POST['timeout'] : (isset($_GET['timeout']) ? (int)$_GET['timeout'] : 30);

            if (!$documentId || !$systemId) {
                http_response_code(400);
                return ['success' => false, 'error' => 'documentId e systemId são obrigatórios'];
            }

            try {
                $result = $locker->lockDocument($documentId, $systemId, $timeout);
                return ['success' => true, 'data' => $result];
            } catch (\Exception $e) {
                http_response_code(400);
                return ['success' => false, 'error' => $e->getMessage()];
            }
        } elseif ($method === 'DELETE') {
            $documentId = $_GET['documentId'] ?? null;
            $systemId = $_GET['systemId'] ?? null;

            if (!$documentId || !$systemId) {
                http_response_code(400);
                return ['success' => false, 'error' => 'documentId e systemId são obrigatórios'];
            }

            try {
                $result = $locker->unlockDocument($documentId, $systemId);
                return ['success' => true, 'data' => $result];
            } catch (\Exception $e) {
                http_response_code(400);
                return ['success' => false, 'error' => $e->getMessage()];
            }
        } elseif ($method === 'GET') {
            $documentId = $_GET['documentId'] ?? null;

            if ($documentId) {
                $isLocked = $locker->isDocumentLocked($documentId);
                $lock = $locker->getDocumentLock($documentId);

                return [
                    'success' => true,
                    'data' => [
                        'isLocked' => $isLocked,
                        'lock' => $lock
                    ]
                ];
            } else {
                $locks = $locker->getActiveLocks();
                $stats = $locker->getLockStats();

                return [
                    'success' => true,
                    'data' => [
                        'locks' => $locks,
                        'stats' => $stats
                    ]
                ];
            }
        }

        http_response_code(405);
        return ['success' => false, 'error' => 'Método não permitido'];
    }

    private function handleHealth(string $method, array $segments): array
    {
        if (!$this->requireMethod($method, ['GET'])) {
            return $this->methodNotAllowed();
        }

        return [
            'success' => true,
            'status' => 'online',
            'timestamp' => date('Y-m-d H:i:s'),
            'server' => 'Alfresco CMIS'
        ];
    }

    private function handleCapabilities(string $method, array $segments): array
    {
        if (!$this->requireMethod($method, ['GET'])) {
            return $this->methodNotAllowed();
        }

        return [
            'success' => true,
            'data' => [
                'system' => 'CMIS API - MGI',
                'version' => '1.0.0',
                'description' => 'API REST para gestão de documentos CMIS com suporte a edição colaborativa',
                'server' => 'Alfresco Community',
                'capabilities' => [
                    'repositories' => [
                        'name' => 'Gerenciamento de Repositórios',
                        'endpoint' => 'GET /api/repositories',
                        'description' => 'Listar repositórios CMIS disponíveis',
                        'status' => 'implemented'
                    ],
                    'contents' => [
                        'name' => 'Listagem de Conteúdo',
                        'endpoint' => 'GET /api/contents?path=/caminho',
                        'description' => 'Listar arquivos e pastas de um diretório',
                        'status' => 'implemented'
                    ],
                    'upload' => [
                        'name' => 'Upload de Documentos',
                        'endpoint' => 'POST /api/upload',
                        'description' => 'Fazer upload de arquivos para o repositório',
                        'status' => 'implemented'
                    ],
                    'download' => [
                        'name' => 'Download de Documentos',
                        'endpoint' => 'GET /api/download?id=xyz',
                        'description' => 'Fazer download de arquivos do repositório',
                        'status' => 'implemented'
                    ],
                    'folders' => [
                        'name' => 'Gerenciamento de Pastas',
                        'endpoint' => 'POST /api/folders',
                        'description' => 'Criar e gerenciar pastas',
                        'status' => 'implemented'
                    ],
                    'search' => [
                        'name' => 'Busca de Documentos',
                        'endpoint' => 'GET /api/search?q=termo',
                        'description' => 'Buscar documentos por termo',
                        'status' => 'implemented'
                    ],
                    'locks' => [
                        'name' => 'Sistema de Locks',
                        'endpoints' => [
                            'POST /api/locks - Bloquear documento',
                            'GET /api/locks?documentId=xyz - Verificar lock',
                            'DELETE /api/locks - Desbloquear documento',
                            'GET /api/locks - Listar todos locks'
                        ],
                        'description' => 'Sistema de registro para edição colaborativa (múltiplos sistemas podem editar simultaneamente)',
                        'status' => 'implemented'
                    ],
                    'collaborative_editing' => [
                        'name' => 'Edição Colaborativa',
                        'description' => 'Múltiplos sistemas podem editar o mesmo documento simultaneamente, com detecção de quem está editando',
                        'status' => 'implemented'
                    ]
                ],
                'methods' => [
                    'getRepositories' => 'Lista repositórios CMIS',
                    'listFolderContents' => 'Lista arquivos e pastas',
                    'uploadDocument' => 'Upload de arquivos',
                    'downloadDocument' => 'Download de arquivos',
                    'createFolder' => 'Criar pastas',
                    'searchDocuments' => 'Buscar documentos',
                    'lockDocument' => 'Registrar edição',
                    'unlockDocument' => 'Finalizar edição',
                    'isDocumentLocked' => 'Verificar se está sendo editado',
                    'getDocumentLock' => 'Ver quem está editando'
                ],
                'features' => [
                    'Real-time file listing' => true,
                    'Upload/Download files' => true,
                    'Folder management' => true,
                    'Document search' => true,
                    'Concurrent editing detection' => true,
                    'CORS enabled' => true,
                    'RESTful API' => true
                ]
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Verifica se o método HTTP é permitido
     */
    private function requireMethod(string $method, array $allowedMethods): bool
    {
        if (!in_array($method, $allowedMethods)) {
            http_response_code(405);
            return false;
        }
        return true;
    }

    /**
     * Retorna erro 405 - Método não permitido
     */
    private function methodNotAllowed(): array
    {
        http_response_code(405);
        return ['success' => false, 'error' => 'Método não permitido'];
    }
}

