<?php
declare(strict_types=1);

namespace Flownative\OpenIdConnect\Neos;

use Neos\Flow\Annotations as Flow;
use Flownative\OpenIdConnect\Client\IdentityToken;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Authentication\TokenInterface;
use Neos\Flow\Security\Context;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Flow\Utility\Algorithms;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Domain\Service\UserService;
use Neos\Party\Domain\Model\ElectronicAddress;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

class AccountManager
{

    /**
     * @var AccountRepository
     * @Flow\Inject
     */
    protected $accountRepository;

    /**
     * @var Context
     * @Flow\Inject
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var UserService
     */
    protected $userService;

    /**
     * @Flow\InjectConfiguration(package="Flownative.OpenIdConnect.Neos", path="identityValueMapping")
     * @var array
     */
    protected array $identityValueMapping = [];

    /**
     * @Flow\InjectConfiguration(package="Flownative.OpenIdConnect.Neos", path="autoCreateUser")
     * @var bool
     */
    protected bool $autoCreateUser = false;

    /**
     * @Flow\InjectConfiguration(package="Flownative.OpenIdConnect.Neos", path="rolesForAutoCreatedUser")
     * @var array
     */
    protected array $rolesForAutoCreatedUser = [];

    /**
     * @Flow\Inject
     * @var Algorithms
     */
    protected $algorithms;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var PolicyService
     */
    protected $policyService;

    /**
     * @param TokenInterface $authenticationToken
     * @param IdentityToken $identityToken
     * @throws \Neos\Neos\Domain\Exception
     * @throws \Exception
     */
    public function addBackendUserAndAccountIfNotExistent(TokenInterface $authenticationToken, IdentityToken $identityToken): void
    {
        $username = $this->getMappedIdentityValue($identityToken, 'username');
        $account = $authenticationToken->getAccount();
        $authenticationProvider = $account->getAuthenticationProviderName();

        $user = $this->userService->getUser($username, $authenticationProvider);
        if ($user instanceof User) {
            $authenticationToken->setAccount($this->exchangeTransientAccountWithPersistentAccount($account));
            return;
        }

        if ($this->autoCreateUser === false) {
            $this->logger->warning(sprintf('Auto creation of users is disabled. Please create a user with identifier "%s" manually.', $username), LogEnvironment::fromMethodName(__METHOD__));
            return;
        }

        $rolesForAutoCreatedUser = $this->rolesForAutoCreatedUser;
        foreach ($this->rolesForAutoCreatedUser as $roleIdentifier) {
            $account->addRole($this->policyService->getRole($roleIdentifier));
        }

        if ($account->getRoles() === []) {
            $this->logger->warning(sprintf('No roles were assigned to the user "%s". Please assign at least one role to the user, either through configuration or via OIDC.', $username), LogEnvironment::fromMethodName(__METHOD__));
            return;
        }

        try {
            $firstName = $this->getMappedIdentityValue($identityToken, 'firstname');
            $lastName = $this->getMappedIdentityValue($identityToken, 'lastname');
        } catch (UnexpectedValueException) {
            $nameParts = explode(' ', $this->getMappedIdentityValue($identityToken, 'name'));
            $lastName = array_pop($nameParts);
            $firstName = implode(' ', $nameParts);
        }

        $user = $this->userService->createUser(
            $username,
            Algorithms::generateRandomString(30),
            $firstName,
            $lastName,
            $rolesForAutoCreatedUser,
            $authenticationProvider
        );

        $this->logger->info(sprintf('Created new backend user %s with roles [%s] from %s', $username, implode(', ', $this->rolesForAutoCreatedUser), $authenticationToken->getAuthenticationProviderName()), LogEnvironment::fromMethodName(__METHOD__));

        try {
            $email = $this->getMappedIdentityValue($identityToken, 'email');
            $electronicAddress = new ElectronicAddress();
            $electronicAddress->setType('Email');
            $electronicAddress->setIdentifier($email);
            $electronicAddress->setUsage('Work');
            $user->setPrimaryElectronicAddress($electronicAddress);
        } catch (UnexpectedValueException $e) {
        }

        $this->persistenceManager->persistAll();

        $authenticationToken->setAccount($this->exchangeTransientAccountWithPersistentAccount($account));
    }

    /**
     * @param Account $account
     * @return Account
     * @throws \Exception
     */
    protected function exchangeTransientAccountWithPersistentAccount(Account $account): Account
    {
        $oidcCredentialSource = $account->getCredentialsSource();
        $assignedRoles = $account->getRoles();

        $this->securityContext->withoutAuthorizationChecks(function () use (&$account) {
            $account = $this->accountRepository->findActiveByAccountIdentifierAndAuthenticationProviderName($account->getAccountIdentifier(), $account->getAuthenticationProviderName());
        });

        // The authenticationAttempted call sets the lastSuccessFullAuthenticationDate, which is persisted afterwards
        $account->authenticationAttempted(TokenInterface::AUTHENTICATION_SUCCESSFUL);
        $this->accountRepository->update($account);
        $this->persistenceManager->persistAll();

        // The credentialSource on the transientAccount is needed on shutdown to generate the cookie.
        // Without the OIDC round trip is done on every request (also XHR requests, which fail currently)
        // The $oidcCredentialSource it is too large to be actually persisted to the database field
        $account->setCredentialsSource($oidcCredentialSource);
        $account->setRoles($assignedRoles);
        
        $this->persistenceManager->clearState();
        
        return $account;
    }

    /**
     * @param IdentityToken $identityToken
     * @param string $key
     * @return string
     * @throws UnexpectedValueException
     */
    protected function getMappedIdentityValue(IdentityToken $identityToken, string $key): string
    {
        $mappedKey = $this->identityValueMapping[$key] ?? $key;
        $identityValue = $identityToken->values[$mappedKey] ?? null;

        if ($identityValue === null) {
            throw new UnexpectedValueException(sprintf('Identity values do not contain the key "%s", available are %s', $mappedKey, implode(', ', array_keys($identityToken->values))));
        }

        return $identityValue;
    }
}
