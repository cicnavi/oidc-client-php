<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc;

use Cicnavi\Oidc\CodeBooks\AuthorizationRequestMethodEnum;
use Cicnavi\Oidc\DataStore\Interfaces\SessionStoreInterface;
use Cicnavi\Oidc\Exceptions\OidcClientException;
use Cicnavi\Oidc\FederatedClient;
use Cicnavi\Oidc\Helpers\HttpHelper;
use Cicnavi\Oidc\Protocol\RequestDataHandler;
use Cicnavi\Oidc\Federation\EntityConfig;
use Cicnavi\Oidc\Federation\RelyingPartyConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use ReflectionClass;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmBag;
use SimpleSAML\OpenID\Algorithms\SignatureAlgorithmEnum;
use SimpleSAML\OpenID\Codebooks\HashAlgorithmsEnum;
use SimpleSAML\OpenID\Codebooks\PkceCodeChallengeMethodEnum;
use SimpleSAML\OpenID\Codebooks\TrustMarkStatusEndpointUsagePolicyEnum;
use SimpleSAML\OpenID\Core;
use SimpleSAML\OpenID\Core\ClientAssertion;
use SimpleSAML\OpenID\Core\Factories\ClientAssertionFactory;
use SimpleSAML\OpenID\Exceptions\TrustChainException;
use SimpleSAML\OpenID\Federation;
use SimpleSAML\OpenID\Federation\EntityStatement;
use SimpleSAML\OpenID\Federation\Factories\EntityStatementFactory;
use SimpleSAML\OpenID\Federation\Factories\RequestObjectFactory;
use SimpleSAML\OpenID\Federation\TrustChain;
use SimpleSAML\OpenID\Federation\TrustChainBag;
use SimpleSAML\OpenID\Federation\TrustChainResolver;
use SimpleSAML\OpenID\Federation\TrustMarkFetcher;
use SimpleSAML\OpenID\Jwk;
use SimpleSAML\OpenID\Jwk\JwkDecorator;
use SimpleSAML\OpenID\Jwks;
use SimpleSAML\OpenID\Jwks\Factories\JwksDecoratorFactory;
use SimpleSAML\OpenID\Jwks\JwksDecorator;
use SimpleSAML\OpenID\SupportedAlgorithms;
use SimpleSAML\OpenID\SupportedSerializers;
use SimpleSAML\OpenID\ValueAbstracts\ClaimBag;
use SimpleSAML\OpenID\ValueAbstracts\Factories\SignatureKeyPairBagFactory;
use SimpleSAML\OpenID\ValueAbstracts\Factories\SignatureKeyPairFactory;
use SimpleSAML\OpenID\ValueAbstracts\KeyedStringBag;
use SimpleSAML\OpenID\ValueAbstracts\RedirectUriBag;
use SimpleSAML\OpenID\ValueAbstracts\ScopeBag;
use SimpleSAML\OpenID\ValueAbstracts\SignatureKeyPair;
use SimpleSAML\OpenID\ValueAbstracts\SignatureKeyPairBag;
use SimpleSAML\OpenID\ValueAbstracts\SignatureKeyPairConfigBag;
use SimpleSAML\OpenID\ValueAbstracts\TrustAnchorConfigBag;
use SimpleSAML\OpenID\ValueAbstracts\UniqueStringBag;

#[CoversClass(FederatedClient::class)]
#[CoversClass(HttpHelper::class)]
final class FederatedClientTest extends TestCase
{
    private MockObject $entityConfigMock;

    private MockObject $realyingPartyConfigMock;

    private \DateInterval $entityStatementDuration;

    private MockObject $cacheMock;

    private \DateInterval $maxCacheDuration;

    private \DateInterval $timestampValidationLeeway;

    private SupportedAlgorithms $supportedAlgorithms;

    private SupportedSerializers $supportedSerializers;

    private MockObject $loggerMock;

    private int $maxTrustChainDepth;

    private TrustMarkStatusEndpointUsagePolicyEnum $defaultTrustMarkStatusEndpointUsagePolicyEnum;

    private MockObject $federationMock;

    private MockObject $jwkMock;

    private HashAlgorithmsEnum $jwkThumbprintHashAlgo;

    private MockObject $signatureKeyPairFactoryMock;

    private MockObject $signatureKeyPairBagFactoryMock;

    private MockObject $jwksDecoratorFactoryMock;

    private bool $includeSoftwareId;

    private \DateInterval $privateKeyJwtDuration;

    private bool $useNonce;

    private bool $usePkce;

    private bool $fetchUserinfoClaims;

    private PkceCodeChallengeMethodEnum $pkceCodeChallengeMethod;

    private MockObject $sessionStoreMock;

    private MockObject $httpClientMock;

    private MockObject $coreMock;

    private MockObject $jwksMock;

    private MockObject $requestDataHandlerMock;

    private AuthorizationRequestMethodEnum $defaultAuthorizationRequestMethod;

