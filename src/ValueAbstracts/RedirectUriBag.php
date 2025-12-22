<?php

declare(strict_types=1);

namespace Cicnavi\Oidc\ValueAbstracts;

class RedirectUriBag extends UniqueStringBag
{
    public function __construct(
        protected readonly string $defaultRedirectUri,
        string ...$additionalRedirectUris,
    ) {
        parent::__construct($this->defaultRedirectUri, ...$additionalRedirectUris);
    }

    public function getDefaultRedirectUri(): string
    {
        return $this->defaultRedirectUri;
    }
}
