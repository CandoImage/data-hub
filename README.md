# Data Hub Bundle

## General

Welcome at pimcore’s new data delivery & consumption platform.
It aims to integrate different input & output channel technologies into a simple & easy-to-configure system on top of pimcore.
Contributions of any kind are warmly appreciated.
A short introduction video of an output channel based on the GraphQL query language can be found [here](./doc/img/graphql/intro.mp4).

## Minimum Requirements

* Pimcore >= 5.7

## Install
```bash 
composer require pimcore/data-hub:dev-master
```

Check if the bundle has been installed
```bash
php bin/console pimcore:bundle:list
bin/console pimcore:bundle:list
+---------------------------------+---------+-----------+----+-----+-----+
| Bundle                          | Enabled | Installed | I? | UI? | UP? |
+---------------------------------+---------+-----------+----+-----+-----+
| PimcoreDataHubBundle            | ✔       | ✔         | ❌  | ✔   | ❌  |
+---------------------------------+---------+-----------+----+-----+-----+
```

In case the bundle has not been installed correctly install it via console
```bash
php bin/console pimcore:bundle:install PimcoreDataHubBundle
```

## Supported Channels

* ![](./doc/img/graphql/logo_mini.png) **[GraphQL](doc/GraphQL.md)**
* ![](./doc/img/csv/logo_small.png) CSV/XLS (coming soon...)
* ![](./doc/img/rest/logo_small.png) webservice (coming soon...)
* ...

## Adding a new configuration

![Configuration Overview](./doc/img/graphql/configuration3.png)

Choose the channel type

![Add Configuration](./doc/img/add_config.png)

And get the configuration done

Example for [GraphQL](doc/GraphQL.md)

## Required Backend User Permission

Either:
* `admin` role or
* `plugin_datahub_config`
