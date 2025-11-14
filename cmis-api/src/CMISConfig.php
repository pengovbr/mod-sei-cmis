<?php

namespace MGI\CMIS;

class CMISConfig
{
    private string $browserUrl;
    private string $username;
    private string $password;
    private string $repositoryId;
    private array $options;

    public function __construct(
        string $browserUrl,
        string $username,
        string $password,
        string $repositoryId = '',
        array $options = []
    ) {
        $this->browserUrl = $browserUrl;
        $this->username = $username;
        $this->password = $password;
        $this->repositoryId = $repositoryId;
        $this->options = array_merge([
            'timeout' => 30,
            'verify' => true,
            'debug' => false
        ], $options);
    }

    public function getBrowserUrl(): string
    {
        return $this->browserUrl;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getRepositoryId(): string
    {
        return $this->repositoryId;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public static function fromEnvironment(): self
    {
        return new self(
            $_ENV['CMIS_BROWSER_URL'] ?? '',
            $_ENV['CMIS_USERNAME'] ?? '',
            $_ENV['CMIS_PASSWORD'] ?? '',
            $_ENV['CMIS_REPOSITORY_ID'] ?? '',
            [
                'timeout' => (int)($_ENV['CMIS_TIMEOUT'] ?? 30),
                'verify' => filter_var($_ENV['CMIS_VERIFY_SSL'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
                'debug' => filter_var($_ENV['CMIS_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN)
            ]
        );
    }

    public function isValid(): bool
    {
        return !empty($this->browserUrl) && 
               !empty($this->username) && 
               !empty($this->password);
    }
}