    protected function setUp(): void
    {
        $this->entityConfigMock = $this->createMock(EntityConfig::class);
        $this->realyingPartyConfigMock = $this->createMock(RelyingPartyConfig::class);
        $this->entityStatementDuration = new \DateInterval('P1D');
        $this->cacheMock = $this->createMock(\Psr\SimpleCache\CacheInterface::class);
        $this->maxCacheDuration = new \DateInterval('PT6H');
        $this->timestampValidationLeeway = new \DateInterval('PT1M');
        $this->supportedAlgorithms = new SupportedAlgorithms(
            new SignatureAlgorithmBag(SignatureAlgorithmEnum::ES256),
        );
        $this->supportedSerializers = new SupportedSerializers();
        $this->loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->maxTrustChainDepth = 9;
        $this->defaultTrustMarkStatusEndpointUsagePolicyEnum = TrustMarkStatusEndpointUsagePolicyEnum::NotUsed;
        $this->federationMock = $this->createMock(Federation::class);
        $this->jwkMock = $this->createMock(Jwk::class);
        $this->jwkThumbprintHashAlgo = HashAlgorithmsEnum::SHA_256;
        $this->signatureKeyPairFactoryMock = $this->createMock(SignatureKeyPairFactory::class);
        $this->signatureKeyPairBagFactoryMock = $this->createMock(SignatureKeyPairBagFactory::class);
        $this->jwksDecoratorFactoryMock = $this->createMock(JwksDecoratorFactory::class);
        $this->includeSoftwareId = true;
        $this->privateKeyJwtDuration = new \DateInterval('PT5M');
        $this->useNonce = true;
        $this->usePkce = true;
        $this->fetchUserinfoClaims = true;
        $this->pkceCodeChallengeMethod = PkceCodeChallengeMethodEnum::S256;
        $this->sessionStoreMock = $this->createMock(SessionStoreInterface::class);
        $this->httpClientMock = $this->createMock(\GuzzleHttp\Client::class);
        $this->coreMock = $this->createMock(Core::class);
        $this->jwksMock = $this->createMock(Jwks::class);
        $this->requestDataHandlerMock = $this->createMock(RequestDataHandler::class);
        $this->defaultAuthorizationRequestMethod = AuthorizationRequestMethodEnum::FormPost;
    }

    protected function sut(
        ?EntityConfig $entityConfig = null,
        ?RelyingPartyConfig $relyingPartyConfig = null,
        ?\DateInterval $entityStatementDuration = null,
        ?\Psr\SimpleCache\CacheInterface $cache = null,
        ?\DateInterval $maxCacheDuration = null,
        ?\DateInterval $timestampValidationLeeway = null,
        ?SupportedAlgorithms $supportedAlgorithms = null,
        ?SupportedSerializers $supportedSerializers = null,
        ?\Psr\Log\LoggerInterface $logger = null,
        ?int $maxTrustChainDepth = null,
        ?TrustMarkStatusEndpointUsagePolicyEnum $defaultTrustMarkStatusEndpointUsagePolicyEnum = null,
        ?Federation $federation = null,
        ?Jwk $jwk = null,
        ?HashAlgorithmsEnum $jwkThumbprintHashAlgo = null,
        ?SignatureKeyPairFactory $signatureKeyPairFactory = null,
        ?SignatureKeyPairBagFactory $signatureKeyPairBagFactory = null,
        ?JwksDecoratorFactory $jwksDecoratorFactory = null,
        ?bool $includeSoftwareId = null,
        ?\DateInterval $privateKeyJwtDuration = null,
        ?bool $useNonce = null,
        ?bool $usePkce = null,
        ?bool $fetchUserinfoClaims = null,
        ?PkceCodeChallengeMethodEnum $pkceCodeChallengeMethod = null,
        ?SessionStoreInterface $sessionStore = null,
        ?\GuzzleHttp\Client $httpClient = null,
        ?Core $core = null,
        ?Jwks $jwks = null,
        ?RequestDataHandler $requestDataHandler = null,
        ?AuthorizationRequestMethodEnum $defaultAuthorizationRequestMethod = null,
    ): FederatedClient {
        $entityConfig ??= $this->entityConfigMock;
        $relyingPartyConfig ??= $this->realyingPartyConfigMock;
        $entityStatementDuration ??= $this->entityStatementDuration;
        $cache ??= $this->cacheMock;
        $maxCacheDuration ??= $this->maxCacheDuration;
        $timestampValidationLeeway ??= $this->timestampValidationLeeway;
        $supportedAlgorithms ??= $this->supportedAlgorithms;
        $supportedSerializers ??= $this->supportedSerializers;
        $logger ??= $this->loggerMock;
        $maxTrustChainDepth ??= $this->maxTrustChainDepth;
        $defaultTrustMarkStatusEndpointUsagePolicyEnum ??= $this->defaultTrustMarkStatusEndpointUsagePolicyEnum;
        $federation ??= $this->federationMock;
        $jwk ??= $this->jwkMock;
        $jwkThumbprintHashAlgo ??= $this->jwkThumbprintHashAlgo;
        $signatureKeyPairFactory ??= $this->signatureKeyPairFactoryMock;
        $signatureKeyPairBagFactory ??= $this->signatureKeyPairBagFactoryMock;
        $jwksDecoratorFactory ??= $this->jwksDecoratorFactoryMock;
        $includeSoftwareId ??= $this->includeSoftwareId;
        $privateKeyJwtDuration ??= $this->privateKeyJwtDuration;
        $useNonce ??= $this->useNonce;
        $usePkce ??= $this->usePkce;
        $fetchUserinfoClaims ??= $this->fetchUserinfoClaims;
        $pkceCodeChallengeMethod ??= $this->pkceCodeChallengeMethod;
        $sessionStore ??= $this->sessionStoreMock;
        $httpClient ??= $this->httpClientMock;
        $core ??= $this->coreMock;
        $jwks ??= $this->jwksMock;
        $requestDataHandler ??= $this->requestDataHandlerMock;
        $defaultAuthorizationRequestMethod ??= $this->defaultAuthorizationRequestMethod;

        return new FederatedClient(
            $entityConfig,
            $relyingPartyConfig,
            $entityStatementDuration,
            $cache,
            $maxCacheDuration,
            $timestampValidationLeeway,
            $supportedAlgorithms,
            $supportedSerializers,
            $logger,
            $maxTrustChainDepth,
            $defaultTrustMarkStatusEndpointUsagePolicyEnum,
            $federation,
            $jwk,
            $jwkThumbprintHashAlgo,
            $signatureKeyPairFactory,
            $signatureKeyPairBagFactory,
            $jwksDecoratorFactory,
            $includeSoftwareId,
            $privateKeyJwtDuration,
            $useNonce,
            $usePkce,
            $fetchUserinfoClaims,
            $pkceCodeChallengeMethod,
            $sessionStore,
            $httpClient,
            $core,
            $jwks,
            $requestDataHandler,
            $defaultAuthorizationRequestMethod,
        );
    }

