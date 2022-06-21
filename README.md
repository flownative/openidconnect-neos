[![MIT license](http://img.shields.io/badge/license-MIT-brightgreen.svg)](http://opensource.org/licenses/MIT)
[![Packagist](https://img.shields.io/packagist/v/flownative/openidconnect-neos.svg)](https://packagist.org/packages/flownative/openidconnect-neos)
[![Maintenance level: Love](https://img.shields.io/badge/maintenance-%E2%99%A1%E2%99%A1%E2%99%A1-ff69b4.svg)](https://www.flownative.com/en/products/open-source.html)

# OpenID Connect for the Neos CMS

This package provides an [OpenID Connect](https://openid.net/connect/) (OIDC)
"bridge" for [Neos](https://www.neos.io/).

It provides sane defaults for the OIDC client package, and provides a way to
match Neos backend users to OIDC users. Optionally users can be created on the
fly if they don't exist yet.

## Terms and Background

Before deploying OpenID Connect for your application, you should get  familiar
with the concepts. For a quick reminder, you should can the documentation of
[Flownative.OpenIdConnect.Client](https://packagist.org/packages/flownative/openidconnect-client)

## Requirements

In order to use this plugin you need:

- Neos CMS
- an OIDC Identity Provider which provides auto discovery

## Installation

The plugin is installed via Composer:

```
composer require flownative/openidconnect-neos
```

## Configuration

This packages provies sane defaults for most of the configuration, suitable for
Neos CMS.

The OIDC provider connection must be set up. The default configuration uses
these environment variables:

- `OIDC_DISCOVERY_URI`
- `OIDC_CLIENT_ID`
- `OIDC_CLIENT_SECRET`

You may of course set the values directly:

```
Flownative:
  OpenIdConnect:
    Client:
      services:
        neos:
          options:
            discoveryUri: '…'
            clientId: '…'
            clientSecret: '…'
```

And you must set up how roles are determined, see the next section.

### Roles

#### Hard-coded roles

You may configure the provider as follows:

```yaml
Neos:
  Flow:
    security:
      authentication:
        providers:
          'Neos.Neos:Backend':
            providerOptions:
              roles:
                - 'Neos.Neos:Editor'
```

That is the simplest way of configuring roles, but also very "static", no variation is
possible.

#### Roles from Identity Token

The  provider can extract the roles from the identity token values. The roles
provided by the token must have the same identifier as in Neos.

Given that the identity token provides a claim called "https://flownative.com/roles",
you may configure the provider as follows:

```yaml
Neos:
  Flow:
    security:
      authentication:
        providers:
          'Neos.Neos:Backend':
            providerOptions:
              rolesFromClaims:
                - 'https://flownative.com/roles'
```

When a user logs in and her identity token has a value "https://flownative.com/roles"
containing an array of Flow role identifiers, the OpenID Connect provider will
automatically assign these roles.

Roles can be mapped in case their values don't match the required Flow role
pattern (`<Package-Key>:<Role>`) or if multiple roles should be translated to a
single role:

```yaml
Neos:
  Flow:
    security:
      authentication:
        providers:
          'Neos.Neos:Backend':
            providerOptions:
              rolesFromClaims:
                -
                  name: 'https://flownative.com/roles'
                  mapping:
                    'role1': 'Some.Package:SomeRole1'
                    'role2': 'Some.Package:SomeOtherRole'
                    'role3': 'Some.Package:SomeRole'
```

You may specify multiple claim names which are all considered for
compiling a list of roles.

Check logs for hints if things are not working as expected.

#### Roles from an Existing Account

If you use locally created Neos users (accounts with the same username which is
provided by the identity token), the roles of that (persisted) account can be
used:

```yaml
Neos:
  Flow:
    security:
      authentication:
        providers:
          'Neos.Neos:Backend':
            providerOptions:
              addRolesFromExistingAccount: true
```

You may mix "rolesFromClaims" with "addRolesFromExistingAccount". In  that case
roles from claims and existing accounts will be merged.

Again, check the logs for hints if things are not working as expected.

#### Roles for Auto-Created Neos Users

In case auto-creation of users is enabled, the roles on the new user can be set
by configuration:

```yaml
Flownative:
  OpenIdConnect:
    Neos:
      autoCreateUser: true
      rolesForAutoCreatedUser:
        - 'Neos.Neos:Editor'
```

Note that you still must use (at least) one of the options to assign roles,
namely "rolesFromClaims" and "addRolesFromExistingAccount".

### Optional settings

You can set the JWT cookie name if you need to use a different name.

```yaml
Neos:
    Flow:
        security:
            authentication:
                providers:
                    'Neos.Neos:Backend':
                        providerOptions:
                            jwtCookieName: 'flownative_oidc_jwt'
```

If your OpenID Connect provider does not return a `username`, you can map
it like this:

```yaml
Flownative:
  OpenIdConnect:
    Neos:
      identityValueMapping:
        'username': 'email'
```

So far this assumes you locally create Neos users with the same username as the
OIDC provider returns. You can enable auto-creation of Neos users like this:

```yaml
Flownative:
  OpenIdConnect:
    Neos:
      autoCreateUser: true
      identityValueMapping:
        'firstname': 'https://flownative.com/given_name'
        'lastname': 'https://flownative.com/family_name'
```

The mapping of `firstname` and `lastname` is needed in case those are not
returned with those names by your OIDC provider. They are used for the created
users.

## Debugging

- Check the security and system log for messages, there is probably something
  helpful there.
- Use `./flow configuration:show --path Flownative.OpenIdConnect` to check the
  settings and look for things you might need to adjust.
- Repeat that step with the `Neos.Flow.security` settings.

## Credits and Support

This library was developed by Karsten Dambekalns / Flownative. Feel free
to  suggest new features, report bugs or provide bug fixes in our Github
project.

Thanks to Daniel Lienert / punkt.de for the initial implementation of the
`AccountManager` class.
