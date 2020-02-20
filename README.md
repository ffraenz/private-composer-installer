
# private-composer-installer

[![Packagist version](https://img.shields.io/packagist/v/ffraenz/private-composer-installer.svg?maxAge=3600)](https://packagist.org/packages/ffraenz/private-composer-installer)
[![MIT license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)
[![Build Status](https://travis-ci.org/ffraenz/private-composer-installer.svg?branch=master)](https://travis-ci.org/ffraenz/private-composer-installer)
[![Coverage Status](https://coveralls.io/repos/github/ffraenz/private-composer-installer/badge.svg?branch=master)](https://coveralls.io/github/ffraenz/private-composer-installer?branch=master)
[![Packagist downloads](https://img.shields.io/packagist/dt/ffraenz/private-composer-installer.svg?maxAge=3600)](https://packagist.org/packages/ffraenz/private-composer-installer)

This is a [Composer](https://getcomposer.org/) plugin offering a way to reference private package URLs within `composer.json` and `composer.lock`. It outsources sensitive dist URL parts (license keys, tokens) into environment variables or a `.env` file typically ignored by version control. This repository is inspired by [acf-pro-installer](https://github.com/PhilippBaschke/acf-pro-installer).

## Quick overview

- When installing or updating a package, the dist URL `{%VERSION}` placeholder gets replaced by the version set in the package. The versioned dist URL is added to `composer.lock`.
- Before downloading the package, `{%VARIABLE}` formatted placeholders get replaced by their corresponding env variables in the dist URL. Env vars will never be stored inside `composer.lock`.
- If an env variable is not set for the given placeholder the plugin trys to read it from the `.env` file using [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv). If it can't be resolved a `MissingEnvException` gets thrown.
- Package dist URLs with no `{%VARIABLE}` formatted placeholders get ignored by this plugin.

## Examples

### Arbitrary private package

Add the desired private package to the `repositories` field inside `composer.json`. In this example the entire dist URL of the package will be replaced by an environment variable. Find more about composer repositories in the [composer documentation](https://getcomposer.org/doc/05-repositories.md#repositories).

```json
{
  "type": "package",
  "package": {
    "name": "package-name/package-name",
    "version": "1.0.0",
    "dist": {
      "type": "zip",
      "url": "https://example.com/package-name.zip?key={%PACKAGE_KEY}&version={%VERSION}"
    },
    "require": {
      "ffraenz/private-composer-installer": "^4.0"
    }
  }
}
```

Provide the private package dist URL inside the `.env` file:

```
PACKAGE_KEY=pleasedontusethiskey
```

Let composer require the private package:

```bash
composer require package-name/package-name:*
```

### WordPress plugins

WordPress plugins can be installed using the package type `wordpress-plugin` in conjunction with the `composer/installers` installer. In this example we are installing the ACF Pro plugin. Add following entry to the [repositories](https://getcomposer.org/doc/05-repositories.md#repositories) field inside `composer.json` and set the desired ACF Pro version.

```json
{
  "type": "package",
  "package": {
    "name": "advanced-custom-fields/advanced-custom-fields-pro",
    "version": "1.2.3",
    "type": "wordpress-plugin",
    "dist": {
      "type": "zip",
      "url": "https://connect.advancedcustomfields.com/index.php?a=download&p=pro&k={%PLUGIN_ACF_KEY}&t={%VERSION}"
    },
    "require": {
      "composer/installers": "^1.4",
      "ffraenz/private-composer-installer": "^4.0"
    }
  }
}
```

Provide the ACF Pro key inside the `.env` file. To get this key, login to your [ACF account](https://www.advancedcustomfields.com/my-account/) and scroll down to 'Licenses & Downloads'.

```
PLUGIN_ACF_KEY=pleasedontusethiskey
```

Let composer require ACF Pro:

```bash
composer require advanced-custom-fields/advanced-custom-fields-pro:*
```

## Versions

This package heavily depends on [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv) to load environment variables "automagically". This may cause version conflicts if your project already depends on it. Refer to this table to set the version of `private-composer-installer` accordingly or consider upgrading.

| `private-composer-installer` | `vlucas/phpdotenv` |
| ---------------------------- | ------------------ |
| `^4.0`                       | `^4.0`             |
| `^3.0`, `^2.0`               | `^3.0`             |
| `^1.0`                       | `^2.2`             |

## Development

Install composer dependencies:

```bash
docker-compose run --rm composer composer install
```

Before pushing changes to the repository run tests and check coding standards using following command:

```bash
docker-compose run --rm composer composer check
```

---

This is a project by [Fränz Friederes](https://fraenz.frieder.es/) and [contributors](https://github.com/ffraenz/private-composer-installer/graphs/contributors)
