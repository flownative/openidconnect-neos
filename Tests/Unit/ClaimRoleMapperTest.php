<?php
declare(strict_types=1);
namespace Flownative\OpenIdConnect\Neos\Tests\Unit;

use Flownative\OpenIdConnect\Client\IdentityToken;
use Flownative\OpenIdConnect\Neos\Authentication\ClaimRoleMapper;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Flow\Security\Policy\Role;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ClaimRoleMapperTest extends TestCase
{
    private const array ROLES_IN_POLICY = ['Neos.Neos:Administrator', 'Neos.Neos:Editor', 'Neos.Flow:Everybody'];
    private const array ABSTRACT_ROLES = ['Neos.Flow:Everybody'];

    #[Test]
    public function isConfiguredOnlyWithClaims(): void
    {
        static::assertFalse($this->createMapper([])->isConfigured());
        static::assertTrue($this->createMapper(['roles'])->isConfigured());
    }

    #[Test]
    public function mapRoleIdentifiersReturnsRolesOfThePolicyFromTheClaim(): void
    {
        $mapper = $this->createMapper(['https://example.com/roles']);

        $roleIdentifiers = $mapper->mapRoleIdentifiers(self::createIdentityToken(['https://example.com/roles' => ['Neos.Neos:Editor', 'Acme:Unknown', 42, 'Neos.Neos:Editor']]));

        static::assertSame(['Neos.Neos:Editor'], $roleIdentifiers);
    }

    #[Test]
    public function mapRoleIdentifiersIgnoresAbstractRoles(): void
    {
        $mapper = $this->createMapper(['roles', ['name' => 'groups', 'mapping' => ['everybody' => 'Neos.Flow:Everybody']]]);

        $roleIdentifiers = $mapper->mapRoleIdentifiers(self::createIdentityToken(['roles' => ['Neos.Flow:Everybody', 'Neos.Neos:Editor'], 'groups' => ['everybody']]));

        static::assertSame(['Neos.Neos:Editor'], $roleIdentifiers);
    }

    #[Test]
    public function mapRoleIdentifiersAppliesTheMappingOfAClaim(): void
    {
        $mapper = $this->createMapper([['name' => 'groups', 'mapping' => ['admins' => 'Neos.Neos:Administrator', 'editors' => 'Neos.Neos:Editor']]]);

        $roleIdentifiers = $mapper->mapRoleIdentifiers(self::createIdentityToken(['groups' => ['admins', 'guests']]));

        static::assertSame(['Neos.Neos:Administrator'], $roleIdentifiers);
    }

    #[Test]
    public function mapRoleIdentifiersCombinesSeveralClaims(): void
    {
        $mapper = $this->createMapper(['roles', ['name' => 'groups', 'mapping' => ['admins' => 'Neos.Neos:Administrator']]]);

        $roleIdentifiers = $mapper->mapRoleIdentifiers(self::createIdentityToken(['roles' => ['Neos.Neos:Editor'], 'groups' => ['admins']]));

        static::assertSame(['Neos.Neos:Editor', 'Neos.Neos:Administrator'], $roleIdentifiers);
    }

    #[Test]
    public function mapRoleIdentifiersIgnoresMissingClaimsAndClaimsWhichAreNoList(): void
    {
        $mapper = $this->createMapper(['roles', 'groups']);

        static::assertSame([], $mapper->mapRoleIdentifiers(self::createIdentityToken(['groups' => 'Neos.Neos:Editor'])));
    }

    #[Test]
    public function mapRoleIdentifiersRejectsAnEntryWithoutMapping(): void
    {
        $mapper = $this->createMapper([['name' => 'groups']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1791793863);
        $mapper->mapRoleIdentifiers(self::createIdentityToken([]));
    }

    private function createMapper(array $rolesFromClaims): ClaimRoleMapper
    {
        $policyService = $this->createStub(PolicyService::class);
        $policyService->method('hasRole')->willReturnCallback(static fn (string $roleIdentifier): bool => in_array($roleIdentifier, self::ROLES_IN_POLICY, true));
        $policyService->method('getRole')->willReturnCallback(static function (string $roleIdentifier): Role {
            $role = new Role($roleIdentifier);
            $role->setAbstract(in_array($roleIdentifier, self::ABSTRACT_ROLES, true));
            return $role;
        });
        return new ClaimRoleMapper($policyService, $rolesFromClaims);
    }

    private static function createIdentityToken(array $values): IdentityToken
    {
        $identityToken = new IdentityToken();
        $identityToken->values = $values;
        return $identityToken;
    }
}
