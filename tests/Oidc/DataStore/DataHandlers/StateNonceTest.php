<?php

declare(strict_types=1);

namespace Cicnavi\Tests\Oidc\DataStore\DataHandlers;

use Cicnavi\Oidc\DataStore\DataHandlers\StateNonce;
use Cicnavi\Oidc\DataStore\PhpSessionStore;
use Cicnavi\Oidc\Helpers\StringHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StateNonce::class)]
#[UsesClass(PhpSessionStore::class)]
#[UsesClass(StringHelper::class)]
final class StateNonceTest extends TestCase
{
    public function testVerifyInvalidKeyThrows(): void
    {
        $stateNonce = new StateNonce();
        $this->expectException(\Exception::class);
        $stateNonce->verify('invalid', 'invalid');
    }

    public function testVerifyInvalidValueThrows(): void
    {
        $stateNonce = new StateNonce();
        $stateNonce->get(StateNonce::STATE_KEY);
        $this->expectException(\Exception::class);
        $stateNonce->verify(StateNonce::STATE_KEY, 'invalid');
    }

    public function testVerifyNonExistantKeyThrows(): void
    {
        $stateNonce = new StateNonce();
//        $stateNonce->get(StateNonce::STATE_KEY); Simulate that get with state_key was never called
        $this->expectException(\Exception::class);
        $stateNonce->verify(StateNonce::STATE_KEY, 'invalid');
    }

    public function testVerify(): void
    {
        $stateNonce = new StateNonce();

        $value = $stateNonce->get(StateNonce::STATE_KEY);

        $stateNonce->verify(StateNonce::STATE_KEY, $value);

        $this->assertNotSame($value, $stateNonce->get(StateNonce::STATE_KEY));
    }

    public function testGetInvalidKeyThrows(): void
    {
        $this->expectException(\Exception::class);

        (new StateNonce())->get('invalid');
    }

    public function testGetExistingValue(): void
    {
        $testValue = 'testValue';

        $storeStub = $this->createStub(PhpSessionStore::class);
        $storeStub->method('exists')
            ->willReturn(true);
        $storeStub->method('get')
            ->willReturn($testValue);

        $stateNonce = new StateNonce($storeStub);
        $this->assertSame($testValue, $stateNonce->get(StateNonce::STATE_KEY));
    }

    public function testGetNewValue(): void
    {
        $stateNonce = new StateNonce();

        $value = $stateNonce->get(StateNonce::STATE_KEY);

        $this->assertSame($value, $stateNonce->get(StateNonce::STATE_KEY));
    }
}
