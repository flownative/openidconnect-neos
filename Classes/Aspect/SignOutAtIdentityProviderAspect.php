<?php
declare(strict_types=1);
namespace Flownative\OpenIdConnect\Neos\Aspect;

use Flownative\OpenIdConnect\Client\Authentication\IdentityTokenOfLogin;
use Flownative\OpenIdConnect\Client\ConfigurationException;
use Flownative\OpenIdConnect\Client\ConnectionException;
use Flownative\OpenIdConnect\Client\OpenIdConnectClientFactory;
use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use Neos\Cache\Exception as CacheException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Mvc\Controller\AbstractController;
use Neos\Flow\Mvc\Exception\StopActionException;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;

/**
 * Signs the user out at the identity provider as well, when they sign out of the Neos backend
 *
 * Without it, the next login in Neos would sign the user in again without asking, because the identity provider still knows
 * them. Neos redirects to its login page after the logout. This aspect sends the browser to the end session endpoint of the
 * identity provider instead, which then redirects to the login page, or to "postLogoutRedirectUri". The identity provider must
 * accept that address as post logout redirect URI of the client.
 *
 * Only a logout with a POST request reaches the identity provider. The logout action of Neos skips the CSRF check, so a route
 * which also accepted GET would let any other website sign the user out of every application of the identity provider.
 */
#[Flow\Aspect]
final class SignOutAtIdentityProviderAspect
{
    private const string AUTHENTICATION_PROVIDER_NAME = 'Neos.Neos:Backend';

    /**
     * Not lazy, because a named injection would otherwise receive a dependency proxy which does not match the type.
     */
    #[Flow\Inject(name: 'Neos.Flow:SecurityLogger', lazy: false)]
    protected ?LoggerInterface $logger = null;

    #[Flow\Inject]
    protected IdentityTokenOfLogin $identityTokenOfLogin;

    #[Flow\Inject]
    protected OpenIdConnectClientFactory $openIdConnectClientFactory;

    #[Flow\InjectConfiguration(path: 'signOutAtIdentityProvider')]
    protected bool $signOutAtIdentityProvider = false;

    #[Flow\InjectConfiguration(path: 'postLogoutRedirectUri')]
    protected ?string $postLogoutRedirectUri = null;

    #[Flow\InjectConfiguration(path: 'security.authentication.providers', package: 'Neos.Flow')]
    protected array $authenticationProviderConfiguration = [];

    /**
     * @throws StopActionException
     */
    #[Flow\Around('method(Neos\Neos\Controller\LoginController->logoutAction())')]
    public function redirectToEndSessionEndpoint(JoinPointInterface $joinPoint): mixed
    {
        if (!$this->signOutAtIdentityProvider || !self::isPostRequest($joinPoint)) {
            return $joinPoint->getAdviceChain()->proceed($joinPoint);
        }
        // Flow destroys the session on logout, and with it the identity token of the login
        $identityToken = $this->identityTokenOfLogin->find(self::AUTHENTICATION_PROVIDER_NAME);
        try {
            // A logout with JSON format returns normally and is left to the client
            return $joinPoint->getAdviceChain()->proceed($joinPoint);
        } catch (StopActionException $exception) {
            $loginPageUri = $exception->response->getHeaderLine('Location');
            if ($identityToken === null || $loginPageUri === '') {
                throw $exception;
            }
            $serviceName = $this->authenticationProviderConfiguration[self::AUTHENTICATION_PROVIDER_NAME]['providerOptions']['serviceName'] ?? null;
            if (!is_string($serviceName)) {
                throw $exception;
            }
            // The user is already signed out of Neos, so any error must still lead to the login page
            try {
                $endSessionUri = $this->openIdConnectClientFactory->create($serviceName)->buildEndSessionUri($identityToken, $this->getPostLogoutRedirectUri($loginPageUri));
            } catch (ConfigurationException|ConnectionException|CacheException|InvalidArgumentException $signOutException) {
                $this->logger?->error(sprintf('OpenID Connect Neos: Could not sign out at the identity provider of service "%s": %s', $serviceName, $signOutException->getMessage()), LogEnvironment::fromMethodName(__METHOD__));
                throw $exception;
            }
            if ($endSessionUri === null) {
                $this->logger?->warning(sprintf('OpenID Connect Neos: The identity provider of service "%s" has no end session endpoint, so the user is still signed in there', $serviceName), LogEnvironment::fromMethodName(__METHOD__));
                throw $exception;
            }
            throw StopActionException::createForResponse($exception->response->withHeader('Location', (string)$endSessionUri), 'Sign out at the identity provider');
        }
    }

    /**
     * @throws InvalidArgumentException if "postLogoutRedirectUri" is no absolute HTTP URI
     */
    private function getPostLogoutRedirectUri(string $loginPageUri): UriInterface
    {
        if ($this->postLogoutRedirectUri === null || $this->postLogoutRedirectUri === '') {
            return new Uri($loginPageUri);
        }
        $postLogoutRedirectUri = new Uri($this->postLogoutRedirectUri);
        if (!in_array($postLogoutRedirectUri->getScheme(), ['http', 'https'], true) || $postLogoutRedirectUri->getHost() === '') {
            throw new InvalidArgumentException(sprintf('"Flownative.OpenIdConnect.Neos.postLogoutRedirectUri" must be an absolute HTTP URI, "%s" is not', $this->postLogoutRedirectUri), 1791800144);
        }
        return $postLogoutRedirectUri;
    }

    private static function isPostRequest(JoinPointInterface $joinPoint): bool
    {
        $controller = $joinPoint->getProxy();
        return $controller instanceof AbstractController && $controller->getControllerContext()->getRequest()->getHttpRequest()->getMethod() === 'POST';
    }
}
