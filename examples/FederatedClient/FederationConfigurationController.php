<?php

declare(strict_types=1);


namespace FederatedClient;

use Cicnavi\Oidc\FederatedClient;
use Psr\Http\Message\ServerRequestInterface;

class FederationConfigurationController
{
    public function __construct(
        protected readonly FederatedClient $federatedClient,
    )
    {
    }

    public function entityConfiguration(ServerRequestInterface $request): void
    {
        $entityStatement = $this->federatedClient->buildEntityStatement();

        header('Content-Type: application/entity-statement+jwt');
        header('Access-Control-Allow-Origin: *');

        echo $entityStatement->getToken();
        exit();
    }
}
