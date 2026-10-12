<?php
declare(strict_types=1);
namespace Flownative\OpenIdConnect\Neos\Authentication;

use Flownative\OpenIdConnect\Client\IdentityToken;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Security\Policy\PolicyService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Determines the Neos roles of a user from claims of the identity token
 *
 * Each entry of "rolesFromClaims" is either the name of a claim which contains role identifiers, or a claim name with a mapping
 * from values of the claim to role identifiers. Values which are no role of the policy, or an abstract one, are ignored.
 */
#[Flow\Scope('singleton')]
final class ClaimRoleMapper
{
    /**
     * Not lazy, because a named injection would otherwise receive a dependency proxy which does not match the type.
     */
    #[Flow\Inject(name: 'Neos.Flow:SecurityLogger', lazy: false)]
    protected ?LoggerInterface $logger = null;

    /**
     * @param array<int,string|array{name: string, mapping: array<string,string>}> $rolesFromClaims
     */
    public function __construct(
        private readonly PolicyService $policyService,
        private readonly array $rolesFromClaims,
    ) {
    }

    /**
     * Tells if roles come from claims. Otherwise, the roles of an account are managed in Neos.
     */
    public function isConfigured(): bool
    {
        return $this->rolesFromClaims !== [];
    }

    /**
     * @return list<string> Identifiers of roles which exist in the policy and can be assigned to an account
     */
    public function mapRoleIdentifiers(IdentityToken $identityToken): array
    {
        $roleIdentifiers = [];
        foreach ($this->rolesFromClaims as $claimConfiguration) {
            [$claimName, $mapping] = self::parseClaimConfiguration($claimConfiguration);
            $claimValue = $identityToken->values[$claimName] ?? null;
            if ($claimValue === null) {
                $this->logger?->debug(sprintf('OpenID Connect Neos: The identity token contains no claim "%s"', $claimName), LogEnvironment::fromMethodName(__METHOD__));
                continue;
            }
            if (!is_array($claimValue)) {
                $this->logger?->error(sprintf('OpenID Connect Neos: Ignoring the claim "%s", because it is not a list of roles', $claimName), LogEnvironment::fromMethodName(__METHOD__));
                continue;
            }
            foreach ($claimValue as $value) {
                if (!is_string($value)) {
                    continue;
                }
                $roleIdentifier = $mapping === null ? $value : ($mapping[$value] ?? null);
                if ($roleIdentifier === null) {
                    $this->logger?->debug(sprintf('OpenID Connect Neos: Ignoring "%s" of the claim "%s", because it has no mapping', $value, $claimName), LogEnvironment::fromMethodName(__METHOD__));
                    continue;
                }
                if (!$this->policyService->hasRole($roleIdentifier)) {
                    $this->logger?->debug(sprintf('OpenID Connect Neos: Ignoring the role "%s" of the claim "%s", because the policy contains no such role', $roleIdentifier, $claimName), LogEnvironment::fromMethodName(__METHOD__));
                    continue;
                }
                if ($this->policyService->getRole($roleIdentifier)->isAbstract()) {
                    $this->logger?->warning(sprintf('OpenID Connect Neos: Ignoring the role "%s" of the claim "%s", because it is abstract and can\'t be assigned to an account', $roleIdentifier, $claimName), LogEnvironment::fromMethodName(__METHOD__));
                    continue;
                }
                $roleIdentifiers[] = $roleIdentifier;
            }
        }
        return array_values(array_unique($roleIdentifiers));
    }

    /**
     * @return array{string, array<string,string>|null}
     */
    private static function parseClaimConfiguration(mixed $claimConfiguration): array
    {
        if (is_string($claimConfiguration) && $claimConfiguration !== '') {
            return [$claimConfiguration, null];
        }
        if (is_array($claimConfiguration) && is_string($claimConfiguration['name'] ?? null) && is_array($claimConfiguration['mapping'] ?? null)) {
            return [$claimConfiguration['name'], $claimConfiguration['mapping']];
        }
        throw new RuntimeException('Each entry of "Flownative.OpenIdConnect.Neos.rolesFromClaims" must be a claim name, or an array with the claim "name" and a "mapping" of its values to role identifiers', 1791793863);
    }
}
