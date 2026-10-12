<?php
declare(strict_types=1);
namespace Flownative\OpenIdConnect\Neos\Tests\Unit;

use DateTime;
use Flownative\OpenIdConnect\Client\IdentityToken;
use Flownative\OpenIdConnect\Client\ValidatedIdentityToken;
use Flownative\OpenIdConnect\Neos\Authentication\ClaimRoleMapper;
use Flownative\OpenIdConnect\Neos\Authentication\NeosUserAccountResolver;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Flow\Security\Policy\Role;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Service\UserService;
use Neos\Party\Domain\Model\Person;
use Neos\Party\Domain\Service\PartyService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class NeosUserAccountResolverTest extends TestCase
{
    private const string PROVIDER_NAME = 'Neos.Neos:Backend';
    private const string USERNAME = 'jane@example.com';
    private const string ROLES_CLAIM = 'https://example.com/roles';

    private AccountRepository&Stub $accountRepository;
    private PartyService&Stub $partyService;
    private UserService&Stub $userService;
    private PersistenceManagerInterface&Stub $persistenceManager;

    protected function setUp(): void
    {
        $this->accountRepository = $this->createStub(AccountRepository::class);
        $this->partyService = $this->createStub(PartyService::class);
        $this->userService = $this->createStub(UserService::class);
        $this->persistenceManager = $this->createStub(PersistenceManagerInterface::class);
    }

    #[Test]
    public function resolveReplacesTheRolesOfAnExistingAccountWithRolesFromClaims(): void
    {
        $account = $this->givenAccountOfNeosUser(self::USERNAME);
        $this->expectUserService()->expects($this->once())->method('setRolesForAccount')->with($account, ['Neos.Neos:Editor']);

        $resolvedAccount = $this->createResolver([self::ROLES_CLAIM])->resolve(self::createValidatedIdentityToken([self::ROLES_CLAIM => ['Neos.Neos:Editor']]), self::PROVIDER_NAME);

        static::assertSame($account, $resolvedAccount);
    }

    #[Test]
    public function resolveKeepsTheRolesOfAnExistingAccountIfRolesAreManagedInNeos(): void
    {
        $account = $this->givenAccountOfNeosUser(self::USERNAME);
        $this->expectUserService()->expects($this->never())->method('setRolesForAccount');

        $resolvedAccount = $this->createResolver([])->resolve(self::createValidatedIdentityToken([self::ROLES_CLAIM => ['Neos.Neos:Editor']]), self::PROVIDER_NAME);

        static::assertSame($account, $resolvedAccount);
    }

    #[Test]
    public function resolveAdmitsNobodyWithoutRolesFromClaims(): void
    {
        $this->givenAccountOfNeosUser(self::USERNAME);
        $this->expectUserService()->expects($this->never())->method('setRolesForAccount');

        static::assertNull($this->createResolver([self::ROLES_CLAIM])->resolve(self::createValidatedIdentityToken([self::ROLES_CLAIM => ['Acme:Unknown']]), self::PROVIDER_NAME));
    }

    #[Test]
    public function resolveRejectsAnAccountWhoseIdentifierOnlyLooksSimilar(): void
    {
        $this->givenAccountOfNeosUser('Jane@example.com');

        static::assertNull($this->createResolver([])->resolve(self::createValidatedIdentityToken([]), self::PROVIDER_NAME));
    }

    #[Test]
    public function resolveRejectsAnExpiredAccount(): void
    {
        $account = $this->givenAccountOfNeosUser(self::USERNAME);
        $account->setExpirationDate(new DateTime('-1 day'));
        (new ReflectionProperty(Account::class, 'now'))->setValue($account, new DateTime());

        static::assertNull($this->createResolver([], autoCreateUser: true, rolesForAutoCreatedUser: ['Neos.Neos:Editor'])->resolve(self::createValidatedIdentityToken([]), self::PROVIDER_NAME));
    }

    #[Test]
    public function resolveRejectsAnAccountWhichBelongsToNoNeosUser(): void
    {
        $account = self::createAccount(self::USERNAME);
        $this->accountRepository->method('findByAccountIdentifierAndAuthenticationProviderName')->willReturn($account);
        $this->partyService->method('getAssignedPartyOfAccount')->willReturn(new Person());

        static::assertNull($this->createResolver([])->resolve(self::createValidatedIdentityToken([]), self::PROVIDER_NAME));
    }

    #[Test]
    public function resolveCreatesNoUserIfAutoCreationIsDisabled(): void
    {
        $this->expectUserService()->expects($this->never())->method('createUser');

        static::assertNull($this->createResolver([self::ROLES_CLAIM])->resolve(self::createValidatedIdentityToken([self::ROLES_CLAIM => ['Neos.Neos:Editor']]), self::PROVIDER_NAME));
    }

    #[Test]
    public function resolveCreatesAUserWithTheRolesFromClaims(): void
    {
        $user = new User();
        $createdAccount = self::createAccount(self::USERNAME);
        $this->accountRepository->method('findActiveByAccountIdentifierAndAuthenticationProviderName')->willReturn($createdAccount);
        $this->expectUserService()->expects($this->once())->method('createUser')
            ->with(self::USERNAME, $this->isString(), 'Jane', 'Doe', ['Neos.Neos:Administrator'], self::PROVIDER_NAME)
            ->willReturn($user);
        $this->expectPersistenceManager()->expects($this->once())->method('persistAll');

        $resolver = $this->createResolver([self::ROLES_CLAIM], autoCreateUser: true, rolesForAutoCreatedUser: ['Neos.Neos:Editor']);
        $resolvedAccount = $resolver->resolve(self::createValidatedIdentityToken([self::ROLES_CLAIM => ['Neos.Neos:Administrator'], 'given_name' => 'Jane', 'family_name' => 'Doe', 'email' => self::USERNAME, 'email_verified' => true]), self::PROVIDER_NAME);

        static::assertSame($createdAccount, $resolvedAccount);
        static::assertSame(self::USERNAME, $user->getPrimaryElectronicAddress()?->getIdentifier());
    }

    #[Test]
    public function resolveCreatesAUserWithTheConfiguredRolesIfRolesAreManagedInNeos(): void
    {
        $this->expectUserService()->expects($this->once())->method('createUser')
            ->with(self::USERNAME, $this->isString(), 'Jane', 'Doe', ['Neos.Neos:Editor'], self::PROVIDER_NAME)
            ->willReturn(new User());

        $this->createResolver([], autoCreateUser: true, rolesForAutoCreatedUser: ['Neos.Neos:Editor'])->resolve(self::createValidatedIdentityToken([self::ROLES_CLAIM => ['Neos.Neos:Administrator'], 'given_name' => 'Jane', 'family_name' => 'Doe']), self::PROVIDER_NAME);
    }

    #[Test]
    public function resolveStoresNoEmailAddressWhichIsNotVerified(): void
    {
        $user = new User();
        $this->expectUserService()->expects($this->once())->method('createUser')->willReturn($user);

        $this->createResolver([], autoCreateUser: true, rolesForAutoCreatedUser: ['Neos.Neos:Editor'])->resolve(self::createValidatedIdentityToken(['name' => 'Jane Doe', 'email' => 'jane@example.com', 'email_verified' => false]), self::PROVIDER_NAME);

        static::assertNull($user->getPrimaryElectronicAddress());
    }

    #[Test]
    public function resolveIgnoresValuesWhichAreTooLongForTheUser(): void
    {
        $user = new User();
        $this->expectUserService()->expects($this->once())->method('createUser')
            ->with(self::USERNAME, $this->isString(), '', 'Doe', ['Neos.Neos:Editor'], self::PROVIDER_NAME)
            ->willReturn($user);

        $this->createResolver([], autoCreateUser: true, rolesForAutoCreatedUser: ['Neos.Neos:Editor'])->resolve(self::createValidatedIdentityToken(['given_name' => str_repeat('a', 256), 'family_name' => 'Doe', 'email' => str_repeat('a', 250) . '@example.com', 'email_verified' => true]), self::PROVIDER_NAME);

        static::assertNull($user->getPrimaryElectronicAddress());
    }

    #[Test]
    public function resolveSplitsTheNameIfTheTokenHasNoFirstAndLastName(): void
    {
        $this->expectUserService()->expects($this->once())->method('createUser')
            ->with(self::USERNAME, $this->isString(), 'Jane Mary', 'Doe', ['Neos.Neos:Editor'], self::PROVIDER_NAME)
            ->willReturn(new User());

        $this->createResolver([], autoCreateUser: true, rolesForAutoCreatedUser: ['Neos.Neos:Editor'])->resolve(self::createValidatedIdentityToken(['name' => 'Jane Mary Doe']), self::PROVIDER_NAME);
    }

    #[Test]
    public function resolveCreatesNoUserWithoutAnyRole(): void
    {
        $this->expectUserService()->expects($this->never())->method('createUser');

        static::assertNull($this->createResolver([], autoCreateUser: true)->resolve(self::createValidatedIdentityToken([]), self::PROVIDER_NAME));
    }

    private function expectUserService(): UserService&MockObject
    {
        return $this->userService instanceof MockObject ? $this->userService : $this->userService = $this->createMock(UserService::class);
    }

    private function expectPersistenceManager(): PersistenceManagerInterface&MockObject
    {
        return $this->persistenceManager instanceof MockObject ? $this->persistenceManager : $this->persistenceManager = $this->createMock(PersistenceManagerInterface::class);
    }

    private function createResolver(array $rolesFromClaims, bool $autoCreateUser = false, array $rolesForAutoCreatedUser = []): NeosUserAccountResolver
    {
        $policyService = $this->createStub(PolicyService::class);
        $policyService->method('hasRole')->willReturnCallback(static fn (string $roleIdentifier): bool => str_starts_with($roleIdentifier, 'Neos.Neos:'));
        $policyService->method('getRole')->willReturnCallback(static fn (string $roleIdentifier): Role => new Role($roleIdentifier));
        return new NeosUserAccountResolver(
            $this->accountRepository,
            $this->partyService,
            $this->userService,
            $this->persistenceManager,
            new ClaimRoleMapper($policyService, $rolesFromClaims),
            $autoCreateUser,
            $rolesForAutoCreatedUser,
            ['firstname' => 'given_name', 'lastname' => 'family_name', 'name' => 'name', 'email' => 'email'],
        );
    }

    private function givenAccountOfNeosUser(string $accountIdentifier): Account
    {
        $account = self::createAccount($accountIdentifier);
        $this->accountRepository->method('findByAccountIdentifierAndAuthenticationProviderName')->willReturn($account);
        $this->partyService->method('getAssignedPartyOfAccount')->willReturn(new User());
        return $account;
    }

    private static function createAccount(string $accountIdentifier): Account
    {
        $account = new Account();
        $account->setAccountIdentifier($accountIdentifier);
        $account->setAuthenticationProviderName(self::PROVIDER_NAME);
        return $account;
    }

    private static function createValidatedIdentityToken(array $values): ValidatedIdentityToken
    {
        $identityToken = new IdentityToken();
        $identityToken->values = $values;
        return new ValidatedIdentityToken($identityToken, self::USERNAME);
    }
}
