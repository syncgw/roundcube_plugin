# ![picture logo](https://github.com/syncgw/gui-bundle/blob/master/assets/syncgw.png "sync•gw") #
 
![](https://img.shields.io/packagist/v/syncgw/roundcube-syncgw.svg)
![](https://img.shields.io/packagist/l/syncgw/roundcube-syncgw.svg)
![](https://img.shields.io/packagist/dt/syncgw/roundcube-syncgw.svg)
 
**sync•gw** is the one and only fully portable server software available providing synchronization service between nearly any mobile device and your web server.

## roundcube_plugin bundle ##

With this Plugin you can specify in your [RoundCube](https://roundcube.net) installation which address books, calendars, task lists and notes you want to synchronize with your cell phone / smart phone. For address boks you can specify whether you want to synchronize only contacts with a phone number specified or if you want to synchronize all contacts within this address book.

**Requirements**

To use this plugin, you need a functional [RoundCube](https://roundcube.net) installation. To enable some post installation scrips you need to edit your `composer.json` file and add somewhere the following lines of code

   ```
	"scripts": {
        "post-package-install" : [
            "syncgw\\lib\\Setup::postInstall"
        ],
        "post-package-update" : [
            "syncgw\\lib\\Setup::postInstall"
        ],
		"post-package-uninstall" : [
            "syncgw\\lib\\Setup::postUninstall"
		]
    }
   ```

This script links `vendor/syncgw/core-bundle/src/sync.php` to `sync.php` which is the script used for synchronization and configuration of **sync•gw**.

**Installation**

* Please install [sync•gw plugin](https://github.com/syncgw/roundcube_plugin).

   ```
  composer require syncgw/roundcube_plugin
   ```

* If you want to synchronize address books, then you don't need any additional RoundCube plugin.

* If you want to use shared address books, then you need to install [globaladdressbook-Plugin](https://github.com/johndoh/roundcube-globaladdressbook).

   ```
   composer require johndoh/globaladdressbook
   ```
  
* If you want to synchronize calendar, then you need to install [calender plugin](https://packagist.org/packages/kolab/calendar).

   ```
  composer require kolab/calendar
   ```

* If you want to synchronize tasklis, then you need to install [tasklist plugin](https://plugins.roundcube.net/packages/kolab/tasklist).

   ```
  composer require kolab/tasklist
   ```
  
    **Caution:** If you use the plugin and receive a error message in RoundCube log file, then please check file `plugins/tasklist/config.inc.php`. There `$config['tasklist_driver'] = 'database';` should be specified.
  
* If you want to synchronize notes, then you need to install [ddnotes plugin](https://packagist.org/packages//dondominio/ddnotes).

   ```
  composer require dondominio/ddnotes 
   ```

* Activate our plugin by adding plugin name in file `config/config.inc.php`

   ```
  $config['plugins'] = array(
	...
	'roundcube_plugin',
	[the other optional plugins]
	...
  );
   ```
   
* Finally you need the **sync•gw** synchonization and GUI interface. Please go to your RoundCube installation
directoy and copy the file 

   ```
   copy (or cp) vendor\syncgw\core-bundle\src\sync.php .
   ```

**Usage**

* Start **sync•gw** web interface by typing into your browser's URL bar `http://[your-domain.tld]/[path to application directory]/sync.php`.

* Go to menu `Settings` and configure synchronization settings by selecting `Synchronization settings`.If this selection does not appear, then you did not install **sync•gw** in RoundCube root directory.
* Now you're ready to synchronize your selected data with your cell phone / smart phone. If you need some help how to configure you device, take a look a [sync•gw FAQ](https://github.com/syncgw/doc-bundle/blob/master/FAQ.md).

**Trouble shooting hints**

* If you don't see any **sync•gw** logo, then you may probably need to modify ``.htacess`` file: Open file and search for ``RewriteRule ... vendor| ...``. Remove ``vendor|`` from that line and save file and try calling **sync•gw** again. 

Please enjoy!

## License ##
This plugin is released under the [GNU General Public License v3.0](./LICENSE).

## Donation ##

If you like this software and you want support my work, feel free to send me a donation:

<a href="https://www.paypal.com/donate/?hosted_button_id=DS6VK49NAFHEQ" target="_blank" rel="noopener">   <img src="https://www.paypalobjects.com/en_US/DK/i/btn/btn_donateCC_LG.gif" alt="Donate with PayPal"/> </a>

[[Documentation](https://github.com/syncgw/doc-bundle/blob/master/README.md)]
[[System requirements](https://github.com/syncgw/doc-bundle/blob/master/PreReqs.md)] 
[[Available bundles](https://github.com/syncgw/doc-bundle/blob/master/Packages.md)] 
[[List of all changes](https://github.com/syncgw/doc-bundle/blob/master/Changes.md)] 
[[Additional Downloads](https://github.com/syncgw/doc-bundle/blob/master/Downloads.md)] 
[[Frequently asked questions](https://github.com/syncgw/doc-bundle/blob/master/FAQ.md)] 