    public function testCanCreateInstance(): void
    {
        $this->assertInstanceOf(FederatedClient::class, $this->sut());
    }

    public function testGetters(): void
    {
        $sut = $this->sut();
        $this->assertSame($this->entityConfigMock, $sut->getEntityConfig());
        $this->assertSame($this->realyingPartyConfigMock, $sut->getRelyingPartyConfig());
        $this->assertSame($this->entityStatementDuration, $sut->getEntityStatementDuration());
        $this->assertSame($this->maxCacheDuration, $sut->getMaxCacheDuration());
        $this->assertSame($this->timestampValidationLeeway, $sut->getTimestampValidationLeeway());
        $this->assertSame($this->supportedAlgorithms, $sut->getSupportedAlgorithms());
        $this->assertSame($this->supportedSerializers, $sut->getSupportedSerializers());
        $this->assertSame($this->maxTrustChainDepth, $sut->getMaxTrustChainDepth());
        $this->assertSame(
            $this->defaultTrustMarkStatusEndpointUsagePolicyEnum,
            $sut->getDefaultTrustMarkStatusEndpointUsagePolicy(),
        );
        $this->assertSame($this->includeSoftwareId, $sut->includeSoftwareId());
        $this->assertSame($this->cacheMock, $sut->getCache());
        $this->assertSame($this->privateKeyJwtDuration, $sut->getPrivateKeyJwtDuration());
        $this->assertSame($this->useNonce, $sut->useNonce());
        $this->assertSame($this->usePkce, $sut->usePkce());
        $this->assertSame($this->fetchUserinfoClaims, $sut->fetchUserinfoClaims());
        $this->assertSame($this->pkceCodeChallengeMethod, $sut->getPkceCodeChallengeMethod());
        $this->assertSame($this->defaultAuthorizationRequestMethod, $sut->getDefaultAuthorizationRequestMethod());
        $this->assertSame($this->federationMock, $sut->getFederation());
    }

    public function testClearCache(): void
    {
        $this->cacheMock->expects($this->once())->method('clear')->willReturn(true);
        $this->loggerMock->expects($this->once())->method('notice')->with('Cache cleared.');
        $this->sut()->clearCache();
    }

    public function testClearCacheFailure(): void
    {
        $this->cacheMock->expects($this->once())->method('clear')->willReturn(false);
        $this->loggerMock->expects($this->once())->method('notice')->with('Cache NOT cleared.');
        $this->sut()->clearCache();
    }

    public function testClearCacheException(): void
    {
        $exception = new \Cicnavi\SimpleFileCache\Exceptions\CacheException('Cache error');
        $this->cacheMock->expects($this->once())->method('clear')->willThrowException($exception);
        $this->loggerMock->expects($this->once())->method('error')->with('Error clearing cache: Cache error');
        $this->sut()->clearCache();
    }

    public function testBuildEntityStatement(): void
    {
        $entityId = 'https://rp.example.org';
        $this->entityConfigMock->method('getEntityId')->willReturn($entityId);
        $this->entityConfigMock->method('getAdditionalClaimBag')->willReturn(new ClaimBag());
        $this->entityConfigMock->method('getAuthorityHintBag')->willReturn(new UniqueStringBag());
        $this->entityConfigMock->method('getFederationSignatureKeyPairConfigBag')
            ->willReturn(new SignatureKeyPairConfigBag());
        $this->entityConfigMock->method('getStaticTrustMarkBag')->willReturn(new UniqueStringBag());
        $this->entityConfigMock->method('getDynamicTrustMarkBag')->willReturn(new KeyedStringBag());

        $this->realyingPartyConfigMock->method('getAdditionalClaimBag')->willReturn(new ClaimBag());
        $this->realyingPartyConfigMock->method('getRedirectUriBag')
            ->willReturn(new RedirectUriBag('https://rp.example.org/callback'));
        $this->realyingPartyConfigMock->method('getConnectSignatureKeyPairBag')
            ->willReturn(new SignatureKeyPairConfigBag());
        $this->realyingPartyConfigMock->method('getScopeBag')->willReturn(new ScopeBag('openid'));

        $signatureKeyPairMock = $this->createMock(SignatureKeyPairBag::class);
        $this->signatureKeyPairBagFactoryMock->method('fromConfig')->willReturn($signatureKeyPairMock);

        $keyPairMock = $this->createMock(SignatureKeyPair::class);
        $signatureKeyPairMock->method('getFirstOrFail')->willReturn($keyPairMock);
        $signatureKeyPairMock->method('getAllPublicKeys')->willReturn([]);

        $innerKeyPairMock = $this->createMock(\SimpleSAML\OpenID\ValueAbstracts\KeyPair::class);
        $keyPairMock->method('getKeyPair')->willReturn($innerKeyPairMock);
        $innerKeyPairMock->method('getKeyId')->willReturn('kid1');
        $innerKeyPairMock->method('getPrivateKey')->willReturn($this->createMock(JwkDecorator::class));
        $keyPairMock->method('getSignatureAlgorithm')->willReturn(SignatureAlgorithmEnum::ES256);

        $this->jwksDecoratorFactoryMock->method('fromJwkDecorators')
            ->willReturn($this->createMock(JwksDecorator::class));

        $helpersMock = $this->createMock(\SimpleSAML\OpenID\Helpers::class);
        $this->federationMock->method('helpers')->willReturn($helpersMock);
        $dateTimeHelperMock = $this->createMock(\SimpleSAML\OpenID\Helpers\DateTime::class);
        $helpersMock->method('dateTime')->willReturn($dateTimeHelperMock);
        $dateTimeHelperMock->method('getUtc')->willReturn(new \DateTimeImmutable());
        $randomHelperMock = $this->createMock(\SimpleSAML\OpenID\Helpers\Random::class);
        $helpersMock->method('random')->willReturn($randomHelperMock);
        $randomHelperMock->method('string')->willReturn('random_jti');

        $entityStatementFactoryMock = $this->createMock(EntityStatementFactory::class);
        $this->federationMock->method('entityStatementFactory')->willReturn($entityStatementFactoryMock);
        $entityStatementFactoryMock->method('fromData')->willReturn($this->createMock(EntityStatement::class));

        $this->assertInstanceOf(EntityStatement::class, $this->sut()->buildEntityStatement());
    }

