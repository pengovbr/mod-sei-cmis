<?php

namespace MGI\CMIS;

/**
 * Sistema de Lock/Unlock de Documentos CMIS
 * 
 * Esta classe gerencia o bloqueio de documentos para evitar
 * edição simultânea por múltiplos sistemas
 */
class CMISDocumentLocker
{
    private CMISService $cmisService;
    private array $activeLocks;
    private string $lockFile;

    public function __construct(CMISService $cmisService, string $lockFile = 'logs/document_locks.json')
    {
        $this->cmisService = $cmisService;
        $this->lockFile = $lockFile;
        $this->loadActiveLocks();
    }

    /**
     * Registra que um sistema está editando um documento
     * NOTA: Múltiplos sistemas podem editar simultaneamente
     */
    public function lockDocument(string $documentId, string $systemId, int $timeoutMinutes = 30): array
    {
        try {
            // Criar/atualizar registro de edição
            if (!isset($this->activeLocks[$documentId])) {
                $this->activeLocks[$documentId] = [];
            }
            
            $lock = [
                'documentId' => $documentId,
                'systemId' => $systemId,
                'lockedAt' => date('Y-m-d H:i:s'),
                'expiresAt' => date('Y-m-d H:i:s', time() + ($timeoutMinutes * 60)),
                'timeoutMinutes' => $timeoutMinutes
            ];
            
            // Armazenar múltiplos locks por documento
            $this->activeLocks[$documentId][$systemId] = $lock;
            $this->saveActiveLocks();

            // Adicionar propriedade customizada ao documento CMIS
            $this->addLockPropertyToDocument($documentId, $lock);

            return ['status' => 'locked', 'lock' => $lock];

        } catch (\Exception $e) {
            throw new \RuntimeException("Erro ao bloquear documento: " . $e->getMessage());
        }
    }

    /**
     * Desbloqueia um documento para um sistema específico
     */
    public function unlockDocument(string $documentId, string $systemId): array
    {
        try {
            if (!isset($this->activeLocks[$documentId][$systemId])) {
                return ['status' => 'not_locked'];
            }

            // Remover lock deste sistema específico
            unset($this->activeLocks[$documentId][$systemId]);
            
            // Se não há mais locks, remover completamente
            if (empty($this->activeLocks[$documentId])) {
                unset($this->activeLocks[$documentId]);
            }
            
            $this->saveActiveLocks();

            // Remover propriedade customizada do documento CMIS
            $this->removeLockPropertyFromDocument($documentId);

            return ['status' => 'unlocked'];

        } catch (\Exception $e) {
            throw new \RuntimeException("Erro ao desbloquear documento: " . $e->getMessage());
        }
    }

    /**
     * Verifica se um documento está sendo editado por algum sistema
     */
    public function isDocumentLocked(string $documentId): bool
    {
        $this->cleanExpiredLocks();
        return isset($this->activeLocks[$documentId]) && !empty($this->activeLocks[$documentId]);
    }

    /**
     * Obtém informações de quem está editando o documento
     */
    public function getDocumentLock(string $documentId): ?array
    {
        $this->cleanExpiredLocks();
        
        if (!isset($this->activeLocks[$documentId])) {
            return null;
        }
        
        // Retornar todos os sistemas editando
        $locks = [];
        foreach ($this->activeLocks[$documentId] as $systemId => $lock) {
            $locks[] = $lock;
        }
        
        return $locks;
    }

    /**
     * Lista todos os locks ativos
     */
    public function getActiveLocks(): array
    {
        $this->cleanExpiredLocks();
        return $this->activeLocks;
    }

