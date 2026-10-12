<?php
declare(strict_types=1);
namespace Flownative\OpenIdConnect\Neos\Authentication;

use Flownative\OpenIdConnect\Client\Authentication\AccountResolverInterface;
use Flownative\OpenIdConnect\Client\IdentityToken;
use Flownative\OpenIdConnect\Client\ValidatedIdentityToken;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Utility\Algorithms;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Service\UserService;
use Neos\Party\Domain\Model\ElectronicAddress;
use Neos\Party\Domain\Service\PartyService;
use Psr\Log\LoggerInterface;

/**
 * Signs in to the account of a Neos user whose username is the account identifier of the identity token
 *
 * The OpenID Connect provider calls it in session mode, after the identity token passed all checks. If roles come from claims,
 * the identity provider decides the roles: they replace the roles of the account with every login, and a user without any of
 * these roles is not admitted. Otherwise, the roles of the account are managed in Neos.
 *
 * With "autoCreateUser", it creates a Neos user for an identity which has no account yet. Its roles are the roles from claims, or
 * "rolesForAutoCreatedUser" if the roles are managed in Neos. Values of claims are only copied to the user if they fit into it,
 * and the email address only if the identity provider verified it.
 */
#[Flow\Scope('singleton')]
final class NeosUserAccountResolver implements AccountResolverInterface
{
    private const int MAXIMUM_IDENTITY_VALUE_LENGTH = 255;

    /**
     * Not lazy, because a named injection would otherwise receive a dependency proxy which does not match the type.
     */
    #[Flow\Inject(name: 'Neos.Flow:SecurityLogger', lazy: false)]
    protected ?LoggerInterface $logger = null;

