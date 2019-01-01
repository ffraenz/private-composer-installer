
# private-composer-installer

[![Packagist version](https://img.shields.io/packagist/v/ffraenz/private-composer-installer.svg?maxAge=3600)](https://packagist.org/packages/ffraenz/private-composer-installer)
[![MIT license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)
[![Build Status](https://travis-ci.org/ffraenz/private-composer-installer.svg?branch=master)](https://travis-ci.org/ffraenz/private-composer-installer)
[![Coverage Status](https://coveralls.io/repos/github/ffraenz/private-composer-installer/badge.svg?branch=master)](https://coveralls.io/github/ffraenz/private-composer-installer?branch=master)
[![Packagist downloads](https://img.shields.io/packagist/dt/ffraenz/private-composer-installer.svg?maxAge=3600)](https://packagist.org/packages/ffraenz/private-composer-installer)

[Composer](https://getcomposer.org/) plugin trying to solve the problem of referencing private package URLs within `composer.json` and `composer.lock`. It outsources sensitive dist URL parts (license keys, tokens) into environment variables or a `.env` file typically ignored by version control. This repository is inspired by [acf-pro-installer](https://github.com/PhilippBaschke/acf-pro-installer).

## Quick overview

- When installing or updating a package, the dist URL `{%version}` placeholder gets replaced by the version set in the package. When the placeholder is not present, a version hash is added to the end of the dist URL to force the re-download. The versioned dist URL is added to `composer.lock`.
- Just before downloading the package, `{%XYZ}` formatted placeholders get replaced by their corresponding env variables in the dist URL. Env vars will never be stored inside `composer.lock`.
- Package dist URLs with no `{%XYZ}` formatted placeholders get ignored by this plugin.
- When an environment variable required by a placeholder is not set, the `.env` file gets loaded.
- When a placeholder cannot be fullfilled, a `MissingEnvException` gets thrown.
- This installer is not WordPress specific. WordPress plugins can be installed using the package type `wordpress-plugin` in conjunction with the `composer/installers` installer.

## Examples

### Generic private package

Add the desired private package to the `repositories` field inside `composer.json`. In this example the entire dist URL of the package will be replaced by an environment variable. Find more about composer repositories in the [composer docs](https://getcomposer.org/doc/05-repositories.md#repositories).

```json
{
  "type": "package",
  "package": {
    "name": "package-name/package-name",
    "version": "1.0.0",
    "dist": {
      "type": "zip",
      "url": "{%PACKAGE_NAME_URL}"
    },
    "require": {
      "ffraenz/private-composer-installer": "^1.0"
    }
  }
}
```

Provide the private package dist URL inside the `.env` file.

```
PACKAGE_NAME_URL=https://example.com/package-name.zip?key=xyz
```

Let composer require the private package.

```bash
composer require package-name/package-name:*
```

### WordPress ACF Pro plugin

Add following entry to the `repositories` field inside `composer.json` and set the desired ACF Pro version.

```json
{
  "type": "package",
  "package": {
    "name": "advanced-custom-fields/advanced-custom-fields-pro",
    "version": "1.2.3",
    "type": "wordpress-plugin",
    "dist": {
      "type": "zip",
      "url": "https://connect.advancedcustomfields.com/index.php?a=download&p=pro&k={%PLUGIN_ACF_KEY}&t={%version}"
    },
    "require": {
      "composer/installers": "^1.4",
      "ffraenz/private-composer-installer": "^1.0"
    }
  }
}
```

Provide the ACF Pro key inside the `.env` file. To get this key, login to your [ACF account](https://www.advancedcustomfields.com/my-account/) and scroll down to 'Licenses & Downloads'.

```
PLUGIN_ACF_KEY=xyz
```

Let composer require ACF Pro.

```bash
composer require advanced-custom-fields/advanced-custom-fields-pro:*
```

### WordPress WPML plugin

Add following entry to the `repositories` field inside `composer.json` and set the desired WPML version.

```json
{
  "type": "package",
  "package": {
    "name": "wpml/wpml-multilingual-cms",
    "version": "3.9.3",
    "type": "wordpress-plugin",
    "dist": {
      "type": "zip",
      "url": "https://wpml.org/?download=6088&user_id={%PLUGIN_WPML_USER_ID}&subscription_key={%PLUGIN_WPML_SUBSCRIPTION_KEY}&version={%version}"
    },
    "require": {
      "composer/installers": "^1.4",
      "ffraenz/private-composer-installer": "^1.0"
    }
  }
}
```

Provide your subscription key and user id inside the `.env` file. To get those, login into your [WPML account](https://wpml.org/account/), navigate to the downloads page and examine the download URL of the desired package.

```
PLUGIN_WPML_SUBSCRIPTION_KEY=xyz
PLUGIN_WPML_USER_ID=123
```

Let composer require WPML.

```bash
composer require wpml/wpml-multilingual-cms:*
```