    /**
     * Renova um lock existente
     */
    public function renewLock(string $documentId, int $timeoutMinutes = 30): array
    {
        if (!$this->isDocumentLocked($documentId)) {
            throw new \Exception("Documento não está bloqueado");
        }

        $lock = $this->activeLocks[$documentId];
        $lock['expiresAt'] = date('Y-m-d H:i:s', time() + ($timeoutMinutes * 60));
        $lock['timeoutMinutes'] = $timeoutMinutes;

        $this->activeLocks[$documentId] = $lock;
        $this->saveActiveLocks();

        // Atualizar propriedade no documento CMIS
        $this->addLockPropertyToDocument($documentId, $lock);

        return ['status' => 'renewed', 'lock' => $lock];
    }

    /**
     * Força o desbloqueio de um documento (admin)
     */
    public function forceUnlock(string $documentId, string $adminSystemId): array
    {
        if (!$this->isDocumentLocked($documentId)) {
            return ['status' => 'not_locked'];
        }

        $lock = $this->getDocumentLock($documentId);
        
        // Remover lock
        unset($this->activeLocks[$documentId]);
        $this->saveActiveLocks();

        // Remover propriedade customizada do documento CMIS
        $this->removeLockPropertyFromDocument($documentId);

        return [
            'status' => 'force_unlocked',
            'previousLock' => $lock,
            'unlockedBy' => $adminSystemId
        ];
    }

    /**
     * Limpa locks expirados
     */
    private function cleanExpiredLocks(): void
    {
        $now = time();
        $needsSave = false;

        foreach ($this->activeLocks as $documentId => $systems) {
            $activeSystems = [];
            
            foreach ($systems as $systemId => $lock) {
                if (strtotime($lock['expiresAt']) >= $now) {
                    // Lock ainda válido
                    $activeSystems[$systemId] = $lock;
                } else {
                    // Lock expirado
                    $needsSave = true;
                }
            }
            
            if (empty($activeSystems)) {
                // Nenhum lock ativo neste documento
                unset($this->activeLocks[$documentId]);
                $this->removeLockPropertyFromDocument($documentId);
            } else {
                // Atualizar com apenas locks ativos
                $this->activeLocks[$documentId] = $activeSystems;
            }
        }

        if ($needsSave) {
            $this->saveActiveLocks();
        }
    }

    /**
     * Adiciona propriedade de lock ao documento CMIS
     * Nota: Usa apenas arquivo local por enquanto
     */
    private function addLockPropertyToDocument(string $documentId, array $lock): void
    {
        // Por enquanto, apenas log
        error_log("Lock adicionado para documento: {$documentId} pelo sistema: {$lock['systemId']}");
    }

    /**
     * Remove propriedade de lock do documento CMIS
     * Nota: Usa apenas arquivo local por enquanto
     */
    private function removeLockPropertyFromDocument(string $documentId): void
    {
        // Por enquanto, apenas log
        error_log("Lock removido para documento: {$documentId}");
    }

    /**
     * Carrega locks ativos do arquivo
     */
    private function loadActiveLocks(): void
    {
        if (file_exists($this->lockFile)) {
            $content = file_get_contents($this->lockFile);
            $this->activeLocks = json_decode($content, true) ?? [];
        } else {
            $this->activeLocks = [];
        }
    }

    /**
     * Salva locks ativos no arquivo
     */
    private function saveActiveLocks(): void
    {
        $dir = dirname($this->lockFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($this->lockFile, json_encode($this->activeLocks, JSON_PRETTY_PRINT));
    }

    /**
     * Obtém estatísticas dos locks
     */
    public function getLockStats(): array
    {
        $this->cleanExpiredLocks();
        
        $stats = [
            'totalLocks' => count($this->activeLocks),
            'systems' => [],
            'expiringSoon' => []
        ];

        $now = time();
        $oneHour = 3600;

        foreach ($this->activeLocks as $lock) {
            $systemId = $lock['systemId'];
            if (!isset($stats['systems'][$systemId])) {
                $stats['systems'][$systemId] = 0;
            }
            $stats['systems'][$systemId]++;

            // Verificar se expira em breve
            if (strtotime($lock['expiresAt']) - $now < $oneHour) {
                $stats['expiringSoon'][] = $lock;
            }
        }

        return $stats;
    }
}