    public function testAutoRegisterAndAuthenticateNoTrustAnchors(): void
    {
        $trustAnchorBagMock = $this->createMock(TrustAnchorConfigBag::class);
        $trustAnchorBagMock->method('getAllEntityIds')->willReturn([]);
        $this->entityConfigMock->method('getTrustAnchorBag')->willReturn($trustAnchorBagMock);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('No valid Trust Anchors configured for the client.');

        $this->sut()->autoRegisterAndAuthenticate('https://op.example.org');
    }

    public function testAutoRegisterAndAuthenticateTrustChainException(): void
    {
        $trustAnchorBagMock = $this->createMock(TrustAnchorConfigBag::class);
        $trustAnchorBagMock->method('getAllEntityIds')->willReturn(['https://ta.example.org']);
        $this->entityConfigMock->method('getTrustAnchorBag')->willReturn($trustAnchorBagMock);

        $trustChainResolverMock = $this->createMock(TrustChainResolver::class);
        $this->federationMock->method('trustChainResolver')->willReturn($trustChainResolverMock);
        $trustChainResolverMock->method('for')
            ->willThrowException(new TrustChainException('Resolution failed'));

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('Resolution failed');

        $this->sut()->autoRegisterAndAuthenticate('https://op.example.org');
    }

    public function testGetUserDataOpJwksUriMissing(): void
    {
        $this->requestDataHandlerMock->method('validateAuthorizationCallbackResponse')->willReturn([
            'code' => 'auth_code',
            'state' => 'state_val',
        ]);
        $this->requestDataHandlerMock->method('getResolvedOpMetadataForState')->willReturn([
            'token_endpoint' => 'https://op.example.org/token',
        ]);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('OpenID Provider JWKS URI not available.');

        $this->sut()->getUserData();
    }

    public function testGetUserDataOpTokenEndpointMissing(): void
    {
        $this->requestDataHandlerMock->method('validateAuthorizationCallbackResponse')->willReturn([
            'code' => 'auth_code',
            'state' => 'state_val',
        ]);
        $this->requestDataHandlerMock->method('getResolvedOpMetadataForState')->willReturn([
            'jwks_uri' => 'https://op.example.org/jwks',
        ]);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('OpenID Provider token endpoint not available.');

        $this->sut()->getUserData();
    }

    public function testGetUserDataOpEntityIdMissing(): void
    {
        $this->requestDataHandlerMock->method('validateAuthorizationCallbackResponse')->willReturn([
            'code' => 'auth_code',
            'state' => 'state_val',
        ]);
        $this->requestDataHandlerMock->method('getResolvedOpMetadataForState')->willReturn([
            'jwks_uri' => 'https://op.example.org/jwks',
            'token_endpoint' => 'https://op.example.org/token',
        ]);

        $this->expectException(OidcClientException::class);
        $this->expectExceptionMessage('OpenID Provider entity ID not available.');

        $this->sut()->getUserData();
    }

