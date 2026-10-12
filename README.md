[![MIT license](http://img.shields.io/badge/license-MIT-brightgreen.svg)](http://opensource.org/licenses/MIT)
[![Packagist](https://img.shields.io/packagist/v/flownative/openidconnect-neos.svg)](https://packagist.org/packages/flownative/openidconnect-neos)
[![Maintenance level: Love](https://img.shields.io/badge/maintenance-%E2%99%A1%E2%99%A1%E2%99%A1-ff69b4.svg)](https://www.flownative.com/en/products/open-source.html)

# OpenID Connect for Neos

This package lets editors sign in to the [Neos](https://www.neos.io/)
backend with [OpenID Connect](https://openid.net/connect/) (OIDC). It
configures
[flownative/openidconnect-client](https://github.com/flownative/flow-openidconnect-client)
for the Neos backend and connects identities to Neos users.

- The identity provider vouches for the user once. After that, an
  ordinary Neos session keeps the login, like a login with a password.
- The identity is connected to the Neos user whose username is the
  email address of the identity, or another claim of your choice.
- Roles can come from claims of the identity token, or they are
  managed in Neos.
- Neos users can be created on the first login.
- Signing out of Neos can sign the user out at the identity provider as
  well.

Upgrading from version 0.x? Read the
[migration guide](Documentation/Migration-1.0.md).

## Requirements

- PHP 8.3, 8.4 or 8.5
- Neos 9
- an identity provider which supports OpenID Connect Discovery

## Installation

```bash
composer require flownative/openidconnect-neos
```

## Configuration

### Identity Provider

The package configures a client service named "neos". By default, it
reads its options from these environment variables:

- `OIDC_DISCOVERY_URI`
- `OIDC_CLIENT_ID`
- `OIDC_CLIENT_SECRET`

You may also set the values directly:

```yaml
Flownative:
  OpenIdConnect:
    Client:
      services:
        neos:
          options:
            discoveryUri: 'https://id.example.com/.well-known/openid-configuration'
            clientId: '…'
            clientSecret: '…'
```

Register `https://www.example.com/oauth2/oidc/neos/finishauthorization`,
with the host of your site, as callback URL of the client at the
identity provider.

### Account Identifier

A Neos user signs in if their username matches the claim "email" of the
identity token. The client only accepts this claim if "email_verified" is
true. Choose another claim if your Neos usernames are something else:

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

The claim must be unique, and it must be one which the identity provider
controls and which users can't change themselves. "sub" always is. Don't
use "preferred_username" or "nickname": a user who can choose their
username at the identity provider could choose the one of a Neos
administrator.

The username of the Neos user must match the claim exactly, including
upper and lower case. Some identity providers return email addresses in
lower case.

The README of flownative/openidconnect-client explains the options of
the provider, for example the expected issuer and audience.

### Who Can Sign In

Every identity which the identity provider vouches for can try to sign
in. Without "rolesFromClaims" and "autoCreateUser", only identities with
an existing Neos user get in. With "autoCreateUser", every identity of
the identity provider becomes a Neos user. Make sure that only the right
people get a token for the client, for example by disabling public
signup, by assigning the application only to certain users, or by
granting roles in a claim with "rolesFromClaims".

### Roles

By default, the roles of a user are managed in Neos, like for any other
Neos user.

Alternatively, the identity provider decides the roles. Name the claims
which contain role identifiers:

```yaml
Flownative:
  OpenIdConnect:
    Neos:
      rolesFromClaims:
        - 'https://example.com/roles'
```

If the values of a claim are no Neos role identifiers, map them:

```yaml
Flownative:
  OpenIdConnect:
    Neos:
      rolesFromClaims:
        -
          name: 'groups'
          mapping:
            'cms-admins': 'Neos.Neos:Administrator'
            'cms-editors': 'Neos.Neos:Editor'
```

With "rolesFromClaims", the roles from the claims replace the roles of
the Neos user with every login. Roles which you assign in Neos are lost
at the next login. Values which are no role of the policy, or an
abstract role like "Neos.Flow:Everybody", are ignored. A user whose
identity token grants none of these roles can't sign in.

### Creating Neos Users

By default, only existing Neos users can sign in. With "autoCreateUser",
the package creates a Neos user on the first login of an identity:

```yaml
Flownative:
  OpenIdConnect:
    Neos:
      autoCreateUser: true
      rolesForAutoCreatedUser:
        - 'Neos.Neos:Editor'
```

A new user gets the roles from claims if "rolesFromClaims" is set, and
the roles of "rolesForAutoCreatedUser" otherwise. Without any role, no
user is created. The user gets a random password, so they can only sign
in through the identity provider.

First name, last name and email address are taken from the standard
claims. Values longer than 255 characters are ignored, and the email
address is only stored if the claim "email_verified" is true. If your
identity provider uses other claims, map them:

```yaml
Flownative:
  OpenIdConnect:
    Neos:
      identityValueMapping:
        firstname: 'https://example.com/first_name'
        lastname: 'https://example.com/last_name'
        # Used if the token has neither a first name nor a last name
        name: 'name'
        email: 'email'
```

### Signing Out at the Identity Provider

Signing out of Neos ends the Neos session only. The identity provider
still knows the user, so the next login doesn't ask for a password. To
sign the user out at the identity provider as well, enable it:

```yaml
Flownative:
  OpenIdConnect:
    Neos:
      signOutAtIdentityProvider: true
```

After signing out of Neos, the browser is sent to the end session
endpoint of the identity provider, which then redirects to the Neos login
page. Register the address of that page, for example
`https://www.example.com/neos/login`, as allowed logout URL of the client
at the identity provider. You may choose another address:

```yaml
Flownative:
  OpenIdConnect:
    Neos:
      postLogoutRedirectUri: 'https://www.example.com/'
```

The address must be absolute. Set it, or `Neos.Flow.http.baseUri`, if
the host of your site may differ from the host of the request.

This only happens for a logout with a POST request, like the one of the
user menu. The logout of Neos has no CSRF protection, so a logout with a
GET request, for example through a route of your own, only ends the Neos
session. Otherwise, any other website could sign the user out of every
application of the identity provider.

The end session endpoint is taken from the discovery document. Auth0, for
example, only publishes it if the tenant setting "RP-Initiated Logout End
Session Endpoint Discovery" is enabled. Without the endpoint, signing out
only ends the Neos session.

Note that signing out at the identity provider usually ends the login in
all applications which use it.

## Debugging

- Messages of this package and of the client are written to the
  security log. Most problems show up there with a reason.
- `./flow configuration:show --path Flownative.OpenIdConnect` shows the
  settings of this package and of the client.
- `./flow configuration:show --path Neos.Flow.security.authentication.providers`
  shows the authentication provider of the Neos backend.

## Credits and Support

This package was developed by Karsten Dambekalns and Robert Lemke /
Flownative. Feel free to suggest new features, report bugs or provide bug
fixes in our GitHub project.

Thanks to Daniel Lienert / punkt.de for the initial implementation of
the account handling.