    /**
     * @param array<string,string> $identityValueMapping Claim names of "firstname", "lastname", "name" and "email"
     * @param list<string> $rolesForAutoCreatedUser
     */
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly PartyService $partyService,
        private readonly UserService $userService,
        private readonly PersistenceManagerInterface $persistenceManager,
        private readonly ClaimRoleMapper $claimRoleMapper,
        private readonly bool $autoCreateUser,
        private readonly array $rolesForAutoCreatedUser,
        private readonly array $identityValueMapping,
    ) {
    }

    public function resolve(ValidatedIdentityToken $validatedIdentityToken, string $authenticationProviderName): ?Account
    {
        $username = $validatedIdentityToken->accountIdentifier;
        $roleIdentifiersFromClaims = $this->claimRoleMapper->isConfigured() ? $this->claimRoleMapper->mapRoleIdentifiers($validatedIdentityToken->identityToken) : null;
        if ($roleIdentifiersFromClaims === []) {
            $this->logger?->notice(sprintf('OpenID Connect Neos: The identity token of "%s" grants none of the roles in "rolesFromClaims"', $username), LogEnvironment::fromMethodName(__METHOD__));
            return null;
        }

        $account = $this->accountRepository->findByAccountIdentifierAndAuthenticationProviderName($username, $authenticationProviderName);
        // Depending on its collation, the database also finds identifiers which only look similar, for example with accents or trailing spaces.
        if ($account instanceof Account && $account->getAccountIdentifier() !== $username) {
            $this->logger?->notice(sprintf('OpenID Connect Neos: The account "%s" does not match the identity "%s" exactly', $account->getAccountIdentifier(), $username), LogEnvironment::fromMethodName(__METHOD__));
            return null;
        }
        if ($account instanceof Account) {
            return $this->admitExistingAccount($account, $roleIdentifiersFromClaims);
        }
        if (!$this->autoCreateUser) {
            $this->logger?->notice(sprintf('OpenID Connect Neos: There is no Neos user "%s", and "autoCreateUser" is disabled', $username), LogEnvironment::fromMethodName(__METHOD__));
            return null;
        }
        return $this->createUser($username, $authenticationProviderName, $validatedIdentityToken->identityToken, $roleIdentifiersFromClaims);
    }

    /**
     * @param list<string>|null $roleIdentifiersFromClaims null if the roles are managed in Neos
     */
    private function admitExistingAccount(Account $account, ?array $roleIdentifiersFromClaims): ?Account
    {
        if (!$account->isActive()) {
            $this->logger?->notice(sprintf('OpenID Connect Neos: The account "%s" is expired', $account->getAccountIdentifier()), LogEnvironment::fromMethodName(__METHOD__));
            return null;
        }
        if (!$this->partyService->getAssignedPartyOfAccount($account) instanceof User) {
            $this->logger?->notice(sprintf('OpenID Connect Neos: The account "%s" belongs to no Neos user', $account->getAccountIdentifier()), LogEnvironment::fromMethodName(__METHOD__));
            return null;
        }
        if ($roleIdentifiersFromClaims !== null) {
            // The provider persists the account after the login, together with its roles
            $this->userService->setRolesForAccount($account, $roleIdentifiersFromClaims);
        }
        return $account;
    }

    /**
     * @param list<string>|null $roleIdentifiersFromClaims null if the roles are managed in Neos
     */
    private function createUser(string $username, string $authenticationProviderName, IdentityToken $identityToken, ?array $roleIdentifiersFromClaims): ?Account
    {
        // Roles from claims replace the roles of the account with every login, so further roles would only last until the next one
        $roleIdentifiers = $roleIdentifiersFromClaims ?? $this->rolesForAutoCreatedUser;
        if ($roleIdentifiers === []) {
            $this->logger?->notice(sprintf('OpenID Connect Neos: Did not create the Neos user "%s", because "rolesForAutoCreatedUser" is empty', $username), LogEnvironment::fromMethodName(__METHOD__));
            return null;
        }

        [$firstName, $lastName] = $this->getFirstAndLastName($identityToken);
        // Nobody knows the password, so the user can only sign in through the identity provider
        $user = $this->userService->createUser($username, Algorithms::generateRandomString(30), $firstName, $lastName, $roleIdentifiers, $authenticationProviderName);
        $emailAddress = $this->getVerifiedEmailAddress($identityToken);
        if ($emailAddress !== null) {
            $electronicAddress = new ElectronicAddress();
            $electronicAddress->setType('Email');
            $electronicAddress->setUsage('Work');
            $electronicAddress->setIdentifier($emailAddress);
            $user->setPrimaryElectronicAddress($electronicAddress);
        }
        // The browser returns from the identity provider with a GET request, in which Flow would only persist explicitly allowed objects
        $this->persistenceManager->persistAll();

        $this->logger?->info(sprintf('OpenID Connect Neos: Created the Neos user "%s" with the roles %s', $username, implode(', ', $roleIdentifiers)), LogEnvironment::fromMethodName(__METHOD__));
        return $this->accountRepository->findActiveByAccountIdentifierAndAuthenticationProviderName($username, $authenticationProviderName);
    }

    /**
     * @return array{string, string}
     */
    private function getFirstAndLastName(IdentityToken $identityToken): array
    {
        $firstName = $this->getIdentityValue($identityToken, 'firstname');
        $lastName = $this->getIdentityValue($identityToken, 'lastname');
        if ($firstName !== null || $lastName !== null) {
            return [$firstName ?? '', $lastName ?? ''];
        }
        $nameParts = explode(' ', $this->getIdentityValue($identityToken, 'name') ?? '');
        $lastName = array_pop($nameParts);
        return [implode(' ', $nameParts), $lastName];
    }

    private function getVerifiedEmailAddress(IdentityToken $identityToken): ?string
    {
        $emailAddress = $this->getIdentityValue($identityToken, 'email');
        if ($emailAddress === null || filter_var($emailAddress, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        // Without this claim, the address may be one which the user entered at the identity provider and never confirmed
        return in_array($identityToken->values['email_verified'] ?? null, [true, 'true'], true) ? $emailAddress : null;
    }

    /**
     * Returns null for a value which is missing, empty or too long for the user
     */
    private function getIdentityValue(IdentityToken $identityToken, string $key): ?string
    {
        $value = $identityToken->values[$this->identityValueMapping[$key] ?? $key] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value !== '' && mb_strlen($value) <= self::MAXIMUM_IDENTITY_VALUE_LENGTH ? $value : null;
    }
}