    public function testAutoRegisterAndAuthenticateSuccessFormPost(): void
    {
        $opEntityId = 'https://op.example.org';
        $trustAnchorId = 'https://ta.example.org';

        $trustAnchorBagMock = $this->createMock(TrustAnchorConfigBag::class);
        $trustAnchorBagMock->method('getAllEntityIds')->willReturn([$trustAnchorId]);
        $this->entityConfigMock->method('getTrustAnchorBag')->willReturn($trustAnchorBagMock);
        $this->entityConfigMock->method('getEntityId')->willReturn('https://rp.example.org');

        $opTrustChainBagMock = $this->createMock(TrustChainBag::class);
        $trustChainResolverMock = $this->createMock(TrustChainResolver::class);
        $this->federationMock->method('trustChainResolver')->willReturn($trustChainResolverMock);
        $trustChainResolverMock->method('for')->willReturn($opTrustChainBagMock);

        $opTrustChainMock = $this->createMock(TrustChain::class);
        $opTrustChainBagMock->method('getShortest')->willReturn($opTrustChainMock);

        $opEntityStatementMock = $this->createMock(EntityStatement::class);
        $opTrustChainMock->method('getResolvedLeaf')->willReturn($opEntityStatementMock);
        $opEntityStatementMock->method('getSubject')->willReturn($opEntityId);
        $opEntityStatementMock->method('getIssuer')->willReturn($opEntityId);

        $opMetadata = [
            'authorization_endpoint' => 'https://op.example.org/auth',
            'issuer' => $opEntityId,
        ];
        $opTrustChainMock->method('getResolvedMetadata')->willReturn($opMetadata);

        $keyPairResolverMock = $this->createMock(\SimpleSAML\OpenID\Utils\KeyPairResolver::class);
        $this->federationMock->method('keyPairResolver')->willReturn($keyPairResolverMock);

        $signingKeyPairMock = $this->createMock(SignatureKeyPair::class);
        $keyPairResolverMock->method('resolveSignatureKeyPairByAlgorithm')->willReturn($signingKeyPairMock);

        $trustAnchorMock = $this->createMock(EntityStatement::class);
        $opTrustChainMock->method('getResolvedTrustAnchor')->willReturn($trustAnchorMock);
        $trustAnchorMock->method('getIssuer')->willReturn($trustAnchorId);

        $rpTrustChainBagMock = $this->createMock(TrustChainBag::class);
        $rpTrustChainBagMock->method('getShortest')->willReturn($this->createMock(TrustChain::class));
        // The second call to 'for' is for RP trust chain
        $trustChainResolverMock->method('for')
            ->willReturnOnConsecutiveCalls($opTrustChainBagMock, $rpTrustChainBagMock);

        $helpersMock = $this->createMock(\SimpleSAML\OpenID\Helpers::class);
        $this->federationMock->method('helpers')->willReturn($helpersMock);
        $dateTimeHelperMock = $this->createMock(\SimpleSAML\OpenID\Helpers\DateTime::class);
        $helpersMock->method('dateTime')->willReturn($dateTimeHelperMock);
        $dateTimeHelperMock->method('getUtc')->willReturn(new \DateTimeImmutable());
        $randomHelperMock = $this->createMock(\SimpleSAML\OpenID\Helpers\Random::class);
        $helpersMock->method('random')->willReturn($randomHelperMock);
        $randomHelperMock->method('string')->willReturn('random_jti');

        $redirectUriBagMock = $this->createMock(RedirectUriBag::class);
        $redirectUriBagMock->method('getDefaultRedirectUri')
            ->willReturn('https://rp.example.org/callback');
        $this->realyingPartyConfigMock->method('getRedirectUriBag')->willReturn($redirectUriBagMock);
        $this->realyingPartyConfigMock->method('getScopeBag')->willReturn(new ScopeBag('openid'));

        $this->requestDataHandlerMock->method('getState')->willReturn('state123');
        $this->requestDataHandlerMock->method('getNonce')->willReturn('nonce123');

        $innerKeyPairMock = $this->createMock(\SimpleSAML\OpenID\ValueAbstracts\KeyPair::class);
        $signingKeyPairMock->method('getKeyPair')->willReturn($innerKeyPairMock);
        $innerKeyPairMock->method('getKeyId')->willReturn('kid1');
        $innerKeyPairMock->method('getPrivateKey')->willReturn($this->createMock(JwkDecorator::class));
        $signingKeyPairMock->method('getSignatureAlgorithm')->willReturn(SignatureAlgorithmEnum::ES256);

        $requestObjectFactoryMock = $this->createMock(RequestObjectFactory::class);
        $this->federationMock->method('requestObjectFactory')->willReturn($requestObjectFactoryMock);
        $requestObjectMock = $this->createMock(\SimpleSAML\OpenID\Federation\RequestObject::class);
        $requestObjectFactoryMock->method('fromData')->willReturn($requestObjectMock);
        $requestObjectMock->method('getToken')->willReturn('signed_request_object');

        $responseMock = $this->createMock(ResponseInterface::class);
        $streamMock = $this->createMock(StreamInterface::class);
        $responseMock->method('getBody')->willReturn($streamMock);
        $responseMock->method('withHeader')->willReturn($responseMock);

        $result = $this->sut()->autoRegisterAndAuthenticate($opEntityId, response: $responseMock);
        $this->assertSame($responseMock, $result);
    }

    public function testGetUserDataSuccess(): void
    {
        $opEntityId = 'https://op.example.org';
        $state = 'state123';
        $this->requestDataHandlerMock->method('validateAuthorizationCallbackResponse')->willReturn([
            'code' => 'auth_code',
            'state' => $state,
        ]);

        $opMetadata = [
            'jwks_uri' => 'https://op.example.org/jwks',
            'token_endpoint' => 'https://op.example.org/token',
            'userinfo_endpoint' => 'https://op.example.org/userinfo',
            'issuer' => $opEntityId,
        ];
        $this->requestDataHandlerMock->method('getResolvedOpMetadataForState')
            ->with($state)->willReturn($opMetadata);
        $this->requestDataHandlerMock->method('getClientRedirectUriForState')
            ->with($state)->willReturn('https://rp.example.org/callback');

        $keyPairResolverMock = $this->createMock(\SimpleSAML\OpenID\Utils\KeyPairResolver::class);
        $this->federationMock->method('keyPairResolver')->willReturn($keyPairResolverMock);
        $signingKeyPairMock = $this->createMock(SignatureKeyPair::class);
        $keyPairResolverMock->method('resolveSignatureKeyPairByAlgorithm')->willReturn($signingKeyPairMock);

        $innerKeyPairMock = $this->createMock(\SimpleSAML\OpenID\ValueAbstracts\KeyPair::class);
        $signingKeyPairMock->method('getKeyPair')->willReturn($innerKeyPairMock);
        $innerKeyPairMock->method('getKeyId')->willReturn('kid1');
        $innerKeyPairMock->method('getPrivateKey')->willReturn($this->createMock(JwkDecorator::class));
        $signingKeyPairMock->method('getSignatureAlgorithm')->willReturn(SignatureAlgorithmEnum::ES256);

        $helpersMock = $this->createMock(\SimpleSAML\OpenID\Helpers::class);
        $this->federationMock->method('helpers')->willReturn($helpersMock);
        $dateTimeHelperMock = $this->createMock(\SimpleSAML\OpenID\Helpers\DateTime::class);
        $helpersMock->method('dateTime')->willReturn($dateTimeHelperMock);
        $dateTimeHelperMock->method('getUtc')->willReturn(new \DateTimeImmutable());
        $randomHelperMock = $this->createMock(\SimpleSAML\OpenID\Helpers\Random::class);
        $helpersMock->method('random')->willReturn($randomHelperMock);
        $randomHelperMock->method('string')->willReturn('random_jti');

        $clientAssertionMock = $this->createMock(ClientAssertion::class);
        $clientAssertionFactoryMock = $this->createMock(ClientAssertionFactory::class);
        $this->coreMock->method('clientAssertionFactory')->willReturn($clientAssertionFactoryMock);
        $clientAssertionFactoryMock->method('fromData')->willReturn($clientAssertionMock);
        $clientAssertionMock->method('getToken')->willReturn('client_assertion_token');

        $expectedUserData = ['sub' => 'user123', 'name' => 'John Doe'];
        $this->requestDataHandlerMock->method('getUserData')->willReturn($expectedUserData);

        $result = $this->sut()->getUserData();
        $this->assertSame($expectedUserData, $result);
    }

