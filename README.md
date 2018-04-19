# WPSyncz

WP-CLI plugins for syncing WordPress databases, media and plugins.

## Installation

### Global (recommended)

    wp package install git@github.com:thegallagher/wpsyncz.git
    
### Local

Copy wpsyncz.php to your `/wp-content/mu-plugins/` folder.

### Remote

**WPSyncz needs to be installed on any remote servers** before it can be used.

You can do this globally on the remote server (once you've configured your aliases, see Configuration below):
    
    wp <alias> package install git@github.com:thegallagher/wpsyncz.git
    # eg: wp @production package install git@github.com:thegallagher/wpsyncz.git

Additionally there is a command built in to do a "local" install on the remote server.

    wp syncz install <alias>
    # eg: wp syncz install @production

## Configuration

WPSyncz works with [WP-CLI aliases](https://make.wordpress.org/cli/handbook/running-commands-remotely/#aliases).
The easiest way to configure this is to add `wp-cli.local.yml` to your WordPress root.

Eg:

    @production:
        ssh: deploy@example.com/var/www/sites/production
    @staging:
        ssh: deploy@example.com/var/www/sites/staging
      
Only aliases with the `ssh` key set will work at this stage. This means alias groups don't work.

If a `@local` alias is defined, this will be the install you will be "pushing" from and "pulling" to.
`@local` is also used internally to refer to the local install if it is not defined.

## Usage

    # Pull data from a remote server
    wp syncz pull <source> [<actions>...] [--yes]
    # eg: wp syncz pull @production
    # eg: wp syncz pull @staging db media
    
    # Push data to a remote server
    wp syncz push <destination> [<actions>...] [--yes]
    # eg: wp syncz push @production
    # eg: wp syncz push @staging plugins
    
    # Sync between 2 remote servers
    wp syncz remote <source> <destination> [<actions>...] [--yes]
    #eg: wp syncz remote @production @staging
    
    # Install WPSyncz on a remote server
    wp syncz install [<destination>] [--yes]
    
    # Used internaly to get data used for syncing
    wp syncz data <source> [<actions>...]
    # eg: wp syncz data @local