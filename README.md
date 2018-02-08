
# private-composer-installer

[![Packagist](https://img.shields.io/packagist/v/ffraenz/private-composer-installer.svg?maxAge=3600)](https://packagist.org/packages/ffraenz/private-composer-installer)
[![MIT license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

Inspired by [acf-pro-installer](https://github.com/PhilippBaschke/acf-pro-installer) this [composer](https://getcomposer.org/) plugin tries to solve the problem of referencing private package URLs within `composer.json` and `composer.lock`. It allows you to outsource sensitive keys from the package dist URL into environment variables or a `.env` file typically ignored by version control.

Once activated, this helper scans the package dist URL for `{%XYZ}` placeholders. It then replaces them with corresponding environment variables before downloading. In addition a `{%version}` placeholder is being made available to inject the package version into the URL.

## Examples

### WordPress ACF Pro plugin

Update the ACF Pro version and add following entry to the `repositories` field in `composer.json`:

```json
{
  "type": "package",
  "package": {
    "name": "advanced-custom-fields/advanced-custom-fields-pro",
    "version": "5.6.8",
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

Add following value to the `.env` file:

```
PLUGIN_ACF_KEY=xyz
```

Require package:

```
composer require advanced-custom-fields/advanced-custom-fields-pro:*
```

### WordPress WPML plugin

Update the WPML version and add following entry to the `repositories` field in `composer.json`:

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

Add following values to the `.env` file:

```
PLUGIN_WPML_SUBSCRIPTION_KEY=xyz
PLUGIN_WPML_USER_ID=123
```

Require package:

```
composer require wpml/wpml-multilingual-cms:*
```