    public function testBuildEntityStatementWithStaticTrustMarks(): void
    {
        $entityId = 'https://rp.example.org';
        $this->entityConfigMock->method('getEntityId')->willReturn($entityId);
        $this->entityConfigMock->method('getAdditionalClaimBag')->willReturn(new ClaimBag());
        $this->entityConfigMock->method('getAuthorityHintBag')->willReturn(new UniqueStringBag());
        $this->entityConfigMock->method('getFederationSignatureKeyPairConfigBag')
            ->willReturn(new SignatureKeyPairConfigBag());

        $staticTrustMarkBag = new UniqueStringBag('tm_token');
        $this->entityConfigMock->method('getStaticTrustMarkBag')->willReturn($staticTrustMarkBag);
        $this->entityConfigMock->method('getDynamicTrustMarkBag')->willReturn(new KeyedStringBag());

        $trustMarkFactoryMock = $this->createMock(\SimpleSAML\OpenID\Federation\Factories\TrustMarkFactory::class);
        $this->federationMock->method('trustMarkFactory')->willReturn($trustMarkFactoryMock);
        $trustMarkEntityMock = $this->createMock(\SimpleSAML\OpenID\Federation\TrustMark::class);
        $trustMarkFactoryMock->method('fromToken')->with('tm_token')
            ->willReturn($trustMarkEntityMock);
        $trustMarkEntityMock->method('getSubject')->willReturn($entityId);
        $trustMarkEntityMock->method('getTrustMarkType')->willReturn('tm_type');

        $this->realyingPartyConfigMock->method('getAdditionalClaimBag')->willReturn(new ClaimBag());
        $this->realyingPartyConfigMock->method('getRedirectUriBag')
            ->willReturn(new RedirectUriBag('https://rp.example.org/callback'));
        $this->realyingPartyConfigMock->method('getConnectSignatureKeyPairBag')
            ->willReturn(new SignatureKeyPairConfigBag());
        $this->realyingPartyConfigMock->method('getScopeBag')->willReturn(new ScopeBag('openid'));

        $signatureKeyPairMock = $this->createMock(SignatureKeyPairBag::class);
        $this->signatureKeyPairBagFactoryMock->method('fromConfig')->willReturn($signatureKeyPairMock);
        $keyPairMock = $this->createMock(SignatureKeyPair::class);
        $signatureKeyPairMock->method('getFirstOrFail')->willReturn($keyPairMock);
        $signatureKeyPairMock->method('getAllPublicKeys')->willReturn([]);
        $innerKeyPairMock = $this->createMock(\SimpleSAML\OpenID\ValueAbstracts\KeyPair::class);
        $keyPairMock->method('getKeyPair')->willReturn($innerKeyPairMock);
        $innerKeyPairMock->method('getKeyId')->willReturn('kid1');
        $innerKeyPairMock->method('getPrivateKey')->willReturn($this->createMock(JwkDecorator::class));
        $keyPairMock->method('getSignatureAlgorithm')->willReturn(SignatureAlgorithmEnum::ES256);

        $this->jwksDecoratorFactoryMock->method('fromJwkDecorators')
            ->willReturn($this->createMock(JwksDecorator::class));

        $helpersMock = $this->createMock(\SimpleSAML\OpenID\Helpers::class);
        $this->federationMock->method('helpers')->willReturn($helpersMock);
        $dateTimeHelperMock = $this->createMock(\SimpleSAML\OpenID\Helpers\DateTime::class);
        $helpersMock->method('dateTime')->willReturn($dateTimeHelperMock);
        $dateTimeHelperMock->method('getUtc')->willReturn(new \DateTimeImmutable());
        $randomHelperMock = $this->createMock(\SimpleSAML\OpenID\Helpers\Random::class);
        $helpersMock->method('random')->willReturn($randomHelperMock);
        $randomHelperMock->method('string')->willReturn('random_jti');

        $entityStatementFactoryMock = $this->createMock(EntityStatementFactory::class);
        $this->federationMock->method('entityStatementFactory')->willReturn($entityStatementFactoryMock);
        $entityStatementFactoryMock->method('fromData')->willReturn($this->createMock(EntityStatement::class));

        $this->assertInstanceOf(EntityStatement::class, $this->sut()->buildEntityStatement());
    }

