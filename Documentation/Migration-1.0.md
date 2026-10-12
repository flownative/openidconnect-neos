# Migrating from 0.x to 1.0

Version 1.0 keeps the login in the Neos session instead of a JWT cookie.
It requires Neos 9 and flownative/openidconnect-client 6.1, whose
[migration guide](https://github.com/flownative/flow-openidconnect-client/blob/main/Documentation/Migration-6.0.md)
applies as well.

All users have to sign in once more after the update.

## Requirements

- PHP 8.3, 8.4 or 8.5
- Neos 9
- flownative/openidconnect-client 6.1

## The Session Keeps the Login

In version 0.x, the identity token was stored in a JWT cookie and checked
with every request, including every request of the Neos UI. A refresh
token renewed it when it expired. The package then exchanged the
transient account of the token with the persisted account of the Neos
user.

Version 1.0 uses the session mode of the client. The identity provider
vouches for the user once, and the Neos session keeps the login of the
persisted account. That means:

- The login lasts as long as the Neos session, which is controlled by
  `Neos.Flow.session.inactivityTimeout`. The lifetime of the identity
  token no longer matters, and no refresh token is requested.
- Flow tags each session with the account, so the sessions of a user
  can be ended on the server.
- The JWT cookie is no longer set. Browsers drop the old cookie when they
  are closed.

The provider of the Neos backend uses `OpenIdConnectSessionToken` and the
account resolver of this package. If your configuration sets "token" or
"accountResolver" of the provider `Neos.Neos:Backend`, remove these
settings.

## Roles

The provider options "roles", "rolesFromClaims" and
"addRolesFromExistingAccount" can't be used anymore, because the roles
belong to the persisted account. The client throws an exception if one
of them is set.

| Version 0.x                                      | Version 1.0                                                           |
|--------------------------------------------------|-----------------------------------------------------------------------|
| provider option `rolesFromClaims`                | `Flownative.OpenIdConnect.Neos.rolesFromClaims`, same format          |
| provider option `addRolesFromExistingAccount`    | remove it; the roles of the Neos user apply anyway                    |
| provider option `roles`                          | remove it; assign roles in Neos, or use `rolesForAutoCreatedUser`     |

With "rolesFromClaims", the roles from the claims are stored in the
account with every login and replace its roles. A user whose identity
token grants none of the roles can't sign in anymore. In version 0.x, such
a user was signed in without any role.

## Username

The account identifier of the provider, the claim "email" by default, is
the username of the Neos user. The setting
`identityValueMapping.username` is no longer used. If you used it to map
another claim, set the provider option `accountIdentifierTokenValueName`
instead. The claim must be unique, and users must not be able to change
it themselves, so prefer "sub" over "preferred_username" or "nickname":

```yaml
Neos:
  Flow:
    security:
      authentication:
        providers:
          'Neos.Neos:Backend':
            providerOptions:
              accountIdentifierTokenValueName: 'sub'
```

## Creating Neos Users

The defaults of "identityValueMapping" are now the standard claims
"given_name", "family_name", "name" and "email". The email address is
only stored if the claim "email_verified" is true.

With "rolesFromClaims", a new user only gets the roles from claims.
"rolesForAutoCreatedUser" only applies if the roles are managed in Neos.
Without any role, no user is created.

## Signing Out at the Identity Provider

Version 1.0 can sign the user out at the identity provider when they sign
out of Neos with a POST request. It is disabled by default. See "Signing Out at the Identity
Provider" in the README.

## Removed Classes

`Flownative\OpenIdConnect\Neos\AccountManager` and its slot for the
signal "authenticated" of the provider are removed. If you extended it,
implement `Flownative\OpenIdConnect\Client\Authentication\AccountResolverInterface`
or replace `NeosUserAccountResolver` in your `Objects.yaml`.