    public function testBuildEntityStatementWithDynamicTrustMarks(): void
    {
        $entityId = 'https://rp.example.org';
        $this->entityConfigMock->method('getEntityId')->willReturn($entityId);
        $this->entityConfigMock->method('getAdditionalClaimBag')->willReturn(new ClaimBag());
        $this->entityConfigMock->method('getAuthorityHintBag')->willReturn(new UniqueStringBag());
        $this->entityConfigMock->method('getFederationSignatureKeyPairConfigBag')
            ->willReturn(new SignatureKeyPairConfigBag());
        $this->entityConfigMock->method('getStaticTrustMarkBag')->willReturn(new UniqueStringBag());

        $dynamicTrustMarkBag = new KeyedStringBag(
            new \SimpleSAML\OpenID\ValueAbstracts\KeyedString('tm_type', 'tm_issuer')
        );
        $this->entityConfigMock->method('getDynamicTrustMarkBag')->willReturn($dynamicTrustMarkBag);

        $entityStatementFetcherMock = $this->createMock(\SimpleSAML\OpenID\Federation\EntityStatementFetcher::class);
        $this->federationMock->method('entityStatementFetcher')->willReturn($entityStatementFetcherMock);
        $entityStatementFetcherMock->method('fromCacheOrWellKnownEndpoint')
            ->with('tm_issuer')->willReturn($this->createMock(EntityStatement::class));

        $trustMarkFetcherMock = $this->createMock(TrustMarkFetcher::class);
        $this->federationMock->method('trustMarkFetcher')->willReturn($trustMarkFetcherMock);
        $trustMarkEntityMock = $this->createMock(\SimpleSAML\OpenID\Federation\TrustMark::class);
        $trustMarkFetcherMock->method('fromCacheOrFederationTrustMarkEndpoint')
            ->willReturn($trustMarkEntityMock);
        $trustMarkEntityMock->method('getToken')->willReturn('tm_token');

        $this->realyingPartyConfigMock->method('getAdditionalClaimBag')->willReturn(new ClaimBag());
        $this->realyingPartyConfigMock->method('getRedirectUriBag')
            ->willReturn(new RedirectUriBag('https://rp.example.org/callback'));
        $this->realyingPartyConfigMock->method('getConnectSignatureKeyPairBag')
            ->willReturn(new SignatureKeyPairConfigBag());
        $this->realyingPartyConfigMock->method('getScopeBag')->willReturn(new ScopeBag('openid'));

        $signatureKeyPairMock = $this->createMock(SignatureKeyPairBag::class);
        $this->signatureKeyPairBagFactoryMock->method('fromConfig')->willReturn($signatureKeyPairMock);
        $keyPairMock = $this->createMock(SignatureKeyPair::class);
        $signatureKeyPairMock->method('getFirstOrFail')->willReturn($keyPairMock);
        $signatureKeyPairMock->method('getAllPublicKeys')->willReturn([]);
        $innerKeyPairMock = $this->createMock(\SimpleSAML\OpenID\ValueAbstracts\KeyPair::class);
        $keyPairMock->method('getKeyPair')->willReturn($innerKeyPairMock);
        $innerKeyPairMock->method('getKeyId')->willReturn('kid1');
        $innerKeyPairMock->method('getPrivateKey')->willReturn($this->createMock(JwkDecorator::class));
        $keyPairMock->method('getSignatureAlgorithm')->willReturn(SignatureAlgorithmEnum::ES256);

        $this->jwksDecoratorFactoryMock->method('fromJwkDecorators')
            ->willReturn($this->createMock(JwksDecorator::class));

        $helpersMock = $this->createMock(\SimpleSAML\OpenID\Helpers::class);
        $this->federationMock->method('helpers')->willReturn($helpersMock);
        $dateTimeHelperMock = $this->createMock(\SimpleSAML\OpenID\Helpers\DateTime::class);
        $helpersMock->method('dateTime')->willReturn($dateTimeHelperMock);
        $dateTimeHelperMock->method('getUtc')->willReturn(new \DateTimeImmutable());
        $randomHelperMock = $this->createMock(\SimpleSAML\OpenID\Helpers\Random::class);
        $helpersMock->method('random')->willReturn($randomHelperMock);
        $randomHelperMock->string(); // just to satisfy any calls

        $entityStatementFactoryMock = $this->createMock(EntityStatementFactory::class);
        $this->federationMock->method('entityStatementFactory')->willReturn($entityStatementFactoryMock);
        $entityStatementFactoryMock->method('fromData')->willReturn($this->createMock(EntityStatement::class));

        $this->assertInstanceOf(EntityStatement::class, $this->sut()->buildEntityStatement());
    }

    public function testAutoRegisterAndAuthenticateSuccessRedirect(): void
    {
        $opEntityId = 'https://op.example.org';
        $trustAnchorId = 'https://ta.example.org';

        $trustAnchorBagMock = $this->createMock(TrustAnchorConfigBag::class);
        $trustAnchorBagMock->method('getAllEntityIds')->willReturn([$trustAnchorId]);
        $this->entityConfigMock->method('getTrustAnchorBag')->willReturn($trustAnchorBagMock);
        $this->entityConfigMock->method('getEntityId')->willReturn('https://rp.example.org');

        $opTrustChainBagMock = $this->createMock(TrustChainBag::class);
        $trustChainResolverMock = $this->createMock(TrustChainResolver::class);
        $this->federationMock->method('trustChainResolver')->willReturn($trustChainResolverMock);
        $trustChainResolverMock->method('for')->willReturn($opTrustChainBagMock);

        $opTrustChainMock = $this->createMock(TrustChain::class);
        $opTrustChainBagMock->method('getShortest')->willReturn($opTrustChainMock);

        $opEntityStatementMock = $this->createMock(EntityStatement::class);
        $opTrustChainMock->method('getResolvedLeaf')->willReturn($opEntityStatementMock);
        $opEntityStatementMock->method('getSubject')->willReturn($opEntityId);
        $opEntityStatementMock->method('getIssuer')->willReturn($opEntityId);

        $opMetadata = [
            'authorization_endpoint' => 'https://op.example.org/auth',
            'issuer' => $opEntityId,
        ];
        $opTrustChainMock->method('getResolvedMetadata')->willReturn($opMetadata);

        $keyPairResolverMock = $this->createMock(\SimpleSAML\OpenID\Utils\KeyPairResolver::class);
        $this->federationMock->method('keyPairResolver')->willReturn($keyPairResolverMock);

        $signingKeyPairMock = $this->createMock(SignatureKeyPair::class);
        $keyPairResolverMock->method('resolveSignatureKeyPairByAlgorithm')->willReturn($signingKeyPairMock);

        $trustAnchorMock = $this->createMock(EntityStatement::class);
        $opTrustChainMock->method('getResolvedTrustAnchor')->willReturn($trustAnchorMock);
        $trustAnchorMock->method('getIssuer')->willReturn($trustAnchorId);

        $rpTrustChainBagMock = $this->createMock(TrustChainBag::class);
        $rpTrustChainBagMock->method('getShortest')->willReturn($this->createMock(TrustChain::class));
        // The second call to 'for' is for RP trust chain
        $trustChainResolverMock->method('for')
            ->willReturnOnConsecutiveCalls($opTrustChainBagMock, $rpTrustChainBagMock);

        $helpersMock = $this->createMock(\SimpleSAML\OpenID\Helpers::class);
        $this->federationMock->method('helpers')->willReturn($helpersMock);
        $dateTimeHelperMock = $this->createMock(\SimpleSAML\OpenID\Helpers\DateTime::class);
        $helpersMock->method('dateTime')->willReturn($dateTimeHelperMock);
        $dateTimeHelperMock->method('getUtc')->willReturn(new \DateTimeImmutable());
        $randomHelperMock = $this->createMock(\SimpleSAML\OpenID\Helpers\Random::class);
        $helpersMock->method('random')->willReturn($randomHelperMock);
        $randomHelperMock->method('string')->willReturn('random_jti');

        $redirectUriBagMock = $this->createMock(RedirectUriBag::class);
        $redirectUriBagMock->method('getDefaultRedirectUri')
            ->willReturn('https://rp.example.org/callback');
        $this->realyingPartyConfigMock->method('getRedirectUriBag')->willReturn($redirectUriBagMock);
        $this->realyingPartyConfigMock->method('getScopeBag')->willReturn(new ScopeBag('openid'));

        $this->requestDataHandlerMock->method('getState')->willReturn('state123');
        $this->requestDataHandlerMock->method('getNonce')->willReturn('nonce123');

        $innerKeyPairMock = $this->createMock(\SimpleSAML\OpenID\ValueAbstracts\KeyPair::class);
        $signingKeyPairMock->method('getKeyPair')->willReturn($innerKeyPairMock);
        $innerKeyPairMock->method('getKeyId')->willReturn('kid1');
        $innerKeyPairMock->method('getPrivateKey')->willReturn($this->createMock(JwkDecorator::class));
        $signingKeyPairMock->method('getSignatureAlgorithm')->willReturn(SignatureAlgorithmEnum::ES256);

        $requestObjectFactoryMock = $this->createMock(RequestObjectFactory::class);
        $this->federationMock->method('requestObjectFactory')->willReturn($requestObjectFactoryMock);
        $requestObjectMock = $this->createMock(\SimpleSAML\OpenID\Federation\RequestObject::class);
        $requestObjectFactoryMock->method('fromData')->willReturn($requestObjectMock);
        $requestObjectMock->method('getToken')->willReturn('signed_request_object');

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('withHeader')->with('Location', $this->anything())
            ->willReturn($responseMock);

        $result = $this->sut(defaultAuthorizationRequestMethod: AuthorizationRequestMethodEnum::Query)
            ->autoRegisterAndAuthenticate($opEntityId, response: $responseMock);
        $this->assertSame($responseMock, $result);
    }

    public function testResolveClientRedirectUriForAuthorizationRequest(): void
    {
        $redirectUriBagMock = $this->createMock(RedirectUriBag::class);
        $redirectUriBagMock->method('getDefaultRedirectUri')
            ->willReturn('https://rp.example.org/default');
        $redirectUriBagMock->method('getAll')
            ->willReturn(['https://rp.example.org/default', 'https://rp.example.org/other']);
        $this->realyingPartyConfigMock->method('getRedirectUriBag')->willReturn($redirectUriBagMock);

        $sut = $this->sut();
        $reflection = new ReflectionClass($sut);
        $method = $reflection->getMethod('resolveClientRedirectUriForAuthorizationRequest');

        // Default
        $this->assertSame('https://rp.example.org/default', $method->invoke($sut, null));

        // Matching
        $this->assertSame(
            'https://rp.example.org/other',
            $method->invoke($sut, 'https://rp.example.org/other'),
        );

        // Not matching - should return default and log warning
        $this->loggerMock->expects($this->once())->method('warning');
        $this->assertSame('https://rp.example.org/default', $method
            ->invoke($sut, 'https://rp.example.org/invalid'));
    }
}
